<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use JeffersonGoncalves\LaravelSaml2\Events\Saml2Failed;
use JeffersonGoncalves\LaravelSaml2\Events\SignedIn;
use JeffersonGoncalves\LaravelSaml2\Saml2;
use JeffersonGoncalves\LaravelSaml2\Tests\Support\FakeIdp;

it('validates a signed response, stores the SAML session and fires SignedIn with the request', function () {
    Event::fake([SignedIn::class]);
    $tenant = FakeIdp::tenant();

    $this->post(route('saml2.acs', ['tenant' => $tenant->uuid]), [
        'SAMLResponse' => FakeIdp::response($tenant),
        'RelayState' => '/dashboard',
    ], ['REMOTE_ADDR' => '203.0.113.7'])
        ->assertRedirect('/dashboard')
        ->assertSessionHas(Saml2::SESSION_TENANT, $tenant->uuid)
        ->assertSessionHas(Saml2::SESSION_NAME_ID, 'jane')
        ->assertSessionHas(Saml2::SESSION_SESSION_INDEX, '_session123');

    Event::assertDispatched(SignedIn::class, function (SignedIn $event) use ($tenant) {
        return $event->user->nameId() === 'jane'
            && $event->user->tenant()->is($tenant)
            && $event->user->email() === 'jane.doe@example.com'
            && $event->user->attribute('groups') === ['admins', 'devs']
            && $event->user->mapped()['groups'] === ['admins', 'devs']
            && $event->auth->tenant()->is($tenant)
            && $event->request->ip() === '203.0.113.7';
    });
});

it('ignores a RelayState pointing to a foreign host', function () {
    $tenant = FakeIdp::tenant(['relay_state_url' => '/home']);

    $this->post(route('saml2.acs', ['tenant' => $tenant->uuid]), [
        'SAMLResponse' => FakeIdp::response($tenant),
        'RelayState' => 'https://evil.example.net/phish',
    ])->assertRedirect('/home');
});

it('follows a RelayState on an allowed host', function () {
    config()->set('saml2.allowed_redirect_hosts', ['app.example.org']);
    $tenant = FakeIdp::tenant();

    $this->post(route('saml2.acs', ['tenant' => $tenant->uuid]), [
        'SAMLResponse' => FakeIdp::response($tenant),
        'RelayState' => 'https://app.example.org/welcome',
    ])->assertRedirect('https://app.example.org/welcome');
});

it('rejects a replayed assertion', function () {
    Event::fake([SignedIn::class, Saml2Failed::class]);
    $tenant = FakeIdp::tenant();
    $response = FakeIdp::response($tenant, assertionId: '_replayed');
    $url = route('saml2.acs', ['tenant' => $tenant->uuid]);

    $this->post($url, ['SAMLResponse' => $response])->assertRedirect('/');
    $this->post($url, ['SAMLResponse' => $response])->assertSessionHas('saml2.error', ['replayed_assertion']);

    Event::assertDispatchedTimes(SignedIn::class, 1);
    Event::assertDispatched(Saml2Failed::class, fn (Saml2Failed $e) => $e->step === 'login' && $e->errors === ['replayed_assertion']);
});

it('rejects an unsigned response', function () {
    Event::fake([SignedIn::class, Saml2Failed::class]);
    config()->set('saml2.redirects.error', '/login?sso=failed');
    $tenant = FakeIdp::tenant();

    $this->post(route('saml2.acs', ['tenant' => $tenant->uuid]), [
        'SAMLResponse' => FakeIdp::response($tenant, sign: false),
    ])
        ->assertRedirect('/login?sso=failed')
        ->assertSessionHas('saml2.error', ['invalid_response']);

    Event::assertNotDispatched(SignedIn::class);
    Event::assertDispatched(Saml2Failed::class);
});

it('rejects a response for another audience', function () {
    Event::fake([SignedIn::class]);
    $tenant = FakeIdp::tenant();

    $this->post(route('saml2.acs', ['tenant' => $tenant->uuid]), [
        'SAMLResponse' => FakeIdp::response($tenant, audience: 'https://other-sp.example.com'),
    ])->assertSessionHas('saml2.error');

    Event::assertNotDispatched(SignedIn::class);
});

it('accepts the response behind a TLS terminating proxy when base_url is set', function () {
    Event::fake([SignedIn::class]);
    config()->set('saml2.base_url', 'https://sso.example.com');
    $tenant = FakeIdp::tenant();

    // The IdP posts to https://sso.example.com/..., the app itself receives plain http.
    expect(app(Saml2::class)->serviceProviderUrls($tenant)['acs'])
        ->toBe("https://sso.example.com/saml2/{$tenant->uuid}/acs")
        ->and(route('saml2.acs', ['tenant' => $tenant->uuid]))->toStartWith('http://');

    $this->post(route('saml2.acs', ['tenant' => $tenant->uuid]), ['SAMLResponse' => FakeIdp::response($tenant)])
        ->assertSessionMissing('saml2.error');

    Event::assertDispatched(SignedIn::class);
});
