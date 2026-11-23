<?php

declare(strict_types = 1);

namespace Drupal\Tests\eic_search\Unit;

use Drupal\eic_search\Search\Sources\SourceTypeInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\GroupMembership;
use Drupal\search_api\SearchApiException;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Solarium\QueryType\Select\RequestBuilder;

/**
 * Tests that every Solr search of an untrusted account is limited to public items.
 *
 * The search API of the site builds raw Solarium queries, so that no Search
 * API processor runs on its own: SolrSearchManager::search() applies the
 * restricted-access filter itself, for every source, and refuses to search for
 * an untrusted account when the index lacks the processor.
 */
#[Group('eic_search')]
class SolrSearchManagerRestrictedContentTest extends UnitTestCase {

  use SolrSearchManagerTestTrait;

  /**
   * Tests that the request of an untrusted account carries the separate filter.
   */
  #[DataProvider('untrustedAccounts')]
  public function testUntrustedSearchCarriesTheFilterQuery(bool $authenticated): void {
    $manager = $this->manager($this->account($authenticated, FALSE), TRUE);
    $manager->init(SourceTypeInterface::class);
    // Facets are concatenated into the fq that the manager builds; whatever
    // they carry cannot reach the separate filter query.
    $manager->buildSortFacets([
      'ss_global_content_type' => ['resource" OR (*:*' => TRUE],
    ], NULL);
    $manager->buildSearchQuery('resource');

    $manager->search();

    $fq = (new RequestBuilder())->build($this->sentQuery)->getParam('fq');
    $this->assertIsArray($fq, 'The request has more than one fq.');
    $this->assertCount(2, $fq);
    $this->assertStringContainsString('ss_global_content_type:"resource\\" OR (*:*"', $fq[0], 'The first fq is the one the search built.');
    $this->assertSame('+ss_ddc_access:public', $fq[1], 'The second fq is the restriction, untouched by the facets.');
  }

  /**
   * Tests that the search of an untrusted account asks for no spellcheck.
   *
   * Solr draws the suggestions from the whole index, restricted titles too.
   */
  #[DataProvider('untrustedAccounts')]
  public function testUntrustedSearchAsksForNoSpellcheck(bool $authenticated): void {
    $manager = $this->manager($this->account($authenticated, FALSE), TRUE);
    $manager->init(SourceTypeInterface::class);
    $manager->buildSearchQuery('Demo Resourse 24');

    $manager->search();

    $request = (new RequestBuilder())->build($this->sentQuery);
    $this->assertSame('false', $request->getParam('spellcheck'));
    $this->assertNull($request->getParam('spellcheck.q'));
  }

  /**
   * Tests that the search of a trusted account keeps the spellcheck.
   */
  public function testTrustedSearchKeepsTheSpellcheck(): void {
    $manager = $this->manager($this->account(TRUE, TRUE), TRUE);
    $manager->init(SourceTypeInterface::class);
    $manager->buildSearchQuery('Demo Resourse 24');

    $manager->search();

    $request = (new RequestBuilder())->build($this->sentQuery);
    $this->assertSame('true', $request->getParam('spellcheck'));
    $this->assertSame('Demo Resourse 24', $request->getParam('spellcheck.q'));
    $this->assertSame(['en'], $request->getParam('spellcheck.dictionary'));
  }

  /**
   * Provides the untrusted accounts.
   */
  public static function untrustedAccounts(): array {
    return [
      'anonymous' => [FALSE],
      'role-less' => [TRUE],
    ];
  }

  /**
   * Tests that the search of a trusted account carries no filter.
   */
  public function testTrustedSearchCarriesNoFilterQuery(): void {
    $manager = $this->manager($this->account(TRUE, TRUE), TRUE);
    $manager->init(SourceTypeInterface::class);

    $manager->search();

    $fq = (new RequestBuilder())->build($this->sentQuery)->getParam('fq');
    $this->assertIsString($fq, 'The request has only the fq the search built.');
    $this->assertStringNotContainsString('ss_ddc_access', $fq);
  }

  /**
   * Tests that an untrusted search is refused when the index lacks the processor.
   */
  #[DataProvider('untrustedAccounts')]
  public function testUntrustedSearchIsRefusedWithoutTheProcessor(bool $authenticated): void {
    $manager = $this->manager($this->account($authenticated, FALSE), FALSE);
    $manager->init(SourceTypeInterface::class);

    try {
      $manager->search();
      $this->fail('The search was not refused.');
    }
    catch (SearchApiException $e) {
      $this->assertNull($this->sentQuery, 'Nothing was sent to Solr.');
    }
  }

  /**
   * Tests that a trusted search still runs when the index lacks the processor.
   */
  public function testTrustedSearchRunsWithoutTheProcessor(): void {
    $manager = $this->manager($this->account(TRUE, TRUE), FALSE);
    $manager->init(SourceTypeInterface::class);

    $this->assertSame('{"response":{"numFound":0,"docs":[]}}', $manager->search());
    $this->assertNotNull($this->sentQuery);
  }

  /**
   * Tests that the admin of the group searched still gets the filter.
   *
   * The group access filter is skipped for the owner or an admin of the group
   * named in current_group; the restricted-access filter never is.
   */
  public function testGroupAdminSearchCarriesTheFilterQuery(): void {
    $account = $this->account(TRUE, FALSE);
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(7);
    $membership = $this->createMock(GroupMembership::class);
    $membership->method('getUser')->willReturn($user);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(12);
    $group->method('bundle')->willReturn('organisation');
    $group->method('getMembers')->willReturn([$membership]);
    $this->containerWithGroup($group);

    $manager = $this->manager($account, TRUE);
    $manager->init(SourceTypeInterface::class);
    $manager->buildGroupQuery('12');
    $manager->search();

    $this->assertContains('+ss_ddc_access:public', (array) $this->sentParams()['fq']);
  }

  /**
   * Tests that a system search is not restricted, whoever triggers it.
   *
   * Index-time statistics (ProcessorGroup) count every item of a group; the
   * value must not depend on the account that happens to trigger indexing.
   */
  public function testSystemSearchIsNotRestricted(): void {
    $manager = $this->manager($this->account(FALSE, FALSE), TRUE);
    $manager->init(SourceTypeInterface::class);

    $manager->search(TRUE);

    $this->assertStringNotContainsString('ss_ddc_access', json_encode($this->sentParams()['fq']));
  }

}
