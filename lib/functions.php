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

/** Remote: install Composer dependencies for production. */
function deploy_vendors(): void
{
    cd('{{deploy_path}}');
    // `{{bin/php}} $(which composer)` pins Composer to the same PHP as the rest
    // of the deploy — important on Plesk, where the default CLI `php` may be a
    // different version than the site runs on.
    run('{{bin/php}} $(which composer) install --no-dev --optimize-autoloader --no-interaction', ...long_running());
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
