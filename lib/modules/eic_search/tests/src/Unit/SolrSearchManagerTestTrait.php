<?php

declare(strict_types = 1);

namespace Drupal\Tests\eic_search\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ddc_default_content\Plugin\search_api\processor\RestrictedAccess;
use Drupal\ddc_default_content\RestrictedContentPolicy;
use Drupal\eic_search\Collector\SourcesCollector;
use Drupal\eic_search\Search\Sources\SourceTypeInterface;
use Drupal\eic_search\Service\SolrSearchManager;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\ServerInterface;
use Drupal\search_api_solr\SolrBackendInterface;
use Drupal\search_api_solr\SolrConnectorInterface;
use Solarium\Core\Client\Response;
use Solarium\QueryType\Select\Query\Query;
use Solarium\QueryType\Select\RequestBuilder;

/**
 * Builds a SolrSearchManager over a mocked index and Solr connector.
 *
 * The source is registered under the key SourceTypeInterface::class, so that
 * a test calls init(SourceTypeInterface::class).
 */
trait SolrSearchManagerTestTrait {

  /**
   * The Solarium query that reached the connector, if any.
   */
  protected ?Query $sentQuery = NULL;

  /**
   * The response that the connector gives, if not the default empty result.
   */
  protected ?Response $solrResponse = NULL;

  /**
   * Builds the manager.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $account
   *   The current user.
   * @param bool $with_processor
   *   Whether the index has the restricted-access processor.
   * @param \Drupal\eic_search\Search\Sources\SourceTypeInterface|null $source
   *   The source, or NULL for a mocked one that declares nothing.
   */
  protected function manager(AccountProxyInterface $account, bool $with_processor, ?SourceTypeInterface $source = NULL): SolrSearchManager {
    $policy = new RestrictedContentPolicy($this->createMock(AliasManagerInterface::class));

    $connector = $this->createMock(SolrConnectorInterface::class);
    $connector->method('getSelectQuery')->willReturnCallback(fn () => new Query());
    $connector->method('search')->willReturnCallback(function (Query $query) {
      $this->sentQuery = $query;
      return $this->solrResponse ?? new Response('{"response":{"numFound":0,"docs":[]}}', ['HTTP/1.1 200 OK']);
    });
    $backend = $this->createMock(SolrBackendInterface::class);
    $backend->method('getConfiguration')->willReturn([]);
    $backend->method('getSolrConnector')->willReturn($connector);
    $server = $this->createMock(ServerInterface::class);
    $server->method('getBackend')->willReturn($backend);

    $processor = new RestrictedAccess([], 'ddc_restricted_access', []);
    $processor->setRestrictedContentPolicy($policy);
    $processor->setCurrentUser($account);

    $index = $this->createMock(Index::class);
    $index->method('getServerInstance')->willReturn($server);
    $index->method('isValidProcessor')->willReturnCallback(fn (string $id) => $with_processor && $id === 'ddc_restricted_access');
    $index->method('getProcessor')->willReturnCallback(function (string $id) use ($with_processor, $processor) {
      if ($with_processor && $id === 'ddc_restricted_access') {
        return $processor;
      }
      throw new SearchApiException("No processor $id.");
    });
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with('global')->willReturn($index);
    $entity_type_manager = $this->createMock(EntityTypeManager::class);
    $entity_type_manager->method('getStorage')->with('search_api_index')->willReturn($storage);

    if (!$source) {
      $source = $this->createMock(SourceTypeInterface::class);
      $source->method('getSourcesId')->willReturn(['group', 'node']);
      $source->method('getSearchFieldsId')->willReturn(['tm_global_title']);
      $source->method('getAvailableFacets')->willReturn(['ss_global_content_type' => 'Content type']);
      $source->method('ignorePublishedState')->willReturn(TRUE);
    }
    $sources = $this->createMock(SourcesCollector::class);
    $sources->method('getSources')->willReturn([SourceTypeInterface::class => $source]);

    $membership_loader = $this->createMock(GroupMembershipLoaderInterface::class);
    $membership_loader->method('loadByUser')->willReturn([]);

    return new SolrSearchManager(
      $account,
      $sources,
      $entity_type_manager,
      $membership_loader,
      $policy,
    );
  }

  /**
   * Mocks the current user.
   */
  protected function account(bool $authenticated, bool $permission): AccountProxyInterface {
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAuthenticated')->willReturn($authenticated);
    $account->method('isAnonymous')->willReturn(!$authenticated);
    $account->method('id')->willReturn($authenticated ? 7 : 0);
    $account->method('getRoles')->willReturn($authenticated ? ['authenticated'] : ['anonymous']);
    $account->method('hasPermission')->willReturnCallback(fn (string $name) => $permission && $name === RestrictedContentPolicy::PERMISSION);
    return $account;
  }

  /**
   * Puts a group in the container, for Group::load().
   */
  protected function containerWithGroup(GroupInterface $group): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturnCallback(fn ($id) => (string) $id === (string) $group->id() ? $group : NULL);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('group')->willReturn($storage);
    $repository = $this->createMock(EntityTypeRepositoryInterface::class);
    $repository->method('getEntityTypeFromClass')->willReturn('group');

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entity_type_manager);
    $container->set('entity_type.repository', $repository);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Returns the parameters of the request that reached Solr.
   */
  protected function sentParams(): array {
    $this->assertNotNull($this->sentQuery, 'A query was sent to Solr.');
    return (new RequestBuilder())->build($this->sentQuery)->getParams();
  }

}
