<?php

declare(strict_types=1);

namespace Drupal\eic_search\Plugin\search_api\processor;

use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Drupal\search_api\Attribute\SearchApiProcessor;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;

/**
 * Keeps unpublished groups and unpublished resources out of the index.
 *
 * The core "Entity status" processor has no settings: it does the same for
 * every datasource of an index, so on the global index it would also drop
 * unpublished nodes of every type and inactive users, which the searches of
 * authors and administrators still need to find. This one only looks at groups
 * and at nodes of the bundle listed in NODE_BUNDLES.
 *
 * A group or resource that is unpublished later is removed from the index by
 * Search API the next time it is indexed, because items dropped here are
 * deleted from the server.
 */
#[SearchApiProcessor(
  id: 'exclude_unpublished',
  label: new TranslatableMarkup('Exclude unpublished groups and resources'),
  description: new TranslatableMarkup('Keeps unpublished groups and unpublished resource nodes out of the index. Other entities are not touched.'),
  stages: [
    'alter_items' => 0,
  ],
)]
class ExcludeUnpublished extends ProcessorPluginBase {

  /**
   * The node bundles whose unpublished nodes are kept out of the index.
   */
  public const NODE_BUNDLES = ['resource'];

  /**
   * {@inheritdoc}
   */
  public static function supportsIndex(IndexInterface $index): bool {
    foreach ($index->getDatasources() as $datasource) {
      if (in_array($datasource->getEntityTypeId(), ['group', 'node'], TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterIndexedItems(array &$items): void {
    /** @var \Drupal\search_api\Item\ItemInterface[] $items */
    foreach ($items as $item_id => $item) {
      $object = $item->getOriginalObject()->getValue();
      if (!$object instanceof EntityPublishedInterface || $object->isPublished()) {
        continue;
      }
      if ($object instanceof GroupInterface
        || ($object instanceof NodeInterface && in_array($object->bundle(), self::NODE_BUNDLES, TRUE))) {
        unset($items[$item_id]);
      }
    }
  }

}
