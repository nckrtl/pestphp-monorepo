<?php

declare(strict_types=1);

use Pest\Plugins\Tia\Recorder;
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

test('a provider change selects its binding consumer without selecting unrelated feature tests', function (array $arguments): void {
    $recording = ($this->run)($arguments);
    expect($recording->exitCode)->toBe(0, $recording->describe());

    $graph = $this->project->graph();
    expect($graph)->not->toBeNull();
    $providerId = array_search('app/Providers/FixtureProvider.php', $graph['files'], true);
    expect($providerId)->not->toBeFalse()
        ->and($graph['edges']['tests/Feature/BindingTest.php'])->toContain($providerId)
        ->and($graph['edges']['tests/Feature/OrdersTest.php'])->not->toContain($providerId)
        ->and($graph['edges']['tests/Feature/UsersTest.php'])->not->toContain($providerId)
        ->and($graph['files'])->not->toContain('config/fixture.php');

    $this->project->git()->commit('Record provider fixture');
    $this->project->git()->switchTo('provider-change', new: true);
    $source = file_get_contents($this->project->path('app/Providers/FixtureProvider.php'));
    $this->project->write('app/Providers/FixtureProvider.php', str_replace("'booted'", "'booted again'", $source));

    $result = ($this->run)($arguments);
    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->affected())->toBe(1, $result->describe());
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

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

test('a graph recorded before test directory startup capture is rebuilt', function (): void {
    $recording = ($this->run)([]);
    expect($recording->exitCode)->toBe(0, $recording->describe());

    $this->project->mutateGraph(function (array $graph): array {
        $graph['fingerprint']['structural']['schema'] = 24;

        return $graph;
    });

    $result = ($this->run)([]);
    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->replayed())->toBe(0, $result->describe())
        ->and($this->project->graph()['fingerprint']['structural']['schema'])->toBe(25);
})->skipOnWindows();
