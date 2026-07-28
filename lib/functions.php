<?php

/**
 * Shared helpers for the mmoollllee/laravel-deployer recipes.
 *
 * Everything lives in the Deployer namespace so the helpers can call Deployer's
 * own functions (get, run, cd, …) without imports and be called bare from the
 * recipes.
 */

namespace Deployer;

// This file only declares functions (no side effects). The recipes pull it in
// via require_once, so it loads exactly once per run — a runtime guard would not
// help here anyway, because top-level function declarations are early-bound at
// compile time, before any guard could run.

/**
 * Named-argument options for long-running commands, spread into run()/runLocally()
 * as `run($cmd, ...long_running())`. Disables the command timeout (0 → Symfony
 * Process treats it as "no timeout", so composer/npm/migrate survive past
 * Deployer's 300 s default) and streams output live.
 *
 * Deployer 8 replaced v7's options-array with named scalar parameters, so this
 * MUST be spread (`...`) — passing it positionally would bind the array to
 * run()'s `$cwd` and throw a TypeError.
 *
 * @return array{timeout: int, forceOutput: bool}
 */
function long_running(): array
{
    return [
        'timeout' => 0,
        'forceOutput' => true,
    ];
}

/**
 * Builds the `git pull` command. When {{git_ssh_key}} is set it pins that key
 * (always with IdentitiesOnly=yes, so no other agent key is offered); otherwise
 * it falls back to a bare `git pull` that relies on ~/.ssh/config.
 */
function git_pull_command(): string
{
    $key = get('git_ssh_key');

    if (empty($key)) {
        return 'git pull';
    }

    // Double-quote the key path (inside the single-quoted value) so a path with
    // spaces still parses as a single -i argument for git.
    return sprintf("GIT_SSH_COMMAND='ssh -i \"%s\" -o IdentitiesOnly=yes' git pull", $key);
}

/**
 * Resolves the local auth.json to upload so the remote `composer install` can
 * authenticate against private repositories (e.g. filament-media-library-pro on
 * satis.ralphjsmit.com). Checks, in order: the {{auth_json}} override, a
 * project-local auth.json, then Composer's global auth.json. Returns null when
 * none exists.
 */
function local_auth_json(): ?string
{
    $home = getenv('HOME') ?: '';

    $candidates = array_filter([
        get('auth_json'),
        'auth.json',
        $home !== '' ? "{$home}/.composer/auth.json" : null,
        $home !== '' ? "{$home}/.config/composer/auth.json" : null,
    ]);

    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

/** Remote: pull the latest code via git. */
function deploy_update_code(): void
{
    cd('{{deploy_path}}');
    run(git_pull_command());
}

/**
 * Whether a shebang line hands the file to a PHP interpreter.
 *
 * Only the interpreter itself counts, never the rest of the line — the phpenv
 * shim that motivated this lives under `~/.phpenv/shims/`, so a substring match
 * on "php" would misread `#!/usr/bin/env bash` scripts sitting in a php-ish
 * path. `env` is unwrapped so `#!/usr/bin/env php` resolves to `php`.
 */
function shebang_runs_php(string $shebang): bool
{
    if (! preg_match('/^#!\s*(\S+)(?:\s+(\S+))?/', $shebang, $matches)) {
        return false;
    }

    $interpreter = basename($matches[1]);

    if ($interpreter === 'env') {
        $interpreter = basename($matches[2] ?? '');
    }

    // php, php8, php8.3, php-cgi — but not `bash`, `sh`, `python`.
    return (bool) preg_match('/^php[\d.\-]*$/', $interpreter);
}

/**
 * Remote: install Composer dependencies for production.
 *
 * {{bin/composer}} resolves how Composer must be invoked on this host (see
 * recipe/base.php). The version probe in front is the guard that made this
 * necessary: a misresolved binary can "succeed" with exit code 0 and install
 * nothing, which leaves vendor/ silently stale for release after release.
 */
function deploy_vendors(): void
{
    cd('{{deploy_path}}');
    assert_composer_runnable();
    run('{{bin/composer}} install --no-dev --optimize-autoloader --no-interaction', ...long_running());
}

/**
 * Fail loudly when {{bin/composer}} does not actually execute Composer.
 *
 * @throws \RuntimeException when the command produces no Composer version banner
 */
function assert_composer_runnable(): void
{
    $output = trim(run('{{bin/composer}} --version 2>&1 || true'));

    if (! str_contains($output, 'Composer version')) {
        throw new \RuntimeException(
            "`{{bin/composer}}` did not run Composer on the remote — vendor/ would be left untouched.\n".
            "Got: ".(($output === '') ? '(no output)' : mb_substr($output, 0, 300))."\n".
            "Set the correct command per host, e.g. ->set('bin/composer', '/usr/local/bin/composer')."
        );
    }
}

/** Remote: install locked npm dependencies and build front-end assets. */
function deploy_assets(): void
{
    cd('{{deploy_path}}');
    run('npm ci', ...long_running());
    run('npm run build', ...long_running());
}

/** Remote: drop stale config/route/view caches before migrating. */
function deploy_clear(): void
{
    cd('{{deploy_path}}');
    run('{{bin/php}} artisan optimize:clear');
}

/** Remote: run database migrations. */
function deploy_migrate(): void
{
    cd('{{deploy_path}}');
    run('{{bin/php}} artisan migrate --force', ...long_running());
}

/** Remote: rebuild the framework caches. */
function deploy_optimize(): void
{
    cd('{{deploy_path}}');
    run('{{bin/php}} artisan optimize');
}

/**
 * The standard in-place deploy sequence. Honours {{deploy_assets}} and
 * {{deploy_migrate}} so sites without a front-end build or a database can reuse
 * it unchanged. Sites with extra steps define their own `deploy` task and call
 * these helpers in whatever order they need.
 */
function deploy_standard(): void
{
    deploy_update_code();
    deploy_vendors();

    if (get('deploy_assets')) {
        deploy_assets();
    }

    if (get('deploy_migrate')) {
        deploy_clear();
        deploy_migrate();
    }

    deploy_optimize();

    cd('{{deploy_path}}');

    if (get('deploy_cache_clear')) {
        run('{{bin/php}} artisan cache:clear');
    }

    if (get('deploy_queue_restart')) {
        run('{{bin/php}} artisan queue:restart');
    }
}
