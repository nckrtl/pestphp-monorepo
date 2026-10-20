<?php

use Pest\Plugins\Tia;
use Pest\Plugins\Tia\FileState;
use Pest\Plugins\Tia\Fingerprint;
use Pest\Plugins\Tia\Graph;
use Pest\Support\Str;
use Tests\Fixtures\Tia\Project;

it('does not run user hooks when replaying cached skipped and incomplete results', function (): void {
    $project = Project::make();
    try {
        $fixture = 'tests/ReplayTest.php';
        $project->write($fixture, (string) file_get_contents(__DIR__.'/../Fixtures/Suites/TiaReplayHooks.php'));
        $project->write('phpunit.xml', '<?xml version="1.0"?><phpunit bootstrap="vendor/autoload.php"><testsuites><testsuite name="replay"><file>'.$fixture.'</file></testsuite></testsuites></phpunit>');
        $project->git()->commit('Add replay hook fixture');
        $id = fn (string $description): string => 'P\\Tests\\ReplayTest::'.Str::evaluable($description);
        $graph = new Graph($project->path());
        $graph->setFingerprint(Fingerprint::compute($project->path()));
        $graph->setRecordedAtSha('master', $project->git()->sha());
        $graph->markKnownTestFiles([$fixture]);
        $graph->setResult('master', $id('replayed pass'), 0, '', 0.01, 1, $fixture);
        $graph->setResult('master', $id('replayed skip'), 1, 'cached skip', 0.01, 0, $fixture);
        $graph->setResult('master', $id('replayed incomplete'), 2, 'cached incomplete', 0.01, 0, $fixture);
        $json = $graph->encode();
        $storage = new FileState($project->graphDir());
        expect($json)->not->toBeNull()
            ->and($storage->write(Tia::KEY_GRAPH, (string) $json))->toBeTrue();
        $result = $project->pest('--tia');
        expect($result->output)->toContain('3 replayed')
            ->and($result->output)->not->toContain('must not run for replayed tests')
            ->and($result->output)->toContain('1 incomplete, 1 skipped, 1 passed')
            ->and($result->exitCode)->toBe(0);
    } finally {
        Project::destroyAll();
    }
})->skipOnWindows();
