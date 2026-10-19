# Pest PHP Monorepo

`nckrtl/pestphp-monorepo` is a fork of [Pest](https://pestphp.com) with test-impact analysis (TIA) support for Composer projects inside a Git monorepo and Git worktrees.

This fork is based on Pest **5.3.2**. It keeps Pest's API, `Pest\` namespace, command, and plugin support. It is maintained independently by nckrtl and is not an official Pest release.

## Changes

- Resolve changed files and historical file contents relative to each Composer project.
- Keep TIA baseline identities separate for projects in the same repository.
- Discover ancestor Git configuration for monorepo subdirectories and keep worktree runs isolated.
- Resolve caller locations without depending on the installed package directory name.
- Load the consuming project's Composer autoloader when Pest is linked locally or starts parallel workers.
- Preserve binary dataset names and result messages when TIA saves and replays test results.

The changes include [Pest PR #1809](https://github.com/pestphp/pest/pull/1809), [Pest PR #1834](https://github.com/pestphp/pest/pull/1834), and the consumer-autoloader fix from Orbit's local fork.

## Installation

Install the fork from [Packagist](https://packagist.org/packages/nckrtl/pestphp-monorepo) in your Composer project:

```bash
composer require --dev nckrtl/pestphp-monorepo
```

If you already require `pestphp/pest` directly, remove that requirement when you add this package. The fork declares that it replaces Pest 5.3.2, so compatible Pest plugins can keep their existing dependency on `pestphp/pest`. Composer installs one implementation of Pest.

Run commands from the Composer project directory, such as `apps/gateway`:

```bash
vendor/bin/pest
vendor/bin/pest --tia
vendor/bin/pest --tia --parallel
```

Use an explicit test base for a gate:

```bash
vendor/bin/pest --tia-base=graph
vendor/bin/pest --tia-base=COMMIT
```

`graph` selects changes since the dependency graph’s default-branch commit. `COMMIT` selects changes since that commit and requires graph edges that are current at that commit. Both forms include uncommitted and untracked files. They select test files from the graph’s edges and run those tests without replaying stored results or using branch baselines or last-run content snapshots. An empty delta runs no tests. A missing or incompatible graph records the full suite. The commit form also records the full suite when the delta touches a Composer manifest or lockfile, the PHPUnit configuration file or any discovery candidate in the configuration directory (`-c` accepts a file or directory and defaults to the project root), a package lockfile, JS/Vite configuration, or a submodule pointer, even if a later run warmed the graph. Changes to startup inputs select all tests. Startup inputs are files loaded before the first test that are inside the project, outside `vendor/`, and are not test files of any configured suite. Suite directories, suffixes, prefixes, exclusions and explicit files define test files, independently of the run’s filter, group or path selection. This includes helpers required by a test file. The fingerprint hashes the active PHPUnit configuration and each submodule’s gitlink SHA. The `graph` form also records the full suite if the graph has no reachable default-branch commit.

`--tia-base` enables TIA by itself and cannot be combined with `--no-tia` or a partial test selection, such as a test path or `--filter`. Coverage reports contain only the selected tests.

See the [Pest documentation](https://pestphp.com/docs) for testing APIs and command options. See [CONTRIBUTING.md](CONTRIBUTING.md) for local checks, upstream updates, and release preparation.

## License

Pest and this fork use the [MIT license](LICENSE.md). The original copyright and permission notice are retained.
