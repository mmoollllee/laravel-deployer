<?php

namespace Mmoollllee\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * `php artisan deploy` — runs this project's Deployer task from the developer
 * machine.
 *
 * The name is deliberate. Consuming apps used to ship a local `deploy` command
 * holding the SERVER-side routine (npm build, migrate, optimize, cache warm),
 * and running that on a laptop is a silent misfire: it rebuilds the developer's
 * own caches, reports success, and never touches the server — while the actual
 * deploy sits in `deploy.php` behind `vendor/bin/dep`. Taking the name here
 * means the habit lands on the right thing instead of on a stale copy.
 *
 * Everything else stays Deployer's job: the recipe decides what a deploy does,
 * this only starts it and passes its exit code back.
 */
class Deploy extends Command
{
    // Declared as properties rather than #[Signature]/#[Description]: those
    // attributes are Laravel 13, and this package supports 12 as well.
    protected $signature = 'deploy {task=deploy : Deployer-Task aus der deploy.php} {--force : Auch mit APP_ENV=production starten}';

    protected $description = 'Startet den Deployer-Task dieses Projekts (vendor/bin/dep)';

    public function handle(): int
    {
        $task = (string) $this->argument('task');

        // On the server this would point the deploy at the host it is already
        // running on — and over an SSH session the command name is exactly the
        // one the old server-side routine carried, so the mistake is easy to
        // make. `--force` is there for the deploy-from-a-production-box setups.
        if ($this->getLaravel()->environment('production') && ! $this->option('force')) {
            $this->components->error(
                'Abbruch: `artisan deploy` läuft von der Entwicklungsmaschine aus, nicht auf dem Server '
                .'(APP_ENV=production). Die Deploy-Schritte selbst stehen in der deploy.php. Mit --force trotzdem starten.'
            );

            return self::FAILURE;
        }

        $binary = base_path('vendor/bin/dep');

        if (! is_file($binary)) {
            $this->components->error(
                'vendor/bin/dep fehlt: `composer require --dev deployer/deployer` — oder Deployer global installieren '
                ."und `dep {$task}` direkt aufrufen."
            );

            return self::FAILURE;
        }

        if (! is_file(base_path('deploy.php'))) {
            $this->components->error('deploy.php fehlt: ohne Rezept weiß Deployer weder Host noch Pfad.');

            return self::FAILURE;
        }

        $process = new Process([$binary, $task], base_path());
        // A deploy runs as long as composer, the asset build and the migrations
        // need; Symfony's 60s default would kill it mid-flight.
        $process->setTimeout(null);

        // With a terminal, hand it straight through — Deployer's own progress
        // output stays live and interactive prompts still work. Without one
        // (CI, a wrapper script) the callback streams the same bytes on.
        if (Process::isTtySupported() && stream_isatty(STDIN)) {
            $process->setTty(true);
        }

        return $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });
    }
}
