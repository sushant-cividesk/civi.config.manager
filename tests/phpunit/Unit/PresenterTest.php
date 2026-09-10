<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\UI\Presenter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PresenterTest extends TestCase {
  public function testImportMessagesAreDeduplicated(): void {
    $presenter = new Presenter();
    $result = [
      'validation' => [
        'items' => [[
          'type' => 'extensions',
          'warnings' => [
            ['message' => 'Provider is backup/monitor-only.'],
            ['message' => 'Provider is backup/monitor-only.'],
          ],
          'errors' => [],
        ]],
      ],
    ];

    $messages = $presenter->extractImportMessages($result);

    self::assertCount(1, $messages);
    self::assertSame('Provider is backup/monitor-only.', $messages[0]['message']);
  }

  public function testCompletePreflightTopLevelErrorsAreShown(): void {
    $presenter = new Presenter();
    $messages = $presenter->extractImportMessages([
      'errors' => [
        ['type' => 'option-groups', 'message' => 'Possible OptionValue identity rename detected.'],
        ['type' => 'import', 'message' => 'Another preflight blocker.'],
      ],
      'validation' => [
        'errors' => [
          ['type' => 'manifest', 'message' => 'Manifest site_id does not match this site_id.'],
        ],
        'items' => [],
      ],
      'items' => [],
    ]);

    $text = implode("\n", array_map(static function(array $message): string {
      return (string) $message['message'];
    }, $messages));

    self::assertStringContainsString('Possible OptionValue identity rename detected.', $text);
    self::assertStringContainsString('Another preflight blocker.', $text);
    self::assertStringContainsString('Manifest site_id does not match this site_id.', $text);
  }

  public function testExtensionStatusChangeUsesPlainLanguageAndBothSides(): void {
    $presenter = new Presenter();
    $method = new ReflectionMethod($presenter, 'describeFieldChange');
    $method->setAccessible(TRUE);

    $sentence = (string) $method->invoke(
      $presenter,
      ['type' => 'extensions'],
      ['path' => 'extension.status'],
      'Extension state',
      'changed',
      'disabled',
      'enabled'
    );

    self::assertStringContainsString('Saved Config Installed but disabled', $sentence);
    self::assertStringContainsString('CiviCRM Enabled', $sentence);
  }

  public function testBackupOnlyExtensionProviderDoesNotOfferRestoreAction(): void {
    $presenter = new Presenter();
    $plan = $presenter->buildImportPlan([[
      'type' => 'extensions',
      'type_label' => 'Extensions',
      'status' => 'missing_in_db',
      'path' => 'extensions/org.wikimedia.geocoder/api4/Geocoder/Addok.yml',
      'write_safe' => FALSE,
    ]]);

    self::assertCount(1, $plan);
    self::assertFalse($plan[0]['importable']);
    self::assertSame('Saved copy only', $plan[0]['action']);
    self::assertStringContainsString('Automatic restore is disabled', $plan[0]['note']);
  }

  public function testWriteSafeExtensionProviderStillOffersCreate(): void {
    $presenter = new Presenter();
    $plan = $presenter->buildImportPlan([[
      'type' => 'extensions',
      'type_label' => 'Extensions',
      'status' => 'missing_in_db',
      'path' => 'extensions/de.systopia.sqltasks/api3/Sqltask/hii.yml',
      'write_safe' => TRUE,
    ]]);

    self::assertTrue($plan[0]['importable']);
    self::assertSame('Restore to Current CiviCRM', $plan[0]['action']);
  }

  public function testVirtualExtensionSubtypeIsPreservedForUiImportApply(): void {
    $presenter = new Presenter();
    $plan = [[
      'type' => 'extensions',
      'importable' => TRUE,
    ]];

    $types = $presenter->getImportApplyTypes($plan, [
      'extensions:de.systopia.sqltasks:api3:Sqltask',
    ]);

    self::assertSame([
      'extensions:de.systopia.sqltasks:api3:Sqltask',
    ], $types);
  }

  public function testExplicitBaseExtensionsSelectionKeepsBroadUiImport(): void {
    $presenter = new Presenter();
    $plan = [[
      'type' => 'extensions',
      'importable' => TRUE,
    ]];

    $types = $presenter->getImportApplyTypes($plan, [
      'extensions',
      'extensions:de.systopia.sqltasks:api3:Sqltask',
    ]);

    self::assertSame(['extensions'], $types);
  }

  public function testCompatibilityInformationDoesNotInflateImportWarningCount(): void {
    $presenter = new Presenter();
    $messages = $presenter->extractImportMessages([
      'items' => [[
        'type' => 'extensions',
        'warnings' => [],
        'compatibility' => [
          ['message' => 'Provider is backup/monitor-only.'],
          ['message' => 'Provider is backup/monitor-only.'],
        ],
        'errors' => [],
      ]],
    ]);

    self::assertSame([], $messages);
  }
  /** Requirement: one-sided sync states use client language that describes the next action clearly. */
  public function testClientFriendlyDifferenceLabels(): void {
    $presenter = new Presenter();

    self::assertSame('Not Yet Saved', $presenter->statusLabel('new_in_db'));
    self::assertSame('Not in Current CiviCRM', $presenter->statusLabel('missing_in_db'));
  }

  /**
   * Requirement: Not Yet Saved is already visible as a badge, so the summary
   * must tell the operator what to do next rather than restating that status.
   */
  public function testNotYetSavedSummaryExplainsNextAction(): void {
    $presenter = new Presenter();
    $files = $presenter->extractDiffFiles(['items' => [[
      'type' => 'tags',
      'label' => 'Tags',
      'files' => [[
        'path' => 'tags/community.yml',
        'status' => 'new_in_db',
        'changes' => [],
      ]],
    ]]]);

    self::assertSame('Not Yet Saved', $files[0]['status_label']);
    self::assertSame('Export this item to add it to Saved Configs.', $files[0]['summary_sentence']);
    self::assertStringNotContainsString('has not been saved', $files[0]['summary_sentence']);
  }

  /**
   * Requirement: Profile Field collision hashes remain internal identity/file
   * details and do not become the normal card title.
   */
  public function testProfileFieldUsesSemanticDisplayTitleAndHidesInlinePath(): void {
    $presenter = new Presenter();
    $files = $presenter->extractDiffFiles(['items' => [[
      'type' => 'profile-fields',
      'label' => 'Profile Fields',
      'files' => [[
        'path' => 'profiles/fields/summary_overlay__phone__Home-Phone--7a8859f54f.yml',
        'file' => 'summary_overlay__phone__Home-Phone--7a8859f54f.yml',
        'status' => 'changed',
        'config_key' => 'profile-fields:api4:UFField|key=profile=summary_overlay%7Cfield=phone%7Cfield_type=Phone%7Clocation=Home%7Clabel=Home Phone',
        'changes' => [['path' => 'item.label', 'type' => 'changed', 'old' => 'Home Phone', 'new' => 'Primary Phone']],
      ]],
    ]]]);

    self::assertSame('Profile Field "Home Phone" in "Summary Overlay"', $files[0]['display_title']);
    self::assertFalse($files[0]['show_inline_path']);
    self::assertStringNotContainsString('7a8859f54f', $files[0]['display_title']);

    $plan = $presenter->buildImportPlan($files);
    self::assertSame($files[0]['display_title'], $plan[0]['display_title']);
    self::assertFalse($plan[0]['show_inline_path']);
  }

  /**
   * Requirement: legacy/incomplete Profile Field diff state must still hide a
   * collision suffix even when the semantic config key is unavailable.
   */
  public function testProfileFieldFilenameFallbackStripsCollisionHash(): void {
    $presenter = new Presenter();
    $files = $presenter->extractDiffFiles(['items' => [[
      'type' => 'profile-fields',
      'label' => 'Profile Fields',
      'files' => [[
        'path' => 'profiles/fields/summary_overlay__phone__Home-Phone--7a8859f54f.yml',
        'status' => 'changed',
        'changes' => [],
      ]],
    ]]]);

    self::assertSame('Profile Field "Home Phone" in "Summary Overlay"', $files[0]['display_title']);
    self::assertFalse($files[0]['show_inline_path']);
    self::assertStringNotContainsString('7a8859f54f', $files[0]['display_title']);
  }

  /** Requirement: the summary exposes the Saved Config inventory count without changing difference totals. */
  public function testSummaryIncludesSavedConfigCount(): void {
    $presenter = new Presenter();
    $summary = $presenter->buildSummary(
      ['ok' => TRUE, 'items' => []],
      ['ok' => TRUE, 'sync_dir' => '/tmp/config', 'exists' => TRUE, 'writable' => TRUE, 'saved_config_count' => 1107],
      'sync'
    );

    self::assertSame(1107, $summary['saved_config_count']);
    self::assertSame(0, $summary['total_changes']);
  }


}
