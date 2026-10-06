<?php

/**
 * Database pull recipe: copy the remote database to local via snapshots. Two entry points:
 *
 *   pull:db-refresh — only the tables listed in {{db_pull_include}}
 *   pull:db-full    — the whole database
 *
 * `--retry-load` skips the remote snapshot and the download and loads the
 * newest dump already in database/dumps again — for an import that broke off
 * after a pull of several hundred megabytes.
 *
 * Both leave local caches in a safe state (config:clear only). Sites that need
 * extra local fixups — e.g. rewriting tenant domains to *.test — hook a task
 * onto `after('pull:db-refresh', …)` and `after('pull:db-full', …)`.
 *
 * Requires the `snapshot:*` artisan commands (spatie/laravel-db-snapshots or a
 * compatible fork) in the consuming app — remote (snapshot:create/cleanup) and
 * local (snapshot:load). The recipe only shells out to them, so this is a
 * Composer `suggest`, not a `require`; it is asserted at run time.
 */

namespace Deployer;

use Symfony\Component\Console\Input\InputOption;

require_once __DIR__ . '/../lib/functions.php';

option('retry-load', null, InputOption::VALUE_NONE, 'pull:db-*: load the newest local dump again instead of pulling a new one');

/**
 * Fail early with a clear message when the app is missing the snapshot:* commands
 * that pull:db-* rely on (from spatie/laravel-db-snapshots or a compatible fork).
 * Checks the remote app (snapshot:create/cleanup) and the local app
 * (snapshot:load). Call after cd() into the remote app so the remote check runs
 * in {{deploy_path}}.
 */
function assert_snapshots_available(): void
{
    if (trim(run('{{bin/php}} artisan help snapshot:create >/dev/null 2>&1 && echo snapshots-ok', nothrow: true)) !== 'snapshots-ok') {
        throw new \RuntimeException('Remote app has no snapshot:* commands — add spatie/laravel-db-snapshots (or a compatible fork) to the project.');
    }

    if (trim(runLocally('php artisan help snapshot:load >/dev/null 2>&1 && echo snapshots-ok', nothrow: true)) !== 'snapshots-ok') {
        throw new \RuntimeException('Local app has no snapshot:* commands — add spatie/laravel-db-snapshots (or a compatible fork) to the project.');
    }
}

/**
 * Whether the run asked to load the last pulled dump again (`--retry-load`).
 */
function pull_db_retry_requested(): bool
{
    return input()->hasOption('retry-load') && (bool) input()->getOption('retry-load');
}

/**
 * Download the freshly created remote snapshot and load it into the local DB.
 * Shared by both pull tasks.
 *
 * @param bool $dropTables when false, keep local tables and only replace the
 *                         ones in the dump (snapshot:load --drop-tables=0)
 */
function pull_db_download_and_load(bool $dropTables): void
{
    // Newest dump the remote just wrote.
    $remote = run('ls -1t {{deploy_path}}/database/dumps | head -n1');

    // A fresh clone may not have the local dumps directory — whether it is
    // gitignored or merely empty — and rsync refuses to write a file into a
    // directory that is not there.
    ensure_local_dir('database/dumps');

    download("{{deploy_path}}/database/dumps/{$remote}", "database/dumps/{$remote}");
    run('{{bin/php}} artisan snapshot:cleanup --keep=1');

    pull_db_load($remote, $dropTables);
}

/**
 * Load the newest dump in the local database/dumps again, without touching the
 * remote — `dep pull:db-full --retry-load`. Refuses when there is none.
 *
 * @param bool $dropTables see pull_db_download_and_load()
 */
function pull_db_reload_latest(bool $dropTables): void
{
    $dumps = array_merge(glob('database/dumps/*.sql') ?: [], glob('database/dumps/*.sql.gz') ?: []);

    if ($dumps === []) {
        throw new \RuntimeException('No dump in database/dumps to load again — run the pull without --retry-load.');
    }

    usort($dumps, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
    $file = $dumps[0];

    // The age, not a clock time: Deployer's PHP usually runs in UTC, so a
    // formatted time would be hours off the developer's own clock.
    $minutes = intdiv(max(0, time() - filemtime($file)), 60);
    $age = match (true) {
        $minutes < 60 => "{$minutes} min",
        $minutes < 48 * 60 => intdiv($minutes, 60) . ' h',
        default => intdiv($minutes, 24 * 60) . ' days',
    };

    writeln(sprintf(
        '<info>Loading %s again (%.1f MB, pulled %s ago).</info>',
        basename($file),
        filesize($file) / 1024 / 1024,
        $age,
    ));

    pull_db_load(basename($file), $dropTables);
}

/**
 * Load a dump from the local database/dumps into the local DB.
 *
 * @param string $file       file name of the dump inside database/dumps
 * @param bool   $dropTables see pull_db_download_and_load()
 */
function pull_db_load(string $file, bool $dropTables): void
{
    // Strip the snapshot extension (.sql.gz when compressed, .sql when not) to
    // get the snapshot name that snapshot:load expects.
    $dump = escapeshellarg(preg_replace('/\.sql(\.gz)?$/', '', $file));
    $drop = $dropTables ? '' : ' --drop-tables=0';

    runLocally("php artisan snapshot:load {$dump}{$drop} --stream --force", ...long_running());

    // Clear ONLY the config cache locally — never `optimize`. `config:cache`
    // would bake the local .env (dev DB) into bootstrap/cache/config.php, after
    // which phpunit.xml's <env> overrides are ignored and a RefreshDatabase test
    // run would hit — and wipe — the real local database. `optimize:clear` would
    // additionally flush the runtime cache store (permissions, rate limiters …),
    // so config:clear is deliberately the narrowest safe choice.
    runLocally('php artisan config:clear');
}

desc('Pull selected DB tables from remote → local (snapshot)');
task('pull:db-refresh', function () {
    $tables = get('db_pull_include');

    if (empty($tables)) {
        writeln('<error>No tables defined. Set `db_pull_include` in deploy.php.</error>');

        return;
    }

    if (pull_db_retry_requested()) {
        pull_db_reload_latest(dropTables: false);
        writeln('<info>✓ DB refresh loaded again.</info>');

        return;
    }

    cd('{{deploy_path}}');
    assert_snapshots_available();
    run('{{bin/php}} artisan snapshot:create --table=' . implode(' --table=', $tables), ...long_running());

    pull_db_download_and_load(dropTables: false);

    writeln('<info>✓ DB refresh pulled via snapshots.</info>');
});

desc('Pull the full DB from remote → local (snapshot)');
task('pull:db-full', function () {
    if (pull_db_retry_requested()) {
        pull_db_reload_latest(dropTables: true);
        writeln('<info>✓ Full DB loaded again.</info>');

        return;
    }

    cd('{{deploy_path}}');
    assert_snapshots_available();
    run('{{bin/php}} artisan snapshot:create', ...long_running());

    pull_db_download_and_load(dropTables: true);

    writeln('<info>✓ Full DB pulled via snapshots.</info>');
});
