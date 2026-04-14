<?php

namespace Ikromjon\NativePHP\SocialAuth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GoogleSignInCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $userId,
        public ?string $identityToken = null,
        public ?string $email = null,
        public ?string $displayName = null,
        public ?string $photoUrl = null,
    ) {}
}
