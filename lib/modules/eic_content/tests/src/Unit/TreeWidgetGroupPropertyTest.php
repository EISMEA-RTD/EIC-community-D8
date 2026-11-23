<?php

declare(strict_types = 1);

namespace Drupal\Tests\eic_content\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ddc_default_content\RestrictedContentPolicy;
use Drupal\eic_content\TreeWidget\TreeWidgetGroupProperty;
use Drupal\group\Entity\GroupInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which groups the group property of the entity tree widget lists.
 *
 * The research institutions are restricted content: the accounts that are not
 * trusted do not get them in the list. Every account gets the groups that it
 * may view, and no other: a group that is not published, or that the group
 * roles keep from it, is not named to it.
 */
#[Group('eic_content')]
class TreeWidgetGroupPropertyTest extends UnitTestCase {

  /**
   * The conditions of the group query built, in order.
   *
   * @var array[]
   */
  protected array $conditions = [];

  /**
   * The groups that the storage loads.
   *
   * @var \Drupal\group\Entity\GroupInterface[]
   */
  protected array $groups = [];

  /**
   * The property under test.
   */
  protected TreeWidgetGroupProperty $property;

  /**
   * The account that makes the request.
   */
  protected AccountInterface $account;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $query = $this->createMock(QueryInterface::class);
    foreach (['accessCheck', 'range'] as $method) {
      $query->method($method)->willReturnSelf();
    }
    $query->method('condition')->willReturnCallback(function (...$arguments) use ($query) {
      // The double fills the optional langcode argument with NULL.
      while ($arguments && end($arguments) === NULL) {
        array_pop($arguments);
      }
      $this->conditions[] = $arguments;
      return $query;
    });
    $query->method('execute')->willReturn([1 => 1]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturnCallback(fn () => $this->groups);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('group')->willReturn($storage);

    $this->account = $this->createMock(AccountInterface::class);

    $container = new ContainerBuilder();
    $container->set('current_user', $this->account);
    $container->set('entity_type.manager', $entity_type_manager);
    $container->set('ddc_default_content.restricted_content', new RestrictedContentPolicy($this->createMock(AliasManagerInterface::class)));
    \Drupal::setContainer($container);

    $this->property = new TreeWidgetGroupProperty();
  }

  /**
   * Tests that the list leaves out the restricted groups for an untrusted account.
   */
  public function testListLeavesOutRestrictedGroupsForUntrustedAccount(): void {
    $this->account(FALSE);
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())->method('condition')->with('type', ['research_institution'], 'NOT IN');

    $this->property->generateExtraCondition($query, []);
  }

  /**
   * Tests that the list is as it was for a trusted account.
   */
  public function testListIsUnchangedForTrustedAccount(): void {
    $this->account(TRUE);
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->never())->method('condition');

    $this->property->generateExtraCondition($query, []);
  }

  /**
   * Tests that the search leaves out the restricted groups for an untrusted account.
   */
  public function testSearchLeavesOutRestrictedGroupsForUntrustedAccount(): void {
    $this->account(FALSE);

    $this->property->generateSearchQueryResults('T');

    $this->assertContains(['label', 'T', 'CONTAINS'], $this->conditions);
    $this->assertContains(['type', ['research_institution'], 'NOT IN'], $this->conditions);
  }

  /**
   * Tests that the search query is as it was for a trusted account.
   */
  public function testSearchQueryIsUnchangedForTrustedAccount(): void {
    $this->account(TRUE);

    $this->property->generateSearchQueryResults('T');

    $this->assertSame([['label', 'T', 'CONTAINS']], $this->conditions);
  }

  /**
   * Tests that the search names the groups that the account may view only.
   *
   * The access of the group decides for every account, trusted or not: a group
   * that is not published, or that the group roles keep from the account, is
   * left out, and the same group is listed to an account that may view it.
   */
  #[DataProvider('trustAndViews')]
  public function testSearchNamesTheViewableGroupsOnly(bool $trusted, bool $second_viewable, array $expected): void {
    $this->account($trusted);
    $this->groups = [1 => $this->group(1, 'Tsinghua University', TRUE), 2 => $this->group(2, 'Test Institution Alpha', $second_viewable)];

    $results = $this->property->generateSearchQueryResults('T');

    $this->assertSame($expected, array_column($results, 'tid'));
  }

  /**
   * Provides the accounts, and whether they may view the second group.
   */
  public static function trustAndViews(): array {
    return [
      'trusted, may not view the second group' => [TRUE, FALSE, [1]],
      'trusted, may view the second group' => [TRUE, TRUE, [1, 2]],
      'untrusted, may not view the second group' => [FALSE, FALSE, [1]],
      'untrusted, may view the second group' => [FALSE, TRUE, [1, 2]],
    ];
  }

  /**
   * Tests that the tree names the groups that the account may view only.
   */
  public function testTreeNamesTheViewableGroupsOnly(): void {
    $this->account(TRUE);
    $this->groups = [1 => $this->group(1, 'Tsinghua University', TRUE), 2 => $this->group(2, 'Test Institution Alpha', FALSE)];

    $this->assertSame([1], array_keys($this->property->loadEntities([1, 2])));
  }

  /**
   * Mocks a group, with the answer of its view access.
   */
  protected function group(int $id, string $label, bool $viewable): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('label')->willReturn($label);
    $group->method('access')->willReturnCallback(
      fn (string $operation, $account = NULL, bool $as_object = FALSE) => $operation === 'view' && $viewable
    );
    return $group;
  }

  /**
   * Sets who makes the request.
   */
  protected function account(bool $trusted): void {
    $this->account->method('isAuthenticated')->willReturn($trusted);
    $this->account->method('hasPermission')->willReturnCallback(
      fn (string $name): bool => $trusted && $name === RestrictedContentPolicy::PERMISSION
    );
  }

}
