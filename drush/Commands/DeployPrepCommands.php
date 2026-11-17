<?php

namespace Drush\Commands;

use Consolidation\AnnotatedCommand\Hooks\HookManager;
use Drush\Attributes as CLI;
use Drush\Drush;

/**
 * Makes a single `drush deploy` sufficient for the Drupal 11 / Group 2 upgrade.
 *
 * Two steps the upgrade needs cannot be expressed as update hooks or config, so
 * they are attached to `drush deploy` itself. The deployment keeps running one
 * command; nothing in the pipeline has to know about them.
 *
 * See docs/d11-upgrade/DEPLOY.md for why each step exists.
 *
 * This is a site-wide commandfile: Drush finds it by its location
 * (drush/Commands/) and its namespace (Drush\Commands), without Drupal being
 * bootstrapped. That matters, because the first hook has to run while Drupal
 * cannot yet boot.
 */
final class DeployPrepCommands extends DrushCommands {

  /**
   * SQL that removes group relation types whose plugins no longer exist.
   *
   * Which relation types: the ones whose plugin we stopped shipping
   * (group_request_archival, group_request_delete, group_request_block,
   * group_shared_content and the group_stakeholder derivatives, removed in
   * ecbd02a), and only when nothing references them as group content. The
   * content check is the important one: a relation type that still holds content
   * must never be deleted silently, and eic_deploy_update_9020 makes the same
   * check.
   *
   * Why it cannot be an update hook: Group 2's GroupRelationshipType::label()
   * asks the plugin manager for its plugin, and EntityTypeBundleInfo calls
   * label() on every bundle. So the moment Group 2's code is in place, building
   * bundle info throws "The group_request_archival plugin does not exist" and
   * `drush updb` dies before it runs anything. Hence SQL: `drush sql:query`
   * bootstraps only as far as the database, so it works while the container
   * cannot be built at all.
   *
   * It lives here, as a string, and not in a .sql file, for two reasons: this
   * folder is synced to the deployed image and docs/ is not, and the clone's
   * pre-commit guard refuses .sql files outside docs/ (it exists to keep
   * database dumps out of the repository).
   *
   * Safe to run more than once, and safe on a database that has nothing to
   * clean, including one where `drush updb` has already renamed the
   * relationship tables.
   */
  private const PRE_UPDATE_SQL = <<<'SQL'
-- Which table holds the relationships depends on whether group_update_9203 has
-- run yet. Resolve it first; a missing table would otherwise abort the script
-- even inside a condition that excludes every row, because MySQL resolves table
-- names when it executes, not when it plans.
SET @content_table := (
  SELECT t.table_name
  FROM information_schema.tables t
  WHERE t.table_schema = DATABASE()
    AND t.table_name IN ('group_content_field_data', 'group_relationship_field_data')
  ORDER BY FIELD(t.table_name, 'group_content_field_data', 'group_relationship_field_data')
  LIMIT 1
);

CREATE TEMPORARY TABLE orphan_relation_types (id VARCHAR(255) NOT NULL);

-- The relation types to drop: those naming a plugin we no longer ship, and only
-- when nothing references them as group content. The content check is the
-- important one -- a relation type that still holds content must never be
-- deleted silently, and eic_deploy_update_9020 makes the same check.
SET @collect := CONCAT(
  'INSERT INTO orphan_relation_types (id) ',
  'SELECT SUBSTRING(c.name, 20) FROM config c ',
  'WHERE c.name LIKE ''group.content_type.%'' ',
  '  AND ( c.data LIKE ''%group_request_archival%''',
  '     OR c.data LIKE ''%group_request_delete%''',
  '     OR c.data LIKE ''%group_request_block%''',
  '     OR c.data LIKE ''%group_shared_content%''',
  '     OR c.data LIKE ''%group_stakeholder:%'' )',
  IF(@content_table IS NULL, '',
     CONCAT(' AND SUBSTRING(c.name, 20) NOT IN (SELECT DISTINCT r.type FROM ', @content_table, ' r)'))
);
PREPARE collect_stmt FROM @collect;
EXECUTE collect_stmt;
DEALLOCATE PREPARE collect_stmt;

SELECT CONCAT('Dropping ', COUNT(*), ' orphaned relation type(s).') AS status
FROM orphan_relation_types;

-- The dependent configuration the entity API would have cascaded to. Config
-- import reconciles the rest afterwards; these are removed here so that the
-- update run in between does not see fields and displays on a bundle that no
-- longer exists.
DELETE FROM config
WHERE name IN (SELECT CONCAT('group.content_type.', id) FROM orphan_relation_types);

DELETE c FROM config c
JOIN orphan_relation_types o
  ON c.name LIKE CONCAT('field.field.group_content.', o.id, '.%')
     OR c.name LIKE CONCAT('core.entity_form_display.group_content.', o.id, '.%')
     OR c.name LIKE CONCAT('core.entity_view_display.group_content.', o.id, '.%');

DROP TEMPORARY TABLE orphan_relation_types;

-- Bundle info, config and the compiled container all cache what was just
-- deleted. They have to go, or the very next request rebuilds from stale data.
TRUNCATE TABLE cache_config;
TRUNCATE TABLE cache_discovery;
TRUNCATE TABLE cache_bootstrap;
TRUNCATE TABLE cache_container;
TRUNCATE TABLE cache_default;
SQL;

  /**
   * Runs before `drush deploy` does anything.
   *
   * Group 2 builds bundle info, and with it every relation type's plugin, on
   * every boot. Relation types that name a plugin we no longer ship make that
   * throw, so `drush updb` cannot even list its pending updates -- and the
   * update hook that would delete those types is one of them. The SQL deletes
   * them without booting Drupal.
   *
   * It is safe to run on every deploy: it does nothing when there is nothing to
   * clean, and it never drops a relation type that still holds content.
   */
  #[CLI\Hook(type: HookManager::PRE_COMMAND_HOOK, target: 'deploy')]
  public function beforeDeploy(): void {
    $self = Drush::aliasManager()->getSelf();

    // sql:query reads a file, and resolves it inside the container, so the path
    // must be absolute.
    $sql_file = tempnam(sys_get_temp_dir(), 'ddc-pre-deploy-');
    if ($sql_file === FALSE || file_put_contents($sql_file, self::PRE_UPDATE_SQL) === FALSE) {
      throw new \RuntimeException('Could not write the pre-deploy SQL to a temporary file; refusing to deploy without it.');
    }

    $this->logger()->notice('Pre-deploy: removing orphaned group relation types.');
    try {
      Drush::drush($self, 'sql:query', [], ['file' => $sql_file])->mustRun();
    }
    finally {
      @unlink($sql_file);
    }

    // The SQL clears the database cache tables, but a site that keeps its caches
    // in Redis (AWS) would still serve the deleted relation types from cached
    // config and bundle info.
    $this->flushRedis();
  }

  /**
   * Empties the Redis database Drupal caches into, without booting Drupal.
   *
   * `drush cache:rebuild` cannot do this job here. It needs the full service
   * container, and on a database that has not been updated yet that container
   * cannot be built: group_permission.calculator depends on
   * flexible_permissions.chain_calculator, which only group_update_9200
   * installs. Only the update kernel behind `drush updatedb` is lenient enough
   * to run in this window, so the flush talks to Redis directly.
   *
   * The connection comes from the same REDIS_HOST / REDIS_PORT environment
   * variables the settings template reads. Where they are not set -- a site
   * that caches in the database -- there is nothing to do.
   */
  private function flushRedis(): void {
    $host = getenv('REDIS_HOST');
    if (!$host) {
      $this->logger()->notice('Pre-deploy: REDIS_HOST is not set; no Redis cache to flush.');
      return;
    }
    if (!extension_loaded('redis')) {
      throw new \RuntimeException('REDIS_HOST is set but the redis PHP extension is not loaded; cannot flush the cache.');
    }

    $port = (int) (getenv('REDIS_PORT') ?: 6379);
    $redis = new \Redis();
    if (!$redis->connect($host, $port, 5)) {
      throw new \RuntimeException(sprintf('Could not connect to Redis at %s:%d.', $host, $port));
    }
    $redis->flushDB();
    $this->logger()->notice(sprintf('Pre-deploy: flushed Redis at %s:%d.', $host, $port));
  }

  /**
   * Runs after `drush deploy` has finished successfully.
   *
   * `drush deploy` imports configuration once. The first import installs
   * modules, and installing a module imports its own default configuration
   * after the importer has already computed its change list, so a second pass is
   * needed to reconcile. Without it the site is left with configuration that
   * does not match the repository. The pass is a no-op when nothing needs
   * reconciling.
   */
  #[CLI\Hook(type: HookManager::POST_COMMAND_HOOK, target: 'deploy')]
  public function afterDeploy(): void {
    $self = Drush::aliasManager()->getSelf();

    $this->logger()->notice('Post-deploy: second configuration import.');
    Drush::drush($self, 'config:import', [], ['yes' => TRUE])->mustRun();

    $this->logger()->notice('Post-deploy: final cache rebuild.');
    Drush::drush($self, 'cache:rebuild')->mustRun();
  }

}
