<?php

/**
 * Base recipe: shared configuration, the tasks that are identical across every
 * site (storage sync, first-time setup) and the default `deploy` task. It also
 * pulls in recipe/tasks.php and registers the `dep shell` command.
 *
 * Deployer's own `recipe/laravel.php` and `contrib/rsync.php` must be loaded
 * before this file — the entry recipes (app.php / signatur.php) take care of it.
 */

namespace Deployer;

use Mmoollllee\LaravelDeployer\ShellCommand;

require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/ShellCommand.php';

/*
|--------------------------------------------------------------------------
| Configuration defaults — override per host/site in deploy.php
|--------------------------------------------------------------------------
*/

set('allow_anonymous_stats', false);

/*
 * In-place deploy: there is no releases/ directory and no `current` symlink, so
 * everything Deployer resolves through {{current_path}} has to point at the
 * webroot itself. Without this, `{{bin/artisan}}` expands to
 * `{{deploy_path}}/current/artisan` and every `artisan:*` task from
 * recipe/laravel.php — plus `dep run` and `logs:app` — misses the app.
 */
set('current_path', '{{deploy_path}}');
set('release_or_current_path', '{{deploy_path}}');

// PHP binary for every remote artisan/composer call. Plesk sites override this
// per host, e.g. ->set('remote_php', '/opt/plesk/php/8.3/bin/php').
set('remote_php', 'php');
set('bin/php', fn () => get('remote_php'));

/*
 * The remote Composer command, PHP-pinned only when that is actually safe.
 *
 * Composer is usually a PHAR (`#!/usr/bin/env php`), so prefixing it with
 * {{bin/php}} pins it to the same interpreter as the rest of the deploy —
 * important on Plesk, where the default CLI `php` may be a different version
 * than the site runs on.
 *
 * But some hosts expose Composer as a *shell* wrapper instead — phpenv/Plesk
 * ships `~/.phpenv/shims/composer` with `#!/usr/bin/env bash`. Handing that to
 * `php` makes PHP echo the script as plain text and exit 0: a silent no-op that
 * leaves vendor/ stale while the deploy reports success. So the prefix is added
 * only when the shebang really names a PHP interpreter; a wrapper is invoked
 * directly and picks its own PHP.
 */
set('bin/composer', function (): string {
    $composer = trim(run('command -v composer || true'));

    if ($composer === '') {
        throw new \RuntimeException(
            'No `composer` found on the remote. Install it or set the full path via set(\'bin/composer\', ...).'
        );
    }

    $shebang = trim(run('head -n 1 '.escapeshellarg($composer).' 2>/dev/null || true'));

    return shebang_runs_php($shebang)
        ? '{{bin/php}} '.$composer
        : $composer;
});

// SSH key for `git pull` on the remote. null → bare `git pull` (~/.ssh/config).
set('git_ssh_key', null);

// Extra environment for `dep shell`, e.g. ['COMPOSER_MEMORY_LIMIT' => '-1'].
// Values are exported inside double quotes, so `$VAR` is expanded on the server.
set('shell_env', []);

// Shorthand commands available in `dep shell`, as name => command. Each becomes
// a small executable in a cache dir that is prepended to PATH — an alias is a
// shell feature and cannot be exported into the session (see ShellCommand).
// {{bin/php}} is resolved before the shim is written, so `art` runs on the
// site's PHP even when a login profile pushes our PATH entry back.
set('shell_aliases', ['art' => '{{bin/php}} artisan']);

// Deploy toggles.
set('deploy_assets', true);         // run `npm ci && npm run build`
set('deploy_migrate', true);        // run `artisan migrate --force`
set('deploy_cache_clear', false);   // run `artisan cache:clear` after optimize
set('deploy_queue_restart', false); // run `artisan queue:restart` after optimize

// Storage folders synced by pull:files / push:files.
set('files', []);
set('files_pull_delete', true); // mirror on pull (rsync --delete)

// Tables for pull:db-refresh (see recipe/database.php).
set('db_pull_include', []);

// Local auth.json uploaded by `setup`/`push:auth` so the remote `composer install`
// can authenticate against private repos. null → auto-detect (project ./auth.json,
// then Composer's global auth.json).
set('auth_json', null);

// `dep setup --seed` opts into running seeders (not idempotent → destructive on a
// live DB), so they are off by default.
option('seed', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'setup: also run database seeders (fresh installs only)');

/*
|--------------------------------------------------------------------------
| Default deploy task (in-place git deploy)
|--------------------------------------------------------------------------
| Replace the Laravel recipe's release-based `deploy` GroupTask with our
| in-place git deploy. Sites with extra steps override `deploy` again with
| their own closure (closure → closure replacement is allowed; closure →
| GroupTask is not, which is why the recipe task is removed first).
*/

Deployer::get()->tasks->remove('deploy');

/*
 * `push` rsyncs the local working tree's uncommitted changes into
 * {{current_path}} and marks it DIRTY_RELEASE. That was harmless while
 * current_path pointed at a `current` symlink no in-place site has — now that it
 * is the webroot, a mistyped `dep push` (for `push:files` / `push:auth`) would
 * overwrite the live site from a dirty local checkout. Removing it makes the
 * bare name ambiguous, so Deployer asks instead of picking one.
 */
Deployer::get()->tasks->remove('push');

desc('Publish code on the remote (in-place git deploy)');
task('deploy', function () {
    deploy_standard();
    writeln('<info>✓ Deploy: git pull, composer, assets, migrate, optimize.</info>');
});

// Keep the recipe's failure hook alive for sites that add a maintenance window:
// Deployer's common.php maps a failing `deploy` command to the `deploy:failed`
// task. Re-asserting it here is idempotent and survives the remove() above.
fail('deploy', 'deploy:failed');

/*
|--------------------------------------------------------------------------
| Storage sync
|--------------------------------------------------------------------------
*/

desc('Pull storage from remote → local');
task('pull:files', function () {
    $options = get('files_pull_delete') ? ['options' => ['--delete']] : [];

    foreach (get('files') as $folder) {
        writeln("<info>Downloading {$folder}:</info>");
        // Trailing slashes so rsync mirrors the folder's contents instead of
        // nesting it (…/storage → ./storage, not ./storage/storage).
        $path = rtrim($folder, '/').'/';
        // Storage folders are git-ignored, and rsync creates at most the last
        // segment of the destination — so a nested target such as
        // storage/app/captures/<site>/ needs the tree below it to exist first.
        ensure_local_dir($path);
        download("{{deploy_path}}/{$path}", $path, $options);
    }

    writeln('<info>✓ Files pulled.</info>');
});

desc('Push storage from local → remote (never deletes on the server)');
task('push:files', function () {
    foreach (get('files') as $folder) {
        writeln("<info>Uploading {$folder}:</info>");
        $path = rtrim($folder, '/').'/';
        upload($path, "{{deploy_path}}/{$path}");
    }

    writeln('<info>✓ Files pushed.</info>');
});

/*
|--------------------------------------------------------------------------
| Maintenance helpers
|--------------------------------------------------------------------------
*/

desc('Upload the local auth.json to the remote (Composer credentials for private repos)');
task('push:auth', function () {
    $auth = local_auth_json();

    if ($auth === null) {
        warning('No local auth.json found (checked auth_json, ./auth.json, ~/.composer/auth.json) — private packages may fail to install.');

        return;
    }

    writeln("<info>Uploading {$auth} → auth.json</info>");
    upload($auth, '{{deploy_path}}/auth.json');
    // Credentials — keep them owner-only on the server.
    run('chmod 600 {{deploy_path}}/auth.json');
    writeln('<info>✓ auth.json uploaded.</info>');
});

desc('Non-destructive first-time setup on the remote (auth.json, .env, deps, key, storage-link, migrate, optimize)');
task('setup', function () {
    cd('{{deploy_path}}');

    // Create .env from .env.prod on the first run; never overwrite an existing one.
    run('if [ ! -f .env ] && [ -f .env.prod ]; then cp .env.prod .env; echo "Created .env from .env.prod"; fi');

    deploy_vendors();

    if (get('deploy_assets')) {
        deploy_assets();
    }

    // Generate an app key only if none is set (otherwise encrypted data breaks).
    run('grep -q "^APP_KEY=.\\+" .env || {{bin/php}} artisan key:generate --force');
    run('{{bin/php}} artisan storage:link --force');

    // migrate --force is additive and safe to re-run. Seeders run only with
    // `dep setup --seed`.
    if (get('deploy_migrate')) {
        $seed = (input()->hasOption('seed') && input()->getOption('seed')) ? ' --seed' : '';
        run('{{bin/php}} artisan migrate --force'.$seed, ...long_running());
    }

    deploy_optimize();

    writeln('<info>✓ Setup complete — deploy with `dep deploy`.</info>');
    writeln('<comment>→ Check the DB credentials in .env; upload storage with `dep push:files`.</comment>');
});

// Upload auth.json before setup so `composer install` can authenticate.
before('setup', 'push:auth');

/*
|--------------------------------------------------------------------------
| Everyday tasks
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/tasks.php';

/*
|--------------------------------------------------------------------------
| `dep shell` — interactive SSH into the webroot
|--------------------------------------------------------------------------
| A console command rather than a task, because tasks run in a worker
| subprocess without a tty and could not hand the terminal to `ssh -t`.
|
| The name has to be `shell`, not `ssh`: Deployer registers its own commands in
| Deployer::init(), which runs *after* this recipe is imported, so it would
| overwrite anything we put under one of its own names.
*/

$console = Deployer::get()->getConsole();
$command = new ShellCommand(Deployer::get());

// Symfony Console renamed add() to addCommand() in 7.4 — the version Deployer 8
// requires — but an older console pinned in the project would only have add().
if (method_exists($console, 'addCommand')) {
    $console->addCommand($command);
} else {
    $console->add($command);
}
