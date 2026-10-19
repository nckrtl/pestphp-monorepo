<?php

declare(strict_types=1);

use Pest\Plugins\Tia;
use Pest\Plugins\Tia\Recorder;
use Tests\Fixtures\Tia\Project;

beforeEach(function (): void {
    if (! new Recorder()->driverAvailable()) {
        $this->markTestSkipped('A coverage driver is required to refresh graph edges.');
    }

    $this->project = Project::make('master');
    $this->project->write('php-ini/99-pcov.ini', "pcov.directory=/\npcov.enabled=1\n");
    $this->run = fn (array $arguments) => $this->project->pestWithEnvironment(
        $this->project->path(),
        ['PHP_INI_SCAN_DIR' => (getenv('PHP_INI_SCAN_DIR') ?: PHP_CONFIG_FILE_SCAN_DIR).PATH_SEPARATOR.$this->project->path('php-ini')],
        '--tia',
        ...$arguments,
    );
});

afterEach(function (): void {
    Project::destroyAll();
});

test('an explicit base ignores a dirty branch baseline and cached failures', function (array $arguments, string $form): void {
    $this->project->seed('master');
    $start = $this->project->git()->sha();
    $this->project->git()->switchTo('implementation', new: true);
    $source = file_get_contents($this->project->path('app/Calculator.php'));
    $this->project->write('app/Calculator.php', str_replace('$a + $b', '$a + $b + 0', $source));
    $this->project->git()->commit('Change calculator');
    $this->project->write('app/Calculator.php', str_replace('$a + $b', '$a + $b + 0 + 0', $source));
    $ownRun = ($this->run)($arguments);
    expect($ownRun->exitCode)->toBe(0, $ownRun->describe());
    $this->project->mutateGraph(function (array $graph): array {
        foreach ($graph['baselines']['implementation']['results'] as &$result) {
            if (($result['file'] ?? null) === 'tests/Unit/GreeterTest.php') {
                $result['status'] = 7;
            }
        }

        return $graph;
    });

    $result = ($this->run)([...$arguments, '--tia-base='.($form === 'graph' ? 'graph' : $start)]);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->affected())->toBe(4, $result->describe())
        ->and($result->replayed())->toBe(0, $result->describe())
        ->and($result->tally())->toContain('4 passed');
})->with(Project::SEQUENTIAL_AND_PARALLEL)->with(['graph', 'commit'])->skipOnWindows();

test('the commit form excludes earlier changes that the graph form must cover', function (string $form, int $expected): void {
    $this->project->seed('master');
    $greeter = file_get_contents($this->project->path('app/Greeter.php'));
    $this->project->write('app/Greeter.php', str_replace('$name);', 'trim($name));', $greeter));
    $this->project->git()->commit('Earlier main change');
    $start = $this->project->git()->sha();
    $this->project->git()->switchTo('implementation', new: true);
    $calculator = file_get_contents($this->project->path('app/Calculator.php'));
    $this->project->write('app/Calculator.php', str_replace('$a + $b', '$a + $b + 0', $calculator));
    $this->project->git()->commit('Subtask change');

    $result = ($this->run)(['--tia-base='.($form === 'graph' ? 'graph' : $start)]);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->affected())->toBe($expected, $result->describe())
        ->and($result->replayed())->toBe(0, $result->describe());
})->with(['graph' => ['graph', 6], 'commit' => ['commit', 4]])->skipOnWindows();

test('an empty graph delta runs no tests despite cached failures', function (): void {
    $this->project->seed('master', failing: ['greets a person']);

    $result = ($this->run)(['--tia-base=graph']);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->output)->toContain('No affected tests found.');
})->skipOnWindows();

test('an explicit base includes untracked tests', function (array $arguments): void {
    $this->project->seed('master');
    $start = $this->project->git()->sha();
    $this->project->write('tests/Unit/NewTest.php', "<?php test('new test', fn () => expect(true)->toBeTrue());\n");

    $result = ($this->run)([...$arguments, '--tia-base='.$start]);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->tally())->toContain('1 passed')
        ->and($result->replayed())->toBe(0, $result->describe());
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('an explicit base records the full suite when no graph exists', function (array $arguments): void {
    $result = ($this->run)(['--tia-base=graph', ...$arguments]);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->tally())->toContain('6 passed')
        ->and($this->project->graph())->not->toBeNull();
})->with(['plain' => [[]], 'coverage' => [['--coverage']]])->skipOnWindows();

test('an invalid explicit commit fails instead of selecting an empty delta', function (): void {
    $this->project->seed('master');

    $result = ($this->run)(['--tia-base=not-a-commit']);

    expect($result->exitCode)->toBe(1, $result->describe())
        ->and($result->output)->toContain('--tia-base must name a reachable commit');
})->skipOnWindows();

test('the graph form records the full suite without a reachable default-branch commit', function (string $shape): void {
    $this->project->seed('master');
    $this->project->git()->switchTo('implementation', new: true);
    $calculator = file_get_contents($this->project->path('app/Calculator.php'));
    $this->project->write('app/Calculator.php', str_replace('$a + $b', '$a + $b + 0', $calculator));
    $this->project->git()->commit('Subtask change');
    $this->project->mutateGraph(function (array $graph) use ($shape): array {
        $graph['baselines']['master']['sha'] = $shape === 'missing' ? null : str_repeat('0', 40);

        return $graph;
    });

    $result = ($this->run)(['--tia-base=graph']);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->tally())->toContain('6 passed')
        ->and($result->replayed())->toBe(0, $result->describe());
})->with(['missing', 'unreachable'])->skipOnWindows();

test('the commit form needs no default-branch baseline', function (): void {
    $this->project->seed('implementation');
    $start = $this->project->git()->sha();
    $calculator = file_get_contents($this->project->path('app/Calculator.php'));
    $this->project->write('app/Calculator.php', str_replace('$a + $b', '$a + $b + 0', $calculator));
    $this->project->git()->commit('Subtask change');

    $result = ($this->run)(['--tia-base='.$start]);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->affected())->toBe(4, $result->describe());
})->skipOnWindows();

test('explicit selection applies to coverage runs', function (): void {
    $this->project->seed('master');
    $calculator = file_get_contents($this->project->path('app/Calculator.php'));
    $this->project->write('app/Calculator.php', str_replace('$a + $b', '$a + $b + 0', $calculator));

    $result = ($this->run)(['--tia-base=graph', '--coverage-php='.$this->project->path('coverage.php')]);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->affected())->toBe(4, $result->describe())
        ->and($result->tally())->toContain('4 passed')
        ->and($result->replayed())->toBe(0, $result->describe());
})->skipOnWindows();

test('explicit bases reject partial test selections', function (array $arguments): void {
    $this->project->seed('master');

    $result = ($this->run)(['--tia-base=graph', ...$arguments]);

    expect($result->exitCode)->toBe(1, $result->describe())
        ->and($result->output)->toContain('--tia-base cannot be combined with a partial test selection');
})->with(['path' => [['tests/Unit/CalculatorTest.php']], 'filter' => [['--filter=adds two numbers']]])->skipOnWindows();

test('the explicit base option enables TIA without the tia flag', function (): void {
    $this->project->seed('master');

    $result = $this->project->pest('--tia-base=graph');

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->output)->toContain('No affected tests found.');
})->skipOnWindows();

test('an empty explicit delta runs no tests with or without cached coverage', function (bool $warm): void {
    $this->project->seed('master');
    if ($warm) {
        $coverage = ($this->run)(['--coverage']);
        expect($coverage->exitCode)->toBe(0, $coverage->describe());
    }

    $result = ($this->run)(['--tia-base=graph', '--coverage']);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->output)->toContain('No affected tests found.');
})->with([false, true])->skipOnWindows();

test('explicit coverage excludes results from a warmed coverage cache', function (): void {
    $this->project->seed('master');
    $coverage = ($this->run)(['--coverage']);
    expect($coverage->exitCode)->toBe(0, $coverage->describe());
    $cachedCoverage = file_get_contents($this->project->graphDir().'/'.Tia::KEY_COVERAGE_CACHE);
    $calculator = file_get_contents($this->project->path('app/Calculator.php'));
    $this->project->write('app/Calculator.php', str_replace('$a + $b', '$a + $b + 0', $calculator));

    $result = ($this->run)(['--tia-base=graph', '--coverage', '--only-covered']);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->tally())->toContain('4 passed')
        ->and($result->output)->not->toContain('app/Greeter')
        ->and(file_get_contents($this->project->graphDir().'/'.Tia::KEY_COVERAGE_CACHE))->toBe($cachedCoverage);
})->skipOnWindows();

test('an explicit base cannot be disabled', function (): void {
    $result = ($this->run)(['--no-tia', '--tia-base=graph']);

    expect($result->exitCode)->toBe(1, $result->describe())
        ->and($result->output)->toContain('--no-tia cannot be combined with --tia-base');
})->skipOnWindows();

test('a commit base cannot become a git option', function (): void {
    $output = $this->project->path('git-output');

    $result = ($this->run)(['--tia-base=--output='.$output]);

    expect($result->exitCode)->toBe(1, $result->describe())
        ->and($result->output)->toContain('--tia-base must name a reachable commit')
        ->and(file_exists($output))->toBeFalse();
})->skipOnWindows();

test('a commit delta containing a fingerprint input records the full suite after an implementer warms the graph', function (string $path): void {
    $this->project->seed('master');
    $start = $this->project->git()->sha();
    $this->project->git()->switchTo('implementation', new: true);
    $contents = file_exists($this->project->path($path)) ? file_get_contents($this->project->path($path)) : '{}';
    $this->project->write($path, $contents."\n");
    $this->project->git()->commit('Change fingerprint input');
    $warm = ($this->run)([]);
    expect($warm->exitCode)->toBe(0, $warm->describe());

    $result = ($this->run)(['--tia-base='.$start]);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->tally())->toContain('6 passed')
        ->and($result->replayed())->toBe(0, $result->describe());
})->with(['composer.lock', 'phpunit.xml', 'phpunit.xml.dist', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb', 'tsconfig.json', 'tsconfig.app.json', 'jsconfig.json', 'vite.config.ts'])->skipOnWindows();

test('a bootstrap change widens explicit selection', function (string $base): void {
    $this->project->seed('master');
    $start = $this->project->git()->sha();
    $contents = file_get_contents($this->project->path('tests/Pest.php'));
    $this->project->write('tests/Pest.php', $contents."\nexpect()->extend('fixtureTrue', fn () => true);\n");

    $result = ($this->run)(['--tia-base='.($base === 'graph' ? 'graph' : $start)]);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->tally())->toContain('6 passed')
        ->and($result->replayed())->toBe(0, $result->describe());
})->with(['graph', 'commit'])->skipOnWindows();

test('an explicit commit follows renamed or deleted dependencies', function (bool $rename): void {
    $this->project->write('resources/views/greeting.blade.php', '<p>Hello</p>');
    $this->project->git()->commit('Add view');
    $this->project->seed('master');
    $this->project->mutateGraph(function (array $graph): array {
        $id = count($graph['files']);
        $graph['files'][$id] = 'resources/views/greeting.blade.php';
        $graph['edges']['tests/Unit/GreeterTest.php'][] = $id;

        return $graph;
    });
    $start = $this->project->git()->sha();
    if ($rename) {
        $this->project->git()->run(['mv', 'resources/views/greeting.blade.php', 'resources/views/hello.blade.php']);
    } else {
        unlink($this->project->path('resources/views/greeting.blade.php'));
    }
    $this->project->git()->commit('Move or delete dependency');

    $result = ($this->run)(['--tia-base='.$start]);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->affected())->toBe(2, $result->describe())
        ->and($result->replayed())->toBe(0, $result->describe());
})->with(['rename' => true, 'deletion' => false])->skipOnWindows();

test('an existing non ancestor commit is rejected', function (): void {
    $this->project->seed('master');
    $this->project->git()->switchTo('other', new: true);
    $this->project->git()->commit('Other history');
    $other = $this->project->git()->sha();
    $this->project->git()->switchTo('master');

    $result = ($this->run)(['--tia-base='.$other]);

    expect($result->exitCode)->toBe(1, $result->describe())
        ->and($result->output)->toContain('--tia-base must name a reachable commit');
})->skipOnWindows();

test('a shallow repository accepts its available base and rejects an unavailable parent', function (): void {
    $this->project->seed('master');
    $parent = $this->project->git()->sha();
    $this->project->git()->commit('Shallow boundary');
    $start = $this->project->git()->sha();
    $this->project->write('.git/shallow', $start."\n");
    $source = file_get_contents($this->project->path('app/Calculator.php'));
    $this->project->write('app/Calculator.php', str_replace('$a + $b', '$a + $b + 0', $source));

    $available = ($this->run)(['--tia-base='.$start]);
    $unavailable = ($this->run)(['--tia-base='.$parent]);

    expect($available->exitCode)->toBe(0, $available->describe())
        ->and($available->affected())->toBe(4, $available->describe())
        ->and($unavailable->exitCode)->toBe(1, $unavailable->describe())
        ->and($unavailable->output)->toContain('--tia-base must name a reachable commit');
})->skipOnWindows();
