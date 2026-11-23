<?php

namespace Drupal\eic_user_login\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\eic_user_login\Constants\SmedUserStatuses;
use Drupal\eic_user_login\Exception\SmedUserLoginException;
use Drupal\user\UserInterface;

/**
 * Manager class for user interaction with SMED.
 */
class SmedUserManager {

  use StringTranslationTrait;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The editable texts of the onboarding statuses, if the site has them.
   *
   * @var object|null
   */
  protected $onboardingTexts;

  /**
   * SmedUserManager constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param object|null $onboarding_texts
   *   (optional) The service that holds the editable texts of the onboarding
   *   statuses (ddc_membership.texts of the module ddc_membership), with a
   *   get($key, $data) method that returns the text of a key. This module does
   *   not depend on the module of the onboarding, so a site may not have it, and
   *   the messages of the onboarding statuses are then the ones in this class.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TimeInterface $time, ?object $onboarding_texts = NULL) {
    $this->config = $config_factory;
    $this->time = $time;
    $this->onboardingTexts = $onboarding_texts;
  }

  /**
   * Processes SMED data and update user account.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account object.
   * @param array $data
   *   Data to be processed.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function updateUserInformation(UserInterface $account, array $data = []) {
    if (!empty($data['user_dashboard_id'])) {
      $account->field_smed_id->value = $data['user_dashboard_id'];
    }
    if (!empty($data['user_status'])) {
      $account->field_user_status->value = $data['user_status'];
    }
    $account->field_updated_profile_by_service->value = $this->time->getRequestTime();

    switch ($account->field_user_status->value) {
      case SmedUserStatuses::USER_VALID:
      case SmedUserStatuses::USER_APPROVED_COMPLETE:
        $account->activate();
        break;

      case SmedUserStatuses::USER_APPROVED_INCOMPLETE:
      case SmedUserStatuses::USER_DRAFT:
      case SmedUserStatuses::USER_PENDING:
      case SmedUserStatuses::USER_INVITED:
      case SmedUserStatuses::USER_NOT_BOOTSTRAPPED:
      case SmedUserStatuses::USER_BLOCKED:
      case SmedUserStatuses::USER_UNSUBSCRIBED:
      case SmedUserStatuses::USER_UNKNOWN:
        $account->block();
        break;

      default:
        // If status is not known, do nothing.
        break;
    }
    $account->save();
  }

  /**
   * Checks if the user account can log in.
   *
   * Besides the members, an account that is onboarding can log in: one that has
   * not applied yet (draft), so as to apply, and one whose application is being
   * reviewed (pending). A rejected account cannot, and no blocked account can,
   * whatever its status.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account.
   *
   * @return bool
   *   TRUE if user can login, FALSE otherwise.
   *
   * @throws \Drupal\eic_user_login\Exception\SmedUserLoginException
   */
  public function isUserLoginAuthorised(UserInterface $account) {
    $allowed_statuses = [
      SmedUserStatuses::USER_VALID,
      SmedUserStatuses::USER_APPROVED_COMPLETE,
      SmedUserStatuses::USER_DRAFT,
      SmedUserStatuses::USER_PENDING,
    ];
    $account_status = $account->field_user_status->value;

    // Check that the account is not blocked, and the user status. CAS checks for
    // a blocked account before it dispatches the pre-login event, but the SMED
    // sync that runs in that event can block the account afterwards: an allowed
    // status must not get a session for an account that was just blocked.
    if (!$account->isBlocked() && in_array($account_status, $allowed_statuses, TRUE)) {
      return TRUE;
    }
    else {
      $exception = new SmedUserLoginException();
      $exception->setUserStatus($account_status);
      $exception->setUserMessage($this->getLoginMessage($account));
      throw $exception;
    }
  }

  /**
   * Returns a message for the user based on their account status.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   The message associated to the status code. The messages of the onboarding
   *   statuses (draft, pending, rejected, approved) are texts that an
   *   administrator edits, when the site has them; the rest are in this class.
   */
  public function getLoginMessage(UserInterface $account): MarkupInterface {
    $account_status = $account->field_user_status->value;

    $smed_url = $this->config->get('eic_user_login.settings')->get('user_registration_url');

    switch ($account_status) {
      case SmedUserStatuses::USER_VALID:
      case SmedUserStatuses::USER_APPROVED_COMPLETE:
        $message = $this->getOnboardingText('status_approved', $account) ?? $this->t('<p class="ecl-u-mv-none">Welcome @username</p>', ['@username' => $account->getDisplayName()]);
        break;

      case SmedUserStatuses::USER_APPROVED_INCOMPLETE:
        $message = $this->t('<p class="ecl-u-mv-none">Welcome @username,<br>Almost there! It look like your registration is in <b>draft</b>. Please go to your <span class="register_button"><a class="ecl-link ecl-link--cta" href=":smed_url" target="_blank">draft registration</a></span>. If you have any questions <a href=":contact_form_url">contact us</a>.</p>', [
          '@username' => $account->getDisplayName(),
          ':smed_url' => $smed_url,
        ]);
        break;

      case SmedUserStatuses::USER_DRAFT:
        // The path, not a route: the membership request is a module of its own.
        $message = $this->getOnboardingText('status_draft', $account) ?? $this->t('<p class="ecl-u-mv-none">Welcome @username.<br>To get access, please <a href=":membership_request_url">complete your onboarding application</a>.</p>', [
          '@username' => $account->getDisplayName(),
          ':membership_request_url' => Url::fromUserInput('/membership-request')->toString(),
        ]);
        break;

      case SmedUserStatuses::USER_PENDING:
        $message = $this->getOnboardingText('status_pending', $account) ?? $this->t('<p class="ecl-u-mv-none">Dear @username,<br>Your onboarding application is undergoing review. If you do not hear from us in the coming days, please <a href=":contact_form_url">contact us</a>.</p>', [
          '@username' => $account->getDisplayName(),
          ':contact_form_url' => Url::fromRoute('contact.site_page')->toString(),
        ]);
        break;

      case SmedUserStatuses::USER_REJECTED:
        $message = $this->getOnboardingText('status_rejected', $account) ?? $this->t('<p class="ecl-u-mv-none">Your onboarding application was rejected. Your user account is blocked.</p>');
        break;

      case SmedUserStatuses::USER_INVITED:
        $message = $this->t('<p class="ecl-u-mv-none">Welcome to the EIC community! To get you started please <span class="register_button"><a class="ecl-link ecl-link--cta" href=":smed_url" target="_blank">register here</a></span>. If you have any questions <a href=":contact_form_url">contact us</a>.</p>', [
          ':smed_url' => $smed_url,
          ':contact_form_url' => Url::fromRoute('contact.site_page')->toString(),
        ]);
        break;

      case SmedUserStatuses::USER_NOT_BOOTSTRAPPED:
      case SmedUserStatuses::USER_BLOCKED:
        $message = $this->t('<p class="ecl-u-mv-none">It looks like something went wrong, Please contact us via the <a href=":contact_form_url">contact form</a>.</p>', [
          ':contact_form_url' => Url::fromRoute('contact.site_page')->toString(),
        ]);
        break;

      case SmedUserStatuses::USER_UNSUBSCRIBED:
        $message = $this->t('<p class="ecl-u-mv-none">It looks like something went wrong, please contact us via the <a href=":contact_form_url">contact form</a>.</p>', [
          ':contact_form_url' => Url::fromRoute('contact.site_page')->toString(),
        ]);
        break;
      case SmedUserStatuses::USER_UNKNOWN:
        $message = $this->t('<p class="ecl-u-mv-none">It looks like you are not a member yet. Interested? Please <span class="register_button"><a class="ecl-link ecl-link--cta" href=":smed_url" target="_blank">register</a></span>. If you have any questions <a href=":contact_form_url">contact us</a>.</p>', [
          ':smed_url' => $smed_url,
          ':contact_form_url' => Url::fromRoute('contact.site_page')->toString(),
        ]);
        break;

      default:
        $message = $this->t('<p class="ecl-u-mv-none">An error occurred. Please contact us via the <a href=":contact_form_url">contact form</a>.</p>', [
          ':contact_form_url' => Url::fromRoute('contact.site_page')->toString(),
        ]);
        break;
    }

    return $message;
  }

  /**
   * Gets the editable text of an onboarding status, if the site has the texts.
   *
   * @param string $key
   *   The key of the text: status_draft, status_pending, status_rejected or
   *   status_approved.
   * @param \Drupal\user\UserInterface $account
   *   The user account, for the tokens of the text.
   *
   * @return \Drupal\Component\Render\MarkupInterface|null
   *   The text, or NULL when the site does not have the texts, and the message
   *   of this class is to be used.
   */
  protected function getOnboardingText(string $key, UserInterface $account): ?MarkupInterface {
    return $this->onboardingTexts?->get($key, ['user' => $account]);
  }

}
