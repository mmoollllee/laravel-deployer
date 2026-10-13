<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * The pull runs for real here: Deployer against localhost, with the remote and
 * the local `php artisan` replaced by scripts that only write down how they were
 * called. The remote one also leaves a dump behind, so the download has
 * something to fetch.
 */

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir().'/laravel-deployer-'.bin2hex(random_bytes(4));
    $this->log = $this->sandbox.'/calls.log';
    $log = escapeshellarg($this->log);
    $php = escapeshellarg(PHP_BINARY);

    File::ensureDirectoryExists($this->sandbox.'/bin');
    File::ensureDirectoryExists($this->sandbox.'/path');
    File::ensureDirectoryExists($this->sandbox.'/remote');
    File::ensureDirectoryExists($this->sandbox.'/local');

    // {{bin/php}}, run in {{deploy_path}}.
    File::put($this->sandbox.'/bin/php', <<<SH
        #!/bin/sh
        echo "remote \$*" >> {$log}
        if [ "\$2" = "snapshot:create" ]; then mkdir -p database/dumps && : > database/dumps/snapshot.sql; fi
        SH);

    // The local `php artisan` is found through PATH — and so is the interpreter
    // of `dep` itself (#!/usr/bin/env php), which is why only artisan is caught.
    File::put($this->sandbox.'/path/php', <<<SH
        #!/bin/sh
        if [ "\$1" = "artisan" ]; then echo "local \$*" >> {$log}; exit 0; fi
        exec {$php} "\$@"
        SH);

    chmod($this->sandbox.'/bin/php', 0755);
    chmod($this->sandbox.'/path/php', 0755);

    // In the local app's root, as in a real project: runLocally() runs where
    // deploy.php is, download() where `dep` was started.
    File::put($this->sandbox.'/local/deploy.php', sprintf(<<<'PHP'
        <?php

        namespace Deployer;

        require_once %s;

        localhost()->set('deploy_path', %s);
        set('bin/php', %s);
        set('db_pull_include', ['migrations', 'users']);

        task('hooked', fn () => runLocally('echo hooked >> '.%s));
        after('pull:db-refresh', 'hooked');
        PHP,
        var_export(dirname(__DIR__).'/recipe/app.php', true),
        var_export($this->sandbox.'/remote', true),
        var_export($this->sandbox.'/bin/php', true),
        var_export($log, true),
    ));

    $this->depIn = fn (string $cwd, string ...$arguments) => tap(new Process(
        [dirname(__DIR__).'/vendor/bin/dep', ...$arguments, '--file='.$this->sandbox.'/local/deploy.php', '--no-interaction'],
        $cwd,
        ['PATH' => $this->sandbox.'/path:'.getenv('PATH'), 'DO_NOT_SHOW_BANNER' => 'true'],
    ))->run();

    $this->dep = fn (string ...$arguments) => ($this->depIn)($this->sandbox.'/local', ...$arguments);

    $this->calls = fn () => is_file($this->log) ? file($this->log, FILE_IGNORE_NEW_LINES) : [];
});

afterEach(function () {
    File::deleteDirectory($this->sandbox);
});

it('pulls only the tables it is given', function () {
    $dep = ($this->dep)('pull:db', 'users, posts');

    expect($dep->getExitCode())->toBe(0, $dep->getOutput().$dep->getErrorOutput())
        ->and(($this->calls)())->toBe([
            'remote artisan help snapshot:create',
            'local artisan help snapshot:load',
            'remote artisan snapshot:create --table=users --table=posts',
            'remote artisan snapshot:cleanup --keep=1',
            // --drop-tables=0: every other local table stays as it is.
            'local artisan snapshot:load snapshot --drop-tables=0 --stream --force',
            'local artisan config:clear',
            // What a site hooks onto pull:db-refresh runs after this one too.
            'hooked',
        ])
        ->and($this->sandbox.'/local/database/dumps/snapshot.sql')->toBeFile();
});

it('lands the dump in the project, wherever dep is started', function () {
    // runLocally() works next to deploy.php, rsync where dep was started: the
    // dump directory used to be made in one and the download aimed at the other.
    $dep = ($this->depIn)($this->sandbox, 'pull:db', 'users');

    expect($dep->getExitCode())->toBe(0, $dep->getOutput().$dep->getErrorOutput())
        ->and($this->sandbox.'/local/database/dumps/snapshot.sql')->toBeFile()
        ->and($this->sandbox.'/database')->not->toBeDirectory();
});

it('takes a host selector after the tables', function () {
    $dep = ($this->dep)('pull:db', 'users posts', 'localhost');

    expect($dep->getExitCode())->toBe(0, $dep->getOutput().$dep->getErrorOutput())
        ->and(($this->calls)())->toContain('remote artisan snapshot:create --table=users --table=posts');
});

it('points out that several tables go into one argument', function () {
    // "posts" is read as a host selector here, as for every Deployer command.
    $dep = ($this->dep)('pull:db', 'users', 'posts');

    expect($dep->getExitCode())->not->toBe(0)
        ->and($dep->getOutput().$dep->getErrorOutput())->toContain('dep pull:db "users posts"')
        ->and(($this->calls)())->toBe([]);
});

it('refuses a table name with shell characters before touching the remote', function () {
    $dep = ($this->dep)('pull:db', 'users;touch pwned');

    expect($dep->getExitCode())->not->toBe(0)
        ->and($dep->getOutput().$dep->getErrorOutput())->toContain('Not a table name: users;touch')
        ->and(($this->calls)())->toBe([])
        ->and($this->sandbox.'/local/pwned')->not->toBeFile();
});

it('refuses an argument that names no table', function () {
    $dep = ($this->dep)('pull:db', ' , ');

    expect($dep->getExitCode())->not->toBe(0)
        ->and($dep->getOutput().$dep->getErrorOutput())->toContain('No table given')
        ->and(($this->calls)())->toBe([]);
});

it('still pulls db_pull_include on a plain refresh', function () {
    $dep = ($this->dep)('pull:db-refresh');

    expect($dep->getExitCode())->toBe(0, $dep->getOutput().$dep->getErrorOutput())
        ->and(($this->calls)())->toContain('remote artisan snapshot:create --table=migrations --table=users');
});
