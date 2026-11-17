<?php

namespace Drupal\eic_groups\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountProxy;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;

/**
 * Provides an access checker for highlighting group content.
 *
 * @package Drupal\eic_groups\Access
 */
class HighlightGroupContentAccessCheck implements AccessInterface {

  const SUPPORTED_PLUGIN_IDS = [
    'group_node:document',
    'group_node:discussion',
    'group_node:video',
    'group_node:gallery',
  ];

  /**
   * Constructs a new HighlightGroupContentAccessCheck object.
   *
   * @param \Drupal\Core\Session\AccountProxy $account
   *   The current user account.
   * @param \Drupal\group\Entity\GroupInterface|null $group
   *   The group entity.
   * @param \Drupal\node\NodeInterface|null $node
   *   The group content node.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(
    AccountProxy $account,
    GroupInterface $group = NULL,
    NodeInterface $node = NULL
  ) {
    if (!$group || !$node) {
      return AccessResult::forbidden();
    }

    $account = $account->getAccount();
    if (!$group->hasPermission('highlight group content', $account)) {
      return AccessResult::forbidden('highlight group content permission is required')
        ->setCacheMaxAge(0);
    }

    // Group 2 dropped the $filters argument from the relationship getters, so
    // rather than loading every relationship and filtering it down by type and
    // entity ID, ask the group for this node's relationships one plugin at a
    // time. Same answer, narrower queries.
    $supported = FALSE;
    $related = FALSE;
    foreach (self::SUPPORTED_PLUGIN_IDS as $plugin_id) {
      if (!$group->getGroupType()->hasPlugin($plugin_id)) {
        continue;
      }
      $supported = TRUE;
      if ($group->getRelationshipsByEntity($node, $plugin_id)) {
        $related = TRUE;
        break;
      }
    }

    if (!$supported) {
      return AccessResult::forbidden('Content not supported')
        ->setCacheMaxAge(0);
    }

    if (!$related) {
      return AccessResult::forbidden('Invalid group content argument')
        ->setCacheMaxAge(0);
    }

    return AccessResult::allowed()
      ->setCacheMaxAge(0);
  }

}
