<?php

namespace Ikromjon\NativePHP\SocialAuth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A completed sign-in, whichever provider handled it.
 *
 * This is the event to listen for from 1.2.0 onwards. The provider-specific
 * events still fire — this one is dispatched alongside them by a listener
 * registered in the service provider, so existing handlers keep working.
 * Listen to *either* this event or the provider-specific one, never both, or
 * your handler runs twice.
 *
 * Every field any provider can return is declared here, and all but `provider`
 * and `userId` are optional. That is a hard requirement rather than a style
 * choice: NativePHP dispatches native events as `new $event(...$payload)`, so a
 * payload key with no matching constructor parameter is a fatal error that the
 * Android bridge swallows silently. See tests/BridgeResponseTest.php, which
 * pins each native payload against the constructor it is spread into.
 */
class SignInCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $provider,
        public string $userId,
        public ?string $identityToken = null,
        public ?string $authorizationCode = null,
        public ?string $accessToken = null,
        public ?string $email = null,
        public ?string $givenName = null,
        public ?string $familyName = null,
        public ?string $displayName = null,
        public ?string $photoUrl = null,
        public ?string $state = null,
        public ?string $realUserStatus = null,
    ) {}
}
