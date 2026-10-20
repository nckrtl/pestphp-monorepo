<?php

declare(strict_types=1);

use Pest\Plugins\Tia\Recorder;
use PHPUnit\Framework\SkippedWithMessageException;
use Tests\Fixtures\Tia\Project;

beforeEach(function (): void {
    if (! new Recorder()->driverAvailable()) {
        $this->markTestSkipped('A coverage driver is required to record a fresh graph.');
    }

    $this->project = Project::make('master', 'precise-selection');
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

test('a provider change selects every test whose setup registers it', function (array $arguments): void {
    $recording = ($this->run)($arguments);
    expect($recording->exitCode)->toBe(0, $recording->describe());

    $graph = $this->project->graph();
    expect($graph)->not->toBeNull();
    $providerId = array_search('app/Providers/FixtureProvider.php', $graph['files'], true);
    expect($providerId)->not->toBeFalse()
        ->and($graph['edges']['tests/Feature/BindingTest.php'])->toContain($providerId)
        ->and($graph['edges']['tests/Feature/OrdersTest.php'])->toContain($providerId)
        ->and($graph['edges']['tests/Feature/UsersTest.php'])->toContain($providerId)
        ->and($graph['edges']['tests/Unit/CalculatorTest.php'])->not->toContain($providerId)
        ->and($graph['files'])->toContain('config/fixture.php');

    $this->project->git()->commit('Record provider fixture');
    $this->project->git()->switchTo('provider-change', new: true);
    $source = file_get_contents($this->project->path('app/Providers/FixtureProvider.php'));
    $this->project->write('app/Providers/FixtureProvider.php', str_replace("'booted'", "'booted again'", $source));

    $result = ($this->run)($arguments);
    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->affected())->toBe(6, $result->describe());
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('a setup-only extension registration selects its reflected container contract', function (array $arguments): void {
    $this->project->write('app/Extensions/FixtureExtension.php', <<<'PHP'
    <?php
    namespace TiaFixture;
    final class FixtureExtension
    {
        public static function register(FixtureContainer $container): void
        {
            $container->instance('artifacts', ['expected']);
        }
    }
    PHP);
    $pest = file_get_contents($this->project->path('tests/Pest.php'));
    $this->project->write('tests/Pest.php', $pest."\nrequire_once __DIR__.'/../app/Extensions/FixtureExtension.php';\n");
    $base = file_get_contents($this->project->path('tests/FixtureTestCase.stub'));
    $this->project->write('tests/FixtureTestCase.stub', str_replace("FixtureContainer::\$app->instance('db', \$this->database);", "FixtureContainer::\$app->instance('db', \$this->database);\n        FixtureExtension::register(FixtureContainer::\$app);", $base));
    $this->project->write('tests/Feature/BindingTest.php', <<<'PHP'
    <?php
    test('reflects registered artifacts', function (): void {
        $property = new ReflectionProperty(\TiaFixture\FixtureContainer::class, 'instances');
        expect($property->getValue(\TiaFixture\FixtureContainer::$app)['artifacts'])->toBe(['expected']);
    });
    PHP);
    $this->project->git()->commit('Register extension artifacts during setup');
    $start = $this->project->git()->sha();
    $recording = ($this->run)($arguments);
    expect($recording->exitCode)->toBe(0, $recording->describe());
    $source = file_get_contents($this->project->path('app/Extensions/FixtureExtension.php'));
    $this->project->write('app/Extensions/FixtureExtension.php', str_replace("['expected']", "['unexpected']", $source));

    $result = ($this->run)(['--tia-base='.$start, ...$arguments]);

    expect($result->exitCode)->toBe(1, $result->describe())
        ->and($result->tally())->toContain('1 failed', '9 passed')
        ->and($result->affected())->toBe(10, $result->describe());
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('setup failures finish coverage before the next test in the same worker', function (string $outcome, array $arguments): void {
    $source = file_get_contents($this->project->path('app/Providers/FixtureProvider.php'));
    $exception = '\\'.($outcome === 'error' ? RuntimeException::class : SkippedWithMessageException::class);
    $this->project->write('app/Providers/FixtureProvider.php', str_replace("return 'booted';", "throw new {$exception}('Setup stopped');", $source));
    $base = file_get_contents($this->project->path('tests/FixtureTestCase.stub'));
    $this->project->write('tests/FixtureTestCase.stub', str_replace('$provider = new FixtureProvider;', 'FixtureContainer::$app = new FixtureContainer; $provider = new FixtureProvider;', $base));
    $this->project->git()->commit('Stop during provider setup');
    $recording = ($this->run)($arguments);
    expect($recording->exitCode)->toBe($outcome === 'error' ? 2 : 0, $recording->describe());
    $graph = $this->project->graph();
    expect($graph)->not->toBeNull();
    $providerId = array_search('app/Providers/FixtureProvider.php', $graph['files'], true);
    $calculatorId = array_search('app/Calculator.php', $graph['files'], true);
    expect($providerId)->not->toBeFalse()
        ->and($calculatorId)->not->toBeFalse()
        ->and($graph['edges']['tests/Feature/BindingTest.php'])->toContain($providerId)
        ->and($graph['edges']['tests/Unit/CalculatorTest.php'])->toContain($calculatorId)
        ->and($graph['edges']['tests/Unit/CalculatorTest.php'])->not->toContain($providerId);
    $start = $this->project->git()->sha();
    $this->project->write('app/Providers/FixtureProvider.php', $source);

    $result = ($this->run)(['--tia-base='.$start, ...$arguments]);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->affected())->toBe(6, $result->describe())
        ->and($result->tally())->toContain('6 passed');
})->with(['error', 'skip'])->with([
    'sequential' => [[]],
    'one worker' => [['--parallel', '--processes=1']],
])->skipOnWindows();

test('a final aborted test retains its setup and teardown coverage', function (string $phase, string $outcome, array $arguments): void {
    $xml = file_get_contents($this->project->path('phpunit.xml'));
    $this->project->write('phpunit.xml', str_replace('<directory suffix="Test.php">./tests</directory>', '<file>tests/Feature/BindingTest.php</file>', $xml));
    $this->project->write('app/SetupCleanup.php', <<<'PHP'
    <?php
    namespace TiaFixture;
    final class SetupCleanup
    {
        public static function run(): void
        {
            file_put_contents(__DIR__.'/../cleanup-called', '.', FILE_APPEND);
        }
    }
    PHP);
    $pest = file_get_contents($this->project->path('tests/Pest.php'));
    $this->project->write('tests/Pest.php', $pest."\nrequire_once __DIR__.'/../app/SetupCleanup.php';\n");
    $base = file_get_contents($this->project->path('tests/FixtureTestCase.stub'));
    $base = str_replace('$provider = new FixtureProvider;', 'FixtureContainer::$app = new FixtureContainer; $provider = new FixtureProvider;', $base);
    $this->project->write('tests/FixtureTestCase.stub', str_replace('protected function setUp(): void', <<<'PHP'
    protected function tearDown(): void
    {
        SetupCleanup::run();
        parent::tearDown();
    }

    protected function setUp(): void
    PHP, $base));
    $exception = '\\'.($outcome === 'error' ? RuntimeException::class : SkippedWithMessageException::class);
    if ($phase === 'setup') {
        $source = file_get_contents($this->project->path('app/Providers/FixtureProvider.php'));
        $this->project->write('app/Providers/FixtureProvider.php', str_replace("return 'booted';", "throw new {$exception}('Setup stopped');", $source));
    } else {
        $this->project->write('tests/Feature/BindingTest.php', "<?php test('executes a provider binding', function (): void { throw new {$exception}('Body stopped'); });\n");
    }
    $this->project->git()->commit('Abort the final test and run its teardown');

    $recording = ($this->run)($arguments);

    expect($recording->exitCode)->toBe($outcome === 'error' ? 2 : 0, $recording->describe())
        ->and(file_get_contents($this->project->path('cleanup-called')))->toBe('.');
    $graph = $this->project->graph();
    expect($graph)->not->toBeNull();
    foreach (['app/Providers/FixtureProvider.php', 'app/SetupCleanup.php'] as $file) {
        $id = array_search($file, $graph['files'], true);
        expect($id)->not->toBeFalse()
            ->and($graph['edges']['tests/Feature/BindingTest.php'])->toContain($id);
    }
})->with(['setup', 'body'])->with(['error', 'skip'])->with([
    'sequential' => [[]],
    'one worker' => [['--parallel', '--processes=1']],
])->skipOnWindows();

test('a new migration for a used table keeps unrelated database tests cached', function (array $arguments): void {
    $recording = ($this->run)($arguments);
    expect($recording->exitCode)->toBe(0, $recording->describe());

    $tables = $this->project->graph()['test_tables'];
    expect($tables['tests/Feature/OrdersTest.php'])->toBe(['orders'])
        ->and($tables['tests/Feature/UsersTest.php'])->toBe(['users'])
        ->and($tables['tests/Feature/BindingTest.php'])->toBe(['orders', 'users']);

    $this->project->git()->commit('Record database fixture');
    $this->project->git()->switchTo('migration-change', new: true);
    $this->project->write('database/migrations/add_users_column.php', "<?php Schema::table('users', function () {});");

    $result = ($this->run)($arguments);
    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->affected())->toBe(5, $result->describe())
        ->and($result->replayed())->toBe(5, $result->describe());
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('a new migration for an unused table keeps database tests cached', function (array $arguments): void {
    $recording = ($this->run)($arguments);
    expect($recording->exitCode)->toBe(0, $recording->describe());

    $this->project->git()->commit('Record database fixture');
    $this->project->git()->switchTo('unused-table', new: true);
    $this->project->write('database/migrations/create_reports.php', "<?php Schema::create('reports', function () {});");

    $result = ($this->run)($arguments);
    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->affected())->toBe(0, $result->describe())
        ->and($result->replayed())->toBe(10, $result->describe());
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('a migration change also selects its direct coverage consumer', function (array $arguments): void {
    $recording = ($this->run)($arguments);
    expect($recording->exitCode)->toBe(0, $recording->describe());

    $this->project->git()->commit('Record migration fixture');
    $this->project->git()->switchTo('direct-migration', new: true);
    $this->project->write('database/migrations/create_orders.php', <<<'PHP'
    <?php
    return static function (): string {
        return 'orders';
    };
    PHP);

    $result = ($this->run)($arguments);
    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->affected())->toBe(1, $result->describe());
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('a graph recorded before setup coverage is rebuilt', function (): void {
    $recording = ($this->run)([]);
    expect($recording->exitCode)->toBe(0, $recording->describe());

    $this->project->mutateGraph(function (array $graph): array {
        $graph['fingerprint']['structural']['schema'] = 25;

        return $graph;
    });

    $result = ($this->run)([]);
    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->replayed())->toBe(0, $result->describe())
        ->and($this->project->graph()['fingerprint']['structural']['schema'])->toBe(27);
})->skipOnWindows();
