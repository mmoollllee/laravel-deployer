<?php

namespace Mmoollllee\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Companion to `dep pull:db-refresh` / `dep pull:db-full`: a production dump
 * carries production domains, which under Herd resolve to nothing. Hook it up
 * in the project's deploy.php:
 *
 *     task('pull:localize-domains', fn () => runLocally('php artisan app:localize-tenant-domains'));
 *     after('pull:db-refresh', 'pull:localize-domains');
 *     after('pull:db-full', 'pull:localize-domains');
 *
 * Idempotent, and it refuses to run anywhere but local/testing — it rewrites
 * every tenant domain in the database, which on a server is the whole site.
 */
class LocalizeTenantDomains extends Command
{
    // Declared as properties rather than #[Signature]/#[Description]: those
    // attributes are Laravel 13, and this package supports 12 as well.
    protected $signature = 'app:localize-tenant-domains';

    protected $description = 'Hängt .test an alle Tenant-Domains, damit ein lokal eingespielter Prod-Dump unter Herd erreichbar ist (idempotent, nur lokal/Test).';

    public function handle(): int
    {
        if (! $this->getLaravel()->environment(['local', 'testing'])) {
            $this->error('Abgebrochen: Dieser Befehl darf nur lokal laufen (er verändert alle Tenant-Domains).');

            return self::FAILURE;
        }

        if (! $this->getLaravel()->make('db')->getSchemaBuilder()->hasTable('tenants')) {
            $this->error('Abgebrochen: Es gibt keine tenants-Tabelle — dieser Befehl ist für Multi-Tenant-Apps.');

            return self::FAILURE;
        }

        // Concatenated in PHP rather than with SQL CONCAT(): portable across
        // MariaDB (Herd) and SQLite (tests), and it goes through the query
        // builder, so no model events fire.
        $tenants = DB::table('tenants')
            ->whereNotNull('primary_domain')
            ->where('primary_domain', '!=', '')
            ->where('primary_domain', 'not like', '%.test')
            ->get(['id', 'primary_domain']);

        foreach ($tenants as $tenant) {
            DB::table('tenants')
                ->where('id', $tenant->id)
                ->update(['primary_domain' => $tenant->primary_domain.'.test']);
        }

        $this->info("{$tenants->count()} Tenant-Domain(s) auf *.test umgeschrieben.");

        return self::SUCCESS;
    }
}
