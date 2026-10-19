<?php

declare(strict_types=1);

namespace Pest\Plugins\Tia;

use Pest\Plugins\Tia\Contracts\Lockfile;
use Pest\Support\Git;
use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\TextUI\CliArguments\Builder;
use PHPUnit\TextUI\CliArguments\XmlConfigurationFileFinder;

/**
 * @internal
 */
final readonly class Fingerprint
{
    private const int SCHEMA_VERSION = 22;

    private const array JS_CONFIG_NAMES = ['tsconfig.json', 'tsconfig.app.json', 'jsconfig.json'];

    private const array PACKAGE_LOCK_NAMES = ['package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb'];

    /**
     * @var array<int, class-string<Lockfile>>
     */
    private const array LOCKFILES = [
        Lockfiles\PackageLock::class,
    ];

    /**
     * @param  array<int, string>  $arguments
     * @return array{
     *     structural: array<string, int|string|null>,
     *     environmental: array<string, int|string|null>,
     * }
     */
    public static function compute(string $projectRoot, array $arguments = []): array
    {
        $structural = ['schema' => self::SCHEMA_VERSION];

        $inputs = self::inputs($projectRoot, $arguments);
        $tracked = self::trackedFiles($projectRoot, array_merge(...array_values(array_diff_key($inputs, ['phpunit_config' => true]))));
        foreach ($inputs as $group => $paths) {
            if ($group === 'submodules') {
                $structural[$group] = self::gitlinks($projectRoot);

                continue;
            }
            $parts = [];
            foreach ($paths as $path) {
                if ($group !== 'phpunit_config' && $tracked !== null && ! isset($tracked[$path])) {
                    continue;
                }
                $hash = $group === 'package_lock'
                    ? self::lockfileHash((str_starts_with($path, '/') ? $path : $projectRoot.'/'.$path), basename($path))
                    : self::hashIfExists((str_starts_with($path, '/') ? $path : $projectRoot.'/'.$path));
                if ($hash !== null) {
                    $parts[] = $path.':'.$hash;
                }
            }
            $structural[$group] = $parts === [] ? null : hash('xxh128', implode("\n", $parts));
        }

        return [
            'structural' => $structural,
            'environmental' => ['php_minor' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION],
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public static function structuralMatches(array $a, array $b): bool
    {
        $aStructural = self::structuralOnly($a);
        $bStructural = self::structuralOnly($b);

        ksort($aStructural);
        ksort($bStructural);

        return $aStructural === $bStructural;
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    public static function structuralDrift(array $stored, array $current): array
    {
        return self::detectDrift(
            self::structuralOnly($stored),
            self::structuralOnly($current),
            'schema',
        );
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    public static function environmentalDrift(array $stored, array $current): array
    {
        return self::detectDrift(
            self::environmentalOnly($stored),
            self::environmentalOnly($current),
        );
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return list<string>
     */
    private static function detectDrift(array $a, array $b, ?string $skipKey = null): array
    {
        $drifts = [];

        foreach ($a as $key => $value) {
            if ($key === $skipKey) {
                continue;
            }
            if (($b[$key] ?? null) !== $value) {
                $drifts[] = $key;
            }
        }

        foreach ($b as $key => $value) {
            if ($key === $skipKey) {
                continue;
            }
            if (! array_key_exists($key, $a) && $value !== null) {
                $drifts[] = $key;
            }
        }

        return array_values(array_unique($drifts));
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     * @return array<string, mixed>
     */
    private static function structuralOnly(array $fingerprint): array
    {
        return self::bucket($fingerprint, 'structural');
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     * @return array<string, mixed>
     */
    private static function environmentalOnly(array $fingerprint): array
    {
        return self::bucket($fingerprint, 'environmental');
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     * @return array<string, mixed>
     */
    private static function bucket(array $fingerprint, string $key): array
    {
        $raw = $fingerprint[$key] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $normalised = [];

        foreach ($raw as $k => $v) {
            if (is_string($k)) {
                $normalised[$k] = $v;
            }
        }

        return $normalised;
    }

    /**
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $arguments
     */
    public static function touchesInputs(array $paths, string $projectRoot, array $arguments = [], ?string $base = null): bool
    {
        return array_intersect($paths, array_merge(...array_values(self::inputs($projectRoot, $arguments, $base, forSelection: true)))) !== [];
    }

    /**
     * @param  array<int, string>  $arguments
     * @return array<string, list<string>>
     */
    private static function inputs(string $projectRoot, array $arguments, ?string $base = null, bool $forSelection = false): array
    {
        $configurationArguments = ['--configuration', $projectRoot];
        $discover = true;
        foreach ($arguments as $index => $argument) {
            if (in_array($argument, ['-c', '--configuration'], true)) {
                $configurationArguments = [$argument, $arguments[$index + 1] ?? ''];
                $discover = false;
            } elseif ($argument === '--no-configuration' || str_starts_with($argument, '--configuration=') || (str_starts_with($argument, '-c') && strlen($argument) > 2)) {
                $configurationArguments = [$argument];
                $discover = false;
            }
        }
        $configuration = new Builder(EventFacade::emitter())->fromParameters($configurationArguments);
        $path = new XmlConfigurationFileFinder()->find($configuration);
        if (is_string($path)) {
            $path = realpath($path) ?: $path;
            $prefix = rtrim($projectRoot, '/').'/';
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
            }
        }

        return [
            'composer_lock' => ['composer.lock'],
            'composer_json' => ['composer.json'],
            'phpunit_config' => $forSelection && $discover
                ? ['phpunit.xml', 'phpunit.dist.xml', 'phpunit.xml.dist']
                : (is_string($path) ? [$path] : []),
            'vite_config' => JsModuleGraph::VITE_CONFIG_NAMES,
            'package_lock' => self::PACKAGE_LOCK_NAMES,
            'js_config' => self::JS_CONFIG_NAMES,
            'submodules' => array_keys(self::gitlinkEntries($projectRoot, $base)),
        ];
    }

    /** @return array<string, string> */
    private static function gitlinkEntries(string $projectRoot, ?string $base = null): array
    {
        $git = new Git($projectRoot);
        $entries = $git->raw(['ls-files', '--stage', '-z']) ?? '';
        if ($base !== null) {
            $entries .= $git->raw(['ls-tree', '-r', '-z', $base, '--', '.']) ?? '';
        }
        $links = [];
        foreach (explode("\0", $entries) as $entry) {
            if (preg_match('/^160000 (?:commit )?([a-f0-9]+)(?: [0-3])?\t(.+)$/s', $entry, $matches) === 1) {
                $links[$matches[2]] = $matches[1];
            }
        }
        ksort($links);

        return $links;
    }

    private static function gitlinks(string $projectRoot): ?string
    {
        $entries = self::gitlinkEntries($projectRoot);

        return $entries === [] ? null : hash('xxh128', serialize($entries));
    }

    private static function lockfileHash(string $path, string $name): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        foreach (self::LOCKFILES as $class) {
            $handler = new $class;

            if (! $handler->applies($name)) {
                continue;
            }

            $fingerprint = $handler->fingerprint($contents);

            if ($fingerprint !== null) {
                return $fingerprint;
            }

            break;
        }

        return hash('xxh128', $contents);
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, true>|null
     */
    private static function trackedFiles(string $projectRoot, array $paths): ?array
    {
        if (GitRepository::locate($projectRoot) === null) {
            return null;
        }

        $paths = array_values(array_filter($paths, static fn (string $path): bool => is_file(str_starts_with($path, '/') ? $path : $projectRoot.'/'.$path)));
        if ($paths === []) {
            return [];
        }
        $files = new Git($projectRoot)->raw(['ls-files', '-z', '--', ...$paths]) ?? '';

        return array_fill_keys(array_filter(explode("\0", $files)), true);
    }

    private static function hashIfExists(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $hash = @hash_file('xxh128', $path);

        return $hash === false ? null : $hash;
    }
}
