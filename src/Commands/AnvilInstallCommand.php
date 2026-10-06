<?php

declare(strict_types=1);

namespace Marrow\Anvil\Commands;

use Marrow\Console\Command;

/**
 * Scaffolds a local Docker development environment: docker-compose.yml,
 * docker/app/Dockerfile, and an `anvil` CLI wrapper script at the project
 * root — the same role Laravel Sail plays, under Marrow's own name.
 *
 *   php forge anvil:install
 *   php forge anvil:install --services=mysql,redis,node
 *   php forge anvil:install --services=pgsql,memcached,mailpit,minio
 *
 * The `app` service always runs `php forge serve` inside the container —
 * the same command used outside Docker — so nothing about how the app is
 * started changes, only where it runs. Every other service is opt-in via
 * --services (comma-separated, any combination), since the skeleton's
 * default SQLite driver needs none of them to actually run.
 */
class AnvilInstallCommand extends Command
{
    /** @var array<string, array{db?: bool, volume?: string, port_env?: string, port?: int, env_hint?: string}> */
    private const SERVICES = [
        'mysql' => ['db' => true, 'volume' => 'anvil-mysql', 'port_env' => 'DB_PORT', 'port' => 3306],
        'mariadb' => ['db' => true, 'volume' => 'anvil-mariadb', 'port_env' => 'DB_PORT', 'port' => 3306],
        'pgsql' => ['db' => true, 'volume' => 'anvil-pgsql', 'port_env' => 'DB_PORT', 'port' => 5432],
        'redis' => ['volume' => 'anvil-redis', 'port_env' => 'REDIS_PORT', 'port' => 6379],
        'memcached' => ['port_env' => 'MEMCACHED_PORT', 'port' => 11211],
        'mailpit' => ['port_env' => 'MAILPIT_PORT', 'port' => 1025],
        'minio' => ['volume' => 'anvil-minio', 'port_env' => 'MINIO_PORT', 'port' => 9000],
        'node' => [],
    ];

    protected string $signature = 'anvil:install
        {--services= : Comma-separated, any of: mysql, mariadb, pgsql, redis, memcached, mailpit, minio, node}
        {--force : Overwrite existing files}';
    protected string $description = 'Scaffold a Docker development environment (docker-compose.yml, Dockerfile, anvil script)';

    protected function handle(): int
    {
        $requested = $this->resolveServices();
        if ($requested === null) {
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        $this->writeFile(base_path('docker-compose.yml'), $this->composeStub($requested), $force);
        $this->writeFile(base_path('docker/app/Dockerfile'), $this->dockerfileStub(), $force);
        $this->writeFile(base_path('anvil'), $this->anvilScriptStub(), $force);
        $this->writeFile(base_path('anvil.ps1'), $this->anvilPs1Stub(), $force);

        if (!str_starts_with(PHP_OS, 'WIN')) {
            @chmod(base_path('anvil'), 0755);
        }

        $this->success('Anvil environment created.');
        $this->newLine();
        $this->line('   <fg=gray>Next</> <fg=gray>(Windows without WSL/git-bash: use .\\anvil.ps1 instead of ./anvil below)</>:');
        $this->line('     ./anvil up -d');
        $this->line('     ./anvil forge key:generate');
        $this->line('     ./anvil forge migrate --seed');
        $this->newLine();

        $this->printEnvHints($requested);

        return self::SUCCESS;
    }

    /**
     * `--services` wins when given (scripted/CI use, `--no-interaction`
     * stays non-interactive automatically via Symfony Console). Otherwise,
     * on a real terminal, asks — a blank `--services=` (or none at all) used
     * to silently mean "app only", which is easy to not realize you got
     * until docker-compose.yml already needs `--force` to fix.
     *
     * @return string[]|null Null means "already reported an error, bail".
     */
    private function resolveServices(): ?array
    {
        $option = $this->option('services');

        if ($option !== null && trim((string) $option) !== '') {
            $requested = array_filter(array_map('trim', explode(',', (string) $option)));
            $unknown = array_diff($requested, array_keys(self::SERVICES));

            if (!empty($unknown)) {
                $this->error('Unknown service(s): ' . implode(', ', $unknown) . '. Valid: ' . implode(', ', array_keys(self::SERVICES)));
                return null;
            }

            return array_values($requested);
        }

        if (!$this->canPrompt()) {
            return [];
        }

        return $this->multiChoice(
            'Which services do you want alongside the app container? (space to select, enter to confirm, none for app-only/SQLite)',
            array_keys(self::SERVICES)
        );
    }

    /** @param string[] $services */
    private function printEnvHints(array $services): void
    {
        $hints = [
            'mysql' => 'MySQL added — .env: DB_DRIVER=mysql, DB_HOST=mysql, DB_DATABASE/DB_USERNAME/DB_PASSWORD to match docker-compose.yml.',
            'mariadb' => 'MariaDB added — .env: DB_DRIVER=mysql, DB_HOST=mariadb, DB_DATABASE/DB_USERNAME/DB_PASSWORD to match docker-compose.yml (MariaDB speaks the MySQL protocol, same driver).',
            'pgsql' => 'PostgreSQL added — .env: DB_DRIVER=pgsql, DB_HOST=pgsql, DB_DATABASE/DB_USERNAME/DB_PASSWORD to match docker-compose.yml.',
            'redis' => 'Redis added — .env: CACHE_DRIVER=redis, REDIS_DSN=redis://redis:6379. Requires predis/predis.',
            'memcached' => 'Memcached added — no built-in Marrow cache driver for it yet; connect to it yourself at memcached:11211 if needed.',
            'mailpit' => 'Mailpit added — .env: MAIL_DSN=smtp://mailpit:1025. Web UI at http://localhost:8025 once running.',
            'minio' => 'MinIO added — .env filesystems disk: driver s3, endpoint http://minio:9000, use_path_style=true. Console at http://localhost:8900.',
            'node' => 'Node added — ./anvil npm install && ./anvil npm run dev (see docs/frontend.md).',
        ];

        foreach ($services as $service) {
            if (isset($hints[$service])) {
                $this->warn($hints[$service]);
            }
        }
    }

    private function writeFile(string $path, string $contents, bool $force): void
    {
        if (is_file($path) && !$force) {
            $interactiveOverwrite = $this->canPrompt()
                && $this->confirm("{$path} already exists — overwrite it?", false);

            if (!$interactiveOverwrite) {
                $this->warn("Skipped (already exists): {$path} — pass --force to overwrite.");
                return;
            }
        }
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $contents);
        $this->success("Created: {$path}");
    }

    /** @param string[] $services */
    private function composeStub(array $services): string
    {
        $dbServices = array_values(array_filter($services, fn (string $s) => self::SERVICES[$s]['db'] ?? false));
        // `condition: service_healthy`, not a bare service-name list — without
        // it, `depends_on` only waits for the *container* to start, not for
        // the database inside it to actually accept connections, so `app`
        // can (and does, intermittently) race a migration against a MySQL/
        // Postgres still initializing on first `./anvil up`.
        $depends = empty($dbServices) ? '' : "\n        depends_on:\n" . implode("\n", array_map(
            fn ($s) => "            {$s}:\n                condition: service_healthy",
            $dbServices
        ));

        $blocks = '';
        $volumeNames = [];

        foreach ($services as $service) {
            $blocks .= $this->serviceBlock($service);
            $volume = self::SERVICES[$service]['volume'] ?? null;
            if ($volume !== null) {
                $volumeNames[] = $volume;
            }
        }

        $volumesBlock = '';
        if (!empty($volumeNames)) {
            $volumesBlock = "\nvolumes:";
            foreach ($volumeNames as $name) {
                $volumesBlock .= "\n    {$name}:\n        driver: local";
            }
        }

        return <<<YAML
# Generated by `php forge anvil:install --services={$this->servicesLabel($services)}`.
# Safe to edit — re-running the command won't overwrite this file unless you pass --force.
services:
    app:
        build:
            context: ./docker/app
        ports:
            - '\${APP_PORT:-8000}:8000'
        environment:
            APP_ENV: '\${APP_ENV:-local}'
        volumes:
            - '.:/var/www/html'
        networks:
            - anvil{$depends}
        command: php forge serve --host=0.0.0.0 --port=8000
{$blocks}
networks:
    anvil:
        driver: bridge
{$volumesBlock}

YAML;
    }

    /** @param string[] $services */
    private function servicesLabel(array $services): string
    {
        return empty($services) ? 'none' : implode(',', $services);
    }

    private function serviceBlock(string $service): string
    {
        return match ($service) {
            'mysql' => <<<YAML

    mysql:
        image: 'mysql:8.0'
        ports:
            - '\${DB_PORT:-3306}:3306'
        environment:
            MYSQL_ROOT_PASSWORD: '\${DB_PASSWORD:-secret}'
            MYSQL_DATABASE: '\${DB_DATABASE:-app}'
            MYSQL_USER: '\${DB_USERNAME:-marrow}'
            MYSQL_PASSWORD: '\${DB_PASSWORD:-secret}'
        volumes:
            - 'anvil-mysql:/var/lib/mysql'
        healthcheck:
            test: ['CMD', 'mysqladmin', 'ping', '-h', 'localhost']
            interval: 5s
            timeout: 5s
            retries: 10
            start_period: 10s
        networks:
            - anvil

YAML,
            'mariadb' => <<<YAML

    mariadb:
        image: 'mariadb:11'
        ports:
            - '\${DB_PORT:-3306}:3306'
        environment:
            MARIADB_ROOT_PASSWORD: '\${DB_PASSWORD:-secret}'
            MARIADB_DATABASE: '\${DB_DATABASE:-app}'
            MARIADB_USER: '\${DB_USERNAME:-marrow}'
            MARIADB_PASSWORD: '\${DB_PASSWORD:-secret}'
        volumes:
            - 'anvil-mariadb:/var/lib/mysql'
        healthcheck:
            test: ['CMD', 'healthcheck.sh', '--connect', '--innodb_initialized']
            interval: 5s
            timeout: 5s
            retries: 10
            start_period: 10s
        networks:
            - anvil

YAML,
            'pgsql' => <<<YAML

    pgsql:
        image: 'postgres:16-alpine'
        ports:
            - '\${DB_PORT:-5432}:5432'
        environment:
            POSTGRES_DB: '\${DB_DATABASE:-app}'
            POSTGRES_USER: '\${DB_USERNAME:-marrow}'
            POSTGRES_PASSWORD: '\${DB_PASSWORD:-secret}'
        volumes:
            - 'anvil-pgsql:/var/lib/postgresql/data'
        healthcheck:
            test: ['CMD-SHELL', 'pg_isready -U \${DB_USERNAME:-marrow}']
            interval: 5s
            timeout: 5s
            retries: 10
            start_period: 10s
        networks:
            - anvil

YAML,
            'redis' => <<<YAML

    redis:
        image: 'redis:7-alpine'
        ports:
            - '\${REDIS_PORT:-6379}:6379'
        volumes:
            - 'anvil-redis:/data'
        healthcheck:
            test: ['CMD', 'redis-cli', 'ping']
            interval: 5s
            timeout: 5s
            retries: 10
            start_period: 5s
        networks:
            - anvil

YAML,
            'memcached' => <<<YAML

    memcached:
        image: 'memcached:1.6-alpine'
        ports:
            - '\${MEMCACHED_PORT:-11211}:11211'
        networks:
            - anvil

YAML,
            'mailpit' => <<<YAML

    mailpit:
        image: 'axllent/mailpit:latest'
        ports:
            - '\${MAILPIT_PORT:-1025}:1025'
            - '\${MAILPIT_DASHBOARD_PORT:-8025}:8025'
        networks:
            - anvil

YAML,
            'minio' => <<<YAML

    minio:
        image: 'minio/minio:latest'
        ports:
            - '\${MINIO_PORT:-9000}:9000'
            - '\${MINIO_CONSOLE_PORT:-8900}:8900'
        environment:
            MINIO_ROOT_USER: '\${MINIO_ROOT_USER:-marrow}'
            MINIO_ROOT_PASSWORD: '\${MINIO_ROOT_PASSWORD:-secretsecret}'
        volumes:
            - 'anvil-minio:/data'
        command: minio server /data --console-address ":8900"
        networks:
            - anvil

YAML,
            'node' => <<<YAML

    node:
        image: 'node:22-alpine'
        working_dir: /var/www/html
        volumes:
            - '.:/var/www/html'
        ports:
            - '\${VITE_PORT:-5173}:5173'
        command: sh -c "npm install && npm run dev -- --host 0.0.0.0"
        networks:
            - anvil

YAML,
            default => '',
        };
    }

    private function dockerfileStub(): string
    {
        return <<<'DOCKERFILE'
# Generated by `php forge anvil:install`.
FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libsqlite3-dev libpq-dev libmemcached-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

EXPOSE 8000
DOCKERFILE;
    }

    private function anvilScriptStub(): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
#
# Generated by `php forge anvil:install`. Thin wrapper around
# `docker compose`, mirroring Laravel Sail's UX under Marrow's own name:
#
#   ./anvil up [-d]        start the environment
#   ./anvil down           stop it
#   ./anvil forge ...      run `php forge ...` inside the app container
#   ./anvil composer ...   run composer inside the app container
#   ./anvil npm ...        run npm inside the node service (needs --services=node)
#   ./anvil shell           open a shell in the app container
#   ./anvil test            run the test suite inside the app container
#   ./anvil queue           run `php forge queue:work` inside the app container
#   ./anvil fresh           drop + re-migrate + re-seed (php forge migrate:fresh --seed)
#   anything else is passed straight to `docker compose`.
#
# Windows without WSL/git-bash: use anvil.ps1 instead (same subcommands).

set -e

COMPOSE="docker compose"
SERVICE="app"

if [ "$1" = "up" ] || [ "$1" = "down" ] || [ "$1" = "ps" ] || [ "$1" = "logs" ] || [ "$1" = "build" ]; then
    exec $COMPOSE "$@"
fi

if [ "$1" = "forge" ]; then
    shift
    exec $COMPOSE exec $SERVICE php forge "$@"
fi

if [ "$1" = "composer" ]; then
    shift
    exec $COMPOSE exec $SERVICE composer "$@"
fi

if [ "$1" = "npm" ]; then
    shift
    exec $COMPOSE exec node npm "$@"
fi

if [ "$1" = "test" ]; then
    exec $COMPOSE exec $SERVICE composer test
fi

if [ "$1" = "queue" ]; then
    exec $COMPOSE exec $SERVICE php forge queue:work
fi

if [ "$1" = "fresh" ]; then
    exec $COMPOSE exec $SERVICE php forge migrate:fresh --seed
fi

if [ "$1" = "shell" ] || [ "$1" = "bash" ]; then
    exec $COMPOSE exec $SERVICE bash
fi

exec $COMPOSE "$@"
BASH;
    }

    private function anvilPs1Stub(): string
    {
        return <<<'POWERSHELL'
#!/usr/bin/env pwsh
#
# Generated by `php forge anvil:install`. PowerShell twin of the `anvil`
# bash script, for Windows without WSL/git-bash — same subcommands:
#
#   .\anvil.ps1 up -d
#   .\anvil.ps1 forge migrate
#   .\anvil.ps1 composer require x
#   .\anvil.ps1 npm run build     (needs --services=node)
#   .\anvil.ps1 shell
#   .\anvil.ps1 test
#   .\anvil.ps1 queue
#   .\anvil.ps1 fresh
#   anything else is passed straight to `docker compose`.

param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $Args
)

$Service = 'app'
$Sub = $Args[0]
$Rest = if ($Args.Length -gt 1) { $Args[1..($Args.Length - 1)] } else { @() }

switch ($Sub) {
    { $_ -in 'up', 'down', 'ps', 'logs', 'build' } { docker compose @Args; break }
    'forge'    { docker compose exec $Service php forge @Rest; break }
    'composer' { docker compose exec $Service composer @Rest; break }
    'npm'      { docker compose exec node npm @Rest; break }
    'test'     { docker compose exec $Service composer test; break }
    'queue'    { docker compose exec $Service php forge queue:work; break }
    'fresh'    { docker compose exec $Service php forge migrate:fresh --seed; break }
    { $_ -in 'shell', 'bash' } { docker compose exec $Service bash; break }
    default    { docker compose @Args }
}
exit $LASTEXITCODE
POWERSHELL;
    }
}
