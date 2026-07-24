<?php

/**
 * Database pull recipe: copy the remote database to local via snapshots
 * (mmoollllee/laravel-db-snapshots). Two entry points:
 *
 *   pull:db-refresh — only the tables listed in {{db_pull_include}}
 *   pull:db-full    — the whole database
 *
 * Both leave local caches in a safe state (config:clear only). Sites that need
 * extra local fixups — e.g. rewriting tenant domains to *.test — hook a task
 * onto `after('pull:db-refresh', …)` and `after('pull:db-full', …)`.
 */

namespace Deployer;

require_once __DIR__ . '/../lib/functions.php';

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

    download("{{deploy_path}}/database/dumps/{$remote}", "database/dumps/{$remote}");
    run('{{bin/php}} artisan snapshot:cleanup --keep=1');

    // Strip the snapshot extension (.sql.gz when compressed, .sql when not) to
    // get the snapshot name that snapshot:load expects.
    $dump = escapeshellarg(preg_replace('/\.sql(\.gz)?$/', '', $remote));
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

    cd('{{deploy_path}}');
    run('{{bin/php}} artisan snapshot:create --table=' . implode(' --table=', $tables), ...long_running());

    pull_db_download_and_load(dropTables: false);

    writeln('<info>✓ DB refresh pulled via snapshots.</info>');
});

desc('Pull the full DB from remote → local (snapshot)');
task('pull:db-full', function () {
    cd('{{deploy_path}}');
    run('{{bin/php}} artisan snapshot:create', ...long_running());

    pull_db_download_and_load(dropTables: true);

    writeln('<info>✓ Full DB pulled via snapshots.</info>');
});
