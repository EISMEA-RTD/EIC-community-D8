<?php

namespace Drupal\eic_overviews;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ddc_default_content\RestrictedContentPolicy;
use Drupal\eic_overviews\GlobalOverviewPages;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the access control handler for the overview page entity type.
 */
class OverviewPageAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  /**
   * The overview ids of the overviews that render restricted data.
   *
   * The global search, the Research Institutions explorer and the Resources
   * Library show documents of the Solr index: resources, institutions and the
   * restricted pages.
   */
  const RESTRICTED_OVERVIEW_IDS = [
    GlobalOverviewPages::GLOBAL_SEARCH,
    GlobalOverviewPages::RESEARCH_INSTITUTIONS,
    GlobalOverviewPages::RESOURCES,
  ];

  /**
   * The UUIDs of the overviews that render restricted data.
   *
   * The global search has none that is the same on every site, so that it is
   * known by its overview id only.
   */
  const RESTRICTED_OVERVIEW_UUIDS = [
    GlobalOverviewPages::RESEARCH_INSTITUTIONS_UUID,
    GlobalOverviewPages::RESOURCES_UUID,
  ];

  /**
   * Constructs the access control handler.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   * @param \Drupal\ddc_default_content\RestrictedContentPolicy $restrictedContent
   *   The policy that tells who may see restricted content.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    protected readonly RestrictedContentPolicy $restrictedContent,
  ) {
    parent::__construct($entity_type);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('ddc_default_content.restricted_content'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    switch ($operation) {
      case 'view':
        // Deny access if page is disabled.
        if (!$entity->isEnabled()) {
          return AccessResult::forbidden();
        }

        // Deny the overviews that render restricted data to every account that
        // is not trusted: an anonymous visitor, and an account that only holds
        // the authenticated role, such as an applicant. The decision follows
        // the permissions of the account, and whether it is anonymous, for the
        // accounts that are let in as well as for those that are not.
        $restricted = $this->isRestricted($entity);
        if ($restricted && !$this->restrictedContent->isTrusted($account)) {
          return AccessResult::forbidden('Restricted content.')
            ->addCacheContexts(['user.permissions', 'user.roles:anonymous'])
            ->addCacheTags($entity->getCacheTags());
        }

        $access = AccessResult::allowedIfHasPermission($account, 'view overview pages');
        if ($restricted) {
          $access->addCacheContexts(['user.roles:anonymous'])
            ->addCacheTags($entity->getCacheTags());
        }
        return $access;

      case 'update':
        return AccessResult::allowedIfHasPermissions($account, [
          'edit overview pages',
          'administer overview pages',
        ], 'OR');

      case 'delete':
        return AccessResult::allowedIfHasPermissions($account, [
          'delete overview pages',
          'administer overview pages',
        ], 'OR');

      default:
        // No opinion.
        return AccessResult::neutral();
    }
  }

  /**
   * Tells whether an overview page renders restricted data.
   *
   * @param \Drupal\Core\Entity\EntityInterface $overview
   *   The overview page.
   *
   * @return bool
   *   TRUE when the overview is known by its UUID or by its overview id.
   */
  protected function isRestricted(EntityInterface $overview): bool {
    if (in_array($overview->uuid(), self::RESTRICTED_OVERVIEW_UUIDS, TRUE)) {
      return TRUE;
    }
    if (!$overview->hasField('field_overview_id')) {
      return FALSE;
    }
    return in_array((int) $overview->get('field_overview_id')->value, self::RESTRICTED_OVERVIEW_IDS, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(
    AccountInterface $account,
    array $context,
    $entity_bundle = NULL
  ) {
    return AccessResult::allowedIfHasPermissions($account, [
      'create overview pages',
      'administer overview pages',
    ], 'OR');
  }

}
