<?php

declare(strict_types = 1);

namespace Drupal\Tests\eic_overviews\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\eic_overviews\Entity\OverviewPage;
use Drupal\eic_overviews\GlobalOverviewPages;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests who may view the overview pages that render restricted data.
 *
 * The Research Institutions explorer, the Resources Library and the global
 * search render documents of the Solr index: institutions, resources, the
 * restricted pages. They are shown to the accounts that hold 'view ddc
 * restricted content' and to nobody else, whatever the 'view overview pages'
 * permission says. The other enabled overviews keep their own permission.
 */
#[Group('eic_overviews')]
#[RunTestsInSeparateProcesses]
class OverviewPageAccessTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The module eic_user_login is here for the user status field, with the
   * modules of the field types of the user and of the overview page.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'options',
    'file',
    'image',
    'node',
    'path',
    'path_alias',
    'eic_user_login',
    'ddc_default_content',
    'eic_overviews',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('overview_page');
    $this->installSchema('user', ['users_data']);
    $this->installSchema('node', ['node_access']);

    FieldStorageConfig::create([
      'field_name' => 'field_overview_id',
      'entity_type' => 'overview_page',
      'type' => 'list_string',
      'settings' => ['allowed_values_function' => '_eic_overviews_allowed_values_id'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_overview_id',
      'entity_type' => 'overview_page',
      'bundle' => 'overview_page',
    ])->save();

    // The account with uid 1 is the super user, so that it is taken here.
    User::create(['uid' => 1, 'name' => 'super user', 'status' => 1])->save();

    // The accounts that are not trusted hold the permission to view overview
    // pages, as the anonymous and authenticated roles of the site do.
    foreach (['anonymous', 'authenticated'] as $role) {
      Role::create([
        'id' => $role,
        'label' => $role,
        'permissions' => ['view overview pages'],
      ])->save();
    }
    Role::create([
      'id' => 'trusted_user',
      'label' => 'Trusted user',
      'permissions' => ['view ddc restricted content'],
    ])->save();
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
  }

  /**
   * Tests that the restricted overviews are shown to trusted accounts only.
   */
  #[DataProvider('restrictedOverviews')]
  public function testRestrictedOverviewIsForTrustedAccountsOnly(string $overview_id, ?string $uuid): void {
    $overview = $this->createOverview($overview_id, $uuid);

    $this->assertFalse($overview->access('view', new AnonymousUserSession()), 'An anonymous visitor does not see it.');
    $this->assertFalse($overview->access('view', $this->createAccount()), 'An account without a role does not see it.');
    $this->assertFalse($overview->access('view', $this->createAccount(['field_user_status' => 'user_pending'])), 'A pending applicant does not see it.');
    $this->assertFalse($overview->access('view', $this->createAccount(['field_user_status' => 'user_rejected'])), 'A rejected applicant does not see it.');
    $this->assertTrue($overview->access('view', $this->createAccount(['roles' => ['trusted_user'], 'field_user_status' => 'user_valid'])), 'A trusted account sees it.');
    $this->assertTrue($overview->access('view', $this->createAccount(['roles' => ['administrator']])), 'The administrator sees it.');
  }

  /**
   * Provides the overviews that render restricted data.
   *
   * The research institutions and the resources are known by the UUID and the
   * overview id that the module installs them with, the global search by its
   * overview id (its UUID differs per site).
   */
  public static function restrictedOverviews(): array {
    return [
      'research institutions' => [(string) GlobalOverviewPages::RESEARCH_INSTITUTIONS, GlobalOverviewPages::RESEARCH_INSTITUTIONS_UUID],
      'resources library' => [(string) GlobalOverviewPages::RESOURCES, GlobalOverviewPages::RESOURCES_UUID],
      'global search' => [(string) GlobalOverviewPages::GLOBAL_SEARCH, NULL],
    ];
  }

  /**
   * Tests that a restricted overview is found by its overview id too.
   *
   * A page that was re-created with another UUID is still the same overview.
   */
  #[DataProvider('restrictedOverviews')]
  public function testRestrictedOverviewIsKnownByItsOverviewId(string $overview_id): void {
    $overview = $this->createOverview($overview_id, NULL);

    $this->assertFalse($overview->access('view', $this->createAccount()));
  }

  /**
   * Tests that a restricted overview is found by its UUID alone.
   */
  public function testRestrictedOverviewIsKnownByItsUuid(): void {
    $overview = $this->createOverview((string) GlobalOverviewPages::NEWS_STORIES, GlobalOverviewPages::RESOURCES_UUID);

    $this->assertFalse($overview->access('view', $this->createAccount()));
    $this->assertFalse($overview->access('view', new AnonymousUserSession()));
  }

  /**
   * Tests that an overview that renders nothing restricted is not touched.
   */
  public function testOtherOverviewKeepsItsPermission(): void {
    $overview = $this->createOverview((string) GlobalOverviewPages::NEWS_STORIES, NULL);

    $this->assertTrue($overview->access('view', new AnonymousUserSession()));
    $this->assertTrue($overview->access('view', $this->createAccount()));
    $this->assertTrue($overview->access('view', $this->createAccount(['roles' => ['trusted_user']])));
  }

  /**
   * Tests that the view permission is still required for the other overviews.
   */
  public function testOtherOverviewStillNeedsTheViewPermission(): void {
    Role::load('anonymous')->revokePermission('view overview pages')->save();
    $overview = $this->createOverview((string) GlobalOverviewPages::NEWS_STORIES, NULL);

    $this->assertFalse($overview->access('view', new AnonymousUserSession()));
    $this->assertTrue($overview->access('view', $this->createAccount()));
  }

  /**
   * Tests that a disabled overview is forbidden to everybody.
   */
  #[DataProvider('restrictedOverviews')]
  public function testDisabledOverviewIsForbiddenToEverybody(string $overview_id, ?string $uuid): void {
    $overview = $this->createOverview($overview_id, $uuid, FALSE);

    $this->assertFalse($overview->access('view', new AnonymousUserSession()));
    $this->assertFalse($overview->access('view', $this->createAccount(['roles' => ['trusted_user']])));
    $this->assertFalse($overview->access('view', $this->createAccount(['roles' => ['administrator']])));
  }

  /**
   * Tests that the decision varies by permissions and by anonymous.
   *
   * The result of a restricted overview is cached for the accounts with the
   * same permissions, and a trusted anonymous visitor does not exist.
   */
  public function testRestrictedDecisionVariesByPermissions(): void {
    $overview = $this->createOverview((string) GlobalOverviewPages::RESOURCES, GlobalOverviewPages::RESOURCES_UUID);

    foreach ([new AnonymousUserSession(), $this->createAccount(), $this->createAccount(['roles' => ['trusted_user']])] as $account) {
      $result = $overview->access('view', $account, TRUE);
      $this->assertContains('user.permissions', $result->getCacheContexts());
      $this->assertContains('user.roles:anonymous', $result->getCacheContexts());
    }
  }

  /**
   * Creates an overview page.
   */
  protected function createOverview(string $overview_id, ?string $uuid, bool $status = TRUE): OverviewPage {
    $values = [
      'title' => 'Overview ' . $overview_id,
      'status' => $status,
      'field_overview_id' => $overview_id,
    ];
    if ($uuid !== NULL) {
      $values['uuid'] = $uuid;
    }
    $overview = OverviewPage::create($values);
    $overview->save();
    return $overview;
  }

  /**
   * Creates an authenticated account.
   */
  protected function createAccount(array $values = []): AccountInterface {
    static $count = 0;
    $count++;
    $account = User::create($values + ['name' => 'account ' . $count, 'status' => 1]);
    $account->save();
    return $account;
  }

}
