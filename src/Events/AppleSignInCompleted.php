<?php

namespace Ikromjon\NativePHP\SocialAuth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppleSignInCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $userId,
        public ?string $identityToken = null,
        public ?string $authorizationCode = null,
        public ?string $email = null,
        public ?string $givenName = null,
        public ?string $familyName = null,
    ) {}
}
