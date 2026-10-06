<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * pull:db-* --retry-load runs for real here: Deployer against a host it must
 * never reach, with the local php replaced by a script that only writes down how
 * it was called. `dep` itself is started through the real PHP binary, so the
 * stand-in on PATH only answers the recipe's `php artisan …`.
 */

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir().'/laravel-deployer-'.bin2hex(random_bytes(4));
    $this->log = $this->sandbox.'/calls.log';

    File::ensureDirectoryExists($this->sandbox.'/path');
    File::ensureDirectoryExists($this->sandbox.'/database/dumps');
    File::put($this->sandbox.'/path/php', "#!/bin/sh\necho \"php \$*\" >> ".escapeshellarg($this->log)."\n");
    chmod($this->sandbox.'/path/php', 0755);

    File::put($this->sandbox.'/deploy.php', sprintf(<<<'PHP'
        <?php

        namespace Deployer;

        require_once %s;

        host('unreachable.invalid')->set('deploy_path', '/nowhere');
        set('db_pull_include', ['users']);
        PHP,
        var_export(dirname(__DIR__).'/recipe/database.php', true),
    ));

    $this->dep = fn (string ...$arguments) => new Process(
        [PHP_BINARY, dirname(__DIR__).'/vendor/bin/dep', ...$arguments, '--file='.$this->sandbox.'/deploy.php', '--no-interaction'],
        $this->sandbox,
        ['PATH' => $this->sandbox.'/path:'.getenv('PATH')],
    );
});

afterEach(function () {
    File::deleteDirectory($this->sandbox);
});

it('loads the newest local dump again without touching the remote', function () {
    File::put($this->sandbox.'/database/dumps/2026-10-05_09-00-00.sql.gz', 'old');
    touch($this->sandbox.'/database/dumps/2026-10-05_09-00-00.sql.gz', time() - 86400);
    File::put($this->sandbox.'/database/dumps/2026-10-06_14-25-43.sql.gz', 'new');
    touch($this->sandbox.'/database/dumps/2026-10-06_14-25-43.sql.gz', time() - 3 * 3600);

    $deploy = ($this->dep)('pull:db-full', '--retry-load');
    $deploy->mustRun();

    expect($deploy->getOutput())->toContain('Loading 2026-10-06_14-25-43.sql.gz again')
        ->and($deploy->getOutput())->toContain('pulled 3 h ago')
        ->and(file($this->log, FILE_IGNORE_NEW_LINES))->toBe([
        'php artisan snapshot:load 2026-10-06_14-25-43 --stream --force',
        'php artisan config:clear',
    ]);
});

it('keeps local tables when pull:db-refresh loads again', function () {
    File::put($this->sandbox.'/database/dumps/2026-10-06_14-25-43.sql', 'new');

    ($this->dep)('pull:db-refresh', '--retry-load')->mustRun();

    expect(file($this->log, FILE_IGNORE_NEW_LINES))->toBe([
        'php artisan snapshot:load 2026-10-06_14-25-43 --drop-tables=0 --stream --force',
        'php artisan config:clear',
    ]);
});

it('refuses when there is no dump to load again', function () {
    $deploy = ($this->dep)('pull:db-full', '--retry-load');
    $deploy->run();

    expect($deploy->isSuccessful())->toBeFalse()
        ->and($deploy->getErrorOutput().$deploy->getOutput())->toContain('No dump in database/dumps')
        ->and(file_exists($this->log))->toBeFalse();
});
