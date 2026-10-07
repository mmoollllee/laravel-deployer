<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * deploy_standard() runs for real here: Deployer against localhost, on a git
 * clone whose `git pull` has nothing to fetch, with php, Composer and npm
 * replaced by scripts that only write down how they were called.
 */

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir().'/laravel-deployer-'.bin2hex(random_bytes(4));
    $this->log = $this->sandbox.'/calls.log';

    // npm is found through PATH, php and Composer through the recipe's settings.
    // Kept apart, because `dep` itself starts with `#!/usr/bin/env php`.
    File::ensureDirectoryExists($this->sandbox.'/bin');
    File::ensureDirectoryExists($this->sandbox.'/path');

    $record = function (string $path, string $body = ''): void {
        File::put($path, "#!/bin/sh\n{$body}echo \"".basename($path)." \$*\" >> ".escapeshellarg($this->log)."\n");
        chmod($path, 0755);
    };

    $record($this->sandbox.'/bin/php');
    $record($this->sandbox.'/bin/composer', "if [ \"\$1\" = \"--version\" ]; then echo 'Composer version 2.8.0'; exit 0; fi\n");
    $record($this->sandbox.'/path/npm');

    $git = fn (string $arguments) => (new Process(['sh', '-c', 'git '.$arguments], $this->sandbox))->mustRun();
    $git('init -q -b main origin');
    $git('-C origin -c user.name=test -c user.email=test@example.test commit -q --allow-empty -m init');
    $git('clone -q origin app');

    File::put($this->sandbox.'/deploy.php', sprintf(<<<'PHP'
        <?php

        namespace Deployer;

        require_once %s;

        localhost()->set('deploy_path', %s);
        set('bin/php', %s);
        set('bin/composer', %s);
        set('git_ssh_key', null);
        set('deploy_assets', true);
        set('deploy_migrate', true);
        set('deploy_cache_clear', false);
        set('deploy_queue_restart', false);

        task('standard', fn () => deploy_standard());
        PHP,
        var_export(dirname(__DIR__).'/lib/functions.php', true),
        var_export($this->sandbox.'/app', true),
        var_export($this->sandbox.'/bin/php', true),
        var_export($this->sandbox.'/bin/composer', true),
    ));
});

afterEach(function () {
    File::deleteDirectory($this->sandbox);
});

it('rebuilds the caches right after composer install, before the asset build', function () {
    $deploy = new Process(
        [dirname(__DIR__).'/vendor/bin/dep', 'standard', '--file='.$this->sandbox.'/deploy.php', '--no-interaction'],
        $this->sandbox,
        ['PATH' => $this->sandbox.'/path:'.getenv('PATH')],
    );
    $deploy->mustRun();

    // `git pull` has put the new code live by now. Until the rebuild it ran
    // against the previous release's config and route cache, for as long as
    // npm took — a config file the release added read as null.
    expect(file($this->log, FILE_IGNORE_NEW_LINES))->toBe([
        'composer install --no-dev --optimize-autoloader --no-interaction',
        'php artisan optimize',
        'npm ci',
        'npm run build',
        'php artisan optimize:clear',
        'php artisan migrate --force',
        'php artisan optimize',
    ]);
});

it('keeps the application cache when told to, and clears only the framework caches', function () {
    File::append($this->sandbox.'/deploy.php', "\nset('deploy_clear_app_cache', false);\n");

    (new Process(
        [dirname(__DIR__).'/vendor/bin/dep', 'standard', '--file='.$this->sandbox.'/deploy.php', '--no-interaction'],
        $this->sandbox,
        ['PATH' => $this->sandbox.'/path:'.getenv('PATH')],
    ))->mustRun();

    // Scheduler mutexes, locks and whatever the site records in its cache
    // survive; config, route, view and event caches are rebuilt as before.
    expect(file($this->log, FILE_IGNORE_NEW_LINES))
        ->toContain('php artisan optimize:clear --except=cache')
        ->not->toContain('php artisan optimize:clear');
});

it('lets a task that runs first turn the flush back on for that deploy', function () {
    // How a site builds its "this release changes what a cache holds" deploy:
    // the setting a task writes reaches the tasks after it in the same run.
    File::append($this->sandbox.'/deploy.php', <<<'PHP'

        set('deploy_clear_app_cache', false);
        task('flush-app-cache', fn () => set('deploy_clear_app_cache', true));
        task('fresh', ['flush-app-cache', 'standard']);
        PHP);

    (new Process(
        [dirname(__DIR__).'/vendor/bin/dep', 'fresh', '--file='.$this->sandbox.'/deploy.php', '--no-interaction'],
        $this->sandbox,
        ['PATH' => $this->sandbox.'/path:'.getenv('PATH')],
    ))->mustRun();

    expect(file($this->log, FILE_IGNORE_NEW_LINES))
        ->toContain('php artisan optimize:clear')
        ->not->toContain('php artisan optimize:clear --except=cache');
});
