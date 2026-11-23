<?php

declare(strict_types = 1);

namespace Drupal\Tests\eic_groups\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxy;
use Drupal\eic_groups\Access\HighlightGroupContentAccessCheck;
use Drupal\eic_groups\Access\LikeGroupContentAccessCheck;
use Drupal\flag\FlagInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\Entity\GroupTypeInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests who may like, or highlight, the content of a group.
 *
 * The routes act on a group and a node of the URL. The access of the flag is
 * not enough: the account has to be able to view the group and the node, and
 * the node has to be content of that group, so that the endpoints do not tell
 * anything about, and do not act on, a restricted node or a research
 * institution, nor a node of another group.
 */
#[Group('eic_groups')]
class LikeGroupContentAccessCheckTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $contexts_manager = $this->createMock(CacheContextsManager::class);
    $contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $contexts_manager);
    \Drupal::setContainer($container);
  }

  /**
   * Tests that the like is allowed to who may view the group and the node.
   */
  public function testLikeIsAllowedWhenEverythingIsAllowed(): void {
    $result = $this->like($this->group(), $this->node(), $this->flagAccess(TRUE));

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Tests that each missing condition denies the like.
   */
  #[DataProvider('deniedConditions')]
  public function testLikeIsDeniedWhenOneConditionFails(bool $group_view, bool $node_view, bool $related, bool $flag): void {
    $result = $this->like($this->group($group_view, $related), $this->node($node_view), $this->flagAccess($flag));

    $this->assertFalse($result->isAllowed());
  }

  /**
   * Provides the combinations where one condition fails.
   */
  public static function deniedConditions(): array {
    return [
      'the group may not be viewed' => [FALSE, TRUE, TRUE, TRUE],
      'the node may not be viewed' => [TRUE, FALSE, TRUE, TRUE],
      'the node is not content of the group' => [TRUE, TRUE, FALSE, TRUE],
      'the flag is not allowed' => [TRUE, TRUE, TRUE, FALSE],
      'nothing is allowed' => [FALSE, FALSE, FALSE, FALSE],
    ];
  }

  /**
   * Tests that a missing group or node is denied.
   */
  public function testMissingGroupOrNodeIsDenied(): void {
    $check = new LikeGroupContentAccessCheck($this->flagService($this->flagAccess(TRUE)));

    $this->assertFalse($check->access($this->proxy(), NULL, $this->node())->isAllowed());
    $this->assertFalse($check->access($this->proxy(), $this->group(), NULL)->isAllowed());
  }

  /**
   * Tests that the result depends on the group and the node.
   */
  public function testResultCarriesTheCacheabilityOfTheGroupAndTheNode(): void {
    $result = $this->like($this->group(), $this->node(), $this->flagAccess(TRUE));

    $this->assertContains('group:7', $result->getCacheTags());
    $this->assertContains('node:144', $result->getCacheTags());
  }

  /**
   * Tests that the highlight is denied when the group or the node is not viewable.
   */
  #[DataProvider('deniedViews')]
  public function testHighlightIsDeniedWhenTheGroupOrTheNodeMayNotBeViewed(bool $group_view, bool $node_view): void {
    $check = new HighlightGroupContentAccessCheck();
    $group = $this->group($group_view, TRUE, TRUE);

    $result = $check->access($this->proxy(), $group, $this->node($node_view));

    $this->assertFalse($result->isAllowed());
  }

  /**
   * Provides the views that are denied.
   */
  public static function deniedViews(): array {
    return [
      'group' => [FALSE, TRUE],
      'node' => [TRUE, FALSE],
      'both' => [FALSE, FALSE],
    ];
  }

  /**
   * Tests that the highlight is allowed when everything is.
   */
  public function testHighlightIsAllowedWhenEverythingIsAllowed(): void {
    $check = new HighlightGroupContentAccessCheck();

    $result = $check->access($this->proxy(), $this->group(TRUE, TRUE, TRUE), $this->node());

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Tests that the like routes check the access and take a known action only.
   */
  public function testRoutesRunTheCheckAndConstrainTheAction(): void {
    $routes = Yaml::parseFile(DRUPAL_ROOT . '/modules/custom/eic_groups/eic_groups.routing.yml');

    $like = $routes['eic_groups.group.like_content']['requirements'];
    $this->assertSame('TRUE', $like['_like_group_content']);
    $this->assertSame('flag|unflag', $like['action']);
    $this->assertSame('TRUE', $routes['eic_groups.group.like_content_status']['requirements']['_like_group_content']);
    $this->assertSame('flag|unflag', $routes['eic_groups.discussion_flag_comment']['requirements']['type']);
  }

  /**
   * Mocks the account proxy of the request.
   */
  protected function proxy(): AccountProxy {
    $proxy = $this->createMock(AccountProxy::class);
    $proxy->method('getAccount')->willReturn($this->createMock(AccountInterface::class));
    return $proxy;
  }

  /**
   * Runs the like access check.
   */
  protected function like(GroupInterface $group, NodeInterface $node, FlagInterface $flag) {
    $check = new LikeGroupContentAccessCheck($this->flagService($flag));
    return $check->access($this->proxy(), $group, $node);
  }

  /**
   * Mocks the flag service.
   */
  protected function flagService(FlagInterface $flag): FlagServiceInterface {
    $service = $this->createMock(FlagServiceInterface::class);
    $service->method('getFlagById')->with('like_content')->willReturn($flag);
    return $service;
  }

  /**
   * Mocks the like flag, with the access it gives.
   */
  protected function flagAccess(bool $allowed): FlagInterface {
    $flag = $this->createMock(FlagInterface::class);
    $flag->method('actionAccess')->willReturn($allowed ? AccessResult::allowed() : AccessResult::neutral());
    return $flag;
  }

  /**
   * Mocks a group.
   */
  protected function group(bool $viewable = TRUE, bool $related = TRUE, bool $permission = FALSE): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(7);
    $group->method('access')->willReturnCallback(
      fn (string $operation, $account = NULL, bool $as_object = FALSE) => $as_object
        ? ($operation === 'view' && $viewable ? AccessResult::allowed() : AccessResult::forbidden())->addCacheTags(['group:7'])
        : $operation === 'view' && $viewable
    );
    $group->method('getCacheTags')->willReturn(['group:7']);
    $group->method('getCacheContexts')->willReturn([]);
    $group->method('getCacheMaxAge')->willReturn(-1);
    $group->method('getRelationshipsByEntity')->willReturn($related ? [$this->createMock(GroupRelationshipInterface::class)] : []);
    $group->method('hasPermission')->willReturn(TRUE);
    $type = $this->createMock(GroupTypeInterface::class);
    $type->method('hasPlugin')->willReturn(TRUE);
    $group->method('getGroupType')->willReturn($type);
    return $group;
  }

  /**
   * Mocks a node.
   */
  protected function node(bool $viewable = TRUE): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(144);
    $node->method('access')->willReturnCallback(
      fn (string $operation, $account = NULL, bool $as_object = FALSE) => $as_object
        ? ($operation === 'view' && $viewable ? AccessResult::allowed() : AccessResult::forbidden())->addCacheTags(['node:144'])
        : $operation === 'view' && $viewable
    );
    $node->method('getCacheTags')->willReturn(['node:144']);
    $node->method('getCacheContexts')->willReturn([]);
    $node->method('getCacheMaxAge')->willReturn(-1);
    return $node;
  }

}
