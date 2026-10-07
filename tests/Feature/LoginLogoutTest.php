<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Event;
use JeffersonGoncalves\LaravelSaml2\Events\SignedOut;
use JeffersonGoncalves\LaravelSaml2\Saml2;
use JeffersonGoncalves\LaravelSaml2\Tests\Support\FakeIdp;

function query(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

function user(): GenericUser
{
    return new GenericUser(['id' => 1, 'remember_token' => null]);
}

it('redirects to the IdP with an AuthnRequest, a safe RelayState and allowed hints', function () {
    $tenant = FakeIdp::tenant();

    $location = $this->get(route('saml2.login', ['tenant' => 'acme', 'returnTo' => '/reports', 'login_hint' => 'jane@acme.com', 'evil' => 'x']))
        ->assertRedirect()
        ->headers->get('Location');

    expect($location)->toStartWith(FakeIdp::SSO_URL.'?')
        ->and(query($location))->toHaveKeys(['SAMLRequest', 'RelayState', 'login_hint'])
        ->and(query($location))->not->toHaveKey('evil')
        ->and(query($location)['RelayState'])->toBe('/reports');

    $request = gzinflate(base64_decode(query($location)['SAMLRequest']));
    expect($request)->toContain(route('saml2.acs', ['tenant' => $tenant->uuid]));
});

it('drops a foreign returnTo and falls back to the tenant relay state', function () {
    FakeIdp::tenant(['relay_state_url' => '/portal']);

    $location = $this->get(route('saml2.login', ['tenant' => 'acme', 'returnTo' => 'https://evil.example.net']))
        ->headers->get('Location');

    expect(query($location)['RelayState'])->toBe('/portal');
});

it('starts Single Logout at the IdP with the NameID and SessionIndex of the session', function () {
    $tenant = FakeIdp::tenant();

    $location = $this->withSession([
        Saml2::SESSION_TENANT => $tenant->uuid,
        Saml2::SESSION_NAME_ID => 'jane',
        Saml2::SESSION_SESSION_INDEX => '_session123',
    ])->get(route('saml2.logout', ['tenant' => 'acme', 'returnTo' => '/bye']))->headers->get('Location');

    expect($location)->toStartWith(FakeIdp::SLO_URL.'?')
        ->and(query($location)['RelayState'])->toBe('/bye');

    $request = gzinflate(base64_decode(query($location)['SAMLRequest']));
    expect($request)->toContain('>jane<')->toContain('_session123');
});

it('logs out locally when the IdP has no logout URL', function () {
    Event::fake([SignedOut::class]);
    FakeIdp::tenant(['idp_logout_url' => null]);

    $this->actingAs(user())
        ->withSession([Saml2::SESSION_NAME_ID => 'jane'])
        ->get(route('saml2.logout', ['tenant' => 'acme']))
        ->assertRedirect('/');

    $this->assertGuest();
    Event::assertDispatched(SignedOut::class, fn (SignedOut $e) => $e->nameId === 'jane');
});

it('handles an IdP-initiated logout: event with subject, local logout, LogoutResponse to the IdP', function () {
    Event::fake([SignedOut::class]);
    $tenant = FakeIdp::tenant();

    $location = $this->actingAs(user())
        ->get(route('saml2.sls', ['tenant' => $tenant->uuid, 'SAMLRequest' => FakeIdp::logoutRequest($tenant), 'RelayState' => '/x']))
        ->assertRedirect()
        ->headers->get('Location');

    expect($location)->toStartWith(FakeIdp::SLO_URL.'?')
        ->and(query($location))->toHaveKeys(['SAMLResponse', 'RelayState']);

    $this->assertGuest();
    Event::assertDispatched(SignedOut::class, fn (SignedOut $e) => $e->nameId === 'jane'
        && $e->sessionIndex === '_session123'
        && $e->tenant->is($tenant));
});

it('reports an invalid logout message', function () {
    Event::fake([SignedOut::class]);
    $tenant = FakeIdp::tenant();

    $this->get(route('saml2.sls', ['tenant' => $tenant->uuid, 'SAMLRequest' => base64_encode((string) gzdeflate('<nope/>'))]))
        ->assertRedirect('/')
        ->assertSessionHas('saml2.error');

    Event::assertNotDispatched(SignedOut::class);
});

it('builds login and logout links for the current tenant through the facade', function () {
    $tenant = FakeIdp::tenant();
    session()->put(Saml2::SESSION_TENANT, $tenant->uuid);

    expect(JeffersonGoncalves\LaravelSaml2\Facades\Saml2::loginUrl(returnTo: '/a'))
        ->toBe(route('saml2.login', ['tenant' => 'acme']).'?returnTo=%2Fa')
        ->and(JeffersonGoncalves\LaravelSaml2\Facades\Saml2::logoutUrl())
        ->toBe(route('saml2.logout', ['tenant' => 'acme']))
        ->and(JeffersonGoncalves\LaravelSaml2\Facades\Saml2::current()?->tenant()->is($tenant))
        ->toBeTrue();
});
