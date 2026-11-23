<?php

declare(strict_types = 1);

namespace Drupal\Tests\eic_search\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ddc_default_content\RestrictedContentPolicy;
use Drupal\eic_groups\EICGroupsHelper;
use Drupal\eic_search\Plugin\Block\ResourcesSearchOverviewBlock;
use Drupal\eic_search\Plugin\Block\RinsSearchOverviewBlock;
use Drupal\eic_search\Plugin\Block\SearchOverviewBlock;
use Drupal\eic_search\Search\Sources\GlobalSourceType;
use Drupal\eic_search\Search\Sources\ResearchInstitutionSourceType;
use Drupal\eic_search\Search\Sources\ResourceSourceType;
use Drupal\eic_search\Search\Sources\UserGallerySourceType;
use Drupal\eic_search\Collector\SourcesCollector;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests who the blocks that render restricted search overviews are shown to.
 *
 * The overview page and the route in front of a block decide first, and the
 * block decides on its own as well, so that it renders nothing for an account
 * that is not trusted wherever it is placed: the Resources Library and the
 * Institutions Explorer blocks, and the generic search block when the source
 * it is configured with serves restricted data, such as the member gallery.
 */
#[Group('eic_search')]
class OverviewBlockAccessTest extends UnitTestCase {

  /**
   * The policy under test.
   */
  protected RestrictedContentPolicy $policy;

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

    $this->policy = new RestrictedContentPolicy($this->createMock(AliasManagerInterface::class));
  }

  /**
   * Tests that the restricted blocks are shown to trusted accounts only.
   */
  #[DataProvider('restrictedBlocks')]
  public function testRestrictedBlockIsForTrustedAccountsOnly(string $block): void {
    foreach ([[FALSE, FALSE], [FALSE, TRUE], [TRUE, FALSE]] as [$authenticated, $permission]) {
      $result = $this->{$block}()->access($this->account($authenticated, $permission), TRUE);

      $this->assertTrue($result->isForbidden(), "Not shown to authenticated=$authenticated permission=$permission.");
      $this->assertContains('user.permissions', $result->getCacheContexts());
      $this->assertContains('user.roles:anonymous', $result->getCacheContexts());
    }

    $result = $this->{$block}()->access($this->account(TRUE, TRUE), TRUE);
    $this->assertTrue($result->isAllowed(), 'Shown to a trusted account.');
    $this->assertContains('user.permissions', $result->getCacheContexts());
  }

  /**
   * Provides the blocks that are restricted whatever their configuration is.
   */
  public static function restrictedBlocks(): array {
    return [
      'resources library' => ['resourcesBlock'],
      'institutions explorer' => ['rinsBlock'],
      'generic block with the resource source' => ['genericBlockWithResources'],
      'generic block with the member gallery' => ['genericBlockWithUsers'],
    ];
  }

  /**
   * Tests that the generic block with an open source is left alone.
   *
   * The sources of the groups and of the events serve what the account may
   * see, which the Solr filter and the group access decide.
   */
  public function testGenericBlockWithOpenSourceIsLeftAlone(): void {
    $result = $this->genericBlock(GlobalSourceType::class)->access($this->account(FALSE, FALSE), TRUE);

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Builds the Resources Library block.
   */
  protected function resourcesBlock(): ResourcesSearchOverviewBlock {
    return new ResourcesSearchOverviewBlock(
      [],
      'resources_search_overview',
      ['provider' => 'eic_search'],
      new ResourceSourceType(),
      $this->createMock(RequestStack::class),
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(TimeInterface::class),
      $this->policy,
    );
  }

  /**
   * Builds the Institutions Explorer block.
   */
  protected function rinsBlock(): RinsSearchOverviewBlock {
    return new RinsSearchOverviewBlock(
      [],
      'rins_search_overview',
      ['provider' => 'eic_search'],
      new ResearchInstitutionSourceType(),
      $this->createMock(RequestStack::class),
      $this->createMock(AccountProxyInterface::class),
      $this->policy,
    );
  }

  /**
   * Builds the generic block with the resource source.
   */
  protected function genericBlockWithResources(): SearchOverviewBlock {
    return $this->genericBlock(ResourceSourceType::class);
  }

  /**
   * Builds the generic block with the member gallery source.
   */
  protected function genericBlockWithUsers(): SearchOverviewBlock {
    return $this->genericBlock(UserGallerySourceType::class);
  }

  /**
   * Builds the generic block configured with a source.
   */
  protected function genericBlock(string $source_class): SearchOverviewBlock {
    $collector = $this->createMock(SourcesCollector::class);
    $collector->method('getSources')->willReturn([$source_class => new $source_class()]);

    return new SearchOverviewBlock(
      ['source_type' => $source_class],
      'eic_search_overview',
      ['provider' => 'eic_search'],
      $collector,
      $this->createMock(EICGroupsHelper::class),
      $this->createMock(RequestStack::class),
      $this->createMock(RouteMatchInterface::class),
      $this->policy,
    );
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
