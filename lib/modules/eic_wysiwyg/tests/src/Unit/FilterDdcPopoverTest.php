<?php

declare(strict_types=1);

namespace Drupal\Tests\eic_wysiwyg\Unit;

use Drupal\Core\Extension\ExtensionList;
use Drupal\Core\Extension\Exception\UnknownExtensionException;
use Drupal\Tests\UnitTestCase;
use Drupal\eic_wysiwyg\Plugin\Filter\FilterDdcPopover;

/**
 * Tests the inline ECL popover filter.
 *
 * @coversDefaultClass \Drupal\eic_wysiwyg\Plugin\Filter\FilterDdcPopover
 *
 * @group eic_wysiwyg
 */
class FilterDdcPopoverTest extends UnitTestCase {

  /**
   * Builds a filter instance with the given settings.
   *
   * @param array $settings
   *   Filter settings to override.
   *
   * @return \Drupal\eic_wysiwyg\Plugin\Filter\FilterDdcPopover
   *   The filter.
   */
  protected function filter(array $settings = []): FilterDdcPopover {
    $filter = new FilterDdcPopover(
      ['settings' => $settings + ['show_close_button' => FALSE, 'enable_hover' => FALSE]],
      'filter_ddc_popover',
      ['provider' => 'eic_wysiwyg']
    );
    $filter->setStringTranslation($this->getStringTranslationStub());

    return $filter;
  }

  /**
   * Runs the filter and returns the processed markup.
   *
   * @param string $text
   *   The input markup.
   * @param array $settings
   *   Filter settings to override.
   *
   * @return string
   *   The processed markup.
   */
  protected function process(string $text, array $settings = []): string {
    return $this->filter($settings)->process($text, 'en')->getProcessedText();
  }

  /**
   * Wraps a phrase in a stored popover trigger.
   *
   * @param string $content
   *   The popover content, as stored (entity-encoded).
   * @param string $phrase
   *   The trigger phrase.
   *
   * @return string
   *   A paragraph containing the trigger.
   */
  protected function stored(string $content, string $phrase = 'due diligence'): string {
    return '<p>The <span data-ddc-popover-content="' . $content . '">' . $phrase . '</span> process.</p>';
  }

  /**
   * Text without the attribute is returned untouched.
   *
   * @covers ::process
   */
  public function testPassesThroughUnrelatedText(): void {
    $text = '<p>Nothing to see <span class="plain">here</span>.</p>';
    $this->assertSame($text, $this->process($text));
  }

  /**
   * The rendered structure satisfies ECL's popover.js contract.
   *
   * @covers ::process
   * @covers ::renderPopover
   */
  public function testRendersEclPopoverStructure(): void {
    $output = $this->process($this->stored('Secondary information.'));

    $dom = new \DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $output, LIBXML_NOERROR);
    $xpath = new \DOMXPath($dom);

    $root = $xpath->query('//span[@data-ecl-auto-init="Popover"]')->item(0);
    $this->assertNotNull($root, 'The popover root is emitted.');
    $this->assertStringContainsString('ecl-popover', $root->getAttribute('class'));

    $toggle = $xpath->query('.//button[@data-ecl-popover-toggle]', $root)->item(0);
    $this->assertNotNull($toggle, 'A toggle button is emitted.');
    $this->assertSame('button', $toggle->getAttribute('type'));
    $this->assertSame('false', $toggle->getAttribute('aria-expanded'));
    $this->assertSame('due diligence', $toggle->textContent);

    // ECL's init() resolves the container by the toggle's aria-controls and
    // then reads container.firstElementChild as the scrollable. Both must
    // hold or the component throws on open.
    $container = $xpath->query('.//span[@class="ecl-popover__container ddc-popover__container"]', $root)->item(0);
    $this->assertNotNull($container);
    $this->assertSame($toggle->getAttribute('aria-controls'), $container->getAttribute('id'));
    $this->assertTrue($container->hasAttribute('hidden'));

    $first = $xpath->query('./*[1]', $container)->item(0);
    $this->assertNotNull($first);
    $this->assertStringContainsString('ecl-popover__scrollable', $first->getAttribute('class'));

    // The trigger must not be marked up as an ECL button: see the comment in
    // renderPopover().
    $this->assertStringNotContainsString('ecl-button', $toggle->getAttribute('class'));

    // Nothing block-level may be emitted, or filter_htmlcorrector would split
    // the surrounding paragraph.
    $this->assertSame(0, $xpath->query('//div')->length);
    $this->assertSame(1, $xpath->query('//p')->length);
  }

  /**
   * The stored attribute never survives into the output.
   *
   * @covers ::process
   */
  public function testStoredAttributeIsConsumed(): void {
    $this->assertStringNotContainsString(
      'data-ddc-popover-content',
      $this->process($this->stored('Secondary information.'))
    );
  }

  /**
   * Permitted inline markup inside the content survives.
   *
   * @covers ::process
   */
  public function testKeepsAllowedInlineMarkup(): void {
    $output = $this->process($this->stored('See &lt;strong&gt;Annex II&lt;/strong&gt; now.'));
    $this->assertStringContainsString('<strong>Annex II</strong>', $output);
  }

  /**
   * Block-level markup in the content is stripped.
   *
   * Admitting it would let filter_htmlcorrector split the host paragraph.
   *
   * @covers ::process
   *
   * @dataProvider providerBlockMarkup
   */
  public function testStripsBlockMarkup(string $stored, string $forbidden): void {
    $output = $this->process($this->stored($stored));
    $this->assertStringNotContainsString($forbidden, $output);
  }

  /**
   * Data provider for testStripsBlockMarkup().
   *
   * @return array[]
   *   Test cases.
   */
  public static function providerBlockMarkup(): array {
    return [
      'div' => ['&lt;div&gt;Blocked&lt;/div&gt;', '<div>'],
      'heading' => ['&lt;h2&gt;Blocked&lt;/h2&gt;', '<h2>'],
      'table' => ['&lt;table&gt;&lt;tr&gt;&lt;td&gt;x&lt;/td&gt;&lt;/tr&gt;&lt;/table&gt;', '<table>'],
      'list' => ['&lt;ul&gt;&lt;li&gt;x&lt;/li&gt;&lt;/ul&gt;', '<ul>'],
    ];
  }

  /**
   * Scripts and event handlers in the content are removed.
   *
   * @covers ::process
   */
  public function testStripsScriptAndEventHandlers(): void {
    $output = $this->process($this->stored(
      '&lt;script&gt;alert(1)&lt;/script&gt;&lt;span onmouseover=&quot;alert(2)&quot;&gt;hover&lt;/span&gt;'
    ));

    $this->assertStringNotContainsString('<script', $output);
    $this->assertStringNotContainsString('onmouseover', $output);
  }

  /**
   * Dangerous link protocols in the content are neutralised.
   *
   * @covers ::process
   */
  public function testNeutralisesDangerousProtocols(): void {
    $output = $this->process($this->stored(
      '&lt;a href=&quot;javascript:alert(1)&quot;&gt;click&lt;/a&gt;'
    ));

    $this->assertStringNotContainsString('javascript:', $output);
    $this->assertStringContainsString('click', $output);
  }

  /**
   * A double-encoded payload renders as text, never as live markup.
   *
   * This is the regression test for the double-decode trap: getAttribute()
   * already decodes once, so any further decode would turn this into a real
   * script tag.
   *
   * @covers ::process
   */
  public function testDoubleEncodedPayloadStaysInert(): void {
    $output = $this->process($this->stored('&amp;lt;script&amp;gt;alert(1)&amp;lt;/script&amp;gt;'));
    $this->assertStringNotContainsString('<script', $output);
  }

  /**
   * Ids are deterministic across runs and unique within a text.
   *
   * Filter output is cached per text/format/langcode, so ids must not depend
   * on a per-request counter.
   *
   * @covers ::buildId
   */
  public function testIdsAreDeterministicAndUnique(): void {
    $text = '<p><span data-ddc-popover-content="First.">one</span> and '
      . '<span data-ddc-popover-content="Second.">two</span>.</p>';

    $first = $this->process($text);
    $this->assertSame($first, $this->process($text), 'Ids are stable across runs.');

    preg_match_all('/id="(ddc-popover-[^"]+)"/', $first, $matches);
    $this->assertCount(2, $matches[1]);
    $this->assertCount(2, array_unique($matches[1]), 'Ids are unique within a text.');
  }

  /**
   * Triggers in a context that cannot hold a button are unwrapped.
   *
   * @covers ::process
   * @covers ::unwrap
   *
   * @dataProvider providerInvalidContext
   */
  public function testUnwrapsInInvalidContext(string $text, string $expected_text): void {
    $output = $this->process($text);

    $this->assertStringNotContainsString('ecl-popover', $output);
    $this->assertStringNotContainsString('data-ddc-popover-content', $output);
    $this->assertStringContainsString($expected_text, $output);
  }

  /**
   * Data provider for testUnwrapsInInvalidContext().
   *
   * @return array[]
   *   Test cases.
   */
  public static function providerInvalidContext(): array {
    return [
      'inside a link' => [
        '<p><a href="/x">see <span data-ddc-popover-content="Detail.">this</span></a></p>',
        'see this',
      ],
      'inside a button' => [
        '<p><button type="button">go <span data-ddc-popover-content="Detail.">now</span></button></p>',
        'go now',
      ],
    ];
  }

  /**
   * Empty or whitespace-only content produces no popover.
   *
   * @covers ::process
   *
   * @dataProvider providerEmptyContent
   */
  public function testUnwrapsEmptyContent(string $stored): void {
    $output = $this->process($this->stored($stored));

    $this->assertStringNotContainsString('ecl-popover', $output);
    $this->assertStringContainsString('due diligence', $output);
  }

  /**
   * Data provider for testUnwrapsEmptyContent().
   *
   * @return array[]
   *   Test cases.
   */
  public static function providerEmptyContent(): array {
    return [
      'empty' => [''],
      'whitespace' => ['   '],
      'markup only' => ['&lt;strong&gt;&lt;/strong&gt;'],
    ];
  }

  /**
   * Newlines in the stored content are collapsed.
   *
   * A blank line would otherwise reach _filter_autop(), which rewrites it to
   * "</p><p>" and would tear the attribute apart.
   *
   * @covers ::process
   */
  public function testCollapsesNewlines(): void {
    $output = $this->process($this->stored("First line.\n\nSecond line."));

    $this->assertStringNotContainsString("\n\n", $output);
    $this->assertStringContainsString('First line. Second line.', $output);
  }

  /**
   * The library is attached only when a popover was actually rendered.
   *
   * @covers ::process
   */
  public function testAttachesLibraryOnlyWhenRendered(): void {
    $rendered = $this->filter()->process($this->stored('Detail.'), 'en');
    $this->assertSame(
      ['library' => ['eic_wysiwyg/popover']],
      $rendered->getAttachments()
    );

    $untouched = $this->filter()->process('<p>Plain text.</p>', 'en');
    $this->assertSame([], $untouched->getAttachments());
  }

  /**
   * The close button is opt-out via filter settings.
   *
   * @covers ::createCloseButton
   */
  public function testCloseButtonIsConfigurable(): void {
    $with = $this->process($this->stored('Detail.'), ['show_close_button' => TRUE]);
    $this->assertStringContainsString('data-ecl-popover-close', $with);
    $this->assertStringContainsString('ecl-popover__close', $with);

    $without = $this->process($this->stored('Detail.'), ['show_close_button' => FALSE]);
    $this->assertStringNotContainsString('data-ecl-popover-close', $without);
  }

}
