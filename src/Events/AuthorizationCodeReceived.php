<?php

namespace Ikromjon\NativePHP\SocialAuth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A browser-based provider returned an authorization code.
 *
 * This is deliberately not a SignInCompleted: nothing is known about the user
 * yet. The code is worthless without a client secret, which must never ship
 * inside the app, so the sign-in only completes once your server exchanges
 * `authorizationCode` + `codeVerifier` for tokens.
 *
 * The verifier is generated on device, handed to the native layer, and echoed
 * back here so the pair travels together. It is a one-time value with no
 * standing authority — but treat it like the code itself and send both to your
 * own backend over TLS, never to a third party.
 */
class AuthorizationCodeReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $provider,
        public string $authorizationCode,
        public ?string $codeVerifier = null,
        public ?string $state = null,
        public ?string $redirectUri = null,
    ) {}
}
