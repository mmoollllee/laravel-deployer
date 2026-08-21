<?php

namespace Mmoollllee\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
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
    protected $signature = 'app:localize-tenant-domains
        {--column= : Spalte mit der Tenant-Domain (Default: die erste vorhandene aus primary_domain, domain)}
        {--set=* : Weitere Spalte für alle Tenants setzen, z. B. --set=app_debug=1 (mehrfach möglich)}';

    protected $description = 'Hängt .test an alle Tenant-Domains, damit ein lokal eingespielter Prod-Dump unter Herd erreichbar ist (idempotent, nur lokal/Test).';

    /**
     * Domain column candidates, in probe order. The apps built on this package
     * disagree on the name — the CMS ones call it `primary_domain`, older ones
     * `domain` — and a shared package has no business making one of them wrong.
     *
     * @var list<string>
     */
    private const DOMAIN_COLUMNS = ['primary_domain', 'domain'];

    public function handle(): int
    {
        if (! $this->getLaravel()->environment(['local', 'testing'])) {
            $this->error('Abgebrochen: Dieser Befehl darf nur lokal laufen (er verändert alle Tenant-Domains).');

            return self::FAILURE;
        }

        $schema = $this->getLaravel()->make('db')->getSchemaBuilder();

        if (! $schema->hasTable('tenants')) {
            $this->error('Abgebrochen: Es gibt keine tenants-Tabelle — dieser Befehl ist für Multi-Tenant-Apps.');

            return self::FAILURE;
        }

        $column = $this->domainColumn($schema);

        if ($column === null) {
            return self::FAILURE;
        }

        $extra = $this->extraColumnValues($schema);

        if ($extra === null) {
            return self::FAILURE;
        }

        // Concatenated in PHP rather than with SQL CONCAT(): portable across
        // MariaDB (Herd) and SQLite (tests), and it goes through the query
        // builder, so no model events fire.
        $tenants = DB::table('tenants')
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->where($column, 'not like', '%.test')
            ->get(['id', $column]);

        foreach ($tenants as $tenant) {
            DB::table('tenants')
                ->where('id', $tenant->id)
                ->update([$column => $tenant->{$column}.'.test']);
        }

        $this->info("{$tenants->count()} Tenant-Domain(s) in {$column} auf *.test umgeschrieben.");

        if ($extra !== []) {
            DB::table('tenants')->update($extra);

            $this->info('Für alle Tenants gesetzt: '.collect($extra)
                ->map(fn (mixed $value, string $name): string => $name.'='.var_export($value, true))
                ->implode(', ').'.');
        }

        return self::SUCCESS;
    }

    /**
     * The column holding the domain — the explicit `--column`, else the first
     * candidate the table actually has. Both failure paths report rather than
     * let a query exception explain it.
     */
    private function domainColumn(SchemaBuilder $schema): ?string
    {
        $explicit = $this->option('column');

        if (is_string($explicit) && $explicit !== '') {
            if (! $schema->hasColumn('tenants', $explicit)) {
                $this->error("Abgebrochen: Die tenants-Tabelle hat keine Spalte {$explicit}.");

                return null;
            }

            return $explicit;
        }

        foreach (self::DOMAIN_COLUMNS as $candidate) {
            if ($schema->hasColumn('tenants', $candidate)) {
                return $candidate;
            }
        }

        $this->error(
            'Abgebrochen: Die tenants-Tabelle hat keine Domain-Spalte ('
            .implode(', ', self::DOMAIN_COLUMNS).') — ggf. --column=… angeben.'
        );

        return null;
    }

    /**
     * The `--set=column=value` pairs, parsed and checked against the table.
     * Returns null when one of them is unusable, so the caller can abort before
     * a single row is touched.
     *
     * @return array<string, string|int|bool|null>|null
     */
    private function extraColumnValues(SchemaBuilder $schema): ?array
    {
        $values = [];

        foreach ((array) $this->option('set') as $pair) {
            if (! is_string($pair) || ! str_contains($pair, '=')) {
                $this->error("Abgebrochen: --set={$pair} ist kein spalte=wert-Paar.");

                return null;
            }

            [$name, $value] = explode('=', $pair, 2);

            if (! $schema->hasColumn('tenants', $name)) {
                $this->error("Abgebrochen: Die tenants-Tabelle hat keine Spalte {$name}.");

                return null;
            }

            $values[$name] = $this->castValue($value);
        }

        return $values;
    }

    /**
     * Everything arrives as a string on the command line, but a boolean column
     * wants a boolean — `--set=app_debug=true` and `=1` must mean the same.
     */
    private function castValue(string $value): string|int|bool|null
    {
        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => ctype_digit($value) ? (int) $value : $value,
        };
    }
}
