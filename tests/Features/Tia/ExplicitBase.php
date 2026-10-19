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

test('loaded test directory inputs widen the suite', function (string $loader, array $arguments): void {
    $xml = file_get_contents($this->project->path('phpunit.xml'));
    $this->project->write('phpunit.xml', str_replace('<directory suffix="Test.php">./tests</directory>', '<directory suffix="Test.php">./tests/Unit</directory><directory suffix="Test.php">./tests/Feature</directory>', $xml));
    $this->project->write('tests/Unit/Support/shared.php', "<?php define('FIXTURE_INPUT', true);\n");
    if ($loader !== 'pest') {
        $this->project->write('tests/bootstrap.php', "<?php require __DIR__.'/../vendor/autoload.php'; require __DIR__.'/Unit/Support/shared.php';\n");
        $xml = file_get_contents($this->project->path('phpunit.xml'));
        $this->project->write('phpunit.xml', str_replace('bootstrap="vendor/autoload.php"', 'bootstrap="tests/bootstrap.php"', $xml));
    } else {
        $pest = file_get_contents($this->project->path('tests/Pest.php'));
        $this->project->write('tests/Pest.php', $pest."\nrequire __DIR__.'/Unit/Support/shared.php';\n");
    }
    $this->project->write('tests/Unit/InputTest.php', "<?php test('loaded input is correct', fn () => expect(FIXTURE_INPUT)->toBeTrue());\n");
    $this->project->git()->commit('Load shared test input');
    $start = $this->project->git()->sha();
    $record = ($this->run)(['--fresh', ...$arguments]);
    expect($record->exitCode)->toBe(0, $record->describe());
    $changed = $loader === 'bootstrap' ? 'tests/bootstrap.php' : 'tests/Unit/Support/shared.php';
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
    $this->project->write('tests/Unit/TestCase.php', $base);
    $this->project->write('tests/Pest.php', file_get_contents($this->project->path('tests/Pest.php'))."\nrequire __DIR__.'/Unit/TestCase.php';\n");
    $this->project->write('tests/Unit/InputTest.php', "<?php uses(FixtureBase::class); test('provider input', fn (bool \$value) => expect(\$value)->toBeTrue())->with(FixtureBase::data());\n");
    $this->project->git()->commit('Add shared provider base');
    $start = $this->project->git()->sha();
    $record = ($this->run)(['--fresh', ...$arguments]);
    expect($record->exitCode)->toBe(0, $record->describe());
    $this->project->write('tests/Unit/TestCase.php', str_replace('[[true]]', '[[false]]', $base));

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

test('deleting a discovered config widens selection after a warm run', function (array $arguments, bool $configurationDirectory): void {
    $xml = file_get_contents($this->project->path('phpunit.xml'));
    $directory = $configurationDirectory ? 'conf/' : '';
    if ($configurationDirectory) {
        $xml = str_replace(['bootstrap="vendor/', './tests', './app'], ['bootstrap="../vendor/', '../tests', '../app'], $xml);
        $arguments = [...$arguments, '-c', 'conf'];
    }
    $this->project->write($directory.'phpunit.xml', str_replace('</phpunit>', '<php><env name="FIXTURE_CONFIG" value="original"/></php></phpunit>', $xml));
    $this->project->write($directory.'phpunit.xml.dist', str_replace('</phpunit>', '<php><env name="FIXTURE_CONFIG" value="fallback"/></php></phpunit>', $xml));
    $this->project->write('tests/Unit/ConfigTest.php', "<?php test('config is correct', fn () => expect(getenv('FIXTURE_CONFIG'))->toBe('original'));\n");
    $this->project->git()->commit('Track both discovered configs');
    $start = $this->project->git()->sha();
    $record = ($this->run)(['--fresh', ...$arguments]);
    expect($record->exitCode)->toBe(0, $record->describe());
    unlink($this->project->path($directory.'phpunit.xml'));
    $this->project->git()->commit('Remove higher priority config');
    $warm = ($this->run)($arguments);
    expect($warm->exitCode)->toBe(1, $warm->describe());

    $result = ($this->run)(['--tia-base='.$start, ...$arguments]);
    $full = $this->project->pest('--no-tia', ...$arguments);

    expect($result->exitCode)->toBe(1, $result->describe())
        ->and($result->tally())->toContain('1 failed', '6 passed')
        ->and($full->exitCode)->toBe(1, $full->describe());
})->with(Project::SEQUENTIAL_AND_PARALLEL)->with(['project root' => false, 'configuration directory' => true])->skipOnWindows();

test('a configured project bootstrap outside test paths widens selection', function (array $arguments, string $kind): void {
    $xml = file_get_contents($this->project->path('phpunit.xml'));
    $xml = $kind === 'main'
        ? str_replace('bootstrap="vendor/autoload.php"', 'bootstrap="bootstrap/testing.php"', $xml)
        : str_replace('<testsuite name="default">', '<testsuite name="default" bootstrap="bootstrap/testing.php">', $xml);
    $this->project->write('phpunit.xml', $xml);
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
})->with([
    'main sequential' => [[], 'main'],
    'main parallel' => [Project::SEQUENTIAL_AND_PARALLEL['parallel'][0], 'main'],
    'suite' => [[], 'suite'],
])->skipOnWindows();

test('a recorded baseline never makes configured test files startup inputs', function (string $selection, string $changed, int $count, array $arguments): void {
    $xml = file_get_contents($this->project->path('phpunit.xml'));
    $this->project->write('phpunit.xml', str_replace('</testsuites>', '<testsuite name="extra"><file>spec/Explicit.php</file><directory suffix=".spec.php" prefix="Check">spec</directory></testsuite></testsuites>', $xml));
    $this->project->write('spec/Explicit.php', "<?php test('explicit test', fn () => expect(1)->toBe(1));\n");
    $this->project->write('spec/CheckOther.spec.php', "<?php test('suffix test', fn () => expect(1)->toBe(1));\n");
    $greeter = file_get_contents($this->project->path('tests/Unit/GreeterTest.php'));
    $this->project->write('tests/Unit/GreeterTest.php', str_replace('});', "})->group('baseline');", $greeter));
    $this->project->git()->commit('Configure test files');
    if ($selection !== 'all') {
        $record = ($this->run)(['--fresh', ...$arguments]);
        expect($record->exitCode)->toBe(0, $record->describe());
    }
    if ($selection === 'tia') {
        $greeter = file_get_contents($this->project->path('tests/Unit/GreeterTest.php'));
        $this->project->write('tests/Unit/GreeterTest.php', str_replace("greet('Nuno')", "greet('Nuno'.'')", $greeter));
        $this->project->git()->commit('Baseline test change');
    }
    $selectionArguments = match ($selection) {
        'tia' => ['--tia-base=graph'],
        'filter' => ['--fresh', '--filter=greets'],
        'group' => ['--fresh', '--group=baseline'],
        'path' => ['--fresh', 'tests/Unit/GreeterTest.php'],
        default => ['--fresh'],
    };
    $baseline = ($this->run)([...$selectionArguments, ...$arguments]);
    expect($baseline->exitCode)->toBe(0, $baseline->describe())
        ->and($baseline->tally())->toContain($selection === 'all' ? '8 passed' : '2 passed');
    $start = $this->project->git()->sha();
    $test = file_get_contents($this->project->path($changed));
    $this->project->write($changed, str_replace('->toBe(', '->toBe(0 + ', $test));

    $result = ($this->run)(['--tia-base='.$start, ...$arguments]);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->tally())->toContain($count.' passed')
        ->and($result->affected())->toBe($count, $result->describe())
        ->and(array_intersect(array_keys($this->project->graph()['edges']), $this->project->graph()['pre_test_files']))->toBeEmpty();
})->with(['all', 'tia', 'filter', 'group', 'path'])->with([
    'directory' => ['tests/Unit/CalculatorTest.php', 2],
    'explicit file' => ['spec/Explicit.php', 1],
    'custom suffix' => ['spec/CheckOther.spec.php', 1],
])->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('test directory helpers loaded early or late widen in every process', function (string $timing, string $position, array $arguments): void {
    $this->project->write('tests/Support/shared.php', "<?php define('STARTUP_HELPER', true);\n");
    $this->project->write('runtime/.gitignore', "*\n");
    $this->project->write('runtime/assert-late', 'baseline');
    $pest = file_get_contents($this->project->path('tests/Pest.php'));
    $pest .= <<<'PHP'

    pest()->beforeEach(function (): void {
        $root = dirname(__DIR__);
        file_put_contents($root.'/runtime/worker-'.getmypid(), 'ready');
        if (getenv('TEST_TOKEN') !== false) {
            $deadline = microtime(true) + 10;
            while (count(glob($root.'/runtime/worker-*')) < 2 && microtime(true) < $deadline) {
                usleep(1000);
            }
            expect(count(glob($root.'/runtime/worker-*')))->toBeGreaterThanOrEqual(2);
        }
    })->in('Unit', 'Feature');
    PHP;
    $this->project->write('tests/Pest.php', $pest);
    for ($index = 0; $index < 6; $index++) {
        $this->project->write('tests/Unit/AWorker0'.$index.'Test.php', "<?php test('worker ".$index."', fn () => expect(true)->toBeTrue());\n");
    }
    $this->project->write('tests/Unit/ZuluTest.php', "<?php test('last worker', fn () => expect(true)->toBeTrue());\n");
    $file = $position === 'early' ? 'tests/Unit/AWorker00Test.php' : 'tests/Unit/ZuluTest.php';
    $test = file_get_contents($this->project->path($file));
    $load = "require_once __DIR__.'/../Support/shared.php';";
    if ($timing === 'beforeAll') {
        $load = 'beforeAll(function (): void { '.$load.' });';
    }
    if ($position === 'late') {
        $load = <<<'PHP'
        if (getenv('TEST_TOKEN') !== false && is_file(dirname(__DIR__, 2).'/runtime/assert-late') && ! is_file(dirname(__DIR__, 2).'/runtime/worker-'.getmypid())) {
            throw new RuntimeException('The helper file must not be the first file in its worker');
        }
        PHP;
        $load .= $timing === 'beforeAll'
            ? "beforeAll(function (): void { require_once __DIR__.'/../Support/shared.php'; });"
            : "require_once __DIR__.'/../Support/shared.php';";
    }
    $test = str_replace('<?php', '<?php '.$load, $test);
    $this->project->write($file, str_replace('expect(true)->toBeTrue()', 'expect(STARTUP_HELPER)->toBeTrue()', $test));
    $this->project->git()->commit('Require test directory helper');
    $baseline = ($this->run)(['--fresh', ...$arguments]);
    expect($baseline->exitCode)->toBe(0, $baseline->describe());
    $start = $this->project->git()->sha();
    $inputs = $this->project->graph()['pre_test_files'];
    unlink($this->project->path('runtime/assert-late'));
    foreach (glob($this->project->path('runtime/worker-*')) as $marker) {
        unlink($marker);
    }
    $this->project->write('tests/Support/shared.php', "<?php define('STARTUP_HELPER', false);\n");

    $result = ($this->run)(['--tia-base='.$start, ...$arguments]);

    expect($result->exitCode)->toBe(1, $result->describe())
        ->and($result->tally())->toContain('1 failed', '12 passed')
        ->and($result->affected())->toBe(13, $result->describe())
        ->and($inputs)->toContain('tests/Support/shared.php');
})->with(['discovery', 'beforeAll'])->with(['early', 'late'])->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('php suffix suites keep loaded support files as startup inputs', function (string $changed, array $arguments): void {
    $xml = file_get_contents($this->project->path('phpunit.xml'));
    $xml = str_replace('suffix="Test.php"', 'suffix=".php"', $xml);
    $this->project->write('phpunit.xml', str_replace('bootstrap="vendor/autoload.php"', 'bootstrap="tests/bootstrap.php"', $xml));
    $this->project->write('tests/bootstrap.php', "<?php require __DIR__.'/../vendor/autoload.php';\n");
    $this->project->write('tests/Helpers.php', "<?php function startupHelper(): int { return 1; }\n");
    $this->project->write('tests/TestCase.php', "<?php abstract class StartupTestCase extends \\PHPUnit\\Framework\\TestCase {}\n");
    $pest = file_get_contents($this->project->path('tests/Pest.php'));
    $this->project->write('tests/Pest.php', $pest."\nrequire_once __DIR__.'/TestCase.php';\n");
    $this->project->git()->commit('Configure php suffix support');
    $baseline = ($this->run)(['--fresh', ...$arguments]);
    expect($baseline->exitCode)->toBe(0, $baseline->describe());
    $start = $this->project->git()->sha();
    $this->project->write($changed, file_get_contents($this->project->path($changed))."\nconst STARTUP_CHANGED = true;\n");

    $result = ($this->run)(['--tia-base='.$start, ...$arguments]);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->affected())->toBe(6, $result->describe())
        ->and($this->project->graph()['pre_test_files'])->toContain($changed);
    if ($arguments !== []) {
        expect(json_decode(file_get_contents($this->project->graphDir().'/affected.json'), true))->not->toContain($changed);
    }
})->with(['tests/Pest.php', 'tests/Helpers.php', 'tests/TestCase.php', 'tests/bootstrap.php'])
    ->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('suite prefixes and exclusions do not turn support files into test files', function (string $kind, bool $loaded, array $arguments): void {
    $xml = file_get_contents($this->project->path('phpunit.xml'));
    $suite = '<testsuite name="support"><directory suffix="Test.php" prefix="Run">spec</directory><exclude>spec/RunExcludedTest.php</exclude></testsuite>';
    $this->project->write('phpunit.xml', str_replace('</testsuites>', $suite.'</testsuites>', $xml));
    $file = $kind === 'prefix' ? 'spec/HelperTest.php' : 'spec/RunExcludedTest.php';
    $this->project->write($file, "<?php const SUITE_SUPPORT = true;\n");
    $pest = file_get_contents($this->project->path('tests/Pest.php'));
    if ($loaded) {
        $this->project->write('tests/Pest.php', $pest."\nrequire_once __DIR__.'/../".$file."';\n");
    }
    $this->project->git()->commit('Configure support exclusion');
    $baseline = ($this->run)(['--fresh', ...$arguments]);
    expect($baseline->exitCode)->toBe(0, $baseline->describe());
    $start = $this->project->git()->sha();
    $this->project->write($file, "<?php const SUITE_SUPPORT = false;\n");
    if (! $loaded) {
        $greeter = file_get_contents($this->project->path('app/Greeter.php'));
        $this->project->write('app/Greeter.php', str_replace('$name);', 'trim($name));', $greeter));
    }

    $result = ($this->run)(['--tia-base='.$start, ...$arguments]);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->affected())->toBe($loaded ? 6 : 2, $result->describe());
    if ($arguments !== []) {
        expect(json_decode(file_get_contents($this->project->graphDir().'/affected.json'), true))->not->toContain($file);
    }
})->with(['prefix', 'exclude'])->with([true, false])->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('app files loaded while tests load stay on coverage edges', function (array $arguments): void {
    $this->project->write('app/Status.php', "<?php enum StartupStatus { case Ready; }\n");
    $pest = file_get_contents($this->project->path('tests/Pest.php'));
    $this->project->write('tests/Pest.php', $pest."\nrequire_once __DIR__.'/../app/Status.php';\n");
    $this->project->git()->commit('Load app files during discovery');
    $baseline = ($this->run)(['--fresh', ...$arguments]);
    expect($baseline->exitCode)->toBe(0, $baseline->describe());
    $inputs = $this->project->graph()['pre_test_files'];
    expect($inputs)->not->toContain('app/Calculator.php')->not->toContain('app/Status.php');
    $start = $this->project->git()->sha();
    $source = file_get_contents($this->project->path('app/Calculator.php'));
    $this->project->write('app/Calculator.php', str_replace('$a + $b', '$a + $b + 0', $source));

    $result = ($this->run)(['--tia-base='.$start, ...$arguments]);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->affected())->toBe(4, $result->describe());
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('filtered parallel recordings retain discovery helpers from excluded tests', function (array $arguments): void {
    $this->project->write('tests/Support/excluded.php', "<?php const EXCLUDED_SUPPORT = true;\n");
    $test = file_get_contents($this->project->path('tests/Unit/CalculatorTest.php'));
    $this->project->write('tests/Unit/CalculatorTest.php', str_replace('declare(strict_types=1);', "declare(strict_types=1); require_once __DIR__.'/../Support/excluded.php';", $test));
    $this->project->git()->commit('Load support from a filtered test');
    $warm = ($this->run)(['--fresh', ...$arguments]);
    expect($warm->exitCode)->toBe(0, $warm->describe());
    $this->project->mutateGraph(function (array $graph): array {
        $graph['pre_test_files'] = [];

        return $graph;
    });
    $greeter = file_get_contents($this->project->path('app/Greeter.php'));
    $this->project->write('app/Greeter.php', str_replace('$name);', 'trim($name));', $greeter));
    $baseline = ($this->run)(['--tia-base=graph', ...$arguments]);
    expect($baseline->exitCode)->toBe(0, $baseline->describe())
        ->and($baseline->affected())->toBe(2, $baseline->describe())
        ->and($this->project->graph()['pre_test_files'])->toContain('tests/Support/excluded.php');
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();
