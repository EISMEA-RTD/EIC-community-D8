<?php

declare(strict_types = 1);

namespace Drupal\Tests\eic_content\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ddc_default_content\RestrictedContentPolicy;
use Drupal\eic_content\Controller\EntityTreeController;
use Drupal\eic_content\Services\EntityTreeManager;
use Drupal\eic_content\TreeWidget\TreeWidgetProperties;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests who the entity tree endpoints serve users, groups and terms to.
 *
 * The widget behind /api/terms-tree lists the terms of a vocabulary, the
 * members of the community (name and e-mail address) and the groups. The
 * member directory and the research institutions are restricted content, so
 * that the user target is served to trusted accounts only, the group target
 * does not list the restricted group bundles to the others, and a target that
 * the widget does not know is refused.
 */
#[Group('eic_content')]
class EntityTreeControllerTest extends UnitTestCase {

  /**
   * The tree manager.
   */
  protected EntityTreeManager|MockObject $manager;

  /**
   * The account that makes the request.
   */
  protected AccountInterface|MockObject $account;

  /**
   * The storage of the entity types the controller queries.
   */
  protected EntityStorageInterface|MockObject $storage;

  /**
   * The controller under test.
   */
  protected EntityTreeController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->account = $this->createMock(AccountInterface::class);
    $this->storage = $this->createMock(EntityStorageInterface::class);
    $this->storage->method('getQuery')->willReturnCallback(fn () => $this->query());
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturn($this->storage);

    $policy = new RestrictedContentPolicy($this->createMock(AliasManagerInterface::class));

    $container = new ContainerBuilder();
    $container->set('current_user', $this->account);
    $container->set('entity_type.manager', $entity_type_manager);
    $container->set('ddc_default_content.restricted_content', $policy);
    \Drupal::setContainer($container);

    $this->manager = $this->createMock(EntityTreeManager::class);
    $this->controller = new EntityTreeController($this->manager, $policy);
  }

  /**
   * Tests that nobody but a trusted account is served the users.
   */
  #[DataProvider('untrustedAccountsAndEndpoints')]
  public function testUsersAreRefusedToAnAccountThatIsNotTrusted(bool $authenticated, string $error, string $endpoint): void {
    $this->account($authenticated, FALSE);
    $this->manager->expects($this->never())->method($this->anything());

    $response = $this->call($endpoint, ['targetEntity' => 'user', 'search_text' => 'a', 'loadAll' => '1']);

    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(['error' => $error], json_decode($response->getContent(), TRUE));
  }

  /**
   * Provides the accounts that are not trusted, with the error they get.
   */
  public static function untrustedAccountsAndEndpoints(): array {
    $cases = [];
    foreach (['tree', 'search'] as $endpoint) {
      $cases["anonymous, $endpoint"] = [FALSE, 'authentication_required', $endpoint];
      $cases["authenticated without a role, $endpoint"] = [TRUE, 'restricted_access_required', $endpoint];
    }
    return $cases;
  }

  /**
   * Provides the endpoints that take a target entity.
   */
  public static function endpoints(): array {
    return [
      'tree' => ['tree'],
      'search' => ['search'],
    ];
  }

  /**
   * Tests that a trusted account is served the users, in both endpoints.
   */
  public function testTrustedAccountIsServedTheUsers(): void {
    $this->account(TRUE, TRUE);
    $property = $this->createMock(TreeWidgetProperties::class);
    $property->method('getSortField')->willReturn('field_first_name');
    $property->method('loadEntities')->willReturn([]);
    $this->manager->method('getTreeWidgetProperty')->with('user')->willReturn($property);
    $this->manager->expects($this->once())->method('search')
      ->with('user', NULL, 'a', [], FALSE)
      ->willReturn([['name' => 'A member', 'tid' => 7, 'parent' => 0]]);

    $search = $this->call('search', ['targetEntity' => 'user', 'search_text' => 'a']);
    $tree = $this->call('tree', ['targetEntity' => 'user', 'loadAll' => '1']);

    $this->assertSame(200, $search->getStatusCode());
    $this->assertSame([['name' => 'A member', 'tid' => 7, 'parent' => 0]], json_decode($search->getContent(), TRUE));
    $this->assertSame(['terms' => [], 'total' => 0], json_decode($tree->getContent(), TRUE));
  }

  /**
   * Tests that the terms are served to everybody, as before.
   */
  #[DataProvider('endpoints')]
  public function testTermsAreServedToAnonymous(string $endpoint): void {
    $this->account(FALSE, FALSE);
    $this->manager->expects($this->atMost(1))->method('search')->willReturn([]);
    $this->manager->expects($this->atMost(1))->method('generateTree')->willReturn([]);

    $response = $this->call($endpoint, ['targetEntity' => 'taxonomy_term', 'targetBundle' => 'topics', 'search_text' => 'a']);

    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Tests that the group target is handed to the manager for every account.
   *
   * The manager's group property leaves out the restricted bundles for the
   * accounts that are not trusted (TreeWidgetGroupPropertyTest).
   */
  public function testGroupSearchIsLeftToTheGroupProperty(): void {
    $this->account(FALSE, FALSE);
    $this->manager->expects($this->once())->method('search')->with('group', NULL, 'T', [], FALSE)->willReturn([]);

    $response = $this->call('search', ['targetEntity' => 'group', 'search_text' => 'T']);

    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Tests that a target that the widget does not know is refused.
   *
   * The answer does not repeat what the caller sent, and nothing is queried.
   */
  #[DataProvider('unknownTargets')]
  public function testUnknownTargetIsRefused(array $query, string $endpoint): void {
    $this->account(TRUE, TRUE);
    $this->manager->expects($this->never())->method($this->anything());

    $response = $this->call($endpoint, $query);

    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame(['error' => 'invalid_parameter'], json_decode($response->getContent(), TRUE));
  }

  /**
   * Provides the targets that are refused, for each endpoint.
   */
  public static function unknownTargets(): array {
    $cases = [];
    foreach (['tree', 'search', 'children'] as $endpoint) {
      $cases["$endpoint: a node"] = [['targetEntity' => 'node', 'targetBundle' => 'resource'], $endpoint];
      $cases["$endpoint: a vocabulary"] = [['targetEntity' => 'taxonomy_vocabulary'], $endpoint];
      $cases["$endpoint: nothing"] = [[], $endpoint];
      $cases["$endpoint: an empty string"] = [['targetEntity' => ''], $endpoint];
    }
    return $cases;
  }

  /**
   * Tests that the children are the children of terms only.
   *
   * Users and groups have none, and the manager would fail on them.
   */
  #[DataProvider('childlessTargets')]
  public function testChildrenOfUsersAndGroupsAreRefused(string $target): void {
    $this->account(TRUE, TRUE);
    $this->manager->expects($this->never())->method($this->anything());

    $response = $this->call('children', ['targetEntity' => $target, 'parent_term' => '1']);

    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * Provides the targets that have no children.
   */
  public static function childlessTargets(): array {
    return ['user' => ['user'], 'group' => ['group']];
  }

  /**
   * Tests that the children of terms are served.
   */
  public function testChildrenOfTermsAreServed(): void {
    $this->account(FALSE, FALSE);
    $this->manager->expects($this->once())->method('loadChildrenLevel')->willReturn([]);

    $response = $this->call('children', ['targetEntity' => 'taxonomy_term', 'targetBundle' => 'topics', 'parent_term' => '1']);

    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Calls an endpoint of the controller.
   */
  protected function call(string $endpoint, array $query) {
    $request = new Request($query);
    return match ($endpoint) {
      'tree' => $this->controller->tree($request),
      'search' => $this->controller->search($request),
      'children' => $this->controller->loadChildren($request),
    };
  }

  /**
   * Sets who makes the request.
   */
  protected function account(bool $authenticated, bool $permission): void {
    $this->account->method('isAuthenticated')->willReturn($authenticated);
    $this->account->method('isAnonymous')->willReturn(!$authenticated);
    $this->account->method('id')->willReturn($authenticated ? 5 : 0);
    $this->account->method('hasPermission')->willReturnCallback(
      fn (string $name): bool => $permission && $name === RestrictedContentPolicy::PERMISSION
    );
  }

  /**
   * Builds an entity query double that answers no entity.
   */
  protected function query(): QueryInterface {
    $counting = FALSE;
    $query = $this->createMock(QueryInterface::class);
    foreach (['accessCheck', 'condition', 'range', 'sort'] as $method) {
      $query->method($method)->willReturnSelf();
    }
    $query->method('count')->willReturnCallback(function () use (&$counting, $query) {
      $counting = TRUE;
      return $query;
    });
    $query->method('execute')->willReturnCallback(fn () => $counting ? 0 : []);
    return $query;
  }

}
