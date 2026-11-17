<?php

namespace Drupal\eic_search\Search\DocumentProcessor;

use Drupal\eic_groups\Constants\GroupVisibilityType;
use Drupal\eic_groups\EICGroupsHelperInterface;
use Solarium\QueryType\Update\Query\Document;

/**
 * Class ProcessorVisibility
 *
 * @package Drupal\eic_search\DocumentProcessor
 */
class ProcessorVisibility extends DocumentProcessor {

  /**
   * @var \Drupal\eic_groups\EICGroupsHelperInterface $groupsHelper
   */
  private $groupsHelper;

  /**
   * @param \Drupal\eic_groups\EICGroupsHelperInterface $groupsHelper
   */
  public function __construct(EICGroupsHelperInterface $groupsHelper) {
    $this->groupsHelper = $groupsHelper;
  }

  /**
   * @inheritDoc
   */
  public function process(Document &$document, array $fields, array $items = []): void {
    $search_id = array_key_exists('ss_search_api_id', $fields) ?
      $fields['ss_search_api_id'] :
      NULL;

    // By default we will add the visibility to "public" to every entity.
    // Even if it's not linked to group but we need to put it public
    // otherwise solr will not be able to reach content without this property.
    $group_visibility = GroupVisibilityType::GROUP_VISIBILITY_PUBLIC;

    if (!$search_id) {
      $document->addField('ss_group_visibility', $group_visibility);

      $document->addField(
        'ss_group_visibility_label',
        GroupVisibilityType::GROUP_VISIBILITY_PUBLIC
      );
      return;
    }

    /** @var \Drupal\search_api\Item\ItemInterface $item */
    $item = array_key_exists($search_id, $items) ? $items[$search_id] : NULL;
    if (!$item) {
      $document->addField('ss_group_visibility', $group_visibility);

      $document->addField(
        'ss_group_visibility_label',
        GroupVisibilityType::GROUP_VISIBILITY_PUBLIC
      );
      return;
    }

    $original_object = $item->getOriginalObject()->getEntity();

    $group = $this->groupsHelper->getOwnerGroupByEntity($original_object);

    if (!$group) {
      $document->addField('ss_group_visibility', $group_visibility);

      $document->addField(
        'ss_group_visibility_label',
        GroupVisibilityType::GROUP_VISIBILITY_PUBLIC
      );
      return;
    }

    // Group visibility was provided by oec_group_flex, which this project no
    // longer installs. Every group type other than event and group was already
    // fixed to the "public" visibility at group-type level, and the
    // oec_group_visibility table has never held a row -- it is empty here and
    // has no INSERT statements in the TEST dump either. So the label below is
    // exactly what getGroupVisibilityTagLabel() returned for every group that
    // exists, and the switch this replaces always fell through to its default.
    //
    // The organisation rule is kept because it never consulted oec_group_flex:
    // it tests the bundle alone. Note it deliberately reproduces the existing
    // mismatch, where the label stays "Public" while the indexed value is the
    // community one, because GroupAccessContent filters on the value only.
    $document->addField('ss_group_visibility_label', 'Public');

    // If group type is organisation, only trusted users can access it.
    // Therefore we assume the same visibility as GROUP_VISIBILITY_COMMUNITY.
    $group_visibility = $group->bundle() === 'organisation' ?
      GroupVisibilityType::GROUP_VISIBILITY_COMMUNITY :
      GroupVisibilityType::GROUP_VISIBILITY_PUBLIC;

    $document->addField('ss_group_visibility', $group_visibility);
    $this->setGroupOwner($document, 'its_group_owner_id', $group);
  }

}
