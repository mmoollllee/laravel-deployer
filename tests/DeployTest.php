<?php

/*
 * The command's own job is narrow: refuse where running it would be wrong, and
 * otherwise hand over to `vendor/bin/dep`. The hand-over itself is not exercised
 * here — that would start a real deploy.
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

it('takes the deploy name so a stale local copy cannot keep it', function () {
    // The whole point of shipping this: a consuming app's own `deploy` command
    // used to hold the SERVER-side routine and ran it against the developer's
    // machine. The package now answers to that name.
    expect(app(Illuminate\Contracts\Console\Kernel::class)->all())->toHaveKey('deploy');
});
