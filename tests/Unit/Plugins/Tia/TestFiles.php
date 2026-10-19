<?php

declare(strict_types=1);

use Pest\Plugins\Tia\TestFiles;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/pest-test-files-'.bin2hex(random_bytes(6)).'/';
    mkdir($this->root.'tests', 0755, true);
    mkdir($this->root.'vendor');
    file_put_contents($this->root.'tests/Example.php', '<?php');
    file_put_contents($this->root.'tests/Helper.php', '<?php');
    file_put_contents($this->root.'bootstrap.php', '<?php');
    file_put_contents($this->root.'vendor/bootstrap.php', '<?php');
    $this->files = new TestFiles(
        $this->root,
        [$this->root.'tests/'],
        [$this->root.'tests/Example.php' => true, $this->root.'tests/Helper.php' => true],
        [$this->root.'bootstrap.php', $this->root.'vendor/bootstrap.php'],
    );
});

afterEach(function (): void {
    foreach (['tests/Example.php', 'tests/Helper.php', 'bootstrap.php', 'vendor/bootstrap.php'] as $file) {
        unlink($this->root.$file);
    }
    rmdir($this->root.'tests');
    rmdir($this->root.'vendor');
    rmdir($this->root);
});

it('does not confuse a matching suite helper with a file defining tests', function (): void {
    expect($this->files->startupInputs([
        $this->root.'tests/Example.php',
        $this->root.'tests/Helper.php',
    ], [$this->root.'tests/Example.php']))->toBe(['bootstrap.php', 'tests/Helper.php']);
});

it('keeps configured bootstraps outside the test directories', function (): void {
    expect($this->files->startupInputs([], []))->toBe(['bootstrap.php']);
});

it('does not include a configured bootstrap from vendor', function (): void {
    expect($this->files->startupInputs([$this->root.'vendor/bootstrap.php'], []))->not->toContain('vendor/bootstrap.php');
});

it('does not include app files or files outside the project', function (): void {
    expect($this->files->startupInputs([$this->root.'app/Status.php', '/other/tests/Helper.php'], []))->toBe(['bootstrap.php']);
});

it('only includes support files that were loaded', function (): void {
    expect($this->files->startupInputs([$this->root.'tests/Example.php'], [$this->root.'tests/Example.php']))->toBe(['bootstrap.php']);
});

it('uses exact suite candidates for new test discovery', function (): void {
    expect($this->files->isCandidate('tests/Example.php'))->toBeTrue()
        ->and($this->files->isCandidate('tests/Excluded.php'))->toBeFalse()
        ->and($this->files->isCandidate('app/Example.php'))->toBeFalse();
});
