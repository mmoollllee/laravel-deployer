<?php

/**
 * Example deploy.php for a full Laravel app (DB + assets + migrations).
 * Copy into a project root and adjust host, keys, tables and hooks.
 */

namespace Deployer;

require 'vendor/mmoollllee/laravel-deployer/recipe/app.php';

host('example.com')
    ->set('remote_user', '1234')
    ->set('deploy_path', '~/example.com')
    // Plesk: pin the PHP binary the site runs on (optional; defaults to `php`).
    ->set('remote_php', '/opt/plesk/php/8.3/bin/php')
    // Deploy key for `git pull` (optional; omit to use ~/.ssh/config).
    ->set('git_ssh_key', '~/.ssh/example_ed25519');

// Tables copied by `dep pull:db-refresh`.
set('db_pull_include', [
    'migrations',
    'users',
]);

// Storage folders synced by `dep pull:files` / `dep push:files`.
set('files', [
    'storage/app/public/',
]);

// Extra environment for `dep shell` (on top of GIT_SSH_COMMAND and the pinned
// PHP binary, which are exported automatically).
set('shell_env', [
    'COMPOSER_MEMORY_LIMIT' => '-1',
]);

// Shorthands for `dep shell`. `art` is the default; listing it keeps it when
// adding more. Set to [] to get a plain shell.
set('shell_aliases', [
    'art' => '{{bin/php}} artisan',
]);

/*
|--------------------------------------------------------------------------
| Site-specific extras
|--------------------------------------------------------------------------
| Common post-deploy steps are opt-in toggles — no override needed:
*/

set('deploy_cache_clear', true);   // artisan cache:clear after optimize
set('deploy_queue_restart', true); // artisan queue:restart after optimize

/*
| For anything bespoke, override `deploy` and compose the deploy_*() helpers.
| deploy_standard() runs them in the default order (update-code, vendors,
| optimize, assets, clear, migrate, optimize) plus the toggles above.
*/

desc('Publish code on the remote');
task('deploy', function () {
    deploy_standard();

    cd('{{deploy_path}}');
    run('{{bin/php}} artisan storage:link');
});

/*
| Local fixups after a DB pull — e.g. rewrite tenant domains to *.test.
| Hook onto both pull tasks:
|
| task('db:localize', function () {
|     runLocally('php artisan app:localize-tenant-domains');
| });
| after('pull:db-refresh', 'db:localize');
| after('pull:db-full', 'db:localize');
*/
