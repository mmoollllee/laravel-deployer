<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * Storage sync and push:auth for real: Deployer against localhost, with `dep`
 * started outside the project. Local paths count from deploy.php, as
 * runLocally() does — not from wherever the command happened to be typed.
 */

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir().'/laravel-deployer-'.bin2hex(random_bytes(4));

    File::ensureDirectoryExists($this->sandbox.'/remote');
    File::ensureDirectoryExists($this->sandbox.'/local');
    File::ensureDirectoryExists($this->sandbox.'/elsewhere');
    File::ensureDirectoryExists($this->sandbox.'/home');

    File::put($this->sandbox.'/local/deploy.php', sprintf(<<<'PHP'
        <?php

        namespace Deployer;

        require_once %s;

        localhost()->set('deploy_path', %s);
        set('files', ['storage/app/public/']);
        PHP,
        var_export(dirname(__DIR__).'/recipe/app.php', true),
        var_export($this->sandbox.'/remote', true),
    ));

    $this->dep = fn (string ...$arguments) => tap(new Process(
        [dirname(__DIR__).'/vendor/bin/dep', ...$arguments, '--file='.$this->sandbox.'/local/deploy.php', '--no-interaction'],
        $this->sandbox.'/elsewhere',
        // A HOME of its own, so push:auth cannot fall back on the real global
        // auth.json of whoever runs the tests.
        ['HOME' => $this->sandbox.'/home', 'DO_NOT_SHOW_BANNER' => 'true'],
    ))->run();
});

afterEach(function () {
    File::deleteDirectory($this->sandbox);
});

it('pulls storage into the project', function () {
    File::ensureDirectoryExists($this->sandbox.'/remote/storage/app/public');
    File::put($this->sandbox.'/remote/storage/app/public/logo.png', 'png');

    $dep = ($this->dep)('pull:files');

    expect($dep->getExitCode())->toBe(0, $dep->getOutput().$dep->getErrorOutput())
        ->and($this->sandbox.'/local/storage/app/public/logo.png')->toBeFile()
        ->and($this->sandbox.'/elsewhere/storage')->not->toBeDirectory();
});

it('pushes storage from the project', function () {
    File::ensureDirectoryExists($this->sandbox.'/local/storage/app/public');
    File::put($this->sandbox.'/local/storage/app/public/logo.png', 'png');

    $dep = ($this->dep)('push:files');

    expect($dep->getExitCode())->toBe(0, $dep->getOutput().$dep->getErrorOutput())
        ->and($this->sandbox.'/remote/storage/app/public/logo.png')->toBeFile();
});

it('uploads the project\'s auth.json', function () {
    File::put($this->sandbox.'/local/auth.json', '{"project": true}');

    $dep = ($this->dep)('push:auth');

    expect($dep->getExitCode())->toBe(0, $dep->getOutput().$dep->getErrorOutput())
        ->and(File::get($this->sandbox.'/remote/auth.json'))->toBe('{"project": true}');
});
