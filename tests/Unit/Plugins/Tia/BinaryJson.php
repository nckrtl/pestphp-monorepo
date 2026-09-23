<?php

declare(strict_types=1);

use Pest\Plugins\Tia\BinaryJson;
use Pest\Plugins\Tia\Graph;

it('keeps existing UTF-8 graph JSON unchanged', function (): void {
    $value = ['schema' => 1, 'results' => ['café' => ['message' => "\0base64:not encoded"]]];
    $json = json_encode($value, JSON_UNESCAPED_SLASHES);

    expect(BinaryJson::encode($value))->toBe($json)
        ->and(BinaryJson::decode($json))->toBe($value);
});

it('preserves byte strings and distinct keys without changing ordinary metadata', function (): void {
    $value = [
        'schema' => 1,
        'fingerprint' => ['php' => '8.5.9'],
        'results' => [
            "\xc3(" => ['message' => "\xff\0"],
            "\xc4(" => ['message' => 'other bytes'],
            "\xef\xbf\xbd(" => ['message' => 'replacement character'],
            "\0base64:".base64_encode("\xc3(") => ['message' => 'literal prefix'],
            '%C3%28' => ['message' => 'literal escapes'],
        ],
        'list' => [0, null, true, 1.25, 'café'],
    ];
    $json = BinaryJson::encode($value);
    $stored = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect(BinaryJson::decode($json))->toBe($value)
        ->and($stored['schema'])->toBe(1)
        ->and($stored['fingerprint'])->toBe($value['fingerprint'])
        ->and($stored['results'])->toHaveCount(5);
});

it('rejects malformed encoded strings', function (): void {
    $json = json_encode(['binary_encoding' => 'base64-v1', 'message' => "\0base64:***"]);

    expect(BinaryJson::decode($json))->toBeNull()
        ->and(BinaryJson::decode('{'))->toBeNull();
});

it('does not disguise other serialization errors', function (): void {
    expect(BinaryJson::encode(['time' => INF]))->toBeFalse();
});

it('round trips binary graph identities, statuses, and messages', function (): void {
    $graph = new Graph(sys_get_temp_dir());
    $ids = ["Tests\\Bytes::test#\xc3(", "Tests\\Bytes::test#\xc4(", "Tests\\Bytes::test#\xef\xbf\xbd("];

    foreach ($ids as $index => $id) {
        $graph->setResult('main', $id, 1, "skip \xff", 0.25, $index + 1);
    }

    $json = $graph->encode();
    $decoded = Graph::decode($json, sys_get_temp_dir());

    expect(Graph::branchesIn($json))->toBe(['main']);

    foreach ($ids as $index => $id) {
        expect($decoded->getResult('main', $id)->isSkipped())->toBeTrue()
            ->and($decoded->getResult('main', $id)->message())->toBe("skip \xff")
            ->and($decoded->getAssertions('main', $id))->toBe($index + 1)
            ->and($decoded->getTime('main', $id))->toBe(0.25);
    }
});
