<?php

declare(strict_types=1);

use Pest\Plugins\Tia;
use Pest\Plugins\Tia\Fingerprint;
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
})->with(['composer.lock', 'composer.json', 'phpunit.xml', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb', 'tsconfig.json', 'tsconfig.app.json', 'jsconfig.json', 'vite.config.ts'])->skipOnWindows();

test('a tracked fingerprint input cannot be hidden by gitignore', function (): void {
    $this->project->write('composer.lock', '{}');
    $this->project->git()->commit('Track the lockfile');
    $this->project->write('.gitignore', file_get_contents($this->project->path('.gitignore'))."\n/composer.lock\n");
    $this->project->git()->commit('Ignore untracked lockfiles');
    $this->project->seed('master');
    $start = $this->project->git()->sha();
    $this->project->git()->switchTo('implementation', new: true);
    $this->project->write('composer.lock', "{}\n");
    $this->project->git()->commit('Change the tracked lockfile');
    $warm = ($this->run)([]);
    expect($warm->exitCode)->toBe(0, $warm->describe());

    $result = ($this->run)(['--tia-base='.$start]);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->tally())->toContain('6 passed')
        ->and($result->replayed())->toBe(0, $result->describe());
})->skipOnWindows();

test('a bootstrap change widens explicit selection', function (string $base): void {
    $record = ($this->run)(['--fresh']);
    expect($record->exitCode)->toBe(0, $record->describe());
    $start = $this->project->git()->sha();
    $contents = file_get_contents($this->project->path('tests/Pest.php'));
    $this->project->write('tests/Pest.php', $contents."\nexpect()->extend('fixtureTrue', fn () => true);\n");

    $result = ($this->run)(['--tia-base='.($base === 'graph' ? 'graph' : $start)]);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->tally())->toContain('6 passed')
        ->and($result->replayed())->toBe(0, $result->describe());
})->with(['graph', 'commit'])->skipOnWindows();

test('a bootstrap change widens partial recorded edges', function (string $base): void {
    $contents = file_get_contents($this->project->path('tests/Pest.php'));
    $this->project->write('tests/Pest.php', $contents."\nfunction fixtureAssert(): void { expect(true)->toBeTrue(); }\n");
    $test = file_get_contents($this->project->path('tests/Unit/CalculatorTest.php'));
    $this->project->write('tests/Unit/CalculatorTest.php', str_replace('    expect(', "    fixtureAssert();\n    expect(", $test));
    $this->project->git()->commit('Use a bootstrap helper in one test file');
    $record = ($this->run)(['--fresh']);
    expect($record->exitCode)->toBe(0, $record->describe());
    $start = $this->project->git()->sha();
    $this->project->write('tests/Pest.php', $contents."\nfunction fixtureAssert(): void { expect(1)->toBe(1); }\n");

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

test('loaded test path inputs widen the suite before any test executes', function (string $loader, array $arguments): void {
    $xml = file_get_contents($this->project->path('phpunit.xml'));
    $this->project->write('phpunit.xml', str_replace('<directory suffix="Test.php">./tests</directory>', '<directory suffix="Test.php">./tests/Unit</directory><directory suffix="Test.php">./tests/Feature</directory>', $xml));
    $this->project->write('tests/Support/shared.php', "<?php define('FIXTURE_INPUT', true);\n");
    if ($loader !== 'pest') {
        $this->project->write('tests/bootstrap.php', "<?php require __DIR__.'/../vendor/autoload.php'; require __DIR__.'/Support/shared.php';\n");
        $xml = file_get_contents($this->project->path('phpunit.xml'));
        $this->project->write('phpunit.xml', str_replace('bootstrap="vendor/autoload.php"', 'bootstrap="tests/bootstrap.php"', $xml));
    } else {
        $pest = file_get_contents($this->project->path('tests/Pest.php'));
        $this->project->write('tests/Pest.php', $pest."\nrequire __DIR__.'/Support/shared.php';\n");
    }
    $this->project->write('tests/Unit/InputTest.php', "<?php test('loaded input is correct', fn () => expect(FIXTURE_INPUT)->toBeTrue());\n");
    $this->project->git()->commit('Load shared test input');
    $start = $this->project->git()->sha();
    $record = ($this->run)(['--fresh', ...$arguments]);
    expect($record->exitCode)->toBe(0, $record->describe());
    $changed = $loader === 'bootstrap' ? 'tests/bootstrap.php' : 'tests/Support/shared.php';
    $this->project->write($changed, $loader === 'bootstrap'
        ? "<?php require __DIR__.'/../vendor/autoload.php'; define('FIXTURE_INPUT', false);\n"
        : "<?php define('FIXTURE_INPUT', false);\n");

    $result = ($this->run)(['--tia-base='.$start, ...$arguments]);
    $full = $this->project->pest('--no-tia', ...$arguments);

    expect($result->exitCode)->toBe(1, $result->describe())
        ->and($result->tally())->toContain('1 failed', '6 passed')
        ->and($full->exitCode)->toBe(1, $full->describe());
})->with(['bootstrap', 'phpunit', 'pest'])->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('the actual phpunit config is a fingerprint input', function (string $config, array $arguments): void {
    $this->project->git()->run(['mv', 'phpunit.xml', $config]);
    $this->project->git()->commit('Choose configuration');
    $start = $this->project->git()->sha();
    $record = ($this->run)(['--fresh', ...$arguments]);
    expect($record->exitCode)->toBe(0, $record->describe());
    $this->project->write($config, str_replace('colors="true"', 'colors="false"', file_get_contents($this->project->path($config))));
    $this->project->git()->commit('Change loaded configuration');
    $warm = ($this->run)($arguments);
    expect($warm->exitCode)->toBe(0, $warm->describe())
        ->and($warm->tally())->toContain('6 passed')
        ->and($warm->output)->toContain('PHPUnit config changed');

    $result = ($this->run)(['--tia-base='.$start, ...$arguments]);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->tally())->toContain('6 passed')
        ->and($result->replayed())->toBe(0, $result->describe());
})->with([
    'default' => ['phpunit.dist.xml', []],
    'custom' => ['phpunit.guidance.xml', ['-c', 'phpunit.guidance.xml']],
    'custom equals' => ['phpunit.guidance.xml', ['--configuration=phpunit.guidance.xml']],
])->skipOnWindows();

test('tracked ignored source files remain affected', function (): void {
    $this->project->write('.gitignore', file_get_contents($this->project->path('.gitignore'))."\n/app/Calculator.php\n");
    $this->project->git()->commit('Ignore untracked source');
    $this->project->seed('master');
    $start = $this->project->git()->sha();
    $source = file_get_contents($this->project->path('app/Calculator.php'));
    $this->project->write('app/Calculator.php', str_replace('$a + $b', '$a + $b + 0', $source));

    $result = ($this->run)(['--tia-base='.$start]);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->affected())->toBe(4, $result->describe());
})->skipOnWindows();

test('an indexed ignored composer input changes the fingerprint', function (string $path): void {
    if ($path === 'composer.lock') {
        $this->project->write($path, '{}');
        $this->project->git()->commit('Track lockfile');
    }
    $this->project->write('.gitignore', file_get_contents($this->project->path('.gitignore'))."\n/".$path."\n");
    $this->project->git()->commit('Ignore untracked manifests');
    $record = ($this->run)(['--fresh']);
    expect($record->exitCode)->toBe(0, $record->describe());
    $manifest = json_decode(file_get_contents($this->project->path($path)), true);
    $manifest['autoload']['files'] = ['app/Calculator.php'];
    $this->project->write($path, json_encode($manifest, JSON_PRETTY_PRINT));

    $result = ($this->run)(['--tia-base=graph']);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->tally())->toContain('6 passed');
})->with(['composer.json', 'composer.lock'])->skipOnWindows();

test('changed submodule pointers widen explicit selection', function (): void {
    $module = Project::make('master');
    $this->project->git()->run(['-c', 'protocol.file.allow=always', 'submodule', 'add', $module->path(), 'dependency']);
    $this->project->git()->commit('Add dependency submodule');
    $record = ($this->run)(['--fresh']);
    expect($record->exitCode)->toBe(0, $record->describe());
    $before = $this->project->graph()['fingerprint'];
    expect($before['structural']['submodules'])->not->toBeNull()
        ->and($before['structural'])->not->toHaveKey('gitlinks');
    $start = $this->project->git()->sha();
    $module->git()->commit('New dependency version');
    $sha = $module->git()->sha();
    $this->project->git()->run(['-C', 'dependency', 'fetch', 'origin']);
    $this->project->git()->run(['-C', 'dependency', 'checkout', $sha]);
    $this->project->git()->commit('Update dependency pointer');
    $after = Fingerprint::compute($this->project->path());
    expect(Fingerprint::structuralDrift($before, $after))->toBe(['submodules']);
    $warm = ($this->run)([]);
    expect($warm->exitCode)->toBe(0, $warm->describe())
        ->and($warm->output)->toContain('Submodule pointer changed');

    $result = ($this->run)(['--tia-base='.$start]);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->tally())->toContain('6 passed');
})->skipOnWindows();

test('recorded startup inputs do not widen ordinary source or individual test edits', function (string $path, int $count): void {
    $record = ($this->run)(['--fresh']);
    expect($record->exitCode)->toBe(0, $record->describe());
    $start = $this->project->git()->sha();
    $source = file_get_contents($this->project->path($path));
    $this->project->write($path, str_replace(['$a + $b', 'toBe(3)'], ['$a + $b + 0', 'toBe(3 + 0)'], $source));

    $result = ($this->run)(['--tia-base='.$start]);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->affected())->toBe($count, $result->describe());
})->with(['source' => ['app/Calculator.php', 4], 'test' => ['tests/Unit/CalculatorTest.php', 2]])->skipOnWindows();

test('shared test base data providers are recorded as startup inputs', function (array $arguments): void {
    $base = "<?php abstract class FixtureBase extends \PHPUnit\Framework\TestCase { public static function data(): array { return [[true]]; } }\n";
    $xml = file_get_contents($this->project->path('phpunit.xml'));
    $this->project->write('phpunit.xml', str_replace('<directory suffix="Test.php">./tests</directory>', '<directory suffix="Test.php">./tests/Unit</directory><directory suffix="Test.php">./tests/Feature</directory>', $xml));
    $this->project->write('tests/TestCase.php', $base);
    $this->project->write('tests/Pest.php', file_get_contents($this->project->path('tests/Pest.php'))."\nrequire __DIR__.'/TestCase.php';\n");
    $this->project->write('tests/Unit/InputTest.php', "<?php uses(FixtureBase::class); test('provider input', fn (bool \$value) => expect(\$value)->toBeTrue())->with(FixtureBase::data());\n");
    $this->project->git()->commit('Add shared provider base');
    $start = $this->project->git()->sha();
    $record = ($this->run)(['--fresh', ...$arguments]);
    expect($record->exitCode)->toBe(0, $record->describe());
    $this->project->write('tests/TestCase.php', str_replace('[[true]]', '[[false]]', $base));

    $result = ($this->run)(['--tia-base='.$start, ...$arguments]);

    expect($result->exitCode)->toBe(1, $result->describe())
        ->and($result->tally())->toContain('1 failed', '6 passed');
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('an ignored active config is still fingerprinted', function (): void {
    $this->project->git()->run(['mv', 'phpunit.xml', 'phpunit.dist.xml']);
    $this->project->git()->commit('Use distributed config');
    $this->project->write('.gitignore', file_get_contents($this->project->path('.gitignore'))."\n/phpunit.guidance.xml\n");
    $this->project->write('phpunit.guidance.xml', file_get_contents($this->project->path('phpunit.dist.xml')));
    $record = ($this->run)(['--fresh', '-c', 'phpunit.guidance.xml']);
    expect($record->exitCode)->toBe(0, $record->describe());
    $this->project->write('phpunit.guidance.xml', str_replace('colors="true"', 'colors="false"', file_get_contents($this->project->path('phpunit.guidance.xml'))));

    $result = ($this->run)(['--tia-base=graph', '-c', 'phpunit.guidance.xml']);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->tally())->toContain('6 passed');
})->skipOnWindows();

test('deleting a discovered config widens selection after a warm run', function (array $arguments): void {
    $xml = file_get_contents($this->project->path('phpunit.xml'));
    $this->project->write('phpunit.xml', str_replace('</phpunit>', '<php><env name="FIXTURE_CONFIG" value="original"/></php></phpunit>', $xml));
    $this->project->write('phpunit.xml.dist', str_replace('</phpunit>', '<php><env name="FIXTURE_CONFIG" value="fallback"/></php></phpunit>', $xml));
    $this->project->write('tests/Unit/ConfigTest.php', "<?php test('config is correct', fn () => expect(getenv('FIXTURE_CONFIG'))->toBe('original'));\n");
    $this->project->git()->commit('Track both discovered configs');
    $start = $this->project->git()->sha();
    $record = ($this->run)(['--fresh', ...$arguments]);
    expect($record->exitCode)->toBe(0, $record->describe());
    unlink($this->project->path('phpunit.xml'));
    $this->project->git()->commit('Remove higher priority config');
    $warm = ($this->run)($arguments);
    expect($warm->exitCode)->toBe(1, $warm->describe());

    $result = ($this->run)(['--tia-base='.$start, ...$arguments]);
    $full = $this->project->pest('--no-tia', ...$arguments);

    expect($result->exitCode)->toBe(1, $result->describe())
        ->and($result->tally())->toContain('1 failed', '6 passed')
        ->and($full->exitCode)->toBe(1, $full->describe());
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('a configured project bootstrap outside test paths widens selection', function (array $arguments): void {
    $xml = file_get_contents($this->project->path('phpunit.xml'));
    $this->project->write('phpunit.xml', str_replace('bootstrap="vendor/autoload.php"', 'bootstrap="bootstrap/testing.php"', $xml));
    $this->project->write('bootstrap/testing.php', "<?php require __DIR__.'/../vendor/autoload.php'; define('FIXTURE_INPUT', true);\n");
    $this->project->write('tests/Unit/InputTest.php', "<?php test('bootstrap is correct', fn () => expect(FIXTURE_INPUT)->toBeTrue());\n");
    $this->project->git()->commit('Configure project bootstrap');
    $start = $this->project->git()->sha();
    $record = ($this->run)(['--fresh', ...$arguments]);
    expect($record->exitCode)->toBe(0, $record->describe());
    $this->project->write('bootstrap/testing.php', "<?php require __DIR__.'/../vendor/autoload.php'; define('FIXTURE_INPUT', false);\n");

    $result = ($this->run)(['--tia-base='.$start, ...$arguments]);
    $full = $this->project->pest('--no-tia', ...$arguments);

    expect($result->exitCode)->toBe(1, $result->describe())
        ->and($result->tally())->toContain('1 failed', '6 passed')
        ->and($this->project->graph()['pre_test_files'])->toContain('bootstrap/testing.php')
        ->and($full->exitCode)->toBe(1, $full->describe());
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();
