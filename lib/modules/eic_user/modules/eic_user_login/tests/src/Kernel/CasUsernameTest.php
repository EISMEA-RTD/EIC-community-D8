<?php

declare(strict_types = 1);

namespace Drupal\Tests\eic_user_login\Kernel;

use Drupal\cas\CasPropertyBag;
use Drupal\cas\Event\CasPreUserLoadEvent;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests which name an EU Login account is known by.
 *
 * The EU Login username (the ID, such as n00abcde) is the CAS username, and so
 * the name of the Drupal account. The e-mail address is an attribute that fills
 * the account's e-mail field, and is not the name of the account.
 */
#[Group('eic_user_login')]
#[RunTestsInSeparateProcesses]
class CasUsernameTest extends KernelTestBase {

  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
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
   * The EU Login ID stays the CAS username, whatever e-mail address comes with it.
   */
  public function testEuLoginIdStaysTheCasUsername(): void {
    $bag = new CasPropertyBag('n00abcde');
    $bag->setAttribute('email', 'someone@example.com');

    $this->container->get('event_dispatcher')->dispatch(new CasPreUserLoadEvent($bag));

    $this->assertSame('n00abcde', $bag->getUsername());
    $this->assertSame('someone@example.com', $bag->getAttribute('email'), 'The address is still there for the e-mail field.');
  }

}
