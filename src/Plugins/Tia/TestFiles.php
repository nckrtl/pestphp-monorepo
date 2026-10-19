<?php

declare(strict_types=1);

namespace Pest\Plugins\Tia;

use Pest\TestSuite;
use PHPUnit\TextUI\Configuration\File;
use PHPUnit\TextUI\Configuration\Registry;
use SebastianBergmann\FileIterator\Facade;

/** @internal */
final readonly class TestFiles
{
    /**
     * @param  list<string>  $directories
     * @param  array<string, true>  $candidates
     * @param  list<string>  $bootstraps
     */
    public function __construct(
        private string $root,
        private array $directories,
        private array $candidates,
        private array $bootstraps,
    ) {}

    public static function fromProjectRoot(string $projectRoot): self
    {
        $root = rtrim(realpath($projectRoot) ?: $projectRoot, '/').'/';
        $configuration = Registry::get();
        $testPath = realpath($root.TestSuite::getInstance()->testPath);
        $directories = $testPath !== false && str_starts_with($testPath.'/', $root)
            ? [$testPath.'/']
            : [];
        $candidates = [];
        $iterator = new Facade;
        foreach ($configuration->testSuite() as $suite) {
            $excluded = array_map(static fn (File $file): string => $file->path(), $suite->exclude()->asArray());
            foreach ($suite->directories() as $directory) {
                foreach (glob($directory->path(), GLOB_ONLYDIR) ?: [] as $path) {
                    $path = realpath($path);
                    if ($path === false || ! str_starts_with($path.'/', $root)) {
                        continue;
                    }
                    $directories[] = $path.'/';
                    foreach ($iterator->getFilesAsArray($path, $directory->suffix(), $directory->prefix(), $excluded) as $file) {
                        $candidates[$file] = true;
                    }
                }
            }
            foreach ($suite->files() as $file) {
                $path = realpath($file->path());
                if ($path !== false && str_starts_with($path, $root)) {
                    $candidates[$path] = true;
                }
            }
        }
        $bootstraps = array_values($configuration->bootstrapForTestSuite());
        if ($configuration->hasBootstrap()) {
            $bootstraps[] = $configuration->bootstrap();
        }

        $bootstraps = array_values(array_filter(
            array_map(realpath(...), $bootstraps),
            static fn (string|false $path): bool => $path !== false
                && str_starts_with($path, $root)
                && ! str_starts_with($path, $root.'vendor/'),
        ));

        return new self($root, array_values(array_unique($directories)), $candidates, $bootstraps);
    }

    public function isCandidate(string $relativePath): bool
    {
        return isset($this->candidates[$this->root.$relativePath]);
    }

    /**
     * @param  list<string>  $included
     * @param  list<string>  $defined
     * @return list<string>
     */
    public function startupInputs(array $included, array $defined): array
    {
        $testFiles = array_fill_keys($defined, true);
        $inputs = [];
        foreach ($this->bootstraps as $bootstrap) {
            $inputs[substr($bootstrap, strlen($this->root))] = true;
        }
        foreach ($included as $file) {
            if (! $this->isProjectFile($file) || (isset($this->candidates[$file]) && isset($testFiles[$file]))) {
                continue;
            }
            foreach ($this->directories as $directory) {
                if (str_starts_with($file, $directory)) {
                    $inputs[substr($file, strlen($this->root))] = true;
                    break;
                }
            }
        }
        $files = array_keys($inputs);
        sort($files);

        return $files;
    }

    private function isProjectFile(string $path): bool
    {
        return str_starts_with($path, $this->root) && ! str_starts_with($path, $this->root.'vendor/');
    }
}
