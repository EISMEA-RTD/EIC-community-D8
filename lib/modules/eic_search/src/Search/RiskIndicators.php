<?php

namespace Drupal\eic_search\Search;

/**
 * The canonical terms of the "risk_indicators" vocabulary.
 *
 * Every term has a fixed UUID, so code can refer to a term without a database
 * lookup and the same term keeps its identity on every environment. The
 * research_institution_admin deploy hook creates the terms from this list.
 *
 * Each term also carries the ECL color mode its tag is themed with. Always use
 * an ECL color mode here rather than a custom color: the class
 * "ecl-color-mode--{color_mode}" on an ".ecl-tag" tints it with no extra CSS.
 * The available modes are listed in @ecl/theme-ec (maps/color.scss).
 *
 * And it carries the icon drawn on the left of the tag: the file name, without
 * extension, of an SVG in ddc_theme's images/risk-indicators folder. The
 * theme's risk-pill.css turns "ddc-risk-pill--{icon}" into that image.
 */
final class RiskIndicators {

  /**
   * The vocabulary machine name.
   */
  public const VOCABULARY = 'risk_indicators';

  public const MILITARY_DEFENSE = 'c9422368-243d-489a-99f6-5b31c1dbf3a2';

  public const HUMAN_RIGHTS = '0604a85b-884c-4b88-b00a-565d6ef46016';

  public const SECURITY_INTELLIGENCE = '4e496d12-3691-49bf-8ebe-b932c7748fdf';

  public const COMPETITIVENESS_IP = 'a4f14548-97e7-42cf-a775-ccf673751951';

  public const INDIRECT_COMPLIANCE = 'b343f408-d50f-4b8d-bbfe-11c2f1b1797e';

  public const OFFICIAL_LISTINGS = '3c9a2dbd-a7c6-48e8-aec3-91b695c19378';

  /**
   * The terms keyed by UUID, in display order.
   *
   * The ECL color mode of each term is the closest ECL palette family to the
   * design color (Military: red-tomato, Human rights: purple-violet, Security:
   * yellow-gold, Listings: warm-grey). Competitiveness and Indirect compliance
   * have no design color yet; their modes are placeholders.
   */
  public const TERMS = [
    self::MILITARY_DEFENSE => [
      'name' => 'Military / Defense',
      'abbreviation' => 'MIL',
      'color_mode' => 'red-tomato',
      'icon' => 'military',
    ],
    self::HUMAN_RIGHTS => [
      'name' => 'Human rights findings',
      'abbreviation' => 'HR',
      'color_mode' => 'purple-violet',
      'icon' => 'human-rights',
    ],
    self::SECURITY_INTELLIGENCE => [
      'name' => 'Security and intelligence',
      'abbreviation' => 'SEC',
      'color_mode' => 'yellow-gold',
      'icon' => 'security',
    ],
    self::COMPETITIVENESS_IP => [
      'name' => 'Competitiveness and IP outflow',
      'abbreviation' => 'IP',
      'color_mode' => 'blue-navy',
      'icon' => 'competitiveness',
    ],
    self::INDIRECT_COMPLIANCE => [
      'name' => 'Indirect compliance risks',
      'abbreviation' => 'COMP',
      'color_mode' => 'green-pine',
      'icon' => 'compliance',
    ],
    self::OFFICIAL_LISTINGS => [
      'name' => 'Official listings and restrictions',
      'abbreviation' => 'LIST',
      'color_mode' => 'warm-grey',
      'icon' => 'listings',
    ],
  ];

  /**
   * Maps each term name to its ECL color mode, for the React front end.
   *
   * Search results only carry term names, so the front end looks the color up
   * by name. The map deliberately holds nothing else (no UUIDs, no
   * abbreviations): it is what ends up in drupalSettings.
   *
   * @return string[]
   *   The ECL color modes, keyed by term name.
   */
  public static function colorModesByName(): array {
    return array_column(self::TERMS, 'color_mode', 'name');
  }

}
