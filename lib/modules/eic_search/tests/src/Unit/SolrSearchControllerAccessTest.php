<?php

declare(strict_types = 1);

namespace Drupal\Tests\eic_search\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ddc_default_content\RestrictedContentPolicy;
use Drupal\eic_search\Controller\SolrSearchController;
use Drupal\eic_search\Search\Sources\GlobalSourceType;
use Drupal\eic_search\Search\Sources\ResearchInstitutionSourceType;
use Drupal\eic_search\Search\Sources\ResourceSourceType;
use Drupal\eic_search\Search\Sources\SourceTypeInterface;
use Drupal\eic_search\Search\Sources\UserGallerySourceType;
use Drupal\eic_search\Search\Sources\UserInvitesListSourceType;
use Drupal\eic_search\Search\Sources\UserListSourceType;
use Drupal\eic_search\Search\Sources\UserRecommendSourceType;
use Drupal\eic_search\Search\Sources\UserTaggingCommentsSourceType;
use Drupal\eic_search\Service\SolrSearchManager;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests which accounts the Solr search controller serves restricted sources to.
 *
 * The Resources Library, the research institutions and the member directory
 * are served to trusted accounts only; the other sources are served to every
 * account and filtered per item by the restricted-access filter.
 */
#[Group('eic_search')]
class SolrSearchControllerAccessTest extends UnitTestCase {

  /**
   * The sources that serve restricted items only.
   */
  const RESTRICTED_SOURCES = [
    ResourceSourceType::class,
    ResearchInstitutionSourceType::class,
    UserListSourceType::class,
    UserGallerySourceType::class,
    UserInvitesListSourceType::class,
    UserTaggingCommentsSourceType::class,
    UserRecommendSourceType::class,
  ];

  /**
   * Tests that the restricted sources say so and the global source does not.
   */
  public function testSourcesThatRequireRestrictedAccess(): void {
    foreach (self::RESTRICTED_SOURCES as $class) {
      $this->assertTrue((new $class())->requiresRestrictedAccess(), "$class requires restricted access.");
    }
    $this->assertFalse((new GlobalSourceType())->requiresRestrictedAccess(), 'The global source is filtered per item.');
  }

  /**
   * Tests that anonymous gets a 403 asking to sign in.
   */
  public function testAnonymousIsAskedToSignIn(): void {
    $response = $this->search($this->account(FALSE, FALSE), TRUE);

    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('authentication_required', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * Tests that a signed-in account without a trusted role gets a 403.
   */
  public function testRoleLessAccountIsRefused(): void {
    $response = $this->search($this->account(TRUE, FALSE), TRUE);

    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('restricted_access_required', json_decode($response->getContent(), TRUE)['error']);
  }

  /**
   * Tests that a trusted account is served.
   */
  public function testTrustedAccountIsServed(): void {
    $response = $this->search($this->account(TRUE, TRUE), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('{"response":{"numFound":0}}', $response->getContent());
  }

  /**
   * Tests that a source that does not require it is served to anybody.
   */
  #[DataProvider('accounts')]
  public function testOtherSourceIsServed(bool $authenticated, bool $permission): void {
    $response = $this->search($this->account($authenticated, $permission), FALSE);

    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Provides anonymous, role-less and trusted accounts.
   */
  public static function accounts(): array {
    return [
      'anonymous' => [FALSE, FALSE],
      'role-less' => [TRUE, FALSE],
      'trusted' => [TRUE, TRUE],
    ];
  }

  /**
   * Runs the controller for a source that does or does not require access.
   */
  protected function search(AccountProxyInterface $account, bool $restricted_source) {
    $source = $this->createMock(SourceTypeInterface::class);
    $source->method('requiresRestrictedAccess')->willReturn($restricted_source);
    $manager = $this->createMock(SolrSearchManager::class);
    $manager->method('init')->willReturnSelf();
    $manager->method('getSource')->willReturn($source);
    $manager->method('search')->willReturn('{"response":{"numFound":0}}');

    $container = new ContainerBuilder();
    $container->set('current_user', $account);
    \Drupal::setContainer($container);
    $controller = new SolrSearchController($manager, new RestrictedContentPolicy($this->createMock(AliasManagerInterface::class)), $this->createMock(LoggerInterface::class));

    return $controller->search(new Request(['source_class' => 'any', 'facets_value' => '{}']));
  }

  /**
   * Mocks the current user.
   */
  protected function account(bool $authenticated, bool $permission): AccountProxyInterface {
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAuthenticated')->willReturn($authenticated);
    $account->method('isAnonymous')->willReturn(!$authenticated);
    $account->method('hasPermission')->willReturnCallback(fn (string $name) => $permission && $name === RestrictedContentPolicy::PERMISSION);
    return $account;
  }

}
