<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @param  list<string>  $domainColumns
 */
function makeTenantsTable(array $domainColumns = ['primary_domain'], bool $withDebugFlag = false): void
{
    Schema::create('tenants', function (Blueprint $table) use ($domainColumns, $withDebugFlag): void {
        $table->id();

        foreach ($domainColumns as $column) {
            $table->string($column)->nullable();
        }

        if ($withDebugFlag) {
            $table->boolean('app_debug')->default(false);
        }
    });
}

it('appends .test only to primary domains that lack it', function () {
    makeTenantsTable();

    $plain = DB::table('tenants')->insertGetId(['primary_domain' => 'example.com']);
    $already = DB::table('tenants')->insertGetId(['primary_domain' => 'sibling.test']);

    $this->artisan('app:localize-tenant-domains')->assertSuccessful();

    expect(DB::table('tenants')->where('id', $plain)->value('primary_domain'))->toBe('example.com.test')
        ->and(DB::table('tenants')->where('id', $already)->value('primary_domain'))->toBe('sibling.test');
});

it('leaves blank and null domains alone', function () {
    makeTenantsTable();

    $null = DB::table('tenants')->insertGetId(['primary_domain' => null]);
    $blank = DB::table('tenants')->insertGetId(['primary_domain' => '']);

    $this->artisan('app:localize-tenant-domains')->assertSuccessful();

    expect(DB::table('tenants')->where('id', $null)->value('primary_domain'))->toBeNull()
        ->and(DB::table('tenants')->where('id', $blank)->value('primary_domain'))->toBe('');
});

it('is idempotent when run twice', function () {
    makeTenantsTable();

    $id = DB::table('tenants')->insertGetId(['primary_domain' => 'example.com']);

    $this->artisan('app:localize-tenant-domains')->assertSuccessful();
    $this->artisan('app:localize-tenant-domains')->assertSuccessful();

    expect(DB::table('tenants')->where('id', $id)->value('primary_domain'))->toBe('example.com.test');
});

/**
 * The apps disagree on the column name — the CMS ones ship `primary_domain`,
 * older ones `domain`. Hard-coding either turns the command into a query
 * exception in half the projects that hook it onto their DB pull.
 */
it('falls back to a domain column when there is no primary_domain', function () {
    makeTenantsTable(['domain']);

    $id = DB::table('tenants')->insertGetId(['domain' => 'example.com']);

    $this->artisan('app:localize-tenant-domains')->assertSuccessful();

    expect(DB::table('tenants')->where('id', $id)->value('domain'))->toBe('example.com.test');
});

it('prefers primary_domain when the table carries both columns', function () {
    makeTenantsTable(['primary_domain', 'domain']);

    $id = DB::table('tenants')->insertGetId(['primary_domain' => 'example.com', 'domain' => 'legacy.com']);

    $this->artisan('app:localize-tenant-domains')->assertSuccessful();

    expect(DB::table('tenants')->where('id', $id)->value('primary_domain'))->toBe('example.com.test')
        ->and(DB::table('tenants')->where('id', $id)->value('domain'))->toBe('legacy.com');
});

it('rewrites the column named by --column', function () {
    makeTenantsTable(['primary_domain', 'vanity_domain']);

    $id = DB::table('tenants')->insertGetId(['primary_domain' => 'example.com', 'vanity_domain' => 'vanity.com']);

    $this->artisan('app:localize-tenant-domains', ['--column' => 'vanity_domain'])->assertSuccessful();

    expect(DB::table('tenants')->where('id', $id)->value('vanity_domain'))->toBe('vanity.com.test')
        ->and(DB::table('tenants')->where('id', $id)->value('primary_domain'))->toBe('example.com');
});

it('fails when --column names a column the table does not have', function () {
    makeTenantsTable();

    $id = DB::table('tenants')->insertGetId(['primary_domain' => 'example.com']);

    $this->artisan('app:localize-tenant-domains', ['--column' => 'nope'])->assertFailed();

    expect(DB::table('tenants')->where('id', $id)->value('primary_domain'))->toBe('example.com');
});

/**
 * A Filament multi-tenancy without domains (tenants are just workspaces) has a
 * tenants table and no domain in it. That is not an error worth a stack trace.
 */
it('fails cleanly when the tenants table has no domain column at all', function () {
    Schema::create('tenants', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    $this->artisan('app:localize-tenant-domains')->assertFailed();
});

/**
 * A staging subdomain has no ".test twin" of its own — the site is developed
 * under the app's Herd domain. `--map` names that target explicitly.
 */
it('maps a domain to its local twin instead of appending .test', function () {
    makeTenantsTable();

    $staging = DB::table('tenants')->insertGetId(['primary_domain' => 'vorschau.example.de']);
    $other = DB::table('tenants')->insertGetId(['primary_domain' => 'other.de']);

    $this->artisan('app:localize-tenant-domains', ['--map' => ['vorschau.example.de=example.de.test']])->assertSuccessful();
    $this->artisan('app:localize-tenant-domains', ['--map' => ['vorschau.example.de=example.de.test']])->assertSuccessful();

    expect(DB::table('tenants')->where('id', $staging)->value('primary_domain'))->toBe('example.de.test')
        ->and(DB::table('tenants')->where('id', $other)->value('primary_domain'))->toBe('other.de.test');
});

it('heals a domain an earlier run already suffixed', function () {
    makeTenantsTable();

    $id = DB::table('tenants')->insertGetId(['primary_domain' => 'vorschau.example.de.test']);

    $this->artisan('app:localize-tenant-domains', ['--map' => ['vorschau.example.de=example.de.test']])->assertSuccessful();

    expect(DB::table('tenants')->where('id', $id)->value('primary_domain'))->toBe('example.de.test');
});

it('refuses a mapping that would give two tenants the same domain', function () {
    makeTenantsTable();

    $staging = DB::table('tenants')->insertGetId(['primary_domain' => 'vorschau.example.de']);
    $live = DB::table('tenants')->insertGetId(['primary_domain' => 'example.de']);

    $this->artisan('app:localize-tenant-domains', ['--map' => ['vorschau.example.de=example.de.test']])->assertFailed();

    expect(DB::table('tenants')->where('id', $staging)->value('primary_domain'))->toBe('vorschau.example.de')
        ->and(DB::table('tenants')->where('id', $live)->value('primary_domain'))->toBe('example.de');
});

it('keeps a map target on the next run, whatever its suffix', function () {
    makeTenantsTable();

    $staging = DB::table('tenants')->insertGetId(['primary_domain' => 'vorschau.example.de']);
    $live = DB::table('tenants')->insertGetId(['primary_domain' => 'example.de']);

    $map = ['--map' => ['vorschau.example.de=example.localhost', 'example.de=www.example.de.test']];

    $this->artisan('app:localize-tenant-domains', $map)->assertSuccessful();
    $this->artisan('app:localize-tenant-domains', $map)->assertSuccessful();

    expect(DB::table('tenants')->where('id', $staging)->value('primary_domain'))->toBe('example.localhost')
        ->and(DB::table('tenants')->where('id', $live)->value('primary_domain'))->toBe('www.example.de.test');
});

it('reads domains without regard to case, as DNS and the unique index do', function () {
    makeTenantsTable();

    $shouting = DB::table('tenants')->insertGetId(['primary_domain' => 'SIBLING.TEST']);

    $this->artisan('app:localize-tenant-domains', ['--map' => ['Vorschau.Example.de=Example.de.test']])->assertSuccessful();

    expect(DB::table('tenants')->where('id', $shouting)->value('primary_domain'))->toBe('sibling.test');

    // Two domains that differ in case only are one domain to the unique index.
    DB::table('tenants')->insert([['primary_domain' => 'vorschau.example.de'], ['primary_domain' => 'Example.de']]);

    $this->artisan('app:localize-tenant-domains', ['--map' => ['vorschau.example.de=example.de.test']])->assertFailed();
});

it('rewrites all domains or none', function () {
    // A constraint the clash check cannot know about fails the second update.
    DB::statement("create table tenants (id integer primary key autoincrement, primary_domain varchar(255) null check (primary_domain <> 'boom.test'))");

    $first = DB::table('tenants')->insertGetId(['primary_domain' => 'example.com']);
    DB::table('tenants')->insert(['primary_domain' => 'boom']);

    expect(fn () => $this->artisan('app:localize-tenant-domains')->run())->toThrow(QueryException::class);

    expect(DB::table('tenants')->where('id', $first)->value('primary_domain'))->toBe('example.com');
});

it('rejects a --map value that is not a domain=domain pair', function () {
    makeTenantsTable();

    $id = DB::table('tenants')->insertGetId(['primary_domain' => 'example.com']);

    $this->artisan('app:localize-tenant-domains', ['--map' => ['example.com=']])->assertFailed();

    expect(DB::table('tenants')->where('id', $id)->value('primary_domain'))->toBe('example.com');
});

/**
 * Some apps carry a per-tenant debug flag that a locally imported production
 * dump wants switched on — the point of pulling it is to look inside.
 */
it('sets additional columns for every tenant via --set', function () {
    makeTenantsTable(['domain'], withDebugFlag: true);

    $plain = DB::table('tenants')->insertGetId(['domain' => 'example.com', 'app_debug' => false]);
    $already = DB::table('tenants')->insertGetId(['domain' => 'sibling.test', 'app_debug' => false]);

    $this->artisan('app:localize-tenant-domains', ['--set' => ['app_debug=1']])->assertSuccessful();

    expect(DB::table('tenants')->where('id', $plain)->value('domain'))->toBe('example.com.test')
        ->and((bool) DB::table('tenants')->where('id', $plain)->value('app_debug'))->toBeTrue()
        ->and((bool) DB::table('tenants')->where('id', $already)->value('app_debug'))->toBeTrue();
});

it('reads true and false in --set as booleans', function () {
    makeTenantsTable(['domain'], withDebugFlag: true);

    $id = DB::table('tenants')->insertGetId(['domain' => 'example.com', 'app_debug' => true]);

    $this->artisan('app:localize-tenant-domains', ['--set' => ['app_debug=false']])->assertSuccessful();

    expect((bool) DB::table('tenants')->where('id', $id)->value('app_debug'))->toBeFalse();
});

it('aborts before touching a row when --set names an unknown column', function () {
    makeTenantsTable(['domain'], withDebugFlag: true);

    $id = DB::table('tenants')->insertGetId(['domain' => 'example.com']);

    $this->artisan('app:localize-tenant-domains', ['--set' => ['app_debug=1', 'nope=1']])->assertFailed();

    expect(DB::table('tenants')->where('id', $id)->value('domain'))->toBe('example.com')
        ->and((bool) DB::table('tenants')->where('id', $id)->value('app_debug'))->toBeFalse();
});

it('rejects a --set value that is not a column=value pair', function () {
    makeTenantsTable(['domain']);

    $id = DB::table('tenants')->insertGetId(['domain' => 'example.com']);

    $this->artisan('app:localize-tenant-domains', ['--set' => ['app_debug']])->assertFailed();

    expect(DB::table('tenants')->where('id', $id)->value('domain'))->toBe('example.com');
});

/**
 * The command rewrites every tenant domain there is, so on a server it would
 * take the whole site offline. The environment guard is the only thing between
 * a mistyped `dep run` and that outcome.
 */
it('refuses to run outside the local or testing environment', function () {
    makeTenantsTable();

    $id = DB::table('tenants')->insertGetId(['primary_domain' => 'example.com']);

    app()->detectEnvironment(fn () => 'production');

    $this->artisan('app:localize-tenant-domains')->assertFailed();

    expect(DB::table('tenants')->where('id', $id)->value('primary_domain'))->toBe('example.com');
});

/**
 * The package is required by apps that are not multi-tenant at all — they get
 * the command whether they want it or not, and a missing table should say so
 * rather than throw a query exception.
 */
it('fails cleanly in an app that has no tenants table', function () {
    $this->artisan('app:localize-tenant-domains')->assertFailed();
});
