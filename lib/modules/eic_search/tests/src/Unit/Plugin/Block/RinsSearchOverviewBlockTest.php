<?php

declare(strict_types=1);

namespace Drupal\Tests\eic_search\Unit\Plugin\Block;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\eic_search\Plugin\Block\RinsSearchOverviewBlock;
use Drupal\eic_search\Search\RiskIndicators;
use Drupal\eic_search\Search\Sources\ResearchInstitutionSourceType;
use Drupal\eic_user\UserHelper;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests which users receive the risk indicator color modes.
 *
 * @coversDefaultClass \Drupal\eic_search\Plugin\Block\RinsSearchOverviewBlock
 *
 * @group eic_search
 */
class RinsSearchOverviewBlockTest extends UnitTestCase {

  /**
   * Builds the block for an account with the given roles.
   *
   * @param string[] $roles
   *   The role ids of the account.
   * @param bool $anonymous
   *   Whether the account is anonymous.
   * @param int $uid
   *   The user id of the account.
   *
   * @return \Drupal\eic_search\Plugin\Block\RinsSearchOverviewBlock
   *   The block.
   */
  protected function block(array $roles, bool $anonymous, int $uid): RinsSearchOverviewBlock {
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAnonymous')->willReturn($anonymous);
    $account->method('getRoles')->willReturn($roles);
    $account->method('id')->willReturn($uid);

    // The source type is final, so it cannot be doubled; the block never
    // touches it in the code under test.
    $source_type = (new \ReflectionClass(ResearchInstitutionSourceType::class))
      ->newInstanceWithoutConstructor();

    return new RinsSearchOverviewBlock(
      [],
      'rins_search_overview',
      ['provider' => 'eic_search'],
      $source_type,
      new RequestStack(),
      $account
    );
  }

  /**
   * Only trusted users and power users get the color modes.
   *
   * @dataProvider accountProvider
   */
  public function testRiskIndicatorsAreOnlyExposedToTrustedAccounts(array $roles, bool $anonymous, int $uid, bool $exposed): void {
    $method = new \ReflectionMethod(RinsSearchOverviewBlock::class, 'getRiskIndicatorSettings');

    $settings = $method->invoke($this->block($roles, $anonymous, $uid));

    $this->assertSame($exposed ? RiskIndicators::colorModesByName() : [], $settings);
  }

  /**
   * Data provider for ::testRiskIndicatorsAreOnlyExposedToTrustedAccounts.
   *
   * @return array[]
   *   Roles, anonymous flag, user id and whether the map is exposed.
   */
  public static function accountProvider(): array {
    return [
      'anonymous' => [['anonymous'], TRUE, 0, FALSE],
      'authenticated without a trusted role' => [['authenticated'], FALSE, 5, FALSE],
      'sensitive content administrator only' => [['authenticated', UserHelper::ROLE_SENSITIVE], FALSE, 6, FALSE],
      'trusted user' => [['authenticated', UserHelper::ROLE_TRUSTED_USER], FALSE, 7, TRUE],
      'content administrator' => [['authenticated', UserHelper::ROLE_CONTENT_ADMINISTRATOR], FALSE, 8, TRUE],
      'site administrator' => [['authenticated', UserHelper::ROLE_SITE_ADMINISTRATOR], FALSE, 9, TRUE],
      'user 1' => [['authenticated'], FALSE, 1, TRUE],
    ];
  }

}
