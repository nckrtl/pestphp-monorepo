<?php

declare(strict_types=1);

namespace Pest\Plugins\Tia;

use Pest\Exceptions\InvalidOption;
use Pest\Support\Git;

final readonly class PathRepositories
{
    /** @var array<string, string> */
    private array $paths;

    public function __construct(string $root)
    {
        $composer = json_decode((string) @file_get_contents($root.'/composer.json'), true);
        $entries = array_filter(is_array($composer) ? ($composer['repositories'] ?? []) : [], static fn (mixed $entry): bool => is_array($entry) && ($entry['type'] ?? null) === 'path' && is_string($entry['url'] ?? null));
        if ($entries === []) {
            $this->paths = [];

            return;
        }
        $prefix = new Git($root)->subdirectoryPrefix() ?? '';
        $repository = $prefix === '' ? $root : substr($root, 0, -strlen($prefix) - 1);
        $paths = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || ($entry['type'] ?? null) !== 'path' || ! is_string($entry['url'] ?? null)) {
                continue;
            }
            $url = $entry['url'];
            $pattern = str_starts_with($url, '/') ? $url : $root.'/'.$url;
            foreach (glob($pattern, GLOB_ONLYDIR) ?: [] as $path) {
                $absolute = realpath($path);
                if ($absolute === false) {
                    continue;
                }
                if (! str_starts_with($absolute.'/', $repository.'/') || new Git($absolute)->output(['rev-parse', '--show-toplevel']) !== $repository) {
                    throw new InvalidOption('TIA path repositories must belong to the same Git repository.');
                }
                $relative = str_repeat('../', $prefix === '' ? 0 : count(explode('/', $prefix))).substr($absolute, strlen($repository) + 1);
                $paths[$absolute] = $relative;
            }
        }
        $this->paths = $paths;
    }

    /** @return list<string> */
    public function directories(): array
    {
        return array_keys($this->paths);
    }

    public function relative(string $absolute): ?string
    {
        foreach ($this->paths as $directory => $relative) {
            if ($absolute === $directory || str_starts_with($absolute, $directory.'/')) {
                return $relative.substr($absolute, strlen($directory));
            }
        }

        return null;
    }

    public static function repositoryPath(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }
}
