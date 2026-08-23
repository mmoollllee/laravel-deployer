<?php

namespace Mmoollllee\LaravelDeployer;

use Illuminate\Support\ServiceProvider;
use Mmoollllee\LaravelDeployer\Commands\Deploy;
use Mmoollllee\LaravelDeployer\Commands\LocalizeTenantDomains;
use Mmoollllee\LaravelDeployer\Commands\SendTestMail;

/**
 * Registers the artisan side of the package. The recipes under recipe/ are read
 * by the `dep` binary and never boot Laravel, so this provider exists purely for
 * the two commands the deploy workflow leans on.
 *
 * The command signatures keep their `app:` prefix even though they now ship in a
 * package: they are named in every project's deploy.php hooks and .env.prod
 * comments, and on the servers themselves. A cleaner `deploy:` prefix is not
 * worth breaking a runbook nobody would think to update.
 *
 * `deploy` carries no prefix for the same reason, from the other side: it is the
 * name the apps' own (now superfluous) deploy command had, and taking it is the
 * point — see {@see Deploy}.
 */
class LaravelDeployerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Deploy::class,
                LocalizeTenantDomains::class,
                SendTestMail::class,
            ]);
        }
    }
}
