<?php

use Illuminate\Support\Facades\File;

/*
 * `dep pull:db-full` drops a production snapshot into the working tree. The only
 * thing between that file and the repository's permanent history is somebody
 * noticing it in `git status` — so the guard has to hold, and these tests run
 * the shipped hook against a real repository rather than trusting it by reading.
 */

/**
 * A throwaway git repository with the hook installed, standing in for a project.
 */
function hookedRepository(): string
{
    $root = sys_get_temp_dir().'/deployer-hooks-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($root);

    exec('git -C '.escapeshellarg($root).' init --quiet 2>&1');
    exec('git -C '.escapeshellarg($root).' config user.email test@example.test');
    exec('git -C '.escapeshellarg($root).' config user.name Test');

    return $root;
}

/**
 * The shipped hook, with the dump directory baked in the way the command does.
 */
function installHookInto(string $root, string $dumpDirectory = 'database/dumps'): void
{
    $stub = (string) file_get_contents(__DIR__.'/../hooks/pre-commit');

    File::ensureDirectoryExists($root.'/.githooks');
    file_put_contents(
        $root.'/.githooks/pre-commit',
        str_replace('__DUMP_DIR__', str_replace("'", "'\\''", $dumpDirectory), $stub),
    );
    chmod($root.'/.githooks/pre-commit', 0o755);

    exec('git -C '.escapeshellarg($root).' config core.hooksPath .githooks');
}

/**
 * Stage a file with the given contents and try to commit. Returns the exit code
 * — non-zero means the hook refused.
 *
 * A refused commit leaves the file STAGED, which is the hook working as
 * intended but would make every later call in the same repository fail too. The
 * index is cleared afterwards so each call stands on its own, the way a
 * developer would unstage before trying something else.
 */
function commitFile(string $root, string $path, string $contents = 'x'): int
{
    File::ensureDirectoryExists(dirname($root.'/'.$path));
    file_put_contents($root.'/'.$path, $contents);

    $output = [];

    exec('git -C '.escapeshellarg($root).' add '.escapeshellarg($path).' 2>&1');
    exec('git -C '.escapeshellarg($root).' commit -m test 2>&1', $output, $exitCode);

    if ($exitCode !== 0) {
        exec('git -C '.escapeshellarg($root).' reset --quiet 2>&1');
    }

    return $exitCode;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/deployer-hooks-*') ?: [] as $leftover) {
        File::deleteDirectory($leftover);
    }
});

it('refuses a dump in the snapshot directory', function () {
    $root = hookedRepository();
    installHookInto($root);

    expect(commitFile($root, 'database/dumps/2026-01-01_10-00-00.sql.gz'))->not->toBe(0)
        ->and(file_exists($root.'/database/dumps/2026-01-01_10-00-00.sql.gz'))->toBeTrue();
});

it('refuses a dump anywhere else in the tree', function () {
    $root = hookedRepository();
    installHookInto($root);

    // Renaming or moving it must not get it past the guard.
    expect(commitFile($root, 'backup.sql'))->not->toBe(0)
        ->and(commitFile($root, 'storage/old.dump'))->not->toBe(0);
});

it('lets ordinary files through', function () {
    $root = hookedRepository();
    installHookInto($root);

    expect(commitFile($root, 'app/Models/User.php', '<?php'))->toBe(0);
});

it('lets the placeholder that keeps the dump directory in git through', function () {
    $root = hookedRepository();
    installHookInto($root);

    expect(commitFile($root, 'database/dumps/.gitkeep', ''))->toBe(0);
});

it('still catches dumps when the app has no snapshot directory configured', function () {
    $root = hookedRepository();
    installHookInto($root, dumpDirectory: '');

    expect(commitFile($root, 'anywhere/snapshot.sql'))->not->toBe(0)
        ->and(commitFile($root, 'app/Models/User.php', '<?php'))->toBe(0);
});

it('refuses a dump whose name git has to quote', function () {
    $root = hookedRepository();
    installHookInto($root);

    // A German dump name: git C-quotes non-ASCII bytes unless core.quotePath is
    // off, and quotes control characters and backslashes regardless. Matching a
    // quoted path by substring silently misses it, which is the one failure
    // this guard cannot afford.
    expect(commitFile($root, 'database/dumps/produktionsdaten-münch.sql.gz'))->not->toBe(0);
});

it('refuses a staged path it cannot parse rather than waving it through', function () {
    $root = hookedRepository();
    installHookInto($root);

    // A backslash in the name stays quoted whatever core.quotePath says.
    expect(commitFile($root, 'database/dumps/back\\slash.bin'))->not->toBe(0);
});

it('matches dump extensions case-insensitively and beyond .sql.gz', function () {
    $root = hookedRepository();
    installHookInto($root);

    foreach (['BACKUP.SQL', 'db.sql.zst', 'db.sql.xz', 'snap.sqlite', 'old.dump.gz'] as $name) {
        expect(commitFile($root, $name))->not->toBe(0, "[{$name}] should be refused");
    }
});

it('refuses a dump staged as a rename of a tracked file', function () {
    $root = hookedRepository();
    installHookInto($root);

    // Renames report as R, not A — filtering on A alone lets this through.
    file_put_contents($root.'/placeholder.bin', str_repeat('x', 200));
    exec('git -C '.escapeshellarg($root).' add placeholder.bin && git -C '.escapeshellarg($root).' commit -m seed 2>&1');

    rename($root.'/placeholder.bin', $root.'/dumps-moved.sql');
    exec('git -C '.escapeshellarg($root).' add -A 2>&1');
    exec('git -C '.escapeshellarg($root).' commit -m test 2>&1', $output, $exitCode);

    expect($exitCode)->not->toBe(0);
});

it('lets the directory\'s own .gitignore through', function () {
    $root = hookedRepository();
    installHookInto($root);

    // The README's other approach puts a `*` / `!.gitignore` file here; the
    // hook must not block the very file that configures the directory.
    expect(commitFile($root, 'database/dumps/.gitignore', "*\n!.gitignore\n"))->toBe(0);
});

it('survives a dump directory containing a quote', function () {
    $root = hookedRepository();
    installHookInto($root, dumpDirectory: "db/Bob's dumps");

    // An unescaped quote would make the hook a shell syntax error, which blocks
    // EVERY commit in the repository.
    expect(commitFile($root, 'app/Models/User.php', '<?php'))->toBe(0)
        ->and(commitFile($root, "db/Bob's dumps/x.bin"))->not->toBe(0);
});

it('installs the hook and points git at it', function () {
    $root = hookedRepository();
    $this->app->setBasePath($root);
    config()->set('db-snapshots.disk', null);

    $this->artisan('app:install-git-hooks')->assertSuccessful();

    expect(file_exists($root.'/.githooks/pre-commit'))->toBeTrue()
        ->and(is_executable($root.'/.githooks/pre-commit'))->toBeTrue()
        ->and(trim((string) shell_exec('git -C '.escapeshellarg($root).' config core.hooksPath')))->toBe('.githooks');
});

it('is idempotent', function () {
    $root = hookedRepository();
    $this->app->setBasePath($root);

    $this->artisan('app:install-git-hooks')->assertSuccessful();
    $this->artisan('app:install-git-hooks')->assertSuccessful();

    expect(substr_count((string) file_get_contents($root.'/.githooks/pre-commit'), '#!/bin/sh'))->toBe(1);
});

it('keeps a hand-edited hook unless forced', function () {
    $root = hookedRepository();
    $this->app->setBasePath($root);

    $this->artisan('app:install-git-hooks')->assertSuccessful();
    file_put_contents($root.'/.githooks/pre-commit', "#!/bin/sh\n# mine\nexit 0\n");

    $this->artisan('app:install-git-hooks')->assertSuccessful();
    expect(file_get_contents($root.'/.githooks/pre-commit'))->toContain('# mine');

    $this->artisan('app:install-git-hooks --force')->assertSuccessful();
    expect(file_get_contents($root.'/.githooks/pre-commit'))->not->toContain('# mine');
});

it('takes over a core.hooksPath that only restates git\'s own default', function () {
    $root = hookedRepository();
    $this->app->setBasePath($root);

    // What some tools write; it points where git already looks, so it is not a
    // setup worth protecting.
    exec('git -C '.escapeshellarg($root).' config core.hooksPath '.escapeshellarg($root.'/.git/hooks'));

    $this->artisan('app:install-git-hooks')->assertSuccessful();

    expect(trim((string) shell_exec('git -C '.escapeshellarg($root).' config core.hooksPath')))->toBe('.githooks');
});

it('leaves a genuinely custom core.hooksPath alone', function () {
    $root = hookedRepository();
    $this->app->setBasePath($root);

    exec('git -C '.escapeshellarg($root).' config core.hooksPath '.escapeshellarg('tools/hooks'));

    $this->artisan('app:install-git-hooks')->assertSuccessful();

    expect(trim((string) shell_exec('git -C '.escapeshellarg($root).' config core.hooksPath')))->toBe('tools/hooks');
});

it('refuses to run outside a git repository', function () {
    $root = sys_get_temp_dir().'/deployer-hooks-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($root);
    $this->app->setBasePath($root);

    $this->artisan('app:install-git-hooks')->assertFailed();
});
