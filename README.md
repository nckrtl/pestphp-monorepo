# Pest PHP Monorepo

`nckrtl/pestphp-monorepo` is a fork of [Pest](https://pestphp.com) with test-impact analysis (TIA) support for Composer projects inside a Git monorepo and Git worktrees.

This fork is based on Pest **5.1.4**. It keeps Pest's API, `Pest\` namespace, command, and plugin support. It is maintained independently by nckrtl and is not an official Pest release.

## Changes

- Resolve changed files and historical file contents relative to each Composer project.
- Keep TIA baseline identities separate for projects in the same repository.
- Discover ancestor Git configuration for monorepo subdirectories and keep worktree runs isolated.
- Resolve caller locations without depending on the installed package directory name.
- Load the consuming project's Composer autoloader when Pest is linked locally or starts parallel workers.

The changes include [Pest PR #1809](https://github.com/pestphp/pest/pull/1809), [Pest PR #1834](https://github.com/pestphp/pest/pull/1834), and the consumer-autoloader fix from Orbit's local fork.

## Installation

Install the fork from [Packagist](https://packagist.org/packages/nckrtl/pestphp-monorepo) in your Composer project:

```bash
composer require --dev nckrtl/pestphp-monorepo
```

If you already require `pestphp/pest` directly, remove that requirement when you add this package. The fork declares that it replaces Pest 5.1.4, so compatible Pest plugins can keep their existing dependency on `pestphp/pest`. Composer installs one implementation of Pest.

Run commands from the Composer project directory, such as `apps/gateway`:

```bash
vendor/bin/pest
vendor/bin/pest --tia
vendor/bin/pest --tia --parallel
```

See the [Pest documentation](https://pestphp.com/docs) for testing APIs and command options. See [CONTRIBUTING.md](CONTRIBUTING.md) for local checks, upstream updates, and release preparation.

## License

Pest and this fork use the [MIT license](LICENSE.md). The original copyright and permission notice are retained.
