<?php

declare(strict_types = 1);

namespace Drupal\Tests\eic_search\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\eic_search\Search\Sources\SourceType;
use Drupal\eic_search\Search\Sources\SourceTypeInterface;
use Drupal\eic_search\Service\SolrSearchManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the registration filter that a search request can carry.
 *
 * The client sends the special facet key 'filter_registration' with an
 * 'open_registration' flag. The facet values are validated as a list of
 * scalars only, so a request can carry the key without the flag: that must be
 * read as "not selected", and not raise a warning on every such request.
 *
 * buildSortFacets() removes the key from the facets (cleanFacets()) before the
 * controller calls buildDateQuery(), so the facets that buildDateQuery() reads
 * hold the key only when a test puts it there. These tests do that to reach
 * the code; they do not say that the filter works through a request.
 */
#[Group('eic_search')]
class SolrSearchManagerRegistrationFilterTest extends UnitTestCase {

  use SolrSearchManagerTestTrait;

  /**
   * The solr field of the start of the registration.
   */
  const REGISTRATION_START = 'its_event_registration_date_start';

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
   * Provides values of the key that do not select the registration filter.
   */
  public static function valuesWithoutOpenRegistration(): array {
    return [
      'a key that is not the flag' => [['x' => 1]],
      'no keys' => [[]],
      'flag not selected' => [['open_registration' => FALSE]],
    ];
  }

  /**
   * Tests that a key without the flag adds no filter and raises nothing.
   */
  #[DataProvider('valuesWithoutOpenRegistration')]
  public function testRegistrationFilterWithoutSelectedFlagIsIgnored(array $value): void {
    $manager = $this->managerWithRegistrationSource();
    $this->setFacets($manager, ['filter_registration' => $value]);

    $warnings = $this->collectWarnings(fn () => $manager->buildDateQuery());
    $manager->search();

    $this->assertSame([], $warnings, 'No PHP warning is raised.');
    $this->assertStringNotContainsString(self::REGISTRATION_START, $this->sentQ());
  }

  /**
   * Tests that a request as the client sends it raises no warning.
   */
  #[DataProvider('valuesWithoutOpenRegistration')]
  public function testRequestWithoutSelectedFlagRaisesNoWarning(array $value): void {
    $manager = $this->managerWithRegistrationSource();

    $warnings = $this->collectWarnings(function () use ($manager, $value) {
      // The order of SolrSearchController.
      $manager->buildSortFacets(['filter_registration' => $value], NULL);
      $manager->buildDateQuery();
    });
    $manager->search();

    $this->assertSame([], $warnings, 'No PHP warning is raised.');
    $this->assertStringNotContainsString(self::REGISTRATION_START, $this->sentQ());
  }

  /**
   * Tests that the selected flag still limits the search to open registration.
   */
  public function testSelectedOpenRegistrationFiltersOnRegistrationDates(): void {
    $manager = $this->managerWithRegistrationSource();
    $this->setFacets($manager, ['filter_registration' => ['open_registration' => TRUE]]);

    $manager->buildDateQuery();
    $manager->search();

    $this->assertStringContainsString(self::REGISTRATION_START . ':[* TO ', $this->sentQ());
  }

  /**
   * Builds a manager over a source that has a registration interval.
   */
  protected function managerWithRegistrationSource(): SolrSearchManager {
    // The date filter is declared by the base class, not by the interface.
    $source = $this->createMock(SourceType::class);
    $source->method('getSourcesId')->willReturn(['node']);
    $source->method('getSearchFieldsId')->willReturn(['tm_global_title']);
    $source->method('getAvailableFacets')->willReturn(['ss_global_content_type' => 'Content type']);
    $source->method('ignorePublishedState')->willReturn(TRUE);
    $source->method('supportDateFilter')->willReturn(FALSE);
    $source->method('getRegistrationDateIntervalField')->willReturn([
      'from' => self::REGISTRATION_START,
      'to' => 'its_event_registration_date_end',
    ]);

    $manager = $this->manager($this->account(TRUE, TRUE), TRUE, $source);
    $manager->init(SourceTypeInterface::class);
    return $manager;
  }

  /**
   * Sets the facets that buildDateQuery() reads.
   */
  protected function setFacets(SolrSearchManager $manager, array $facets): void {
    $property = new \ReflectionProperty(SolrSearchManager::class, 'facets');
    $property->setValue($manager, $facets);
  }

  /**
   * Runs a callback and returns the PHP warnings and notices it raised.
   */
  protected function collectWarnings(callable $callback): array {
    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
      $warnings[] = $message;
      return TRUE;
    });
    try {
      $callback();
    }
    finally {
      restore_error_handler();
    }
    return $warnings;
  }

  /**
   * Returns the q that the manager built.
   */
  protected function sentQ(): string {
    $q = (array) $this->sentParams()['q'];
    return reset($q);
  }

}
