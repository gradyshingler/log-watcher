# Contributing to Log Watcher

## Development workflow

`log_watcher` is intended to live in its own Git repository and be consumed by Drupal projects through Composer.

For local development:

1. Work in the shared repository directly.
2. Consume it from Drupal projects with a Composer `path` repository using `symlink: true`.
3. Rebuild Drupal caches after code or routing changes.
4. Run database updates when install/update hooks change.

Example project commands:

```bash
composer update gradyshingler/log_watcher -W
php -d detect_unicode=0 -l src/Controller/DashboardController.php
php -d detect_unicode=0 -l src/Form/LogWatcherSettingsForm.php
vendor/bin/drush cr
```

## Coding expectations

- Follow Drupal coding standards and naming conventions.
- Prefer dependency injection over static service lookups.
- Keep routes, permissions, and configuration schema in sync with new features.
- Preserve backward compatibility for existing configuration and database schema when practical.
- Add or update tests when behavior changes.

## Testing

This repository contains module tests, but full execution typically happens from a consuming Drupal site where Drupal core and the site test dependencies are installed.

Recommended checks before committing:

```bash
composer validate --no-check-publish --strict
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

If you have the module installed in a Drupal project, also run the relevant site-level automated tests there.

## Release process

1. Update `CHANGELOG.md`.
2. Commit the release changes.
3. Create a semantic version tag, for example `1.0.0`.
4. Push the branch and tag to the hosted Git remote.
5. Update consuming projects with Composer.

Example:

```bash
git add .
git commit -m "Prepare 1.0.0 release"
git tag 1.0.0
git push origin main --tags
```

## Reporting issues

Include the following when reporting a bug:

- Drupal version
- PHP version
- Log Watcher version/tag
- Relevant error message
- Steps to reproduce
- Expected versus actual behavior