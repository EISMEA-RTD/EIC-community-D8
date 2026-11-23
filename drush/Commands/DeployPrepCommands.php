<?php

namespace Drush\Commands;

use Consolidation\AnnotatedCommand\Hooks\HookManager;
use Drush\Attributes as CLI;
use Drush\Drush;

/**
 * Makes a single `drush deploy` sufficient: it adds the second config import.
 *
 * The step cannot be expressed as an update hook or config, so it is attached
 * to `drush deploy` itself. The deployment keeps running one command; nothing
 * in the pipeline has to know about it.
 *
 * This file also used to carry a pre-deploy hook for the Drupal 10 to 11 / Group
 * 1 to 2 jump (SQL that dropped orphaned group relation types, then a Redis
 * flush). It was removed once the environments were on Drupal 11; it is in git
 * history at 1cbc79c, and docs/d11-upgrade/DEPLOY.md says when it would be
 * needed.
 *
 * This is a site-wide commandfile: Drush finds it by its location
 * (drush/Commands/) and its namespace (Drush\Commands), without Drupal being
 * bootstrapped.
 */
final class DeployPrepCommands extends DrushCommands {

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
