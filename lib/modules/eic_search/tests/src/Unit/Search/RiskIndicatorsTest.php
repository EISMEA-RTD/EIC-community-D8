<?php

declare(strict_types=1);

namespace Drupal\Tests\eic_search\Unit\Search;

use Drupal\Tests\UnitTestCase;
use Drupal\eic_search\Search\RiskIndicators;

/**
 * Tests the risk indicator term definitions.
 *
 * @coversDefaultClass \Drupal\eic_search\Search\RiskIndicators
 *
 * @group eic_search
 */
class RiskIndicatorsTest extends UnitTestCase {

  /**
   * Pattern for a version 4 UUID, as generated for the term constants.
   */
  const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

  /**
   * The color modes ECL 5 (EC theme) defines, as in ecl-ec-color-modes.css.
   *
   * A term may only use one of these, so the theme never needs custom CSS.
   */
  const ECL_COLOR_MODES = [
    'blue',
    'blue-electric',
    'blue-navy',
    'blue-ocean',
    'green',
    'green-dark',
    'green-lemon',
    'green-pine',
    'orange',
    'purple',
    'purple-violet',
    'red-crayola',
    'red-tomato',
    'warm-grey',
    'yellow-gold',
  ];

  /**
   * Returns the UUID constants of the class, keyed by constant name.
   *
   * Every public constant that is not the vocabulary id or the lookup array
   * is a term UUID.
   *
   * @return string[]
   *   The UUIDs keyed by constant name.
   */
  protected function uuidConstants(): array {
    $constants = (new \ReflectionClass(RiskIndicators::class))->getConstants();
    return array_diff_key($constants, array_flip(['VOCABULARY', 'TERMS']));
  }

  /**
   * Every UUID constant is a well-formed v4 UUID and none is reused.
   */
  public function testEveryUuidConstantIsAUniqueV4Uuid(): void {
    $uuids = $this->uuidConstants();

    $this->assertCount(6, $uuids);
    foreach ($uuids as $name => $uuid) {
      $this->assertMatchesRegularExpression(self::UUID_PATTERN, $uuid, "Constant {$name} is not a v4 UUID.");
    }
    $this->assertSame(array_values($uuids), array_values(array_unique($uuids)), 'A UUID is used by more than one constant.');
  }

  /**
   * The lookup array is keyed by exactly the UUID constants.
   */
  public function testTermsAreKeyedByExactlyTheUuidConstants(): void {
    $this->assertEqualsCanonicalizing(
      array_values($this->uuidConstants()),
      array_keys(RiskIndicators::TERMS)
    );
  }

  /**
   * Every term has a name, a unique abbreviation and a unique ECL color mode.
   */
  public function testEveryTermHasNameAbbreviationAndColorMode(): void {
    foreach (RiskIndicators::TERMS as $uuid => $term) {
      $this->assertNotSame('', trim($term['name']), "Term {$uuid} has no name.");
      $this->assertMatchesRegularExpression('/^[A-Z]{2,5}$/', $term['abbreviation'], "Term {$uuid} has an invalid abbreviation.");
      $this->assertContains($term['color_mode'], self::ECL_COLOR_MODES, "Term {$uuid} does not use an ECL color mode.");
    }

    $abbreviations = array_column(RiskIndicators::TERMS, 'abbreviation');
    $this->assertSame($abbreviations, array_values(array_unique($abbreviations)), 'An abbreviation is used by more than one term.');

    $color_modes = array_column(RiskIndicators::TERMS, 'color_mode');
    $this->assertSame($color_modes, array_values(array_unique($color_modes)), 'Two terms share a color mode, so their tags look the same.');
  }

  /**
   * The terms are defined in the order the editors gave them.
   */
  public function testTermsFollowTheEditorialOrder(): void {
    $this->assertSame([
      'Military / Defense',
      'Human rights findings',
      'Security and intelligence',
      'Competitiveness and IP outflow',
      'Indirect compliance risks',
      'Official listings and restrictions',
    ], array_column(RiskIndicators::TERMS, 'name'));
  }

  /**
   * The terms with a design color keep the ECL color mode nearest to it.
   *
   * The design colors were sampled from the mock-ups (#FA9B8E, #F8C622,
   * #BC8FFB, #E3D4C1); each maps to the closest ECL palette family.
   *
   * @dataProvider givenTermProvider
   */
  public function testGivenAbbreviationAndColorMode(string $constant, string $name, string $abbreviation, string $color_mode): void {
    $term = RiskIndicators::TERMS[constant(RiskIndicators::class . '::' . $constant)];

    $this->assertSame($name, $term['name']);
    $this->assertSame($abbreviation, $term['abbreviation']);
    $this->assertSame($color_mode, $term['color_mode']);
  }

  /**
   * Data provider for ::testGivenAbbreviationAndColorMode.
   *
   * @return array[]
   *   Constant name, term name, abbreviation and ECL color mode.
   */
  public static function givenTermProvider(): array {
    return [
      'military' => ['MILITARY_DEFENSE', 'Military / Defense', 'MIL', 'red-tomato'],
      'human rights' => ['HUMAN_RIGHTS', 'Human rights findings', 'HR', 'purple-violet'],
      'security' => ['SECURITY_INTELLIGENCE', 'Security and intelligence', 'SEC', 'yellow-gold'],
      'listings' => ['OFFICIAL_LISTINGS', 'Official listings and restrictions', 'LIST', 'warm-grey'],
    ];
  }

  /**
   * The client map holds term names and color modes, and nothing else.
   *
   * It is what ends up in drupalSettings, so it must not carry UUIDs or
   * abbreviations.
   *
   * @covers ::colorModesByName
   */
  public function testColorModesByNameExposesOnlyNamesAndColorModes(): void {
    $map = RiskIndicators::colorModesByName();

    $this->assertSame(array_column(RiskIndicators::TERMS, 'name'), array_keys($map));
    $this->assertSame(array_column(RiskIndicators::TERMS, 'color_mode'), array_values($map));
  }

}
