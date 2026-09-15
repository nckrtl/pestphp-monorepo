# Contributing

This repository maintains the monorepo and consumer-autoloader changes on top of upstream Pest. Keep the upstream history and limit changes to the fork's purpose.

## Local checks

Use PHP 8.4 or later and Composer 2:

```bash
composer install
composer test:affected
composer check
composer test:consumer
```

`test:affected` runs the TIA unit tests and monorepo feature regressions. `check` validates the package, checks formatting and types, and runs the unit suite. `test:consumer` installs the renamed fork and the official Laravel plugin into a temporary monorepo project, then runs plain, parallel, and TIA tests, including from a linked worktree. The full upstream commands remain available, including `composer test:parallel` and `composer test:integration`.

## Update upstream

The current base is Pest 5.1.4, commit `e68976ea9da26e57ce74bf6ac4d638ded9151771`.

```bash
git remote add upstream https://github.com/pestphp/pest.git
git fetch upstream --tags
git switch -c update-pest-<version>
git merge <upstream-tag>
```

If the `upstream` remote already exists, use it. Resolve conflicts while retaining the fork's package name, support links, replacement version, and tests. Update the exact `replace.pestphp/pest` version and the base recorded in this document and the README. Remove fork changes when the upstream release provides the same behavior.

Run the local checks, parallel tests, integration tests, and a Composer consumer installation before merging an update. Verify that the consumer installs the fork without a second copy of Pest and discovers official Pest plugins. Exercise TIA in a monorepo subdirectory and a linked worktree.

## Prepare a release

Register `https://github.com/nckrtl/pestphp-monorepo` on Packagist before the first release. Releases use the fork's own version sequence, starting with `v1.0.0`; `replace.pestphp/pest` records the upstream version provided by that release.

Publish a release tag only after the checks pass. Push only the fork's release tag, not the upstream tags retained in the local repository. Composer derives the package version from the release tag; do not add a `version` field to `composer.json`.

This repository can be cloned inside another project's `packages/` directory for development. It remains an independent Git repository. Consumers install published releases through Composer; they do not need a path repository or a source-directory export process.
