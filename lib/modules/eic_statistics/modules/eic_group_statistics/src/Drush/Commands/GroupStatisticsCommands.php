<?php

namespace Drupal\eic_group_statistics\Drush\Commands;

use Drupal\eic_group_statistics\GroupStatisticsHelperInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A Drush commandfile for updating group statistics.
 */
class GroupStatisticsCommands extends DrushCommands {

  /**
   * The EIC group statistics helper service.
   *
   * @var \Drupal\eic_group_statistics\GroupStatisticsHelperInterface
   */
  protected $groupStatisticsHelper;

  /**
   * Constructs a new GroupStatisticsCommands object.
   *
   * @param \Drupal\eic_group_statistics\GroupStatisticsHelperInterface $group_statistics_helper
   *   The EIC group statistics helper service.
   */
  public function __construct(GroupStatisticsHelperInterface $group_statistics_helper) {
    parent::__construct();
    $this->groupStatisticsHelper = $group_statistics_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('eic_group_statistics.helper'),
    );
  }

  /**
   * Command to update all group statistics.
   */
  #[CLI\Command(name: 'eic:updateGroupStatistics', aliases: ['update-group-statistics'])]
  #[CLI\Usage(name: 'eic:updateGroupStatistics', description: 'Updates the statistics of all groups.')]
  public function updateGroupStatistics() {
    $this->groupStatisticsHelper->updateAllGroupsStatistics();
    $this->output()->writeln('All group statistics have been updated successfully.');
    $this->logger()->info('All group statistics have been updated successfully.');
  }

}
