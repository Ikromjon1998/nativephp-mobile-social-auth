<?php

namespace Ikromjon\NativePHP\SocialAuth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

class AppleSignInCompleted implements DispatchableFromNative
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $userId,
        public ?string $identityToken = null,
        public ?string $authorizationCode = null,
        public ?string $email = null,
        public ?string $givenName = null,
        public ?string $familyName = null,
        public ?string $displayName = null,
        public ?string $state = null,
        public ?string $realUserStatus = null,
    ) {}
}
