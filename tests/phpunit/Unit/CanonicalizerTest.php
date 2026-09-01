<?php

declare(strict_types=1);

namespace Civi\ConfigManager\Tests\Unit;

use Civi\ConfigManager\Service\Canonicalizer;
use PHPUnit\Framework\TestCase;

final class CanonicalizerTest extends TestCase {
  public function testHashIsDeterministicForAssociativeKeyOrder(): void {
    $canonicalizer = new Canonicalizer();

    $first = ['name' => 'alpha', 'settings' => ['enabled' => TRUE, 'limit' => 5]];
    $second = ['settings' => ['limit' => 5, 'enabled' => TRUE], 'name' => 'alpha'];

    self::assertSame($canonicalizer->hash($first), $canonicalizer->hash($second));
    self::assertSame(64, strlen($canonicalizer->hash($first)));
  }

  public function testHashPreservesScalarTypes(): void {
    $canonicalizer = new Canonicalizer();

    self::assertNotSame($canonicalizer->hash(['value' => 1]), $canonicalizer->hash(['value' => '1']));
    self::assertNotSame($canonicalizer->hash(['value' => TRUE]), $canonicalizer->hash(['value' => 1]));
    self::assertNotSame($canonicalizer->hash(['value' => NULL]), $canonicalizer->hash(['value' => '']));
  }

  public function testOrderedListsRemainOrderedUnlessExplicitlyUnordered(): void {
    $canonicalizer = new Canonicalizer();
    $first = ['steps' => [['name' => 'email'], ['name' => 'activity']]];
    $second = ['steps' => [['name' => 'activity'], ['name' => 'email']]];

    self::assertNotSame($canonicalizer->hash($first), $canonicalizer->hash($second));
    self::assertSame(
      $canonicalizer->hash($first, ['unordered_paths' => ['steps']]),
      $canonicalizer->hash($second, ['unordered_paths' => ['steps']])
    );
  }

  public function testIgnoredPathsAreExactAndDoNotStripSameNamedNestedFields(): void {
    $canonicalizer = new Canonicalizer();
    $canonical = $canonicalizer->canonicalize([
      'id' => 10,
      'settings' => [
        'id' => 'portable-setting-id',
        'secret' => 'remove-me',
      ],
    ], [
      'runtime_fields' => ['id'],
      'sensitive_fields' => ['settings.secret'],
    ]);

    self::assertArrayNotHasKey('id', $canonical);
    self::assertSame('portable-setting-id', $canonical['settings']['id']);
    self::assertArrayNotHasKey('secret', $canonical['settings']);
  }

  public function testOperationalMetadataDoesNotChangeContentHash(): void {
    $canonicalizer = new Canonicalizer();
    $base = [
      'type' => 'example.item',
      'item' => ['name' => 'alpha', 'label' => 'Alpha'],
    ];
    $withMetadata = $base + [
      'schema_version' => 99,
      'key' => 'name=alpha',
      'dependencies' => [['type' => 'extension', 'name' => 'provider']],
      'capabilities' => ['create' => TRUE, 'update' => TRUE, 'delete' => FALSE],
      'identity_confidence' => 'EXPLICIT',
      'identity_portable' => TRUE,
      'monitor_only' => FALSE,
      'ambiguity' => [
        'reason' => 'duplicate_portable_identity',
        'group_count' => 2,
        'content_count' => 1,
        'content_fingerprint' => str_repeat('a', 64),
        'occurrence' => 1,
      ],
      'config_index' => [['api' => 'api4', 'entity' => 'Example', 'count' => 1]],
    ];

    self::assertSame($canonicalizer->hash($base), $canonicalizer->hash($withMetadata));
  }

  public function testFinalWildcardRemovesEntriesInsteadOfReplacingThemWithNull(): void {
    $canonicalizer = new Canonicalizer();
    $canonical = $canonicalizer->canonicalize([
      'items' => [
        ['name' => 'one', 'runtime' => ['first' => 1, 'second' => 2]],
      ],
    ], [
      'runtime_fields' => ['items.*.runtime.*'],
    ]);

    self::assertSame([], $canonical['items'][0]['runtime']);
  }

  public function testLineEndingsAreCanonicalized(): void {
    $canonicalizer = new Canonicalizer();

    self::assertSame(
      $canonicalizer->hash(['body' => "one\ntwo\n"]),
      $canonicalizer->hash(['body' => "one\r\ntwo\r\n"])
    );
  }

  public function testIdentityMetadataDoesNotChangePortableContentHash(): void {
    $service = new Canonicalizer();
    $first = [
      'type' => 'message_template',
      'identity_key' => 'workflow_name=receipt|is_default=0',
      'identity_confidence' => 'API_VERIFIED',
      'template' => ['workflow_name' => 'receipt', 'msg_subject' => 'Hello'],
    ];
    $second = $first;
    $second['identity_key'] = 'different-operational-identity-metadata';

    self::assertSame($service->hash($first), $service->hash($second));
  }

  public function testPrecanonicalizedHashMatchesNormalHash(): void {
    $canonicalizer = new Canonicalizer();
    $data = [
      'type' => 'example.item',
      'item' => [
        'name' => 'alpha',
        'body' => str_repeat('Portable body ', 50),
      ],
    ];

    $canonical = $canonicalizer->canonicalize($data);
    self::assertSame($canonicalizer->hash($data), $canonicalizer->hashCanonical($canonical));
  }

}
