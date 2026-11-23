<?php

declare(strict_types = 1);

namespace Drupal\Tests\eic_search\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ddc_default_content\RestrictedContentPolicy;
use Drupal\eic_search\Controller\SolrSearchController;
use Drupal\eic_search\Exception\InvalidSearchParameterException;
use Drupal\eic_search\Search\Sources\SourceTypeInterface;
use Drupal\eic_search\Service\SolrSearchManager;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\search_api_solr\SearchApiSolrException;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests how the Solr search controller answers a malformed or failing search.
 *
 * A parameter that is not what the search expects gets a 400 and no search;
 * a failure of Solr gets a generic 500, so that no query or field name of the
 * index reaches the caller.
 */
#[Group('eic_search')]
class SolrSearchControllerInputTest extends UnitTestCase {

  /**
   * The mocked search manager.
   */
  protected SolrSearchManager&MockObject $manager;

  /**
   * The mocked logger.
   */
  protected LoggerInterface&MockObject $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAnonymous')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('current_user', $account);
    \Drupal::setContainer($container);

    $this->manager = $this->createMock(SolrSearchManager::class);
    $this->manager->method('init')->willReturnSelf();
    $this->manager->method('getSource')->willReturn($this->createMock(SourceTypeInterface::class));
    $this->logger = $this->createMock(LoggerInterface::class);
  }

  /**
   * Tests that a parameter that must be a number is checked before searching.
   */
  #[DataProvider('notNumbers')]
  public function testNumericParameterMustBeANumber(string $parameter, string $value): void {
    $this->manager->expects($this->never())->method('search');

    $response = $this->search([$parameter => $value]);

    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('invalid_parameter', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * Provides values that are not numbers for the numeric parameters.
   */
  public static function notNumbers(): array {
    return [
      'topics' => ['topics', '1) OR (ss_ddc_access:restricted'],
      'current_group' => ['current_group', '1 OR 1'],
      'userIdFromRoute' => ['userIdFromRoute', '7 OR 1'],
      'page' => ['page', 'x'],
      'offset' => ['offset', '10) OR (1'],
    ];
  }

  /**
   * Tests that facets_value must be a JSON object of value lists.
   */
  #[DataProvider('malformedFacets')]
  public function testMalformedFacetsAreRefused(string $value): void {
    $this->manager->expects($this->never())->method('search');

    $response = $this->search(['facets_value' => $value]);

    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * Provides malformed facets_value parameters.
   */
  public static function malformedFacets(): array {
    return [
      'not JSON' => ['{ss_global_content_type'],
      'a string' => ['"resource"'],
      'a list of strings' => ['["resource"]'],
      'a nested object' => ['{"ss_global_content_type":{"resource":{"x":true}}}'],
    ];
  }

  /**
   * Tests that a parameter refused by the search manager gets a 400.
   */
  public function testRefusedParameterGetsA400(): void {
    $this->manager->method('buildSortFacets')->willThrowException(new InvalidSearchParameterException('facets_value'));
    $this->manager->expects($this->never())->method('search');
    $this->logger->expects($this->never())->method('error');

    $response = $this->search(['facets_value' => '{"ss_user_mail":{"x":true}}']);

    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('invalid_parameter', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * Tests that a pager refused by the search manager gets a 400, not logged.
   *
   * The manager refuses a page below 1 and a request beyond the result window.
   */
  public function testRefusedPagerGetsA400WithoutLogging(): void {
    $this->manager->method('buildQueryPager')->willThrowException(new InvalidSearchParameterException('page'));
    $this->manager->expects($this->never())->method('search');
    $this->logger->expects($this->never())->method('error');

    $response = $this->search(['page' => '0']);

    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('invalid_parameter', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * Tests that a parameter of the wrong shape gets a 400 and no log entry.
   *
   * No search is made either. The parameters are read from the query string,
   * where an anonymous caller can send an array (search_value[]=x) for any of
   * them: that is not an error of the site, so that it must not be logged as
   * one.
   */
  #[DataProvider('malformedShapes')]
  public function testMalformedShapeGetsA400WithoutLogging(string $parameter, string|array $value): void {
    $this->manager->expects($this->never())->method('search');
    $this->logger->expects($this->never())->method('error');

    $response = $this->search([$parameter => $value]);

    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('invalid_parameter', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * Provides every parameter of the search with a value of the wrong shape.
   */
  public static function malformedShapes(): array {
    $cases = [];
    foreach (['source_class', 'search_value', 'sort_value', 'current_group', 'topics', 'userIdFromRoute', 'page', 'offset', 'from_date', 'end_date', 'year_from', 'year_to', 'facets_value'] as $parameter) {
      $cases["{$parameter}[]"] = [$parameter, ['x']];
      $cases["{$parameter}[a]"] = [$parameter, ['a' => 'b']];
    }
    // The value must be a list of facet fields, not a single one.
    $cases['facets_options scalar'] = ['facets_options', 'ss_global_content_type'];
    $cases['facets_options[a][b]'] = ['facets_options', ['a' => ['b']]];
    return $cases;
  }

  /**
   * Tests that a request without a source class is an invalid source.
   *
   * The same answer as for a class that is not a source, not a TypeError.
   */
  public function testMissingSourceClassIsAnInvalidSource(): void {
    $this->manager = $this->createMock(SolrSearchManager::class);
    $this->manager->method('init')->willReturnSelf();
    $this->manager->method('getSource')->willReturn(NULL);
    $this->manager->expects($this->never())->method('search');
    $this->logger->expects($this->never())->method('error');

    $response = $this->search([], FALSE);

    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('invalid_source', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * Tests that a failure of Solr is logged and answered with a generic error.
   */
  public function testSolrFailureIsNotEchoed(): void {
    $this->manager->method('search')->willThrowException(new SearchApiSolrException('Solr HTTP error: undefined field ss_user_mail in q=(tm_global_title:x)'));
    $this->logger->expects($this->once())->method('error');

    $response = $this->search([]);

    $this->assertSame(500, $response->getStatusCode());
    $this->assertSame(['error' => 'search_failed', 'message' => 'The search failed.'], json_decode($response->getContent(), TRUE));
    $this->assertStringNotContainsString('ss_user_mail', $response->getContent());
  }

  /**
   * Tests that the numbers the client sends are accepted.
   */
  public function testClientParametersAreAccepted(): void {
    $this->manager->method('search')->willReturn('{"response":{"numFound":0}}');

    $response = $this->search([
      'page' => '1',
      'offset' => '10',
      'current_group' => '',
      'userIdFromRoute' => '-1',
      'from_date' => '0',
      'end_date' => '0',
      'year_from' => '',
      'year_to' => '',
      'facets_value' => '{"ss_global_content_type":{"news":true,"event":false},"interests":{"my_interests":false}}',
    ]);

    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Runs the controller.
   */
  protected function search(array $parameters, bool $with_source_class = TRUE) {
    $controller = new SolrSearchController($this->manager, new RestrictedContentPolicy($this->createMock(AliasManagerInterface::class)), $this->logger);
    return $controller->search(new Request($with_source_class ? $parameters + ['source_class' => 'any'] : $parameters));
  }

}
