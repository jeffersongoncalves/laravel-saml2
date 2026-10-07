<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant;
use JeffersonGoncalves\LaravelSaml2\Tests\Support\FakeIdp;

it('creates a tenant from options', function () {
    $this->artisan('saml2:create-tenant', [
        '--key' => 'okta',
        '--entity-id' => FakeIdp::ENTITY_ID,
        '--login-url' => FakeIdp::SSO_URL,
        '--x509cert' => FakeIdp::certificate(),
        '--name-id-format' => 'emailAddress',
        '--settings' => '{"security":{"wantAssertionsSigned":true}}',
    ])->assertSuccessful()->expectsOutputToContain('/saml2/');

    $tenant = Saml2Tenant::query()->sole();
    expect($tenant->key)->toBe('okta')
        ->and($tenant->uuid)->not->toBeEmpty()
        ->and($tenant->name_id_format)->toBe('emailAddress')
        ->and($tenant->idp_logout_url)->toBeNull()
        ->and($tenant->settings)->toBe(['security' => ['wantAssertionsSigned' => true]]);
});

it('creates a tenant from an IdP metadata URL', function () {
    Http::fake(['idp.example.com/*' => Http::response(FakeIdp::metadata())]);

    $this->artisan('saml2:create-tenant', ['--metadata-url' => 'https://idp.example.com/metadata.xml'])
        ->assertSuccessful();

    expect(Saml2Tenant::query()->sole())
        ->idp_entity_id->toBe(FakeIdp::ENTITY_ID)
        ->idp_metadata_url->toBe('https://idp.example.com/metadata.xml');
});

it('refuses incomplete or invalid input', function () {
    $this->artisan('saml2:create-tenant', ['--entity-id' => 'x'])->assertFailed();
    $this->artisan('saml2:create-tenant', ['--entity-id' => 'x', '--login-url' => 'y', '--x509cert' => 'z', '--name-id-format' => 'bogus'])->assertFailed();
    $this->artisan('saml2:create-tenant', ['--entity-id' => 'x', '--login-url' => 'y', '--x509cert' => 'z', '--settings' => 'not json'])->assertFailed();

    FakeIdp::tenant();
    $this->artisan('saml2:create-tenant', ['--key' => 'acme', '--entity-id' => 'x', '--login-url' => 'y', '--x509cert' => 'z'])->assertFailed();

    expect(Saml2Tenant::query()->count())->toBe(1);
});

it('updates only the given options', function () {
    $tenant = FakeIdp::tenant();

    $this->artisan('saml2:update-tenant', ['tenant' => 'acme', '--relay-state-url' => '/home'])->assertSuccessful();

    expect($tenant->fresh())
        ->relay_state_url->toBe('/home')
        ->name_id_format->toBe('persistent')
        ->idp_login_url->toBe(FakeIdp::SSO_URL);
});

it('lists, deletes, restores and force deletes tenants', function () {
    $tenant = FakeIdp::tenant();

    $this->artisan('saml2:list-tenants')->assertSuccessful()->expectsOutputToContain('acme');
    $this->artisan('saml2:tenant-credentials', ['tenant' => $tenant->uuid])->assertSuccessful()->expectsOutputToContain('/acs');

    $this->artisan('saml2:delete-tenant', ['tenant' => 'acme'])->assertSuccessful();
    expect($tenant->fresh()->trashed())->toBeTrue();

    $this->artisan('saml2:restore-tenant', ['tenant' => (string) $tenant->id])->assertSuccessful();
    expect($tenant->fresh()->trashed())->toBeFalse();

    $this->artisan('saml2:delete-tenant', ['tenant' => 'acme', '--force' => true])->assertSuccessful();
    expect(Saml2Tenant::withTrashed()->count())->toBe(0);

    $this->artisan('saml2:delete-tenant', ['tenant' => 'acme'])->assertFailed();
});

it('syncs certificates from the metadata URL', function () {
    $tenant = FakeIdp::tenant(['idp_metadata_url' => 'https://idp.example.com/metadata.xml', 'idp_x509_cert' => 'old']);
    Http::fake(['idp.example.com/*' => Http::response(FakeIdp::metadata())]);

    $this->artisan('saml2:sync-metadata')->assertSuccessful()->expectsOutputToContain('updated');

    expect($tenant->fresh()->idp_x509_cert)->not->toBe('old');
});

it('generates a SP certificate and key', function () {
    $directory = 'storage/saml2-cert-test';
    $options = ['--path' => $directory, '--cn' => 'sp.test'];

    if (PHP_OS_FAMILY === 'Windows') {
        $options['--openssl-config'] = realpath(__DIR__.'/../Support/openssl.cnf');
    }

    try {
        $this->artisan('saml2:generate-cert', $options)
            ->assertSuccessful()
            ->expectsOutputToContain('SAML2_SP_X509_CERT');

        expect(openssl_x509_parse((string) file_get_contents(base_path("{$directory}/sp.crt")))['subject']['CN'])->toBe('sp.test')
            ->and(file_get_contents(base_path("{$directory}/sp.key")))->toContain('PRIVATE KEY');

        $this->artisan('saml2:generate-cert', ['--path' => $directory])->assertFailed();
    } finally {
        File::deleteDirectory(base_path($directory));
    }
});
