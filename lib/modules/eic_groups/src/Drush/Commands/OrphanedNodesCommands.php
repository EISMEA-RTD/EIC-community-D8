<?php

namespace Drupal\eic_groups\Drush\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command class that contains a command to find and remove
 * nodes that are no longer part of a group.
 */
class OrphanedNodesCommands extends DrushCommands {

  protected $entityTypeManager;

  protected $database;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, Connection $database) {
    parent::__construct();
    $this->entityTypeManager = $entityTypeManager;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('database'),
    );
  }

  /**
   * Finds all nodes that were part of a group, which is now deleted.
   *
   */
  #[CLI\Command(name: 'eic_groups:orphanednodes', aliases: ['eic-orphaned'])]
  #[CLI\Usage(name: 'eic_groups:orphanednodes', description: 'Finds all nodes that were part of a group, which is now deleted.')]
  public function actionOrphanedNodes() {
    $aliases = $this->database->select('path_alias', 'pa')
      ->fields('pa', ['path', 'alias'])
      ->condition('pa.path', '%node/%', 'LIKE')
      ->condition('pa.alias', '%/groups/%', 'LIKE')
      ->execute()->fetchAllKeyed();
    $nodes_to_remove = [];
    foreach ($aliases as $path => $alias) {
      $parts = explode('/', $alias);
      $group_url = array_slice($parts, 0, 3);

      // Let's make sure the node is/was indeed part of a group.
      if ($group_url[1] === 'groups') {
        $group_url = implode('/', $group_url);
        $q = $this->database->select('path_alias', 'pa')
          ->fields('pa', ['alias'])
          ->condition('pa.alias', $group_url)
          ->execute()->fetchAssoc();
        if (empty($q)) {
          $node_id = explode('/', $path);
          $nodes_to_remove[$node_id[2]] = $alias;
        }
      }
    }

    $count = count($nodes_to_remove);
    if ($this->confirm("$count orphaned nodes will be deleted. Proceed?")) {
      $count_final = 0;
      $this->io()->progressStart($count);
      foreach ($nodes_to_remove as $item_node_id => $alias) {
        $node = $this->entityTypeManager->getStorage('node')->load($item_node_id);
        if (is_null($node)) {
          $this->io()->note("No node found for $alias");
        }
        elseif ($node instanceof NodeInterface && $node->isPublished()) {
          $count_final++;
          $node->delete();
        }
        $this->io()->progressAdvance();
      }
      $this->io()->progressFinish();
      $this->io()->success("$count_final/$count orphaned nodes were deleted.");
    }
    else {
      $this->io()->note('No action has taken place.');
    }
  }
}
