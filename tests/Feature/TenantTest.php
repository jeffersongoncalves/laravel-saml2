<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use JeffersonGoncalves\LaravelSaml2\Facades\Saml2;
use JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant;
use JeffersonGoncalves\LaravelSaml2\Tests\Support\FakeIdp;

it('resolves the tenant by UUID or friendly key', function () {
    $tenant = FakeIdp::tenant();

    $this->get(route('saml2.metadata', ['tenant' => $tenant->uuid]))->assertOk();
    $this->get(route('saml2.metadata', ['tenant' => 'acme']))->assertOk();
    $this->get(route('saml2.metadata', ['tenant' => 'nope']))->assertNotFound();
    $this->get(route('saml2.metadata', ['tenant' => (string) $tenant->id]))->assertNotFound();
});

it('does not serve deleted tenants', function () {
    FakeIdp::tenant()->delete();

    $this->get(route('saml2.metadata', ['tenant' => 'acme']))->assertNotFound();
});

it('can resolve tenants with a custom resolver', function () {
    $tenant = FakeIdp::tenant(['key' => null]);
    Saml2::resolveTenantUsing(fn (Request $request) => $request->route('tenant') === 'subdomain' ? $tenant : null);

    $this->get(route('saml2.metadata', ['tenant' => 'subdomain']))->assertOk();
});

it('serves SP metadata with UUID based endpoints', function () {
    $tenant = FakeIdp::tenant();

    $this->get(route('saml2.metadata', ['tenant' => 'acme']))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/xml; charset=utf-8')
        ->assertSee('entityID="'.route('saml2.metadata', ['tenant' => $tenant->uuid]).'"', false)
        ->assertSee(route('saml2.acs', ['tenant' => $tenant->uuid]), false)
        ->assertSee('urn:oasis:names:tc:SAML:2.0:nameid-format:persistent', false);
});

it('builds toolkit settings from config, tenant settings and callbacks', function () {
    config()->set('saml2.security.wantAssertionsSigned', false);
    $tenant = FakeIdp::tenant([
        'name_id_format' => 'emailAddress',
        'settings' => ['security' => ['wantAssertionsSigned' => true], 'sp' => ['entityId' => 'urn:acme:sp']],
    ]);
    Saml2::configureUsing(function (array $settings, Saml2Tenant $tenant) {
        $settings['idp']['singleSignOnService']['url'] .= '?tenant='.$tenant->key;

        return $settings;
    });

    $settings = Saml2::settings($tenant);

    expect($settings['security']['wantAssertionsSigned'])->toBeTrue()
        ->and($settings['sp']['entityId'])->toBe('urn:acme:sp')
        ->and($settings['sp']['NameIDFormat'])->toBe('urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress')
        ->and($settings['idp']['singleSignOnService']['url'])->toBe(FakeIdp::SSO_URL.'?tenant=acme')
        ->and($settings['idp']['x509cert'])->toBe(FakeIdp::certificate());
});

it('reads SP certificates from a path relative to the project root', function () {
    $relative = 'storage/saml2-test.crt';
    file_put_contents(base_path($relative), FakeIdp::certificate());
    config()->set('saml2.sp.x509cert', $relative);

    try {
        expect(Saml2::settings(FakeIdp::tenant())['sp']['x509cert'])->toBe(trim(FakeIdp::certificate()));
    } finally {
        unlink(base_path($relative));
    }
});

it('fills the IdP columns from metadata and keeps rollover certificates', function () {
    $other = str_replace('A', 'B', FakeIdp::certificate());
    $tenant = new Saml2Tenant;

    $tenant->fillFromIdpMetadata(FakeIdp::metadata(FakeIdp::certificate(), $other));

    expect($tenant->idp_entity_id)->toBe(FakeIdp::ENTITY_ID)
        ->and($tenant->idp_login_url)->toBe(FakeIdp::SSO_URL)
        ->and($tenant->idp_logout_url)->toBe(FakeIdp::SLO_URL)
        ->and($tenant->settings['idp']['x509certMulti']['signing'])->toHaveCount(2);

    $tenant->fillFromIdpMetadata(FakeIdp::metadata());

    expect($tenant->settings)->toBeNull()
        ->and($tenant->idp_x509_cert)->not->toBeEmpty();
});

it('only redirects to safe URLs', function (?string $url, ?string $expected) {
    config()->set('saml2.allowed_redirect_hosts', ['trusted.example.com']);

    expect(Saml2::safeRedirect($url))->toBe($expected);
})->with([
    ['/dashboard', '/dashboard'],
    ['//evil.example.net', null],
    ['/\\evil.example.net', null],
    ['https://evil.example.net', null],
    ['javascript:alert(1)', null],
    ['http://localhost/ok', 'http://localhost/ok'],
    ['https://TRUSTED.example.com/x', 'https://TRUSTED.example.com/x'],
    [null, null],
]);
