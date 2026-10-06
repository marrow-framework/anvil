<div align="center">

<img src="https://raw.githubusercontent.com/marrow-framework/.github/main/marrow-logo-mark.svg" alt="Marrow" width="120">

# Marrow Anvil

A local Docker Compose development environment for [Marrow](https://github.com/marrow-framework/core) apps.

[![CI](https://img.shields.io/github/actions/workflow/status/marrow-framework/anvil/ci.yml?branch=main&style=flat-square&label=CI)](https://github.com/marrow-framework/anvil/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/marrow/anvil?style=flat-square&label=packagist)](https://packagist.org/packages/marrow/anvil)
[![Packagist Downloads](https://img.shields.io/packagist/dt/marrow/anvil?style=flat-square&color=blue)](https://packagist.org/packages/marrow/anvil)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![License MIT](https://img.shields.io/badge/license-MIT-22c55e?style=flat-square)](LICENSE)

</div>

---

The same role [Laravel Sail](https://laravel.com/docs/sail) plays, under Marrow's
own name (`forge` shapes the code, Anvil is where you run it).

```bash
composer require --dev marrow/anvil
php forge anvil:install
```

`anvil:install` is available immediately after `composer require` — no
manual registration step. The package declares its own module via
`extra.marrow.modules` in its `composer.json`, which Marrow's package
auto-discovery picks up automatically at boot.

## What it creates

```bash
php forge anvil:install                                          # app container only — matches the default SQLite driver
php forge anvil:install --services=mysql,redis,node
php forge anvil:install --services=pgsql,memcached,mailpit,minio
php forge anvil:install --force                                  # overwrite existing files
```

| File | Purpose |
|---|---|
| `docker-compose.yml` | The `app` service always, plus any requested `--services` |
| `docker/app/Dockerfile` | PHP 8.3-cli + the extensions Marrow needs (`pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, `zip`) + Composer |
| `anvil` | An executable CLI wrapper (`chmod +x` already applied on non-Windows) |
| `anvil.ps1` | The same CLI wrapper for Windows without WSL/git-bash — identical subcommands |

All four are plain generated files, safe to edit by hand afterward —
re-running `anvil:install` won't touch them again unless you pass `--force`.

Every database service (`mysql`/`mariadb`/`pgsql`) and `redis` ships with a `healthcheck`, and `app`'s
`depends_on` waits on `condition: service_healthy` rather than just "container started" — without this,
`./anvil up -d` followed immediately by `./anvil forge migrate` can race a database that's still initializing,
especially on a cold volume (first run, or after `./anvil down -v`).

## The `app` service runs the same command as bare-metal dev

```yaml
command: php forge serve --host=0.0.0.0 --port=8000
```

Nothing about how the app starts changes between Docker and non-Docker
development — only where it runs. There's no separate Nginx/PHP-FPM setup
to keep in sync with `php forge serve`'s own behavior (colourised request
logging, crash auto-restart, `bin/server.php` routing) because it's the
literal same command.

## The `anvil` CLI

```bash
./anvil up -d              # start, detached
./anvil down                # stop
./anvil forge migrate       # -> docker compose exec app php forge migrate
./anvil composer require x  # -> docker compose exec app composer require x
./anvil npm run build       # -> docker compose exec node npm run build (needs --services=node)
./anvil shell               # bash inside the app container
./anvil test                # -> docker compose exec app composer test
./anvil queue                # -> docker compose exec app php forge queue:work
./anvil fresh                # -> docker compose exec app php forge migrate:fresh --seed
./anvil ps / logs / build   # passed straight to `docker compose`
```

Anything not in the list above falls through to `docker compose` directly,
so `./anvil <anything>` always does *something* reasonable.

On Windows without WSL/git-bash, use `.\anvil.ps1` instead — same subcommands, same behavior.

## Available services

`--services` takes any comma-separated combination of:

| Service | Image | Notes |
|---|---|---|
| `mysql` | `mysql:8.0` | |
| `mariadb` | `mariadb:11` | MySQL-protocol compatible — same `DB_DRIVER=mysql` |
| `pgsql` | `postgres:16-alpine` | `DB_DRIVER=pgsql` |
| `redis` | `redis:7-alpine` | needs `predis/predis` |
| `memcached` | `memcached:1.6-alpine` | no built-in Marrow cache driver yet |
| `mailpit` | `axllent/mailpit` | SMTP catcher + web UI at `:8025` |
| `minio` | `minio/minio` | S3-compatible storage, console at `:8900` |
| `node` | `node:22-alpine` | runs `npm run dev` for Vite — see the framework's frontend docs |

Each database service you pick becomes an `app` `depends_on`. Running the
install command prints the exact `.env` keys to set for whatever you
chose — `anvil:install` never edits `.env` itself.

### Example: `--services=mysql`

```bash
DB_DRIVER=mysql
DB_HOST=mysql
DB_DATABASE=app
DB_USERNAME=marrow
DB_PASSWORD=secret
```

(Same credentials as the generated `docker-compose.yml`'s `mysql`
environment block — change both together if you change one.)

## Known limitation: path-repository symlinks and volumes

If you're developing against a local `path` Composer repository that
points **outside** the project directory (common when working on Marrow
itself, or on a package, alongside the app that consumes it), `vendor/`
contains **symlinks**, not real files. `docker-compose.yml` only mounts
the project directory itself (`.:/var/www/html`), so a symlink pointing
outside it resolves to nothing inside the container — PHP fails to
`require` the autoloader.

This doesn't affect a normal install — a real `composer require
marrow/framework` puts actual files under `vendor/`, nothing
to break. It only bites local path-repository development. Fix it with:

```bash
COMPOSER_MIRROR_PATH_REPOS=1 composer install
```

which forces Composer to copy path-repository packages into `vendor/`
instead of symlinking them.

## Requirements

Docker and Docker Compose on the host. PHP is only needed there to run
`php forge anvil:install` itself — everything after that runs in the
container.

## License

MIT — see [LICENSE](LICENSE).

---

<div align="center">

Made with ❤️ by [Aure Dulvresse](https://github.com/AureDulvresse)

</div>
