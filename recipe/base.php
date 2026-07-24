<?php

/**
 * Base recipe: shared configuration, the tasks that are identical across every
 * site (storage sync, reset, first-time setup) and the default `deploy` task.
 *
 * Deployer's own `recipe/laravel.php` and `contrib/rsync.php` must be loaded
 * before this file — the entry recipes (app.php / signatur.php) take care of it.
 */

namespace Deployer;

require_once __DIR__ . '/../lib/functions.php';

/*
|--------------------------------------------------------------------------
| Configuration defaults — override per host/site in deploy.php
|--------------------------------------------------------------------------
*/

set('allow_anonymous_stats', false);

// PHP binary for every remote artisan/composer call. Plesk sites override this
// per host, e.g. ->set('remote_php', '/opt/plesk/php/8.3/bin/php').
set('remote_php', 'php');
set('bin/php', fn () => get('remote_php'));

// SSH key for `git pull` on the remote. null → bare `git pull` (~/.ssh/config).
set('git_ssh_key', null);

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

desc('Reset the remote working copy (git reset --hard)');
task('reset:hard', function () {
    if (! askConfirmation('Run `git reset --hard` on the remote? Local server-side changes will be lost.', false)) {
        writeln('<comment>Aborted.</comment>');

        return;
    }

    cd('{{deploy_path}}');
    run('git reset --hard');
    writeln('<info>✓ git reset --hard.</info>');
});

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
