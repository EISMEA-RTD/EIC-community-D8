<?php

namespace Drupal\eic_group_membership\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\eic_flags\FlagHelper;
use Drupal\eic_flags\FlagType;
use Drupal\flag\FlagService;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class that provides drush commands related to group memberships.
 */
class GroupMembershipCommands extends DrushCommands {

  /**
   * The Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The queue factory service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The Flag service.
   *
   * @var \Drupal\flag\FlagService
   */
  protected $flagService;

  /**
   * The EIC Flag helper service.
   *
   * @var \Drupal\eic_flags\FlagHelper
   */
  protected $eicFlagHelper;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new GroupMembershipCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The Entity type manager.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory service.
   * @param \Drupal\flag\FlagService $flag_service
   *   The Flag service.
   * @param \Drupal\eic_flags\FlagHelper $eic_flag_helper
   *   The EIC Flag helper service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    QueueFactory $queue_factory,
    FlagService $flag_service,
    FlagHelper $eic_flag_helper,
    ConfigFactoryInterface $config_factory
  ) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->queueFactory = $queue_factory;
    $this->flagService = $flag_service;
    $this->eicFlagHelper = $eic_flag_helper;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('queue'),
      $container->get('flag'),
      $container->get('eic_flags.helper'),
      $container->get('config.factory'),
    );
  }

  /**
   * Execute queue items to unfollow group content nodes from non members.
   *
   */
  #[CLI\Command(name: 'eic_group_membership:unfollowGroupContentFromNonMembers', aliases: ['unfollow_group_content_from_non_members'])]
  #[CLI\Usage(name: 'eic_group_membership:unfollowGroupContentFromNonMembers', description: 'Run the command without any arguments to unfollow group content nodes from non members.')]
  public function unfollowGroupContentFromNonMembers() {
    $config = $this->configFactory->get('eic_group_membership.settings');
    // Setting to unfollow group content nodes when leaving the group is
    // disabled so we do nothing.
    if (!$config->get('unfollow_group_content_dequeue_items')) {
      return;
    }

    $queue = $this->queueFactory->get('eic_group_membership_unfollow_content');

    while ($item = $queue->claimItem()) {
      try {
        if (
          !empty($item->data['gid']) &&
          !empty($item->data['uid'])
        ) {

          $group = $this->entityTypeManager->getStorage('group')->load($item->data['gid']);
          if (!$group instanceof GroupInterface) {
            $queue->deleteItem($item);
            continue;
          }

          // The group visibility short-circuit that stood here came from
          // oec_group_flex, which this project no longer installs. It is not
          // replaced: the unfollow loop below already decides per flagging,
          // using $flagging->getFlaggable()->access('view', $user), so it
          // unflags only content the user genuinely cannot see. With every
          // group public that access check passes and nothing is unflagged --
          // the same outcome the short-circuit produced, but without a second
          // source of truth.
          //
          // The removed code was also a live fatal: it called getType() on
          // GroupVisibilityDatabaseStorage::load(), which returns FALSE when a
          // group has no visibility record, and the oec_group_visibility table
          // is empty in every environment. It raised "Call to a member function
          // getType() on false" on the first queued item.

          $user = $this->entityTypeManager->getStorage('user')->load($item->data['uid']);
          if (!$user instanceof UserInterface) {
            $queue->deleteItem($item);
            continue;
          }

          // Get user follow content flags of all group content nodes of the
          // group.
          $user_content_flaggings = $this->eicFlagHelper->getGroupContentFollowFlaggingsFromUser($group, $user);

          // Unfollows every group content node if the user cannot view it.
          if (!empty($user_content_flaggings)) {
            foreach ($user_content_flaggings as $flagging) {
              if ($flagging->getFlaggable()->access('view', $user)) {
                continue;
              }
              // Anonymous users shouldn't be able to flag in the first place.
              // Therefore, we delete the flagging.
              if ($user->isAnonymous()) {
                $flagging->delete();
                continue;
              }
              $this->flagService->unflag($flagging->getFlag(), $flagging->getFlaggable(), $user);
            }
          }
        }

        $queue->deleteItem($item);
      }
      catch (SuspendQueueException $e) {
        $queue->releaseItem($item);
        break;
      }
    }
  }

  /**
   * Execute queue items to unfollow group content nodes on visibility change.
   *
   */
  // @todo The command name below is a pre-existing placeholder
  // ("commandName"). Kept verbatim so the registered command does not change;
  // rename deliberately once callers are known.
  #[CLI\Command(name: 'eic_group_membership:commandName', aliases: ['unfollow_group_content_on_visibility_change'])]
  #[CLI\Usage(name: 'eic_group_membership:commandName', description: 'Run the command without any arguments to unfollow group content nodes from non members after changing group visibility.')]
  public function unfollowGroupContentOnVisibilityChange() {
    $config = $this->configFactory->get('eic_group_membership.settings');
    // Setting to unfollow group content nodes on visibility change is disabled,
    // so we do nothing.
    if (!$config->get('unfollow_group_content_dequeue_items')) {
      return;
    }

    $queue = $this->queueFactory->get('eic_group_membership_visibility_change_unfollow_content');

    while ($item = $queue->claimItem()) {
      try {
        if (!empty($item->data['gid'])) {

          $group = $this->entityTypeManager->getStorage('group')->load($item->data['gid']);
          if (!$group instanceof GroupInterface) {
            $queue->deleteItem($item);
            continue;
          }

          // The group visibility short-circuit that stood here came from
          // oec_group_flex, which this project no longer installs. It is not
          // replaced: the unfollow loop below already decides per flagging,
          // using $flagging->getFlaggable()->access('view', $user), so it
          // unflags only content the user genuinely cannot see. With every
          // group public that access check passes and nothing is unflagged --
          // the same outcome the short-circuit produced, but without a second
          // source of truth.
          //
          // The removed code was also a live fatal: it called getType() on
          // GroupVisibilityDatabaseStorage::load(), which returns FALSE when a
          // group has no visibility record, and the oec_group_visibility table
          // is empty in every environment. It raised "Call to a member function
          // getType() on false" on the first queued item.

          $flag_types = [
            FlagType::FOLLOW_GROUP,
            FlagType::FOLLOW_CONTENT,
          ];
          // Unfollows every group and group content node if the user cannot
          // view it.
          foreach ($flag_types as $flag_type) {
            $flaggings = $this->eicFlagHelper->getGroupFollowFlaggingsByNonMembers($group, $flag_type);

            foreach ($flaggings as $flagging) {
              if ($flagging->getFlaggable()->access('view', $flagging->getOwner())) {
                continue;
              }
              // Anonymous users shouldn't be able to flag in the first place.
              // Therefore, we delete the flagging.
              if ($flagging->getOwner()->isAnonymous()) {
                $flagging->delete();
                continue;
              }
              $this->flagService->unflag($flagging->getFlag(), $flagging->getFlaggable(), $flagging->getOwner());
            }
          }
        }

        $queue->deleteItem($item);
      }
      catch (SuspendQueueException $e) {
        $queue->releaseItem($item);
        break;
      }
    }
  }

}
