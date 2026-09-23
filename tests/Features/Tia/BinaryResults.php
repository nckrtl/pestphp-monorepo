<?php

declare(strict_types=1);

use Tests\Fixtures\Tia\Project;

afterEach(function (): void {
    Project::destroyAll();
});

test('TIA saves and replays every binary dataset row', function (array $arguments): void {
    $project = Project::make('master');
    $project->write('tests/Unit/BinaryTest.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        test('accepts bytes', function (string $value): void {
            file_put_contents(__DIR__.'/../../executed', '.', FILE_APPEND);
            expect(strlen($value))->toBeGreaterThan(0);
        })->with(["\xc3(", "\xc4(", "\xef\xbf\xbd(", '%C3%28', 'café']);
        PHP);
    $project->git()->commit('binary dataset');

    $recorded = $project->pest('--tia', ...$arguments);

    expect($recorded->exitCode)->toBe(0, $recorded->describe())
        ->and($recorded->tally())->toContain('11 passed')
        ->and($project->graphExists())->toBeTrue()
        ->and(file_get_contents($project->path('executed')))->toBe('.....');

    $replayed = $project->pest('--tia', ...$arguments);

    expect($replayed->exitCode)->toBe(0, $replayed->describe())
        ->and($replayed->replayed())->toBe(11, $replayed->describe())
        ->and(file_get_contents($project->path('executed')))->toBe('.....');
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('TIA preserves binary skip messages through worker merging and replay', function (array $arguments): void {
    $project = Project::make('master');
    $project->write('tests/Unit/BinaryTest.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        test('skips binary input', function (): void {
            file_put_contents(__DIR__.'/../../executed', '.', FILE_APPEND);
            test()->markTestSkipped("unsupported \xff");
        });
        PHP);
    $project->git()->commit('binary skip message');

    $recorded = $project->pest('--tia', ...$arguments);
    $replayed = $project->pest('--tia', ...$arguments);

    expect($recorded->exitCode)->toBe(0, $recorded->describe())
        ->and($replayed->exitCode)->toBe(0, $replayed->describe())
        ->and($replayed->tally())->toContain('1 skipped', '6 passed')
        ->and($replayed->replayed())->toBe(7, $replayed->describe())
        ->and(file_get_contents($project->path('executed')))->toBe('.');
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('a completed worker replaces its forked child partial when dataset names contain bytes', function (): void {
    $project = Project::make('master');
    $project->write('tests/Unit/BinaryTest.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        test('forks and waits', function (): void {
            $pid = pcntl_fork();

            if ($pid === 0) {
                exit(0);
            }

            expect($pid)->toBeGreaterThan(0);
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        });

        test('accepts bytes', function (string $value): void {
            expect(strlen($value))->toBe(2);
        })->with(["\xc3("]);
        PHP);
    $project->git()->commit('fork followed by binary dataset');

    $recorded = $project->pest('--tia', '--parallel', '--processes=2');
    $replayed = $project->pest('--tia', '--parallel', '--processes=2');

    expect($recorded->exitCode)->toBe(0, $recorded->describe())
        ->and($project->graphExists())->toBeTrue()
        ->and($replayed->exitCode)->toBe(0, $replayed->describe())
        ->and($replayed->replayed())->toBe(8, $replayed->describe());
})->skip(! function_exists('pcntl_fork'), 'pcntl is required');
