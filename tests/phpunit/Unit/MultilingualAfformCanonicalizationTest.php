<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Service\Canonicalizer;
use PHPUnit\Framework\TestCase;

/**
 * ML-001 requirement-first regression. Intentionally red until the active
 * locale is separated from the portable translation representation.
 *
 * This is NOT a test of CiviCRM's runtime translation API. The two fixtures
 * represent the language-dependent payloads observed in the La Cause report.
 */
final class MultilingualAfformCanonicalizationTest extends TestCase {
  public function testLocaleOnlyChangeMustNotCauseFalseAfformDrift(): void {
    $canonicalizer = new Canonicalizer();
    $english = $this->afform('Household Name');
    $french = $this->afform('Nom du foyer');

    // Expected result is the externally stated ML-001 contract, not calculated
    // from the code under test. This intentionally fails on Canonicalizer v2.
    self::assertSame(
      $canonicalizer->hash($english),
      $canonicalizer->hash($french),
      'ML-001: display-locale-only changes must not cause configuration drift.'
    );
  }

  public function testGenuineTitleEditMustRemainDetectable(): void {
    $canonicalizer = new Canonicalizer();
    self::assertNotSame(
      $canonicalizer->hash($this->afform('Household Name')),
      $canonicalizer->hash($this->afform('Household Profile')),
      'ML-001: a genuine translated title edit must not disappear from the fingerprint.'
    );
  }

  private function afform(string $title): array {
    return [
      'schema_version' => 1,
      'type' => 'formbuilder-afforms.item',
      'entity' => 'Afform',
      'name' => 'af_household',
      'item' => [
        'name' => 'af_household',
        'title' => $title,
        'layout' => [
          ['tag' => 'div', 'children' => [['tag' => 'af-field', 'name' => 'display_name']]],
        ],
      ],
    ];
  }
}
