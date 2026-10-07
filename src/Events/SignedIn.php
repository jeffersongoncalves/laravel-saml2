<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2\Events;

use Illuminate\Http\Request;
use JeffersonGoncalves\LaravelSaml2\Saml2Auth;
use JeffersonGoncalves\LaravelSaml2\Saml2User;

/**
 * A SAMLResponse was validated. Find or create your user here and log them in.
 */
class SignedIn
{
    public function __construct(
        public readonly Saml2User $user,
        public readonly Saml2Auth $auth,
        public readonly Request $request,
    ) {}
}
