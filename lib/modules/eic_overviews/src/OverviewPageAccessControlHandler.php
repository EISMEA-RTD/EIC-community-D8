<?php

namespace Drupal\eic_overviews;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\eic_overviews\GlobalOverviewPages;

/**
 * Defines the access control handler for the overview page entity type.
 */
class OverviewPageAccessControlHandler extends EntityAccessControlHandler {

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

        // Deny anonymous access to the authenticated-only overviews
        // (Research Institutions and Resources Library).
        $authenticated_only = [
          GlobalOverviewPages::RESEARCH_INSTITUTIONS_UUID,
          GlobalOverviewPages::RESOURCES_UUID,
        ];
        if (in_array($entity->uuid(), $authenticated_only, TRUE) && $account->isAnonymous()) {
          return AccessResult::forbidden()
            ->addCacheContexts(['user.roles:anonymous'])
            ->addCacheTags($entity->getCacheTags());
        }

        return AccessResult::allowedIfHasPermission($account, 'view overview pages');

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
