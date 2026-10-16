# AGENTS.md — Log Watcher module

This repository is the standalone, self-hosted contrib-style home for the `gradyshingler/log_watcher` Drupal module.

## Scope
- Apply these instructions to everything under this repository unless a deeper `AGENTS.md` file says otherwise.
- This module is reused by multiple Drupal projects through Composer, so keep changes compatible with tagged releases when possible.

## Repository conventions
- Keep the module package metadata aligned with `composer.json`:
  - Package name: `gradyshingler/log_watcher`
  - Type: `drupal-module`
  - Drupal compatibility is controlled via the `conflict` section, not a direct `drupal/core` requirement.
- Treat `main` as the development branch and semantic tags (for example `1.0.0`, `1.0.1`) as release points.
- Avoid making assumptions about the consuming Drupal project; this repo should remain self-contained.
- This module is actively used by other websites. Treat backward compatibility as a release requirement, not an optional consideration.
- Preserve existing configuration keys, service IDs, route names, permissions, database tables/columns, plugin IDs, and public method behavior unless a breaking change is explicitly requested and documented.

## Working on the module
- Prefer small, targeted changes that preserve Drupal coding standards and the existing module architecture.
- When changing runtime behavior, check the corresponding service, controller, form, or repository class rather than only editing templates.
- Keep documentation in sync with behavior changes, especially `README.md` and `CHANGELOG.md`.

## Composer / release workflow
- For local development in a consuming Drupal project, this module may be installed via a Composer `path` repository.
- For shared or CI environments, prefer the GitHub VCS repository and tagged releases.
- If changing `composer.json`, verify that the package name and stability expectations still match the consuming projects.

## Upgrade and deployment safety
- Before changing data structures or persisted configuration, determine whether existing installations need an update path.
- Database schema changes must be implemented through Drupal update hooks in `.install` (with sequential `hook_update_N()` implementations), and must be safe to run on already-populated installations.
- Configuration migrations or renamed settings must include an explicit upgrade path that preserves existing values; do not silently reset consumer configuration.
- New required services, events, queues, cron behavior, or deployment steps must be documented in `README.md` and `CHANGELOG.md` and included in the release notes.
- If an update requires cache rebuilds, database updates, cron runs, queue processing, or a specialized event/subscriber, document the exact command or deployment action required.
- Do not remove or rename existing database tables, columns, configuration keys, routes, permissions, or service IDs without a compatibility layer or a documented migration and deprecation path.
- Test upgrades from the latest tagged release when practical, including database updates and existing configuration. A fresh-install test alone is insufficient for changes that affect persisted state.

## Validation expectations
- If you edit PHP code, validate the affected file(s) for syntax or obvious errors.
- If you edit Composer metadata, validate the JSON structure.
- If you change module behavior that affects Drupal pages or services, run the most relevant module tests from a consuming Drupal project if available.
- If test execution is not available in this shell, state that clearly and describe the next command to run in the usual environment.

## Useful locations
- `composer.json` — package metadata and Drupal compatibility rules
- `README.md` — installation, development, and release workflow
- `src/` — controllers, forms, services, repositories, and plugins
- `tests/` — module test coverage

## When unsure
- Prefer the existing README and code patterns in this repository.
- If a change could affect consumers, preserve backward compatibility unless the task explicitly requires a breaking change.
- Document any behavior or release-process changes before finishing.