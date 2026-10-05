<?php

/**
 * @file
 * Deploy hooks for the EIC Deploy module.
 *
 * Deploy hooks run AFTER config import (`drush deploy:hook`), unlike
 * hook_update_N / hook_post_update_N which run during `drush updb`, BEFORE
 * `drush cim`. The realignment below reads the `allowed_formats` setting of
 * every formatted field, which `cim` imports — so it must run in the deploy
 * phase, not the update phase.
 *
 * Running it earlier (as the previous hook_update_9014 did) does not error, it
 * silently does nothing: before `cim` the field instances still carry their old
 * unrestricted `allowed_formats`, so every one of them is treated as "allows
 * every format" and skipped, and any instance with a stale non-empty list is
 * rewritten toward the wrong target format.
 *
 * NOTE: unlike most projects, this one's pipeline does NOT run `deploy:hook`.
 * .gitlab-ci.yml (the `.deploy_job` anchor) runs only:
 *   updb -y -> cr -> cim -y -> cr
 * so the hook below has to be invoked by hand after the deploy:
 *   ./vendor/bin/drush deploy:hook
 *
 * Deploy hooks are recorded in the `deploy_hook` keyvalue collection once they
 * report completion, so re-running `deploy:hook` is a no-op.
 */

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\SynchronizableInterface;

/**
 * Aligns stored text formats with the "Allowed text formats" of each field.
 *
 * Commit 9f0934c restricted the allowed_formats setting on most formatted
 * fields and removed the full_html editor, which left existing content
 * pointing at formats the field no longer accepts. This walks every content
 * entity carrying such a field and, whenever a stored format is not in the
 * instance's allowed list, rewrites it to the first allowed format.
 *
 * Field instances that allow every format (empty allowed_formats) are left
 * untouched: there is no "first" format to fall back to.
 *
 * Only the default revision of each entity is updated. Entities under content
 * moderation will still get a new revision on save, because content_moderation
 * forces one in its presave handler.
 *
 * Must run after `drush cim`: it reads the imported allowed_formats settings.
 * Idempotent — a format already in the allowed list is left alone, so a second
 * pass rewrites nothing.
 */
function eic_deploy_deploy_0001_allowed_formats(&$sandbox) {
  $batch_size = 50;
  $entity_type_manager = \Drupal::entityTypeManager();

  if (!isset($sandbox['map'])) {
    [$map, $skipped] = _eic_deploy_allowed_formats_map();

    $sandbox['map'] = $map;
    $sandbox['skipped'] = count($skipped);
    $sandbox['queue'] = array_keys($map);
    $sandbox['types_total'] = count($map);
    $sandbox['types_done'] = 0;
    $sandbox['type_total'] = NULL;
    $sandbox['type_done'] = 0;
    $sandbox['last_id'] = NULL;
    $sandbox['scanned'] = 0;
    $sandbox['updated'] = 0;
    $sandbox['rewritten'] = 0;

    if ($skipped) {
      \Drupal::logger('eic_deploy')->notice('Skipped @count field instances that allow every text format: @list', [
        '@count' => count($skipped),
        '@list' => implode(', ', $skipped),
      ]);
    }
  }

  // Drop entity types whose storage is no longer available, e.g. because the
  // providing module was uninstalled after the field config was created.
  while (!empty($sandbox['queue']) && !$entity_type_manager->hasDefinition(reset($sandbox['queue']))) {
    array_shift($sandbox['queue']);
    $sandbox['types_done']++;
    $sandbox['type_total'] = NULL;
  }

  if (empty($sandbox['queue'])) {
    $sandbox['#finished'] = 1;

    return t('Rewrote @rewritten field values on @updated of @scanned entities. @skipped field instances were skipped because they allow every text format; see the eic_deploy log for the list.', [
      '@rewritten' => $sandbox['rewritten'],
      '@updated' => $sandbox['updated'],
      '@scanned' => $sandbox['scanned'],
      '@skipped' => $sandbox['skipped'],
    ]);
  }

  $entity_type_id = reset($sandbox['queue']);
  $entity_type = $entity_type_manager->getDefinition($entity_type_id);
  $storage = $entity_type_manager->getStorage($entity_type_id);
  $id_key = $entity_type->getKey('id');
  $bundle_key = $entity_type->getKey('bundle');
  $bundles = array_keys($sandbox['map'][$entity_type_id]);

  if ($sandbox['type_total'] === NULL) {
    $count_query = $storage->getQuery()->accessCheck(FALSE);
    if ($bundle_key) {
      $count_query->condition($bundle_key, $bundles, 'IN');
    }

    $sandbox['type_total'] = (int) $count_query->count()->execute();
    $sandbox['type_done'] = 0;
    $sandbox['last_id'] = NULL;
  }

  $query = $storage->getQuery()
    ->accessCheck(FALSE)
    ->sort($id_key)
    ->range(0, $batch_size);
  if ($bundle_key) {
    $query->condition($bundle_key, $bundles, 'IN');
  }
  if ($sandbox['last_id'] !== NULL) {
    $query->condition($id_key, $sandbox['last_id'], '>');
  }
  $ids = $query->execute();

  if (empty($ids)) {
    // This entity type is done, move on to the next one.
    array_shift($sandbox['queue']);
    $sandbox['types_done']++;
    $sandbox['type_total'] = NULL;
    $sandbox['#finished'] = _eic_deploy_allowed_formats_progress($sandbox);

    return NULL;
  }

  // The query is sorted by ID, so the last value is the highest one seen.
  $sandbox['last_id'] = end($ids);

  foreach ($storage->loadMultiple(array_values($ids)) as $entity) {
    $sandbox['scanned']++;
    $sandbox['type_done']++;

    if (!$entity instanceof ContentEntityInterface) {
      continue;
    }

    $fields = $sandbox['map'][$entity_type_id][$entity->bundle()] ?? [];
    if (!$fields) {
      continue;
    }

    $rewritten = _eic_deploy_apply_allowed_formats($entity, $fields);
    if (!$rewritten) {
      continue;
    }

    // Keep the existing revision and flag the save as a synchronisation, so
    // the changed timestamp is preserved and modules reacting to editorial
    // saves (notifications, indexing) can opt out.
    if ($entity instanceof RevisionableInterface && $entity_type->isRevisionable()) {
      $entity->setNewRevision(FALSE);
    }
    if ($entity instanceof SynchronizableInterface) {
      $entity->setSyncing(TRUE);
    }
    $entity->save();

    $sandbox['updated']++;
    $sandbox['rewritten'] += $rewritten;
  }

  $sandbox['#finished'] = _eic_deploy_allowed_formats_progress($sandbox);

  return NULL;
}

/**
 * Builds the map of formatted fields that restrict their allowed formats.
 *
 * @return array
 *   A two item array. The first item maps entity type ID to bundle to field
 *   name to an array with the 'target' format (the first allowed one) and the
 *   full list of 'allowed' formats. The second item lists the IDs of the field
 *   instances that were skipped because they allow every format.
 */
function _eic_deploy_allowed_formats_map() {
  $formatted_types = ['text', 'text_long', 'text_with_summary'];
  $map = [];
  $skipped = [];

  $field_configs = \Drupal::entityTypeManager()
    ->getStorage('field_config')
    ->loadMultiple();

  /** @var \Drupal\field\FieldConfigInterface $field_config */
  foreach ($field_configs as $field_config) {
    if (!in_array($field_config->getType(), $formatted_types, TRUE)) {
      continue;
    }

    $allowed = array_values(array_filter((array) $field_config->getSetting('allowed_formats')));
    if (!$allowed) {
      $skipped[] = $field_config->id();
      continue;
    }

    $map[$field_config->getTargetEntityTypeId()][$field_config->getTargetBundle()][$field_config->getName()] = [
      'target' => reset($allowed),
      'allowed' => $allowed,
    ];
  }

  ksort($map);
  sort($skipped);

  return [$map, $skipped];
}

/**
 * Rewrites the disallowed formats of a single entity.
 *
 * @param \Drupal\Core\Entity\ContentEntityInterface $entity
 *   The entity to fix. It is modified in place but not saved.
 * @param array $fields
 *   Field name keyed rules, as built by _eic_deploy_allowed_formats_map().
 *
 * @return int
 *   The number of field values that were rewritten.
 */
function _eic_deploy_apply_allowed_formats(ContentEntityInterface $entity, array $fields) {
  $rewritten = 0;
  $original_langcode = $entity->getUntranslated()->language()->getId();

  foreach (array_keys($entity->getTranslationLanguages()) as $langcode) {
    $translation = $entity->getTranslation($langcode);

    foreach ($fields as $field_name => $rule) {
      if (!$translation->hasField($field_name)) {
        continue;
      }

      $items = $translation->get($field_name);

      // Untranslatable fields share a single set of values across languages,
      // so they only need to be handled once, on the original language.
      if ($langcode !== $original_langcode && !$items->getFieldDefinition()->isTranslatable()) {
        continue;
      }

      foreach ($items as $item) {
        if ($item->isEmpty()) {
          continue;
        }

        $format = $item->get('format')->getValue();
        if (in_array($format, $rule['allowed'], TRUE)) {
          continue;
        }

        $item->set('format', $rule['target']);
        $rewritten++;
      }
    }
  }

  return $rewritten;
}

/**
 * Reports how far eic_deploy_deploy_0001_allowed_formats() has progressed.
 *
 * @param array $sandbox
 *   The deploy hook sandbox.
 *
 * @return float
 *   A value between 0 and 1, capped below 1 while work remains.
 */
function _eic_deploy_allowed_formats_progress(array $sandbox) {
  if (empty($sandbox['types_total'])) {
    return 1;
  }

  // No entity type is being walked right now, so nothing is partially done.
  $current = 0;
  if ($sandbox['type_total'] !== NULL) {
    $current = empty($sandbox['type_total'])
      ? 1
      : $sandbox['type_done'] / $sandbox['type_total'];
  }

  return min(0.99, ($sandbox['types_done'] + $current) / $sandbox['types_total']);
}
