<?php

declare(strict_types = 1);

namespace Drupal\Tests\eic_search\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\eic_search\Exception\InvalidSearchParameterException;
use Drupal\eic_search\Search\Sources\GlobalSourceType;
use Drupal\eic_search\Search\Sources\SourceTypeInterface;
use Drupal\eic_search\Service\SolrSearchManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Drupal\search_api\SearchApiException;
use Solarium\Core\Client\Response;
use Solarium\Core\Query\Helper;

/**
 * Tests that no text of a search request reaches Solr as query syntax.
 *
 * The search builds its q and fq by concatenation, so that a value that
 * carried Solr syntax (local params, sub-queries, joins, a closing quote or
 * parenthesis) could test restricted documents even through the separate
 * restricted-access filter. Text is escaped as a phrase; field names and sort
 * fields are taken only from what the source declares; ids, dates and years
 * must be numbers. Anything else is refused before Solr is called. This holds
 * for every account, trusted or not.
 */
#[Group('eic_search')]
class SolrSearchManagerInputTest extends UnitTestCase {

  use SolrSearchManagerTestTrait;

  /**
   * Payloads that would carry Solr syntax.
   */
  public static function payloads(): array {
    return [
      'join' => ['{!join from=id to=id}ss_user_mail:a*'],
      'sub-query' => ['_query_:"{!lucene}ss_ddc_access:restricted"'],
      'closing parenthesis' => ['x) OR (ss_ddc_access:restricted'],
      'closing quote' => ['x" OR ss_ddc_access:"restricted'],
      'escaped quote' => ['x\\" OR *:* OR \\"y'],
      'match all' => ['*:*'],
      'restricted field' => ['ss_ddc_access:restricted'],
      'comment' => ['x /* y */ z'],
      // The oracle of the Task 10 review: a join over the whole index.
      'review join breakout' => ['zz" OR {!join from=index_id to=index_id score=none v="ss_user_mail:a*"} OR ss_search_api_datasource:"zz'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Tests that the search text is escaped as a phrase.
   */
  #[DataProvider('payloads')]
  public function testSearchTextIsEscaped(string $payload): void {
    $manager = $this->globalManager();
    $manager->buildSearchQuery($payload);
    $manager->search();

    $escaped = (new Helper())->escapePhrase($payload);
    $fields = (new GlobalSourceType())->getSearchFieldsId();
    $expected = '(' . implode(' OR ', array_map(fn ($field) => "$field:$escaped", $fields)) . ')';
    $q = $this->sentQ();
    $this->assertSame($expected, $q);
    $this->assertSame(2 * count($fields), $this->countUnescapedQuotes($q), 'Every phrase is closed by its own quote only.');
  }

  /**
   * Tests that an empty search still matches everything in the search fields.
   */
  public function testEmptySearchTextMatchesEverything(): void {
    $manager = $this->globalManager();
    $manager->buildSearchQuery('');
    $manager->search();

    $this->assertStringStartsWith('(tm_X3b_en_rendered_item:* OR ', $this->sentQ());
  }

  /**
   * Tests that a facet value is escaped as a phrase.
   */
  #[DataProvider('payloads')]
  public function testFacetValueIsEscaped(string $payload): void {
    $fq = $this->fqWithLanguage($payload);

    $this->assertStringContainsString(' AND ss_content_language_string:' . (new Helper())->escapePhrase($payload) . ' ', $fq);
    $this->assertSame($this->countUnescapedQuotes($this->fqWithLanguage('x')), $this->countUnescapedQuotes($fq), 'The value adds no quote of its own.');
  }

  /**
   * Tests that a facet key the source does not declare is refused.
   */
  #[DataProvider('undeclaredFields')]
  public function testUndeclaredFacetKeyIsRefused(string $key): void {
    $manager = $this->globalManager();

    $this->expectException(InvalidSearchParameterException::class);
    try {
      $manager->buildSortFacets([$key => ['resource' => TRUE]], NULL);
    }
    finally {
      $this->assertNull($this->sentQuery, 'Nothing was sent to Solr.');
    }
  }

  /**
   * Provides field names that the global source does not declare.
   */
  public static function undeclaredFields(): array {
    return [
      'local params' => ['{!join from=id to=id}ss_user_mail'],
      'closing parenthesis' => ['ss_global_content_type:x) OR (ss_ddc_access'],
      'sub-query' => ['_query_'],
      'restricted user field' => ['ss_user_mail'],
      'marker field' => ['ss_ddc_access'],
      'comment' => ['ss_global_content_type /* x */'],
      // The oracles of the Task 10 review.
      'review join ANDed with a facet' => ['{!join from=index_id to=index_id score=none v="ss_user_mail:a*"} AND ss_global_content_type'],
      'review join in an OR clause' => ['ss_search_api_datasource:zz OR {!join from=index_id to=index_id score=none v="ss_user_mail:a*"} OR ss_search_api_datasource'],
    ];
  }

  /**
   * Tests that a facet value that is not a list of values is refused.
   */
  public function testMalformedFacetValueIsRefused(): void {
    $this->expectException(InvalidSearchParameterException::class);
    $this->globalManager()->buildSortFacets(['ss_global_content_type' => 'resource'], NULL);
  }

  /**
   * Tests that the declared facet keys and the special keys are accepted.
   */
  public function testDeclaredFacetKeysAreAccepted(): void {
    $manager = $this->globalManager();
    $manager->buildSortFacets([
      'ss_global_content_type' => ['news' => TRUE, 'event' => FALSE],
      'sm_content_field_vocab_topics_string' => ['Energy' => TRUE],
      // The keys of the filter links of the site (SearchHelper).
      'itm_aggregated_field_vocab_topics' => ['12' => TRUE],
      'interests' => ['my_interests' => FALSE],
      'filter_registration' => ['open_registration' => FALSE],
    ], NULL);
    $manager->search();

    $fq = $this->sentParams()['fq'];
    $fq = is_array($fq) ? $fq[0] : $fq;
    $this->assertStringContainsString(' AND ss_global_content_type:"news"', $fq);
    $this->assertStringContainsString(' AND sm_content_field_vocab_topics_string:"Energy"', $fq);
    $this->assertStringContainsString(' AND itm_aggregated_field_vocab_topics:"12"', $fq);
  }

  /**
   * Tests that a facet field the source does not declare is refused.
   */
  #[DataProvider('undeclaredFields')]
  public function testUndeclaredFacetFieldIsRefused(string $field): void {
    $manager = $this->globalManager();

    $this->expectException(InvalidSearchParameterException::class);
    $manager->buildFacets(['ss_global_content_type', $field]);
  }

  /**
   * Tests that the declared facet fields are requested.
   */
  public function testDeclaredFacetFieldsAreRequested(): void {
    $manager = $this->globalManager();
    $manager->buildFacets(['ss_global_content_type', 'ss_content_language_string']);
    $manager->search();

    $this->assertSame(['ss_global_content_type', 'ss_content_language_string'], array_values((array) $this->sentParams()['facet.field']));
  }

  /**
   * Tests that a sort the source does not declare is refused.
   */
  #[DataProvider('undeclaredSorts')]
  public function testUndeclaredSortIsRefused(string $sort): void {
    $manager = $this->globalManager();

    $this->expectException(InvalidSearchParameterException::class);
    $manager->buildSortFacets([], $sort);
  }

  /**
   * Provides sorts that the global source does not declare.
   */
  public static function undeclaredSorts(): array {
    return [
      'restricted field' => ['ss_user_mail__ASC'],
      'marker field' => ['ss_ddc_access__DESC'],
      'function query' => ["query({!lucene v='ss_user_mail:a*'})__ASC"],
      'second sort smuggled in the direction' => ['ss_global_title__ASC,ss_user_mail asc'],
      'unknown direction' => ['ss_global_title__SIDEWAYS'],
      'no direction' => ['ss_global_title'],
      'review function query' => ['query({!join from=index_id to=index_id score=none v="ss_user_mail:a*"},0)__desc'],
    ];
  }

  /**
   * Tests that the declared sorts, the default sort and no sort are accepted.
   */
  #[DataProvider('declaredSorts')]
  public function testDeclaredSortIsAccepted(string $sort, ?string $expected): void {
    $manager = $this->globalManager();
    $manager->buildSortFacets([], $sort);
    $manager->search();

    $this->assertSame($expected, $this->sentParams()['sort'] ?? NULL);
  }

  /**
   * Provides the sorts that the client sends for the global source.
   */
  public static function declaredSorts(): array {
    return [
      'declared' => ['ss_global_title__ASC', 'ss_global_title ASC'],
      'default' => ['timestamp__DESC', 'timestamp DESC'],
      'any' => ['any', NULL],
      'none' => ['', 'timestamp DESC'],
    ];
  }

  /**
   * Tests that a group id must be a number.
   */
  #[DataProvider('notNumbers')]
  public function testGroupIdMustBeANumber(string $value): void {
    $this->expectException(InvalidSearchParameterException::class);
    $this->globalManager()->buildGroupQuery($value);
  }

  /**
   * Tests that dates must be timestamps.
   */
  #[DataProvider('notNumbers')]
  public function testDatesMustBeTimestamps(string $value): void {
    $manager = $this->globalManager();
    $manager->buildSortFacets([], NULL);

    $this->expectException(InvalidSearchParameterException::class);
    $manager->buildDateQuery($value, '*');
  }

  /**
   * Tests that years must be numbers.
   */
  #[DataProvider('notNumbers')]
  public function testYearsMustBeNumbers(string $value): void {
    $this->expectException(InvalidSearchParameterException::class);
    $this->globalManager()->buildRangeQuery('*', $value);
  }

  /**
   * Provides values that are not numbers.
   */
  public static function notNumbers(): array {
    return [
      'closing parenthesis' => ['1) OR (ss_ddc_access:restricted'],
      'boolean' => ['1 OR 1'],
      'word' => ['abc'],
      'negative' => ['-1'],
      'range' => ['[* TO *]'],
    ];
  }

  /**
   * Tests that numbers are accepted.
   */
  public function testNumbersAreAccepted(): void {
    $manager = $this->globalManager();
    $manager->buildGroupQuery('12');
    $manager->buildSortFacets([], NULL);
    $manager->buildDateQuery('1700000000', '*');
    $manager->buildDateQuery('0', '0');
    $manager->buildRangeQuery('2019', '*');
    $manager->buildRangeQuery('', '');

    $this->addToAssertionCount(1);
  }

  /**
   * Tests that an error answered by Solr is thrown, not returned as a result.
   *
   * The error names the query and the fields of the index; the controller
   * logs it and answers with a generic error.
   */
  #[DataProvider('solrErrors')]
  public function testSolrErrorIsThrown(array $headers): void {
    $this->solrResponse = new Response('{"error":{"msg":"undefined field ss_user_mail","code":400}}', $headers);
    $manager = $this->globalManager();

    $this->expectException(SearchApiException::class);
    $manager->search();
  }

  /**
   * Provides the status lines of a Solr error.
   */
  public static function solrErrors(): array {
    return [
      'HTTP error' => [['HTTP/1.1 400 Bad Request']],
      'error in a 200 response' => [['HTTP/1.1 200 OK']],
    ];
  }

  /**
   * Tests that the pager gives the rows and the start of a page.
   */
  public function testPagerOfAPaginatedSource(): void {
    $manager = $this->globalManager();
    $manager->buildQueryPager(3, 10);
    $manager->search();

    $this->assertSame(10, $this->sentQuery->getRows());
    $this->assertSame(20, $this->sentQuery->getStart());
  }

  /**
   * Tests that the pager of a "load more" source loads everything up to a page.
   */
  public function testPagerOfALoadMoreSource(): void {
    $manager = $this->loadMoreManager();
    $manager->buildQueryPager(3, 20);
    $manager->search();

    $this->assertSame(60, $this->sentQuery->getRows());
    $this->assertSame(0, $this->sentQuery->getStart());
  }

  /**
   * Tests that a page that is not a positive number is refused.
   *
   * The client counts the pages from 1; a page of 0 would give a negative
   * start.
   */
  #[DataProvider('invalidPages')]
  public function testInvalidPageIsRefused(int $page, bool $paginated): void {
    $manager = $paginated ? $this->globalManager() : $this->loadMoreManager();

    $this->expectException(InvalidSearchParameterException::class);
    $this->expectExceptionMessage('page');
    try {
      $manager->buildQueryPager($page, 10);
    }
    finally {
      $this->assertNull($this->sentQuery, 'Nothing was sent to Solr.');
    }
  }

  /**
   * Provides pages below 1, for both kinds of source.
   */
  public static function invalidPages(): array {
    return [
      'zero, paginated' => [0, TRUE],
      'zero, load more' => [0, FALSE],
      'negative, paginated' => [-1, TRUE],
      'negative, load more' => [-5, FALSE],
    ];
  }

  /**
   * Tests that a request beyond the result window is refused.
   *
   * Both kinds of source reach the position page * offset: a page ends there,
   * a "load more" list is that long. The products that overflow an integer are
   * refused as well.
   */
  #[DataProvider('oversizedWindows')]
  public function testOversizedWindowIsRefused(int $page, int $offset, string $parameter, bool $paginated): void {
    $manager = $paginated ? $this->globalManager() : $this->loadMoreManager();

    $this->expectException(InvalidSearchParameterException::class);
    $this->expectExceptionMessage($parameter);
    try {
      $manager->buildQueryPager($page, $offset);
    }
    finally {
      $this->assertNull($this->sentQuery, 'Nothing was sent to Solr.');
    }
  }

  /**
   * Provides requests beyond the window, for both kinds of source.
   */
  public static function oversizedWindows(): array {
    $max = SolrSearchManager::MAX_RESULT_WINDOW;

    $cases = [];
    foreach ([TRUE, FALSE] as $paginated) {
      $kind = $paginated ? 'paginated' : 'load more';
      $cases["offset over the cap, $kind"] = [1, $max + 1, 'offset', $paginated];
      $cases["offset of 100000, $kind"] = [1, 100000, 'offset', $paginated];
      $cases["offset of PHP_INT_MAX, $kind"] = [1, PHP_INT_MAX, 'offset', $paginated];
      $cases["negative offset, $kind"] = [1, -1, 'offset', $paginated];
      $cases["page over the cap, $kind"] = [$max + 1, 1, 'page', $paginated];
      $cases["page times offset over the cap, $kind"] = [3, intdiv($max, 3) + 1, 'page', $paginated];
      $cases["page of PHP_INT_MAX, $kind"] = [PHP_INT_MAX, 20, 'page', $paginated];
      $cases["both of PHP_INT_MAX, $kind"] = [PHP_INT_MAX, PHP_INT_MAX, 'offset', $paginated];
    }
    return $cases;
  }

  /**
   * Tests that what the client sends, and the cap itself, are accepted.
   *
   * The client sends 6 to 100 results per page (the page size selector) or a
   * "load more" batch of 5, 10 or 20; the page is what the user reached.
   */
  #[DataProvider('windowsInsideTheCap')]
  public function testWindowInsideTheCapIsAccepted(int $page, int $offset, bool $paginated): void {
    $manager = $paginated ? $this->globalManager() : $this->loadMoreManager();
    $manager->buildQueryPager($page, $offset);
    $manager->search();

    $this->assertSame($paginated ? $offset : $page * $offset, $this->sentQuery->getRows());
    $this->assertSame($paginated ? ($page - 1) * $offset : 0, $this->sentQuery->getStart());
  }

  /**
   * Provides requests inside the window, for both kinds of source.
   */
  public static function windowsInsideTheCap(): array {
    $max = SolrSearchManager::MAX_RESULT_WINDOW;

    $cases = [];
    foreach ([TRUE, FALSE] as $paginated) {
      $kind = $paginated ? 'paginated' : 'load more';
      $cases["first page of 10, $kind"] = [1, 10, $paginated];
      $cases["biggest page size of the selector, $kind"] = [1, 100, $paginated];
      $cases["tenth page of 100, $kind"] = [10, 100, $paginated];
      $cases["default load more batch, $kind"] = [4, 5, $paginated];
      $cases["resources load more batch, $kind"] = [25, 20, $paginated];
      $cases["last position of the window by page, $kind"] = [$max, 1, $paginated];
      $cases["last position of the window by offset, $kind"] = [1, $max, $paginated];
      $cases["last position of the window, $kind"] = [50, intdiv($max, 50), $paginated];
    }
    return $cases;
  }

  /**
   * Builds a manager for a source that loads more results instead of paging.
   */
  protected function loadMoreManager(): SolrSearchManager {
    $source = new class extends GlobalSourceType {

      /**
       * {@inheritdoc}
       */
      public function allowPagination(): bool {
        return FALSE;
      }

    };
    $manager = $this->manager($this->account(TRUE, TRUE), TRUE, $source);
    $manager->init(SourceTypeInterface::class);
    return $manager;
  }

  /**
   * Builds a manager for the global source, for a trusted account.
   *
   * The checks are validation, not authorisation: they hold for a trusted
   * account too.
   */
  protected function globalManager(): SolrSearchManager {
    $manager = $this->manager($this->account(TRUE, TRUE), TRUE, new GlobalSourceType());
    $manager->init(SourceTypeInterface::class);
    return $manager;
  }

  /**
   * Returns the fq built for a language facet value.
   */
  protected function fqWithLanguage(string $value): string {
    $this->sentQuery = NULL;
    $manager = $this->globalManager();
    $manager->buildSortFacets(['ss_content_language_string' => [$value => TRUE]], NULL);
    $manager->search();

    $fq = $this->sentParams()['fq'];
    return is_array($fq) ? $fq[0] : $fq;
  }

  /**
   * Returns the q that the manager built.
   *
   * Solarium also sends the default q of the select query ('*:*') after the
   * one the manager adds as a parameter; Solr uses the first.
   */
  protected function sentQ(): string {
    $q = (array) $this->sentParams()['q'];
    $this->assertSame('*:*', end($q));
    return reset($q);
  }

  /**
   * Counts the double quotes that are not escaped by a backslash.
   */
  protected function countUnescapedQuotes(string $text): int {
    return preg_match_all('/(?<!\\\\)(?:\\\\\\\\)*"/', $text);
  }

}
