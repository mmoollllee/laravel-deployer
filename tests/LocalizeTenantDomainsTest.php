<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function makeTenantsTable(): void
{
    Schema::create('tenants', function (Blueprint $table): void {
        $table->id();
        $table->string('primary_domain')->nullable();
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
