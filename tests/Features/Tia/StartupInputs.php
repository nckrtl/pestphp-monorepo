<?php

declare(strict_types=1);

use Pest\Plugins\Tia\Graph;
use Pest\Plugins\Tia\Recorder;
use Pest\Plugins\Tia\TestFiles;
use Tests\Fixtures\Tia\Project;

afterEach(function (): void {
    Project::destroyAll();
});

test('loaded helpers remain edges of their consumers while harness inputs stay global', function (): void {
    $project = Project::make();
    $project->write('tests/Support/Helper.php', '<?php class Helper {}');
    $project->write('tests/TestCase.php', '<?php class FixtureBase {}');
    $project->write('tests/bootstrap.php', '<?php');
    $root = $project->path().'/';
    $files = new TestFiles($root, [$root.'tests/'], [], [$root.'tests/bootstrap.php']);
    $globals = $files->startupInputs([
        $root.'tests/Pest.php', $root.'tests/TestCase.php', $root.'tests/Support/Helper.php',
    ], []);
    expect($globals)->toBe(['tests/Pest.php', 'tests/TestCase.php', 'tests/bootstrap.php']);
    $graph = new Graph($project->path());
    $graph->replacePreTestFiles($globals, complete: true);
    $graph->link('tests/Unit/CalculatorTest.php', 'tests/Support/Helper.php');
    $graph->markKnownTestFiles(['tests/Unit/GreeterTest.php']);
    expect($graph->affected(['tests/Support/Helper.php']))->toBe(['tests/Unit/CalculatorTest.php'])
        ->and($graph->affected(['tests/bootstrap.php']))->toHaveCount(2)
        ->and($graph->affected(['tests/Pest.php']))->toHaveCount(2)
        ->and($graph->affected(['tests/TestCase.php']))->toHaveCount(2);
});

test('a runtime helper change selects its consumer without widening the suite', function (array $arguments): void {
    if (! new Recorder()->driverAvailable()) {
        $this->markTestSkipped('A coverage driver is required.');
    }
    $project = Project::make();
    $project->write('tests/Support/value.php', '<?php function helperValue(): bool { return true; }');
    $project->write('tests/ZZHelperTest.php', "<?php test('reads its helper', function () { require_once __DIR__.'/Support/value.php'; expect(helperValue())->toBeTrue(); });");
    $project->git()->commit('Add one helper consumer');
    $recording = $project->pest('--tia', '--fresh', ...$arguments);
    expect($recording->exitCode)->toBe(0, $recording->describe())
        ->and($project->graph()['pre_test_files'])->not->toContain('tests/Support/value.php');
    $project->write('tests/Support/value.php', '<?php function helperValue(): bool { return false; }');
    $result = $project->pest('--tia', '--tia-base=graph', ...$arguments);
    expect($result->exitCode)->toBe(1, $result->describe())
        ->and($result->affected())->toBe(1, $result->describe())
        ->and($result->tally())->toContain('1 failed');
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();
