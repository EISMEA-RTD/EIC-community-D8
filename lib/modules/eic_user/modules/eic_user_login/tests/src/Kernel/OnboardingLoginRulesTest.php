<?php

declare(strict_types = 1);

namespace Drupal\Tests\eic_user_login\Kernel;

use Drupal\cas\CasPropertyBag;
use Drupal\cas\Event\CasPreRegisterEvent;
use Drupal\Core\Url;
use Drupal\eic_user_login\Constants\SmedUserStatuses;
use Drupal\eic_user_login\Exception\SmedUserLoginException;
use Drupal\eic_user_login\Service\SmedUserManager;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the login rules and messages of the onboarding user statuses.
 *
 * The user status is what a sign in is checked against: an account that has not
 * applied yet (draft) or whose application is being reviewed (pending) may sign
 * in, so as to reach the application and its outcome; a rejected account may
 * not.
 */
#[Group('eic_user_login')]
#[RunTestsInSeparateProcesses]
class OnboardingLoginRulesTest extends KernelTestBase {

  /**
   * The statuses of an account that may sign in, if it is not blocked.
   */
  const ALLOWED_STATUSES = ['user_draft', 'user_pending', 'user_valid', 'user_approved_complete'];

  /**
   * {@inheritdoc}
   *
   * The settings of eic_webservices, that userPreRegister() reads, have no
   * schema as the module is not enabled.
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   *
   * The real module is enabled, for the user status field and the services. The
   * kernel does not resolve dependencies, so cas is not enabled: only its event
   * and property bag classes are used, and they are autoloaded.
   * The contact module is there for the contact page, that the messages link.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'options',
    'contact',
    'eic_user_login',
  ];

  /**
   * The SMED user manager.
   */
  protected SmedUserManager $userManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The messages of some of the SMED statuses link the registration URL, and
    // a link placeholder cannot take an empty value.
    $this->config('eic_user_login.settings')->set('user_registration_url', 'https://smed.example.com/register')->save();
    $this->userManager = $this->container->get('eic_user_login.smed_user_manager');
  }

  /**
   * Tests that the accounts who are onboarding or members may sign in.
   */
  public function testLoginAuthorisedForDraftPendingValid(): void {
    foreach (self::ALLOWED_STATUSES as $status) {
      $this->assertTrue($this->userManager->isUserLoginAuthorised($this->createAccount($status)), "An account with the status $status may sign in.");
    }
  }

  /**
   * Tests that a rejected account, and any other status, cannot sign in.
   *
   * Every status the field defines but the allowed ones is tried, and so are an
   * account that has no status and one that has a status that is not defined.
   */
  public function testLoginDeniedForRejectedAndOtherStatuses(): void {
    $statuses = array_values(array_diff(array_keys(SmedUserStatuses::getUserStatuses()), self::ALLOWED_STATUSES));
    $this->assertContains('user_rejected', $statuses);
    $this->assertContains('user_blocked', $statuses);
    $this->assertContains('user_unknown', $statuses);

    foreach ([...$statuses, NULL, '', 'user_made_up'] as $status) {
      $this->assertLoginDenied($this->createAccount($status), 'An account with the status ' . var_export($status, TRUE));
    }
  }

  /**
   * Tests that a blocked account cannot sign in, whatever its status.
   *
   * CAS checks for a blocked account before the pre-login event, and the SMED
   * sync that runs in that event can block the account after that check.
   */
  public function testBlockedAccountIsNeverAuthorised(): void {
    foreach (self::ALLOWED_STATUSES as $status) {
      $this->assertTrue($this->userManager->isUserLoginAuthorised($this->createAccount($status)), "The same account with the status $status may sign in when it is not blocked.");

      $this->assertLoginDenied($this->createAccount($status)->block(), "A blocked account with the status $status");
    }
  }

  /**
   * Tests that the rejected status is one the user status field accepts.
   *
   * The values the field accepts are the ones of getUserStatuses(). The check
   * also refuses a value that is not defined, so that it can fail.
   */
  public function testRejectedStatusIsDefined(): void {
    $this->assertSame('user_rejected', SmedUserStatuses::USER_REJECTED);
    $this->assertArrayHasKey('user_rejected', SmedUserStatuses::getUserStatuses());

    $this->assertCount(0, $this->createAccount('user_rejected')->get('field_user_status')->validate(), 'The field accepts the rejected status.');
    $this->assertNotCount(0, $this->createAccount('user_made_up')->get('field_user_status')->validate(), 'The field refuses a status that is not defined.');
  }

  /**
   * Tests the message of the draft, pending and rejected statuses.
   */
  public function testMessagesByStatus(): void {
    $contact_link = 'href="' . Url::fromRoute('contact.site_page')->toString() . '"';

    $draft = $this->getMessage('user_draft');
    $this->assertStringContainsString('Welcome applicant.', $draft);
    $this->assertStringContainsString('complete your onboarding application', $draft);
    $this->assertStringContainsString('href="' . Url::fromUserInput('/membership-request')->toString() . '"', $draft, 'The draft message links the membership request.');

    // The sentence of the spec of 2026-11-18 (GitLab #18).
    $pending = $this->getMessage('user_pending');
    $this->assertStringContainsString('Your onboarding application is undergoing review.', $pending);
    $this->assertStringContainsString($contact_link, $pending, 'The pending message links the contact page.');

    $rejected = $this->getMessage('user_rejected');
    $this->assertStringContainsString('Your onboarding application was rejected. Your user account is blocked.', $rejected);
  }

  /**
   * Tests that an account who registers starts without an application.
   *
   * The status is what the sign in is checked against, and the accounts of this
   * site have to apply: it is no longer valid at registration, but draft.
   */
  public function testPreRegisterStartsAccountsAsDraft(): void {
    $this->config('eic_user_login.settings')->set('allow_user_register', TRUE)->save();
    $event = new CasPreRegisterEvent(new CasPropertyBag('applicant@example.com'));

    $this->container->get('eic_user_login.cas_event_subscriber')->userPreRegister($event);

    $this->assertTrue($event->getAllowAutomaticRegistration(), 'The account is registered.');
    $this->assertArrayHasKey('field_user_status', $event->getPropertyValues());
    $this->assertSame('user_draft', $event->getPropertyValues()['field_user_status']);

    // Where an account cannot register without SMED, the registration is
    // cancelled, as it was, and no status is set.
    $this->config('eic_user_login.settings')->set('allow_user_register', FALSE)->save();
    $this->config('eic_webservices.settings')->set('smed_url', 'https://smed.example.com')->save();
    $event = new CasPreRegisterEvent(new CasPropertyBag('applicant@example.com'));

    $this->container->get('eic_user_login.cas_event_subscriber')->userPreRegister($event);

    $this->assertFalse($event->getAllowAutomaticRegistration(), 'The registration is cancelled.');
    $this->assertArrayNotHasKey('field_user_status', $event->getPropertyValues());
  }

  /**
   * Asserts that an account may not sign in, with the status and the message.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account.
   * @param string $label
   *   What the account is, for the failure messages.
   */
  protected function assertLoginDenied(UserInterface $account, string $label): void {
    try {
      $this->userManager->isUserLoginAuthorised($account);
      $this->fail("$label may not sign in.");
    }
    catch (SmedUserLoginException $exception) {
      $this->assertSame($account->field_user_status->value, $exception->getUserStatus(), "The exception for $label carries the status.");
      $this->assertSame((string) $this->userManager->getLoginMessage($account), (string) $exception->getUserMessage(), "The exception for $label carries the message.");
    }
  }

  /**
   * Creates an account with a user status.
   *
   * The account is not saved: the rules read the status from the entity.
   *
   * @param string|null $status
   *   The user status, or NULL for an account that has none.
   */
  protected function createAccount(?string $status): UserInterface {
    return User::create([
      'name' => 'applicant',
      'mail' => 'applicant@example.com',
      'status' => 1,
      'field_user_status' => $status,
    ]);
  }

  /**
   * Returns the message for an account with a user status, as markup.
   *
   * @param string $status
   *   The user status.
   */
  protected function getMessage(string $status): string {
    return (string) $this->userManager->getLoginMessage($this->createAccount($status));
  }

}
