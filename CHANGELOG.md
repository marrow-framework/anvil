# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.1.1] - 2026-10-06

### Fixed

- **`anvil:install`'s service prompt and overwrite-confirmation could hang indefinitely** on a stdin that
  `Symfony\Console\Input::isInteractive()` incorrectly reported as safe to prompt — uses `marrow/framework`
  3.0.1's new `Command::canPrompt()` guard (also checks `stream_isatty(STDIN)`) instead. Requires
  `marrow/framework` ^3.0.1 accordingly.

## [1.1.0] - 2026-10-06

### Added

- **`anvil:install` is now interactive** — run it with no `--services` on a real terminal and it asks (space to
  select, enter to confirm) instead of silently defaulting to "app only", which was easy to not realize you'd
  gotten until `docker-compose.yml` already needed `--force` to add a service. Passing `--services=...`
  explicitly still skips the prompt entirely (scripted/CI use), and it's skipped automatically whenever the
  command isn't running on a real terminal at all. Also now asks before overwriting an existing file instead of
  only accepting `--force`.
- **`anvil.ps1`** — a PowerShell twin of the `anvil` bash wrapper (same subcommands: `up`, `down`, `forge`,
  `composer`, `npm`, `shell`, `test`, `queue`, `fresh`, anything else passed through to `docker compose`), for
  Windows without WSL or git-bash. `anvil:install` now publishes both.
- **`./anvil queue`** — `docker compose exec app php forge queue:work`.
- **`./anvil fresh`** — `docker compose exec app php forge migrate:fresh --seed`, a one-command dev-database
  reset.

### Fixed

- **`app`'s `depends_on` only waited for a database *container* to start, not for the database inside it to
  actually accept connections** — `./anvil up -d` immediately followed by `./anvil forge migrate` could race a
  MySQL/MariaDB/PostgreSQL still initializing, especially against a cold volume. Every `mysql`/`mariadb`/
  `pgsql`/`redis` service now ships a `healthcheck`, and `app` depends on `condition: service_healthy` rather
  than a bare service-name list. Verified against the real `docker compose config` parser, not just read by
  eye.

## [1.0.1] - 2026-10-05

### Changed

- **`LICENSE` copyright holder corrected to "Aure Dulvresse"** — matches every other Marrow repository
  (previously read "marrow").
- **`composer.json` `authors`** — credits the maintainer, matching every other Marrow repository.

## [1.0.0]

Initial stable release — see the main README for the full feature set at this point; not individually
detailed here.
