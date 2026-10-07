<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use JeffersonGoncalves\LaravelSaml2\Events\Saml2Failed;
use JeffersonGoncalves\LaravelSaml2\Events\SignedIn;
use JeffersonGoncalves\LaravelSaml2\Events\SignedOut;
use JeffersonGoncalves\LaravelSaml2\Exceptions\Saml2Exception;
use JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant;
use JeffersonGoncalves\LaravelSaml2\Saml2;

/**
 * Extend this class and point saml2.routes.controller to it to customise any step.
 */
class Saml2Controller extends Controller
{
    public function __construct(protected Saml2 $saml2) {}

    public function metadata(Request $request): Response
    {
        $metadata = $this->saml2->auth($this->tenant($request))->metadata();

        return response($metadata, 200, ['Content-Type' => 'text/xml']);
    }

    public function login(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);

        $returnTo = $this->saml2->safeRedirect($request->query('returnTo'), $request)
            ?? $tenant->relay_state_url
            ?? config('saml2.redirects.login');

        $parameters = array_filter(
            $request->only((array) config('saml2.login_query_parameters', [])),
            'is_string',
        );

        return redirect()->away(
            $this->saml2->auth($tenant)->login($returnTo, $parameters, $request->boolean('force'))
        );
    }

    public function acs(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $auth = $this->saml2->auth($tenant);

        try {
            $user = $auth->acs($request);
        } catch (Saml2Exception $e) {
            return $this->failed($request, $tenant, $e, 'login');
        }

        $request->session()->put([
            Saml2::SESSION_TENANT => $tenant->uuid,
            Saml2::SESSION_NAME_ID => $user->nameId(),
            Saml2::SESSION_NAME_ID_FORMAT => $user->nameIdFormat(),
            Saml2::SESSION_SESSION_INDEX => $user->sessionIndex(),
        ]);

        event(new SignedIn($user, $auth, $request));

        $relayState = $request->input('RelayState');
        $relayState = is_string($relayState) && $relayState !== $request->url() ? $relayState : null;

        return redirect()->to(
            $this->saml2->safeRedirect($relayState, $request)
                ?? $tenant->relay_state_url
                ?? config('saml2.redirects.login')
        );
    }

    public function sls(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $session = $request->session();

        try {
            $url = $this->saml2->auth($tenant)->sls(
                $request,
                function (?string $nameId, ?string $sessionIndex) use ($request, $session, $tenant): void {
                    event(new SignedOut(
                        $tenant,
                        $nameId ?? $session->get(Saml2::SESSION_NAME_ID),
                        $sessionIndex ?? $session->get(Saml2::SESSION_SESSION_INDEX),
                        $request,
                    ));

                    $this->logoutLocally($request);
                },
                (bool) config('saml2.retrieve_parameters_from_server', false),
            );
        } catch (Saml2Exception $e) {
            return $this->failed($request, $tenant, $e, 'logout');
        }

        if ($url) {
            return redirect()->away($url);
        }

        return redirect()->to(
            $this->saml2->safeRedirect($request->query('RelayState'), $request) ?? config('saml2.redirects.logout')
        );
    }

    public function logout(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $session = $request->session();

        $returnTo = $this->saml2->safeRedirect($request->query('returnTo'), $request)
            ?? config('saml2.redirects.logout');

        if (! $tenant->idp_logout_url) {
            event(new SignedOut($tenant, $session->get(Saml2::SESSION_NAME_ID), $session->get(Saml2::SESSION_SESSION_INDEX), $request));
            $this->logoutLocally($request);

            return redirect()->to($returnTo);
        }

        return redirect()->away($this->saml2->auth($tenant)->logout(
            $returnTo,
            $session->get(Saml2::SESSION_NAME_ID),
            $session->get(Saml2::SESSION_SESSION_INDEX),
            $session->get(Saml2::SESSION_NAME_ID_FORMAT),
        ));
    }

    protected function tenant(Request $request): Saml2Tenant
    {
        $tenant = $this->saml2->resolveTenant($request);

        if (! $tenant || $tenant->trashed()) {
            abort(404);
        }

        return $tenant;
    }

    protected function logoutLocally(Request $request): void
    {
        if ($guard = config('saml2.logout.guard')) {
            Auth::guard($guard)->logout();
        }

        if (config('saml2.logout.invalidate_session', true)) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        } else {
            $request->session()->forget([
                Saml2::SESSION_TENANT,
                Saml2::SESSION_NAME_ID,
                Saml2::SESSION_NAME_ID_FORMAT,
                Saml2::SESSION_SESSION_INDEX,
            ]);
        }
    }

    protected function failed(Request $request, Saml2Tenant $tenant, Saml2Exception $e, string $step): RedirectResponse
    {
        Log::error("[saml2] {$step} failed", [
            'tenant' => $tenant->uuid,
            'errors' => $e->errors,
            'reason' => $e->getMessage(),
        ]);

        event(new Saml2Failed($tenant, $step, $e->errors, $e->getMessage(), $request));

        $request->session()->flash('saml2.error', $e->errors);
        $request->session()->flash('saml2.error_reason', $e->getMessage());

        return redirect()->to(config('saml2.redirects.error'));
    }
}
