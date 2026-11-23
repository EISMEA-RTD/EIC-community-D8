<?php

declare(strict_types = 1);

namespace Drupal\Tests\eic_search\Unit;

use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Access\AccessResultNeutral;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\ddc_default_content\RestrictedContentPolicy;
use Drupal\group\Entity\GroupInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which accounts may view a research institution group.
 *
 * The group role configuration decides what a member or an outsider of a group
 * may do. The research institutions are restricted content, so that the hook
 * of this module keeps them from every account that is not trusted, whatever
 * a group role grants and whatever the operation is: a group role edit, or the
 * edit permission of the owner of a group, cannot open them to an applicant.
 */
#[Group('eic_search')]
class GroupAccessHookTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once DRUPAL_ROOT . '/modules/custom/eic_search/eic_search.module';

    $contexts_manager = $this->createMock(CacheContextsManager::class);
    $contexts_manager->method('assertValidTokens')->willReturn(TRUE);

    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $contexts_manager);
    $container->set('ddc_default_content.restricted_content', new RestrictedContentPolicy($this->createMock(AliasManagerInterface::class)));
    \Drupal::setContainer($container);
  }

  /**
   * The operations that the group access knows, and a custom one.
   */
  const OPERATIONS = [
    'view',
    'update',
    'delete',
    'view all revisions',
    'view revision',
    'revert',
    'delete revision',
    'publish',
  ];

  /**
   * Tests that an account that is not trusted may do nothing with an institution.
   */
  #[DataProvider('untrustedAccountsAndOperations')]
  public function testUntrustedAccountMayNotUseInstitution(bool $authenticated, bool $permission, string $operation): void {
    $result = eic_search_group_access($this->group('research_institution'), $operation, $this->account($authenticated, $permission));

    $this->assertInstanceOf(AccessResultForbidden::class, $result);
  }

  /**
   * Provides the accounts that are not trusted, with every operation.
   *
   * An anonymous visitor is not trusted even where the permission is granted
   * to the anonymous role.
   */
  public static function untrustedAccountsAndOperations(): array {
    $accounts = [
      'anonymous' => [FALSE, FALSE],
      'anonymous with the permission' => [FALSE, TRUE],
      'authenticated without a role' => [TRUE, FALSE],
    ];
    $cases = [];
    foreach ($accounts as $account => $values) {
      foreach (self::OPERATIONS as $operation) {
        $cases["$account, $operation"] = [...$values, $operation];
      }
    }
    return $cases;
  }

  /**
   * Tests that a trusted account is left to the group permissions.
   */
  #[DataProvider('operations')]
  public function testTrustedAccountHasNoOpinion(string $operation): void {
    $result = eic_search_group_access($this->group('research_institution'), $operation, $this->account(TRUE, TRUE));

    $this->assertInstanceOf(AccessResultNeutral::class, $result);
  }

  /**
   * Provides every operation.
   */
  public static function operations(): array {
    return array_combine(self::OPERATIONS, array_map(fn ($operation) => [$operation], self::OPERATIONS));
  }

  /**
   * Tests that the result varies by permissions and by anonymous.
   *
   * The result of a trusted account is cached as well: it must not be reused
   * for an account with the same group permissions that is not trusted.
   */
  #[DataProvider('trustAndOperations')]
  public function testResultVariesByPermissions(bool $trusted, string $operation): void {
    $group = $this->group('research_institution');
    $result = eic_search_group_access($group, $operation, $this->account(TRUE, $trusted));

    $this->assertContains('user.permissions', $result->getCacheContexts());
    $this->assertContains('user.roles:anonymous', $result->getCacheContexts());
    $this->assertContains('group:1', $result->getCacheTags());
  }

  /**
   * Provides the trusted and untrusted accounts, with a few operations.
   */
  public static function trustAndOperations(): array {
    return [
      'untrusted, view' => [FALSE, 'view'],
      'untrusted, update' => [FALSE, 'update'],
      'trusted, view' => [TRUE, 'view'],
      'trusted, update' => [TRUE, 'update'],
    ];
  }

  /**
   * Tests that the other groups are left alone, whatever the operation.
   */
  #[DataProvider('operations')]
  public function testOtherGroupsAreLeftAlone(string $operation): void {
    $result = eic_search_group_access($this->group('event'), $operation, $this->account(TRUE, FALSE));

    $this->assertInstanceOf(AccessResultNeutral::class, $result);
  }

  /**
   * Mocks a group.
   */
  protected function group(string $bundle): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('getEntityTypeId')->willReturn('group');
    $group->method('bundle')->willReturn($bundle);
    $group->method('getCacheContexts')->willReturn([]);
    $group->method('getCacheTags')->willReturn(['group:1']);
    $group->method('getCacheMaxAge')->willReturn(-1);
    return $group;
  }

  /**
   * Mocks an account.
   */
  protected function account(bool $authenticated, bool $permission): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn($authenticated);
    $account->method('isAnonymous')->willReturn(!$authenticated);
    $account->method('hasPermission')->willReturnCallback(
      fn (string $name): bool => $permission && $name === RestrictedContentPolicy::PERMISSION
    );
    return $account;
  }

}
