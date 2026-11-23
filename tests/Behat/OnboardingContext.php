<?php

declare(strict_types = 1);

namespace OpenEuropa\Site\Tests\Behat;

use Behat\Gherkin\Node\TableNode;
use Behat\Hook\AfterScenario;
use Behat\Hook\AfterSuite;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Drupal\DrupalExtension\Context\RawDrupalContext;
use Drupal\ddc_membership\Constants\RequestStatus;
use Drupal\ddc_membership\Entity\MembershipRequest;
use Drupal\ddc_membership\MembershipRequestInterface;
use Drupal\profile\Entity\Profile;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Step definitions for the onboarding process (ddc_membership).
 *
 * Accounts, applications and member profiles are made through Drupal's entity
 * API, the way the site makes them, so that saving an application runs the real
 * synchronisation of the account (status, role, block). Whatever a scenario
 * makes is deleted after it.
 *
 * Signing in with "I sign in as" uses the one-time login link of the account:
 * the login form is protected by Antibot, which needs JavaScript to submit.
 * Scenarios tagged @eulogin sign in through EU Login itself: the EU Login mock
 * (cas_mock_server and oe_authentication_eulogin_mock) is enabled for them, and
 * "I sign in with EU Login as" walks the real path: the log in address, the
 * mock EU Login form, and the ticket that CAS validates on the way back.
 */
class OnboardingContext extends RawDrupalContext {

  /**
   * The roles a step may name, by the name used in the scenarios.
   */
  const ROLES = [
    'RTD admin' => 'rtd_admin',
    'country reviewer' => 'country_reviewer',
    'site admin' => 'site_admin',
    'trusted user' => 'trusted_user',
  ];

  /**
   * The accounts made by the scenario, by name.
   *
   * @var int[]
   */
  protected array $accounts = [];

  /**
   * The applications made by the scenario, by ID.
   *
   * @var int[]
   */
  protected array $requests = [];

  /**
   * The EU Login accounts that the scenario put in the mock, by username.
   *
   * @var array<string, array>
   */
  protected array $euLoginAccounts = [];

  /**
   * The password of every EU Login account of the mock.
   */
  const EU_LOGIN_PASSWORD = 'Eddsp-test-1!';

  /**
   * The modules of the mock, and whether this run enabled them.
   */
  const EU_LOGIN_MOCK_MODULES = ['cas_mock_server', 'oe_authentication_eulogin_mock'];

  /**
   * TRUE if this run enabled the mock modules, which it then removes again.
   */
  protected static bool $enabledMockModules = FALSE;

  /**
   * TRUE if this scenario switched the mock server on (and so switches it off).
   */
  protected bool $startedMockServer = FALSE;

  /**
   * Makes sure that Drupal is bootstrapped for the steps that use its API.
   */
  protected function bootstrapDrupal(): void {
    $this->getDriver();
  }

  /**
   * Turns a name into an account name: "Aurelia Albertini" is aurelia_albertini.
   */
  protected function accountName(string $name): string {
    return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
  }

  /**
   * Creates an account that the scenario deletes afterwards.
   *
   * @param string $name
   *   The name of the person, or the account name.
   * @param string[] $roles
   *   The role IDs.
   * @param string|null $user_status
   *   The value of field_user_status, or NULL to leave it empty.
   */
  protected function createAccount(string $name, array $roles = [], ?string $user_status = NULL): UserInterface {
    $this->bootstrapDrupal();
    $account_name = $this->accountName($name);
    $values = [
      'name' => $account_name,
      'mail' => $account_name . '@example.com',
      'pass' => bin2hex(random_bytes(8)),
      'status' => 1,
      'roles' => $roles,
    ];
    if ($user_status !== NULL) {
      $values['field_user_status'] = $user_status;
    }
    $account = User::create($values);
    $account->save();
    $this->accounts[$name] = (int) $account->id();
    return $account;
  }

  /**
   * Loads an account made by the scenario, fresh from the database.
   */
  protected function getAccount(string $name): UserInterface {
    if (!isset($this->accounts[$name])) {
      // An account that the site made, such as the one CAS registers at the
      // first sign-in with EU Login. It is deleted with the others.
      $found = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $this->accountName($name)]);
      if (!$found) {
        throw new \RuntimeException(sprintf('There is no account "%s".', $name));
      }
      $this->accounts[$name] = (int) reset($found)->id();
    }
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    // This process and the web server do not share a cache.
    $storage->resetCache([$this->accounts[$name]]);
    return $storage->load($this->accounts[$name]);
  }

  /**
   * Loads the application of an account made by the scenario, fresh.
   */
  protected function getRequest(string $name): MembershipRequestInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('membership_request');
    $storage->resetCache();
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('uid', $this->getAccount($name)->id())->execute();
    if (!$ids) {
      throw new \RuntimeException(sprintf('"%s" has no onboarding application.', $name));
    }
    return $storage->load((int) reset($ids));
  }

  /**
   * Gets the code of a country from its name: "Italy" is IT.
   */
  protected function countryCode(string $country): string {
    $list = \Drupal::service('country_manager')->getList();
    foreach ($list as $code => $label) {
      if ((string) $label === $country) {
        return (string) $code;
      }
    }
    throw new \InvalidArgumentException(sprintf('"%s" is not a country.', $country));
  }

  /**
   * Gets the term that an application names as the type of the organisation.
   */
  protected function organisationTypeId(): int {
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'organisation_types']);
    if (!$terms) {
      throw new \RuntimeException('The site has no organisation types.');
    }
    return (int) reset($terms)->id();
  }

  /**
   * Gives an account a member profile with a country.
   */
  protected function giveCountry(UserInterface $account, string $country): void {
    Profile::create([
      'type' => 'member',
      'uid' => $account->id(),
      'status' => TRUE,
      'is_default' => TRUE,
      'field_location_address' => [['country_code' => $this->countryCode($country)]],
    ])->save();
  }

  /**
   * Makes an account that has not made an application.
   */
  #[Given('an applicant account :name who has not applied')]
  public function anApplicantWhoHasNotApplied(string $name): void {
    $this->createAccount($name, [], 'user_draft');
  }

  /**
   * Makes applicants and their applications.
   *
   * Columns: name, country, status (pending, approved or rejected), and for a
   * processed application, optionally "reasoning" and "resolved by". Each
   * applicant is an account without roles that starts as a draft; saving the
   * application does what the site does for the status.
   */
  #[Given('the following onboarding applications:')]
  public function theFollowingOnboardingApplications(TableNode $table): void {
    $this->bootstrapDrupal();
    foreach ($table->getHash() as $row) {
      $name = $row['name'];
      $account = $this->createAccount($name, [], 'user_draft');
      [$first, $last] = array_pad(explode(' ', $name, 2), 2, 'Applicant');
      $values = [
        'uid' => $account->id(),
        'first_name' => $first,
        'last_name' => $last,
        'organisation_name' => $row['organisation'] ?? 'Acme Research',
        'organisation_type' => $this->organisationTypeId(),
        'organisation_website' => ['uri' => 'https://example.com'],
        'organisation_role' => 'Analyst',
        'country' => $this->countryCode($row['country']),
        'privacy_consent' => TRUE,
        'request_status' => $row['status'],
      ];
      if ($row['status'] !== RequestStatus::PENDING) {
        $values['resolved_at'] = strtotime($row['resolved'] ?? 'now');
        $values['resolved_by_name'] = $row['resolved by'] ?? 'Salvatore Monti';
        if (($row['reasoning'] ?? '') !== '') {
          $values['resolution_reason'] = $row['reasoning'];
        }
      }
      $request = MembershipRequest::create($values);
      $request->save();
      $this->requests[] = (int) $request->id();
    }
  }

  /**
   * Makes a reviewer, with the country of their member profile if one is given.
   */
  #[Given('a :role account :name')]
  public function aRoleAccount(string $role, string $name): void {
    $this->createAccount($name, [$this->roleId($role)]);
  }

  /**
   * Makes a reviewer whose member profile has a country.
   */
  #[Given('a :role account :name for :country')]
  public function aRoleAccountForCountry(string $role, string $name, string $country): void {
    $account = $this->createAccount($name, [$this->roleId($role)]);
    $this->giveCountry($account, $country);
  }

  /**
   * Makes an applicant a reviewer too, with the country of their member profile.
   *
   * It is the account of a reviewer that has an application of their own, such
   * as a TVO who applied before they were given the role.
   */
  #[Given('the applicant :name is a :role for :country')]
  public function theApplicantIsAReviewerFor(string $name, string $role, string $country): void {
    $account = $this->getAccount($name);
    $account->addRole($this->roleId($role));
    $account->save();
    $this->giveCountry($account, $country);
  }

  /**
   * Gets the ID of a role from the name that the scenarios use.
   */
  protected function roleId(string $role): string {
    if (!isset(self::ROLES[$role])) {
      throw new \InvalidArgumentException(sprintf('"%s" is not a role of the scenarios (%s).', $role, implode(', ', array_keys(self::ROLES))));
    }
    return self::ROLES[$role];
  }

  /**
   * Signs in as an account that the scenario made, through its login link.
   */
  #[Given('I sign in as :name')]
  public function iSignInAs(string $name): void {
    $account = $this->getAccount($name);
    $session = $this->getSession();
    $session->reset();
    $path = parse_url(user_pass_reset_url($account), PHP_URL_PATH);
    $session->visit($this->locatePath($path . '/login'));
  }

  /**
   * Decides an application behind the back of the page or the session that is open.
   *
   * It is what happens when another reviewer decides first: the page of a
   * reviewer who has the application open is then stale, and the session of an
   * applicant sees the effect on the next click.
   */
  #[Given('the application of :name is :status by someone else')]
  public function theApplicationIsDecidedBySomeoneElse(string $name, string $status): void {
    if (!in_array($status, [RequestStatus::APPROVED, RequestStatus::REJECTED], TRUE)) {
      throw new \InvalidArgumentException(sprintf('"%s" is not a decision.', $status));
    }
    $request = $this->getRequest($name);
    $request->set('request_status', $status);
    $request->set('resolved_at', time());
    $request->set('resolved_by_name', 'Someone Else');
    $request->set('resolution_reason', 'Decided first.');
    $request->save();
  }

  /**
   * Visits the review page of the application of an applicant.
   */
  #[Given('I am on the review page of :name')]
  #[When('I go to the review page of :name')]
  public function iGoToTheReviewPageOf(string $name): void {
    $this->visitPath('/onboarding-applications/' . $this->getRequest($name)->id() . '/review');
  }

  /**
   * Visits the page of the application of an applicant.
   */
  #[When('I go to the application page of :name')]
  public function iGoToTheApplicationPageOf(string $name): void {
    $this->visitPath('/onboarding-applications/' . $this->getRequest($name)->id());
  }

  /**
   * Visits the delete page of the application of an applicant.
   */
  #[When('I go to the delete page of the application of :name')]
  public function iGoToTheDeletePageOf(string $name): void {
    $this->visitPath('/onboarding-applications/' . $this->getRequest($name)->id() . '/delete');
  }

  /**
   * Posts the review form as if a button had been pressed.
   *
   * Hiding a button is not the check: the decision service refuses a decision
   * that the reviewer may not make, whatever the page showed. The form is
   * taken from the current page, with its hidden values, and sent with the
   * name of the button as a visitor could craft it.
   */
  #[When('I post the review form as if I had pressed :button')]
  public function iPostTheReviewFormAsIfIHadPressed(string $button): void {
    $page = $this->getSession()->getPage();
    $form = $page->find('xpath', "//form[.//input[@name='form_id' and @value='ddc_membership_review']]");
    if (!$form) {
      throw new \RuntimeException('The current page has no review form.');
    }
    $params = ['op' => $button, 'reasoning' => ''];
    foreach ($form->findAll('css', 'input[type=hidden]') as $input) {
      $params[$input->getAttribute('name')] = $input->getValue();
    }
    $client = $this->getSession()->getDriver()->getClient();
    $client->request('POST', $this->locatePath($form->getAttribute('action') ?: $this->getSession()->getCurrentUrl()), $params);
  }

  /**
   * Checks the status of the application of an applicant.
   */
  #[Then('the onboarding application of :name should be :status')]
  public function theApplicationShouldBe(string $name, string $status): void {
    $actual = $this->getRequest($name)->getStatus();
    if ($actual !== $status) {
      throw new \RuntimeException(sprintf('The application of "%s" is "%s", not "%s".', $name, $actual, $status));
    }
  }

  /**
   * Checks that an applicant has no application.
   */
  #[Then('the onboarding application of :name should not exist')]
  public function theApplicationShouldNotExist(string $name): void {
    $storage = \Drupal::entityTypeManager()->getStorage('membership_request');
    $storage->resetCache();
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('uid', $this->getAccount($name)->id())->execute();
    if ($ids) {
      throw new \RuntimeException(sprintf('"%s" has an onboarding application.', $name));
    }
  }

  /**
   * Checks the website stored on the application of an applicant.
   */
  #[Then('the onboarding application of :name should have the website :uri')]
  public function theApplicationShouldHaveTheWebsite(string $name, string $uri): void {
    $actual = (string) $this->getRequest($name)->get('organisation_website')->uri;
    if ($actual !== $uri) {
      throw new \RuntimeException(sprintf('The website of "%s" is "%s", not "%s".', $name, $actual, $uri));
    }
  }

  /**
   * Checks the reasoning logged on the application of an applicant.
   */
  #[Then('the onboarding application of :name should have the reasoning :reasoning')]
  public function theApplicationShouldHaveTheReasoning(string $name, string $reasoning): void {
    $actual = trim((string) $this->getRequest($name)->get('resolution_reason')->value);
    if ($actual !== $reasoning) {
      throw new \RuntimeException(sprintf('The reasoning of "%s" is "%s", not "%s".', $name, $actual, $reasoning));
    }
  }

  /**
   * Checks that an application was resolved by an account.
   */
  #[Then('the onboarding application of :name should be resolved by :reviewer')]
  public function theApplicationShouldBeResolvedBy(string $name, string $reviewer): void {
    $request = $this->getRequest($name);
    $expected = $this->getAccount($reviewer);
    if ((int) $request->get('resolved_by')->target_id !== (int) $expected->id()) {
      throw new \RuntimeException(sprintf('The application of "%s" was not resolved by "%s".', $name, $reviewer));
    }
    if ((string) $request->get('resolved_by_name')->value !== $expected->getDisplayName()) {
      throw new \RuntimeException(sprintf('The application of "%s" does not carry the name of "%s".', $name, $reviewer));
    }
  }

  /**
   * Checks that an account is active.
   */
  #[Then('the account of :name should be active')]
  public function theAccountShouldBeActive(string $name): void {
    if (!$this->getAccount($name)->isActive()) {
      throw new \RuntimeException(sprintf('The account of "%s" is blocked.', $name));
    }
  }

  /**
   * Checks that an account is blocked.
   */
  #[Then('the account of :name should be blocked')]
  public function theAccountShouldBeBlocked(string $name): void {
    if (!$this->getAccount($name)->isBlocked()) {
      throw new \RuntimeException(sprintf('The account of "%s" is active.', $name));
    }
  }

  /**
   * Checks the roles of an account.
   */
  #[Then('the account of :name should have the role :role')]
  public function theAccountShouldHaveTheRole(string $name, string $role): void {
    if (!$this->getAccount($name)->hasRole($this->roleId($role))) {
      throw new \RuntimeException(sprintf('The account of "%s" does not have the role "%s".', $name, $role));
    }
  }

  /**
   * Checks that an account has not a role.
   */
  #[Then('the account of :name should not have the role :role')]
  public function theAccountShouldNotHaveTheRole(string $name, string $role): void {
    if ($this->getAccount($name)->hasRole($this->roleId($role))) {
      throw new \RuntimeException(sprintf('The account of "%s" has the role "%s".', $name, $role));
    }
  }

  /**
   * Checks the user status that the portal keeps for an account.
   */
  #[Then('the user status of :name should be :status')]
  public function theUserStatusShouldBe(string $name, string $status): void {
    $actual = (string) $this->getAccount($name)->get('field_user_status')->value;
    if ($actual !== $status) {
      throw new \RuntimeException(sprintf('The user status of "%s" is "%s", not "%s".', $name, $actual, $status));
    }
  }

  /**
   * Checks that a field holds a text, as a part of its value.
   *
   * Mink's own step compares the whole value.
   */
  #[Then('the :field field should include :text')]
  public function theFieldShouldInclude(string $field, string $text): void {
    $element = $this->getSession()->getPage()->findField($field);
    if (!$element) {
      throw new \RuntimeException(sprintf('The field "%s" is not on the page.', $field));
    }
    if (!str_contains((string) $element->getValue(), $text)) {
      throw new \RuntimeException(sprintf('The field "%s" does not include "%s".', $field, $text));
    }
  }

  /**
   * Checks the labels of the exposed filters of the page, in order.
   */
  #[Then('the filters should be, in this order:')]
  public function theFiltersShouldBeInThisOrder(TableNode $table): void {
    $labels = [];
    foreach ($this->getSession()->getPage()->findAll('css', '.views-exposed-form label.ecl-form-label') as $label) {
      $labels[] = trim(preg_replace('/\s+/', ' ', $label->getText()));
    }
    $expected = array_column($table->getRows(), 0);
    if ($labels !== $expected) {
      throw new \RuntimeException(sprintf('The filters are "%s", not "%s".', implode('", "', $labels), implode('", "', $expected)));
    }
  }

  /**
   * Turns the EU Login mock on for the scenario.
   *
   * The modules are enabled once, for the first scenario that needs them, and
   * removed after the suite. The mock server itself is a flag that is on for
   * the scenario only.
   */
  #[BeforeScenario('@eulogin')]
  public function startEuLoginMock(): void {
    $this->bootstrapDrupal();
    $installer = \Drupal::service('module_installer');
    $handler = \Drupal::service('module_handler');
    $missing = array_filter(self::EU_LOGIN_MOCK_MODULES, fn (string $module) => !$handler->moduleExists($module));
    if ($missing) {
      $installer->install($missing);
      self::$enabledMockModules = TRUE;
    }
    // A server that somebody started by hand is theirs: it is left running.
    $server = \Drupal::service('cas_mock_server.server_manager');
    if (!$server->isServerActive()) {
      $server->start();
      $this->startedMockServer = TRUE;
    }
  }

  /**
   * Turns the EU Login mock server off.
   */
  #[AfterScenario('@eulogin')]
  public function stopEuLoginMock(): void {
    if ($this->startedMockServer && \Drupal::hasService('cas_mock_server.server_manager')) {
      \Drupal::service('cas_mock_server.server_manager')->stop();
    }
    $this->startedMockServer = FALSE;
  }

  /**
   * Removes the mock modules again, if this run enabled them.
   */
  #[AfterSuite]
  public static function removeEuLoginMock(): void {
    if (!self::$enabledMockModules || !class_exists(\Drupal::class)) {
      return;
    }
    \Drupal::service('module_installer')->uninstall(self::EU_LOGIN_MOCK_MODULES);
    self::$enabledMockModules = FALSE;
  }

  /**
   * Puts accounts in the EU Login mock.
   *
   * Columns: username (the EU Login ID), email, first name, last name, and
   * optionally "local account": the name of an account made by the scenario,
   * which is then the account of that EU Login ID, as it is once the person has
   * signed in with EU Login. Without it the ID is one that the site has not
   * seen, and CAS registers a new account at the first sign-in.
   */
  #[Given('the following EU Login accounts:')]
  public function theFollowingEuLoginAccounts(TableNode $table): void {
    $this->bootstrapDrupal();
    $users = [];
    foreach ($table->getHash() as $row) {
      $username = $row['username'];
      $users[$username] = [
        'username' => $username,
        'email' => $row['email'],
        'password' => self::EU_LOGIN_PASSWORD,
        'firstName' => $row['first name'] ?? '',
        'lastName' => $row['last name'] ?? '',
      ];
      $this->euLoginAccounts[$username] = $row;
      // A link that an interrupted run left behind would make the link below
      // fail with a duplicate key.
      \Drupal::database()->delete('authmap')->condition('provider', 'cas')->condition('authname', $username)->execute();
      if (($row['local account'] ?? '') !== '') {
        \Drupal::service('externalauth.externalauth')->linkExistingAccount($username, 'cas', $this->getAccount($row['local account']));
      }
    }
    \Drupal::service('cas_mock_server.user_manager')->addUsers($users);
  }

  /**
   * Signs in with EU Login, on the mock EU Login form.
   *
   * The session is new. The address of the log in link leads to EU Login, which
   * here is the mock; the form takes the e-mail address and the password, and
   * the redirect back carries the ticket that the site validates.
   */
  #[When('I sign in with EU Login as :username')]
  public function iSignInWithEuLoginAs(string $username): void {
    if (!isset($this->euLoginAccounts[$username])) {
      throw new \RuntimeException(sprintf('The scenario put no EU Login account "%s" in the mock.', $username));
    }
    $session = $this->getSession();
    $session->reset();
    $this->visitPath('/eulogin');
    $page = $session->getPage();
    $page->fillField('Username or e-mail address', $this->euLoginAccounts[$username]['email']);
    $page->fillField('Password', self::EU_LOGIN_PASSWORD);
    $page->pressButton('Login!');
  }

  /**
   * Deletes what the scenario made.
   *
   * The applications go first, then the profiles, then the accounts.
   */
  #[AfterScenario]
  public function cleanUp(): void {
    if (!$this->accounts && !$this->requests && !$this->euLoginAccounts) {
      return;
    }
    $request_storage = \Drupal::entityTypeManager()->getStorage('membership_request');
    $profile_storage = \Drupal::entityTypeManager()->getStorage('profile');
    $user_storage = \Drupal::entityTypeManager()->getStorage('user');
    // The accounts that CAS registered for the mock users, and the mock users.
    if ($this->euLoginAccounts) {
      if (\Drupal::hasService('cas_mock_server.user_manager')) {
        \Drupal::service('cas_mock_server.user_manager')->deleteUsers(array_keys($this->euLoginAccounts));
      }
      $emails = array_filter(array_column($this->euLoginAccounts, 'email'));
      $or = $user_storage->getQuery()->orConditionGroup()
        ->condition('name', array_keys($this->euLoginAccounts), 'IN');
      if ($emails) {
        $or->condition('mail', $emails, 'IN');
      }
      foreach ($user_storage->getQuery()->condition($or)->accessCheck(FALSE)->execute() as $uid) {
        $this->accounts['cas:' . $uid] = (int) $uid;
      }
    }
    $uids = array_values($this->accounts);

    // The applications of the accounts too, in case a scenario made one through
    // the application form.
    $ids = $this->requests;
    if ($uids) {
      $ids = array_merge($ids, array_values($request_storage->getQuery()->accessCheck(FALSE)->condition('uid', $uids, 'IN')->execute()));
    }
    $request_storage->resetCache();
    foreach ($request_storage->loadMultiple(array_unique($ids)) as $request) {
      $request->delete();
    }
    if ($uids) {
      $profile_storage->resetCache();
      foreach ($profile_storage->loadByProperties(['uid' => $uids]) as $profile) {
        $profile->delete();
      }
      $user_storage->resetCache();
      foreach ($user_storage->loadMultiple($uids) as $account) {
        $account->delete();
      }
    }
    $this->accounts = [];
    $this->requests = [];
    $this->euLoginAccounts = [];
  }

}
