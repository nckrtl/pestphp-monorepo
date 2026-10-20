<?php

declare(strict_types=1);

use Pest\Plugins\Tia\Recorder;
use Tests\Fixtures\Tia\Project;

beforeEach(function (): void {
    if (! new Recorder()->driverAvailable()) {
        $this->markTestSkipped('A coverage driver is required.');
    }
    $this->project = Project::make();
    $this->project->write('php-ini/99-pcov.ini', "pcov.directory=/\npcov.enabled=1\n");
    $this->run = fn (array $arguments) => $this->project->pestWithEnvironment(
        $this->project->path(),
        ['PHP_INI_SCAN_DIR' => (getenv('PHP_INI_SCAN_DIR') ?: PHP_CONFIG_FILE_SCAN_DIR).PATH_SEPARATOR.$this->project->path('php-ini')],
        '--tia', ...$arguments,
    );
});

afterEach(function (): void {
    Project::destroyAll();
});

test('records every file consuming declarations loaded once per process', function (string $declaration, string $expression, array $arguments): void {
    $this->project->write('app/Declaration.php', '<?php '.$declaration);
    for ($consumer = 1; $consumer <= 4; $consumer++) {
        $this->project->write("tests/Unit/Consumer{$consumer}Test.php", "<?php test('reads a declaration', function (): void { require_once __DIR__.'/../../app/Declaration.php'; expect({$expression})->toBe('expected'); });");
    }
    $this->project->git()->commit('Add four declaration consumers');
    $recording = ($this->run)($arguments);
    expect($recording->exitCode)->toBe(0, $recording->describe());
    $this->project->write('app/Declaration.php', '<?php '.str_replace('expected', 'broken', $declaration));
    $result = ($this->run)($arguments);
    expect($result->exitCode)->toBe(1, $result->describe())
        ->and($result->tally())->toContain('4 failed');
    $graph = $this->project->graph();
    $id = array_search('app/Declaration.php', $graph['files'], true);
    for ($consumer = 1; $consumer <= 4; $consumer++) {
        expect($graph['edges']["tests/Unit/Consumer{$consumer}Test.php"])->toContain($id);
    }
})->with([
    'enum' => ["enum Declaration: string { case Value = 'expected'; }", 'Declaration::Value->value'],
    'constant' => ["class Declaration { public const VALUE = 'expected'; }", 'Declaration::VALUE'],
    'property' => ["class Declaration { public string \$value = 'expected'; }", '(new Declaration)->value'],
    'parameter' => ["class Declaration { public function __construct(public string \$value = 'expected') {} }", '(new Declaration)->value'],
    'attribute' => ["#[Attribute] class Value { public function __construct(public string \$value) {} } #[Value('expected')] class Declaration {}", '(new ReflectionClass(Declaration::class))->getAttributes()[0]->getArguments()[0]'],
])->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

it('retains a file when only its final executable line ran', function (): void {
    $recorder = new Recorder;
    $method = new ReflectionMethod(Recorder::class, 'filesWithExecutedLines');
    expect($method->invoke($recorder, ['/project/Declaration.php' => [3 => -1, 9 => 1]]))
        ->toBe(['/project/Declaration.php']);
});

test('forked test children cannot publish inherited worker receipts', function (array $arguments): void {
    $this->project->write('tests/Unit/ForkTest.php', <<<'FIXTURE'
    <?php
    test('passes before forking', function (): void { expect(true)->toBeTrue(); });
    test('waits for its child', function (): void {
        $pid = pcntl_fork();
        if ($pid === 0) {
            exit(0);
        }
        expect($pid)->toBeGreaterThan(0);
        pcntl_waitpid($pid, $childStatus);
        expect(pcntl_wexitstatus($childStatus))->toBe(0);
    });
    FIXTURE);
    $recording = ($this->run)($arguments);
    expect($recording->exitCode)->toBe(0, $recording->describe())
        ->and($this->project->graph()['edges'])->toHaveKey('tests/Unit/ForkTest.php');
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();
