<?php

namespace Drupal\eic_content\TreeWidget;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\group\Entity\GroupInterface;

class TreeWidgetGroupProperty implements TreeWidgetProperties {

  /**
   * Returns the group bundles that the current account may not list.
   *
   * The research institutions are restricted content: they are listed to the
   * trusted accounts only. The rule is the one of the restricted content
   * policy.
   *
   * @return string[]
   *   The bundles; none for a trusted account.
   */
  protected function getUnlistedBundles(): array {
    /** @var \Drupal\ddc_default_content\RestrictedContentPolicy $policy */
    $policy = \Drupal::service('ddc_default_content.restricted_content');
    if ($policy->isTrusted(\Drupal::currentUser())) {
      return [];
    }
    return $policy->getRestrictedGroupBundles();
  }

  /**
   * Keeps the groups that the current account may view.
   *
   * The queries of the widget do not check access, so that every list is
   * filtered here, for every account: a group that is not published, or that
   * the group roles keep from the account, is not named to it.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $groups
   *   The groups, keyed by id.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The groups that may be viewed, with their keys.
   */
  protected function filterViewable(array $groups): array {
    $account = \Drupal::currentUser();
    return array_filter($groups, fn (EntityInterface $group) => $group->access('view', $account));
  }

  /**
   * {@inheritdoc}
   */
  public function getSortField(): string {
    return 'label';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabelFromEntity(EntityInterface $entity): string {
    return $entity->label();
  }

  /**
   * {@inheritdoc}
   */
  public function generateExtraCondition(QueryInterface &$query, array $options): void {
    if ($unlisted = $this->getUnlistedBundles()) {
      $query->condition('type', $unlisted, 'NOT IN');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function loadEntities(array $ids): array {
    return $this->filterViewable(
      \Drupal::entityTypeManager()->getStorage('group')->loadMultiple($ids)
    );
  }

  /**
   * {@inheritdoc}
   */
  public function generateSearchQueryResults(string $search_text): array {
    $query = \Drupal::entityQuery('group')
      ->accessCheck(FALSE)
      ->condition('label', $search_text, 'CONTAINS')
      ->range(0, 20);

    // An account that is not trusted does not get the restricted groups.
    if ($unlisted = $this->getUnlistedBundles()) {
      $query->condition('type', $unlisted, 'NOT IN');
    }

    if (!empty($ignored_tids)) {
      $query->condition('tid', $ignored_tids, 'NOT IN');
    }

    $entities = $query->execute();
    $entities = $this->filterViewable(
      \Drupal::entityTypeManager()->getStorage('group')->loadMultiple($entities)
    );

    return array_map(function (GroupInterface $group) {
      return [
        'name' => $group->label(),
        'tid' => $group->id(),
        'parent' => 0,
      ];
    }, $entities);
  }

  /**
   * {@inheritdoc}
   */
  public function formatPreselection(array $entities): string {
    return json_encode(
      array_map(function (EntityInterface $entity) {
        return [
          'name' => $entity->label(),
          'tid' => $entity->id(),
          'parent' => 0,
        ];
      }, $entities)
    );
  }

}
