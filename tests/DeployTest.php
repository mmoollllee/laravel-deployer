<?php

use Illuminate\Support\Facades\File;

/*
 * The command's own job is narrow: refuse where running it would be wrong, and
 * otherwise hand over to `vendor/bin/dep`. The hand-over goes to a stand-in for
 * dep that only writes down what it was given — the real one would deploy.
 */

it('refuses to run on a production box unless forced', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('deploy')
        ->expectsOutputToContain('Entwicklungsmaschine')
        ->assertFailed();
});

it('stops when Deployer is not installed', function () {
    // Testbench's skeleton has neither vendor/bin/dep nor a deploy.php, so the
    // binary check is the first thing the command hits.
    $this->artisan('deploy')
        ->expectsOutputToContain('vendor/bin/dep')
        ->assertFailed();
});

it('names the task it would have run', function () {
    $this->artisan('deploy', ['task' => 'deploy:quick'])
        ->expectsOutputToContain('dep deploy:quick')
        ->assertFailed();
});

it('names the whole call, arguments included', function () {
    $this->artisan('deploy', ['task' => 'pull:db', 'arguments' => ['users posts']])
        ->expectsOutputToContain("dep pull:db 'users posts'")
        ->assertFailed();
});

it('hands further arguments to dep unchanged', function () {
    $base = sys_get_temp_dir().'/laravel-deployer-'.bin2hex(random_bytes(4));

    File::ensureDirectoryExists($base.'/vendor/bin');
    File::put($base.'/deploy.php', "<?php\n");
    // One argument per line: a quoted one has to arrive as one.
    File::put($base.'/vendor/bin/dep', "#!/bin/sh\nprintf '%s\\n' \"\$@\" > ".escapeshellarg($base.'/arguments')."\n");
    chmod($base.'/vendor/bin/dep', 0755);

    app()->setBasePath($base);

    try {
        // --no-hooks is what `artisan deploy -- pull:db users --no-hooks` passes.
        $this->artisan('deploy', ['task' => 'pull:db', 'arguments' => ['users posts', '--no-hooks']])
            ->assertSuccessful();

        expect(file($base.'/arguments', FILE_IGNORE_NEW_LINES))->toBe(['pull:db', 'users posts', '--no-hooks']);
    } finally {
        File::deleteDirectory($base);
    }
});

it('takes the deploy name so a stale local copy cannot keep it', function () {
    // The whole point of shipping this: a consuming app's own `deploy` command
    // used to hold the SERVER-side routine and ran it against the developer's
    // machine. The package now answers to that name.
    expect(app(Illuminate\Contracts\Console\Kernel::class)->all())->toHaveKey('deploy');
});
