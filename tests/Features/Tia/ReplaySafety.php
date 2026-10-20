<?php

declare(strict_types=1);

use Pest\Plugins\Tia\ChangedFiles;
use Pest\Plugins\Tia\Fingerprint;
use Pest\Plugins\Tia\Graph;
use Pest\Plugins\Tia\Recorder;
use Pest\Plugins\Tia\ResultCollector;
use Pest\Plugins\Tia\SourceScope;
use Pest\Plugins\Tia\WatchPatterns;
use Tests\Fixtures\Tia\Project;

afterEach(function (): void {
    Project::destroyAll();
});

test('a freshly downloaded graph must have an ancestor recording commit', function (array $arguments): void {
    $project = Project::make();
    $project->seed('master');
    $payload = $project->detachGraph();
    $project->git()->run(['checkout', '--orphan', 'unrelated']);
    $project->write('tests/Unit/CalculatorTest.php', "<?php test('adds two numbers', function () { expect(false)->toBeTrue(); });");
    $project->git()->commit('Create unrelated history with a broken consumer');
    $environment = $project->gh('ok', $payload);
    $result = $project->pestWithEnvironment($project->path(), $environment, '--tia', '--baselined', ...$arguments);
    expect($result->exitCode)->not->toBe(0, $result->describe())
        ->and($result->replayed())->toBe(0, $result->describe());
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('a worker crash cannot advance a recording or replay its old passes', function (): void {
    $project = Project::make();
    $project->seed('master');
    $sha = $project->git()->sha();
    $project->write('tests/Unit/CalculatorTest.php', "<?php test('adds two numbers', function () { exit(23); });");
    $project->git()->commit('Crash an affected worker');
    $first = $project->pest('--tia', '--parallel', '--processes=2');
    $second = $project->pest('--tia', '--parallel', '--processes=2');
    expect($first->exitCode)->not->toBe(0, $first->describe())
        ->and($second->exitCode)->not->toBe(0, $second->describe())
        ->and($project->graph()['baselines']['master']['sha'])->toBe($sha);
})->skipOnWindows();

test('a retried pass leaves a receipt that must execute again', function (): void {
    $collector = new ResultCollector;
    $collector->testPrepared('retry', 'tests/RetryTest.php');
    $collector->testRetried();
    $collector->testPassed();
    expect($collector->all()['retry']['status'])->toBe(-1);
});

test('failed execution leaves the recording commit unchanged', function (array $arguments): void {
    $project = Project::make();
    $project->seed('master');
    $sha = $project->git()->sha();
    $project->write('tests/Unit/CalculatorTest.php', "<?php test('adds two numbers', function () { expect(false)->toBeTrue(); });");
    $project->git()->commit('Break an affected test');
    $result = $project->pest('--tia', ...$arguments);
    expect($result->exitCode)->not->toBe(0, $result->describe())
        ->and($project->graph()['baselines']['master']['sha'])->toBe($sha);
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('a full run without coverage does not advance a stale graph', function (): void {
    $project = Project::make();
    $project->seed('master');
    $sha = $project->git()->sha();
    $project->write('app/Unrecorded.php', '<?php class Unrecorded {}');
    $project->git()->commit('Add unrecorded PHP');
    $project->write('php-ini/no-coverage.ini', "pcov.enabled=0\n");
    $result = $project->pestWithEnvironment($project->path(), [
        'PHP_INI_SCAN_DIR' => PHP_CONFIG_FILE_SCAN_DIR.PATH_SEPARATOR.$project->path('php-ini'),
        'XDEBUG_MODE' => 'off',
    ], '--tia');
    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($project->graph()['baselines']['master']['sha'])->toBe($sha);
})->skipOnWindows();

test('raw fixtures are tracked and explicit consumer watches keep them narrow', function (bool $explicitWatch): void {
    $project = Project::make();
    $project->write('tests/data/value.json', 'true');
    $project->write('tests/Unit/CalculatorTest.php', "<?php test('adds two numbers', function () { expect(file_get_contents(__DIR__.'/../data/value.json'))->toBe('true'); });");
    if ($explicitWatch) {
        $project->write('tests/Pest.php', "<?php pest()->tia()->watch(['tests/data/*.json' => 'tests/Unit/CalculatorTest.php']);");
    }
    $project->git()->commit('Add a raw fixture');
    $project->seed('master');
    $project->write('tests/data/value.json', 'false');
    $result = $project->pest('--tia');
    expect($result->exitCode)->toBe(1, $result->describe())
        ->and($result->affected())->toBe($explicitWatch ? 1 : Project::TOTAL_TESTS - 1, $result->describe());
})->with([false, true])->skipOnWindows();

test('branch recordings cannot replace another branch dependency edges', function (): void {
    if (! new Recorder()->driverAvailable()) {
        $this->markTestSkipped('A coverage driver is required.');
    }
    $project = Project::make();
    $record = $project->pest('--tia', '--fresh');
    expect($record->exitCode)->toBe(0, $record->describe());
    $project->git()->switchTo('branch-a', new: true);
    $project->write('tests/Unit/CalculatorTest.php', <<<'PHP'
    <?php
    test('adds two numbers', function () { expect((new \Fixture\App\Greeter)->greet('Nuno'))->toBe('Hello, Nuno!'); });
    test('subtracts two numbers', function () { expect((new \Fixture\App\Greeter)->greet('Nuno'))->toBe('Hello, Nuno!'); });
    PHP);
    $project->git()->commit('Replace calculator edges on branch A');
    $record = $project->pest('--tia');
    expect($record->exitCode)->toBe(0, $record->describe());
    $project->git()->switchTo('master');
    $project->git()->switchTo('branch-b', new: true);
    $calculator = file_get_contents($project->path('app/Calculator.php'));
    $project->write('app/Calculator.php', str_replace(['$a + $b', '$a - $b'], ['0', '0'], $calculator));
    $project->git()->commit('Break calculator on branch B');
    $result = $project->pest('--tia');
    expect($result->exitCode)->toBe(1, $result->describe())
        ->and($result->tally())->toContain('4 failed');
})->skipOnWindows();

test('flaky retry passes execute again on an unchanged tree', function (): void {
    if (! new Recorder()->driverAvailable()) {
        $this->markTestSkipped('A coverage driver is required.');
    }
    $project = Project::make();
    $project->write('runtime/.gitignore', "*\n");
    $project->write('tests/Unit/RetryTest.php', <<<'PHP'
    <?php
    $GLOBALS['attempt'] = 0;
    test('retries', function () {
        file_put_contents(__DIR__.'/../../runtime/runs', '.', FILE_APPEND);
        expect(++$GLOBALS['attempt'])->toBeGreaterThan(1);
    })->flaky(2);
    PHP);
    $project->git()->commit('Add a retried test');
    $first = $project->pest('--tia', '--fresh');
    $second = $project->pest('--tia');
    expect($first->exitCode)->toBe(0, $first->describe())
        ->and($second->exitCode)->toBe(0, $second->describe())
        ->and(file_get_contents($project->path('runtime/runs')))->toBe('....');
})->skipOnWindows();

test('Composer path packages contribute scope, Git changes, metadata, and runtime edges', function (): void {
    if (! new Recorder()->driverAvailable()) {
        $this->markTestSkipped('A coverage driver is required.');
    }
    $project = Project::make();
    $root = $project->nested('apps/cli');
    $project->write('apps/cli/composer.json', json_encode(['repositories' => [['type' => 'path', 'url' => '../../packages/php-sdk']]]));
    $project->write('packages/php-sdk/composer.json', '{"name":"fixture/sdk"}');
    $project->write('packages/php-sdk/src/Value.php', "<?php class SdkValue { public static function value(): string { return 'expected'; } }");
    $project->write('apps/cli/tests/Unit/SdkTest.php', <<<'PHP'
    <?php
    test('reads the SDK', function () {
        require_once dirname(__DIR__, 4).'/packages/php-sdk/src/Value.php';
        expect(SdkValue::value())->toBe('expected');
    });
    PHP);
    $project->git()->commit('Add a Composer path package');
    $project->seedFor($root, 'master', sentinel: false);
    $record = $project->pestIn($root, '--tia', '--fresh');
    expect($record->exitCode)->toBe(0, $record->describe());
    $sha = $project->git()->sha();
    $project->write('packages/php-sdk/src/Value.php', "<?php class SdkValue { public static function value(): string { return 'broken'; } }");
    $changes = new ChangedFiles($root)->since($sha);
    expect($changes)->toContain('../../packages/php-sdk/src/Value.php')
        ->and(SourceScope::fromProjectRoot($root)->contains($project->path('packages/php-sdk/src/Value.php')))->toBeTrue();
    $result = $project->pestIn($root, '--tia');
    expect($result->exitCode)->toBe(1, $result->describe())
        ->and($result->affected())->toBe(1, $result->describe());
    $before = Fingerprint::compute($root);
    $project->write('packages/php-sdk/composer.json', '{"name":"fixture/changed"}');
    expect(Fingerprint::structuralMatches($before, Fingerprint::compute($root)))->toBeFalse();
})->skipOnWindows();

test('environment drift clears inherited results as well as branch results', function (): void {
    $graph = new Graph(sys_get_temp_dir());
    $graph->setResult('main', 'inherited', 0, '', 0.1, 1, 'tests/InheritedTest.php');
    $graph->setResult('feature', 'own', 0, '', 0.1, 1, 'tests/OwnTest.php');
    $graph->clearResults('feature');
    expect($graph->getResult('feature', 'inherited'))->toBeNull()
        ->and($graph->getResult('feature', 'own'))->toBeNull();
});

test('reset removes explicit watch ownership before another suite configures defaults', function (): void {
    $watches = new WatchPatterns;
    $watches->add(['tests/Fixtures/**/*' => 'tests/OldConsumerTest.php']);
    $watches->reset();
    $watches->useDefaults(__DIR__);
    expect($watches->matchedDirectories(__DIR__, ['tests/Fixtures/input.json']))->toBe(['tests']);
});
