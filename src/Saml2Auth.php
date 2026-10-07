<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use JeffersonGoncalves\LaravelSaml2\Exceptions\Saml2Exception;
use JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant;
use OneLogin\Saml2\Auth as OneLoginAuth;
use OneLogin\Saml2\Error as OneLoginError;
use OneLogin\Saml2\LogoutRequest;
use OneLogin\Saml2\Utils as OneLoginUtils;
use OneLogin\Saml2\ValidationError;
use Throwable;

/**
 * One configured toolkit instance bound to a single tenant (Identity Provider).
 *
 * Every method returns instead of redirecting/exiting, so the caller builds a
 * Laravel response and the session middleware still runs.
 */
class Saml2Auth
{
    public function __construct(
        protected OneLoginAuth $base,
        protected Saml2Tenant $tenant,
    ) {}

    public function tenant(): Saml2Tenant
    {
        return $this->tenant;
    }

    public function base(): OneLoginAuth
    {
        return $this->base;
    }

    /**
     * Build the IdP SSO URL carrying the AuthnRequest.
     *
     * @param  array<string, string>  $parameters  Extra query parameters for the IdP (e.g. login_hint).
     */
    public function login(
        ?string $returnTo = null,
        array $parameters = [],
        bool $forceAuthn = false,
        bool $isPassive = false,
        bool $setNameIdPolicy = true,
        ?string $nameIdValueReq = null,
    ): string {
        return (string) $this->base->login($returnTo, $parameters, $forceAuthn, $isPassive, true, $setNameIdPolicy, $nameIdValueReq);
    }

    /**
     * Build the IdP SLO URL carrying the LogoutRequest.
     */
    public function logout(
        ?string $returnTo = null,
        ?string $nameId = null,
        ?string $sessionIndex = null,
        ?string $nameIdFormat = null,
    ): string {
        if (! $this->tenant->idp_logout_url) {
            throw new Saml2Exception("Tenant [{$this->tenant->routeIdentifier()}] has no IdP logout URL.");
        }

        return (string) $this->base->logout($returnTo, [], $nameId, $sessionIndex, true, $nameIdFormat);
    }

    /**
     * Validate the SAMLResponse posted to the ACS endpoint.
     *
     * @throws Saml2Exception
     */
    public function acs(Request $request): Saml2User
    {
        $this->bridge($request);

        try {
            $this->base->processResponse();
        } catch (OneLoginError|ValidationError $e) {
            throw new Saml2Exception($e->getMessage(), ['invalid_response'], $e);
        }

        if ($errors = $this->base->getErrors()) {
            throw new Saml2Exception($this->base->getLastErrorReason() ?: 'Invalid SAML response.', $errors);
        }

        if (! $this->base->isAuthenticated()) {
            throw new Saml2Exception('Could not authenticate.', ['not_authenticated']);
        }

        $this->guardAgainstReplay();

        return new Saml2User($this->base, $this->tenant);
    }

    /**
     * Process a LogoutRequest (IdP-initiated) or LogoutResponse (SP-initiated) on the SLS endpoint.
     *
     * $onLogout receives the NameID and SessionIndex of the IdP LogoutRequest (null for a LogoutResponse).
     *
     * @param  Closure(?string, ?string): void  $onLogout
     * @return string|null URL to send the LogoutResponse back to the IdP, null when nothing is owed.
     *
     * @throws Saml2Exception
     */
    public function sls(Request $request, Closure $onLogout, bool $retrieveParametersFromServer = false): ?string
    {
        $this->bridge($request);

        try {
            $url = $this->base->processSLO(false, null, $retrieveParametersFromServer, function () use ($onLogout): void {
                [$nameId, $sessionIndex] = $this->logoutRequestSubject();
                $onLogout($nameId, $sessionIndex);
            }, true);
        } catch (OneLoginError $e) {
            throw new Saml2Exception($e->getMessage(), ['invalid_binding'], $e);
        }

        if ($errors = $this->base->getErrors()) {
            throw new Saml2Exception($this->base->getLastErrorReason() ?: 'Invalid logout message.', $errors);
        }

        return $url ?: null;
    }

    /**
     * SP metadata XML to hand to the IdP administrator.
     *
     * @throws Saml2Exception
     */
    public function metadata(): string
    {
        $settings = $this->base->getSettings();
        $metadata = $settings->getSPMetadata();

        if ($errors = $settings->validateMetadata($metadata)) {
            throw new Saml2Exception('Invalid SP metadata: '.implode(', ', $errors), $errors);
        }

        return $metadata;
    }

    public function lastErrorReason(): ?string
    {
        return $this->base->getLastErrorReason();
    }

    /**
     * Feed the toolkit from the Laravel request instead of PHP globals.
     *
     * The toolkit reads $_GET/$_POST/$_SERVER directly, which are empty under Octane and
     * wrong behind proxies. Scheme/host/port come from the request (TrustProxies aware)
     * or from saml2.base_url, so the received URL matches the URL the IdP posted to.
     */
    protected function bridge(Request $request): void
    {
        $_GET = $request->query->all();
        $_POST = $request->request->all();
        $_REQUEST = $_GET + $_POST;
        $_SERVER['REQUEST_URI'] = $request->server('REQUEST_URI', $request->getRequestUri());
        $_SERVER['QUERY_STRING'] = $request->server('QUERY_STRING', (string) $request->getQueryString());

        $base = parse_url((string) config('saml2.base_url')) ?: [];
        $scheme = $base['scheme'] ?? $request->getScheme();

        if (isset($base['host'])) {
            $port = $base['port'] ?? ($scheme === 'https' ? 443 : 80);
        } else {
            $port = (int) $request->getPort();
        }

        OneLoginUtils::setSelfProtocol($scheme);
        OneLoginUtils::setSelfHost($base['host'] ?? $request->getHost());
        OneLoginUtils::setSelfPort($port);
    }

    /**
     * Reject an assertion ID that was already consumed (until it expires).
     *
     * @throws Saml2Exception
     */
    protected function guardAgainstReplay(): void
    {
        $assertionId = $this->base->getLastAssertionId();

        if (! config('saml2.replay_protection.enabled', true) || ! $assertionId) {
            return;
        }

        $notOnOrAfter = $this->base->getLastAssertionNotOnOrAfter();
        $ttl = $notOnOrAfter ? Carbon::createFromTimestamp($notOnOrAfter)->addMinutes(5) : now()->addHour();

        $fresh = Cache::store(config('saml2.replay_protection.store'))
            ->add("saml2:assertion:{$this->tenant->uuid}:{$assertionId}", true, $ttl);

        if (! $fresh) {
            throw new Saml2Exception('This SAML assertion was already used.', ['replayed_assertion']);
        }
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    protected function logoutRequestSubject(): array
    {
        $xml = $this->base->getLastRequestXML();

        if (! $xml) {
            return [null, null];
        }

        try {
            $nameId = LogoutRequest::getNameId($xml, $this->base->getSettings()->getSPkey());
        } catch (Throwable) {
            $nameId = null;
        }

        return [$nameId, LogoutRequest::getSessionIndexes($xml)[0] ?? null];
    }
}
