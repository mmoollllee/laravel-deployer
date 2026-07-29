<?php

/**
 * Everyday remote tasks — the things you reach for between deploys: git,
 * Composer, assets, logs and a status readout.
 *
 * The `artisan:*` tasks are not repeated here: Deployer's own `recipe/laravel.php`
 * already ships them (artisan:optimize, artisan:migrate, artisan:route:list,
 * artisan:down/up, artisan:queue:restart, artisan:horizon:*, …) and recipe/base.php
 * pins {{current_path}} to the webroot so they work with an in-place deploy.
 *
 * Loaded by recipe/base.php, so both entry recipes get them.
 */

namespace Deployer;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputOption;

require_once __DIR__ . '/../lib/functions.php';

// Shared by git:log, logs and logs:tail. No shortcut — `-l` is Deployer's --limit.
option('lines', null, InputOption::VALUE_REQUIRED, 'git:log / logs / logs:tail: how many lines to show');

/*
|--------------------------------------------------------------------------
| Git
|--------------------------------------------------------------------------
| All of these run in {{deploy_path}} and, where they talk to the remote,
| carry {{git_ssh_key}} via git_env().
*/

desc('Pull the latest code on the remote (no composer, assets or migrations)');
task('git:pull', function () {
    write_raw(deploy_update_code());
    writeln('<info>✓ git pull.</info>');
});

desc('Fetch remote refs without touching the working copy');
task('git:fetch', function () {
    cd('{{deploy_path}}');
    run('git fetch --prune', env: git_env());
    write_raw(run('git status --short --branch'));
});

desc('Show the state of the remote working copy (git status)');
task('git:status', function () {
    cd('{{deploy_path}}');

    $status = run('git status --short --branch');

    write_raw($status);

    // --short prints the branch line first; anything after it is a local edit.
    if (! str_contains($status, "\n")) {
        writeln('<info>✓ Working copy clean.</info>');
    }
});

desc('Show the last commits on the remote (--lines=N, default 10)');
task('git:log', function () {
    cd('{{deploy_path}}');
    write_raw(run('git log --no-color --oneline --decorate -n '.option_lines(10)));
});

desc('Discard local changes on the remote (git reset --hard)');
task('git:reset-hard', function () {
    if (! askConfirmation('Run `git reset --hard` on the remote? Local server-side changes will be lost.', false)) {
        writeln('<comment>Aborted.</comment>');

        return;
    }

    cd('{{deploy_path}}');
    run('git reset --hard');
    writeln('<info>✓ git reset --hard — now at:</info>');
    write_raw(run('git log --no-color -1 --oneline'));
});

// 0.1 shipped this as `reset:hard`; keep the old name working.
desc('Alias of git:reset-hard');
task('reset:hard', ['git:reset-hard']);

/*
|--------------------------------------------------------------------------
| Composer / assets
|--------------------------------------------------------------------------
*/

desc('Install Composer dependencies on the remote (--no-dev, optimized autoloader)');
task('composer:install', function () {
    deploy_vendors();
    writeln('<info>✓ composer install.</info>');
});

desc('Rebuild the optimized Composer autoloader on the remote');
task('composer:dump', function () {
    cd('{{deploy_path}}');
    assert_composer_runnable();
    run('{{bin/composer}} dump-autoload --optimize --no-dev --no-interaction', ...long_running());
    writeln('<info>✓ composer dump-autoload.</info>');
});

desc('Build front-end assets on the remote (npm ci && npm run build)');
task('assets:build', function () {
    deploy_assets();
    writeln('<info>✓ Assets built.</info>');
});

/*
|--------------------------------------------------------------------------
| Deploy variants
|--------------------------------------------------------------------------
*/

desc('Code-only deploy: git pull + rebuild caches (no composer, assets or migrations)');
task('deploy:quick', function () {
    write_raw(deploy_update_code());

    // Drop the stale caches first — otherwise a changed config or blade file
    // keeps being served out of bootstrap/cache.
    deploy_clear();
    deploy_optimize();

    // Same toggles as a full deploy: a quick deploy publishes new code too, so a
    // running queue worker still needs the restart to pick up the new caches.
    deploy_post_optimize();

    writeln('<info>✓ Quick deploy: git pull, optimize.</info>');
});

/*
|--------------------------------------------------------------------------
| Logs
|--------------------------------------------------------------------------
| {{log_files}} comes from Deployer's Laravel recipe (storage/logs/*.log).
*/

desc('Show the tail of the newest remote log file (--lines=N, default 100)');
task('logs', function () {
    $file = latest_log_file();

    if ($file === null) {
        // Does not interpolate {{log_files}}: an unset log_files is one of the
        // two reasons to be here, and parse() would throw on it.
        warning('No readable log file in {{deploy_path}} — check the log_files option.');

        return;
    }

    write_raw($file);
    write_raw(run(sprintf('tail -n %d %s', option_lines(100), quote($file))));
});

desc('Follow the newest remote log file, Ctrl-C to stop (--lines=N, default 20)');
task('logs:tail', function () {
    $file = latest_log_file();

    if ($file === null) {
        // Does not interpolate {{log_files}}: an unset log_files is one of the
        // two reasons to be here, and parse() would throw on it.
        warning('No readable log file in {{deploy_path}} — check the log_files option.');

        return;
    }

    write_raw($file);
    // Spelled out rather than spread from long_running(), because this one also
    // needs nothrow: no timeout and live output (otherwise `tail -f` would be
    // killed after {{default_timeout}} and buffer everything until then), and a
    // tail that ends because the user interrupted it is not a failure.
    run(
        sprintf('tail -n %d -f %s', option_lines(20), quote($file)),
        nothrow: true,
        forceOutput: true,
        timeout: 0,
    );
});

desc('Truncate the remote log files (asks first)');
task('logs:clear', function () {
    $glob = log_files_glob();

    if ($glob === null) {
        warning('No log_files option set — nothing to truncate.');

        return;
    }

    if (! askConfirmation(sprintf('Truncate all log files matching %s on the remote?', $glob), false)) {
        writeln('<comment>Aborted.</comment>');

        return;
    }

    cd('{{deploy_path}}');
    // Truncate instead of delete, so a process holding the file open keeps
    // writing to the same inode. The -f test also swallows a glob that matched
    // nothing.
    run(sprintf('for f in %s; do if [ -f "$f" ]; then : > "$f"; fi; done', $glob));
    writeln('<info>✓ Logs truncated.</info>');
});

/*
|--------------------------------------------------------------------------
| Status
|--------------------------------------------------------------------------
*/

desc('Show what is deployed on the remote (commit, PHP, Laravel, .env)');
task('app:info', function () {
    cd('{{deploy_path}}');

    // Every probe is nothrow: a half-configured host should still print the rows
    // it can answer instead of aborting on the first one. nothrow returns stdout
    // only, so a failed probe is an empty string and shows up as "?".
    $branch = run('git rev-parse --abbrev-ref HEAD', nothrow: true);
    $php = run('{{bin/php}} -r "echo PHP_VERSION;"', nothrow: true);

    $rows = [
        'Path' => get('deploy_path'),
        'Branch' => $branch,
        'Commit' => run('git log --no-color -1 --pretty="%h %s (%cr, %an)"', nothrow: true),
        // Derived from $branch, not from an empty `git status`: outside a
        // repository git writes to stderr and leaves stdout empty, which would
        // otherwise read as "no local changes".
        'Working copy' => $branch === ''
            ? ''
            : (trim(run('git status --porcelain', nothrow: true)) === '' ? 'clean' : 'has local changes'),
        'PHP' => $php === '' ? '' : $php.' ('.get('bin/php').')',
        'Laravel' => run('{{bin/php}} artisan --version 2>/dev/null', nothrow: true),
        'APP_ENV' => env_value('APP_ENV'),
        'APP_DEBUG' => env_value('APP_DEBUG'),
        'APP_URL' => env_value('APP_URL'),
    ];

    if (get('deploy_migrate')) {
        $pending = trim(run('{{bin/php}} artisan migrate:status 2>/dev/null | grep -c Pending || true', nothrow: true));
        $rows['Migrations'] = ($pending === '' || $pending === '0') ? 'up to date' : "{$pending} pending";
    }

    write_line('');

    foreach ($rows as $label => $value) {
        // write_line() keeps the label's style tags for the master's formatter
        // and skips the worker's, so the escape on the remote value survives
        // both — see write_raw().
        write_line(sprintf(
            '  <info>%-13s</info> %s',
            $label,
            $value === '' ? '<comment>?</comment>' : OutputFormatter::escape($value),
        ));
    }

    write_line('');
});
