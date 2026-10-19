<?php

declare(strict_types=1);

namespace Pest\Plugins\Tia;

use PHPUnit\Framework\TestCase;
use PHPUnit\TextUI\Configuration\File;
use PHPUnit\TextUI\Configuration\Registry;
use PHPUnit\Util\Test;
use ReflectionClass;
use SebastianBergmann\FileIterator\Facade;

/** @internal */
final class TestFiles
{
    /** @var array<string, self> */
    private static array $projects = [];

    /**
     * @param  list<string>  $directories
     * @param  array<string, true>  $candidates
     * @param  list<string>  $bootstraps
     */
    public function __construct(
        private readonly string $root,
        private readonly array $directories,
        private readonly array $candidates,
        private readonly array $bootstraps,
    ) {}

    public static function fromProjectRoot(string $projectRoot): self
    {
        $root = rtrim(realpath($projectRoot) ?: $projectRoot, '/').'/';
        if (isset(self::$projects[$root])) {
            return self::$projects[$root];
        }

        $configuration = Registry::get();
        $directories = [];
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

        return self::$projects[$root] = new self($root, array_values(array_unique($directories)), $candidates, $bootstraps);
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
        foreach (get_declared_classes() as $class) {
            if (! is_subclass_of($class, TestCase::class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            $file = $reflection->getFileName();
            if ($reflection->isAbstract() || $file === false || ! isset($this->candidates[$file])) {
                continue;
            }
            foreach ($reflection->getMethods() as $method) {
                if (Test::isTestMethod($method)) {
                    $testFiles[$file] = true;
                    break;
                }
            }
        }

        $inputs = [];
        foreach ($this->bootstraps as $bootstrap) {
            $path = realpath($bootstrap);
            if ($path !== false && $this->isProjectFile($path)) {
                $inputs[substr($path, strlen($this->root))] = true;
            }
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
