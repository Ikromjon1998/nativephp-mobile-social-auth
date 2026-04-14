<?php

namespace Ikromjon\NativePHP\SocialAuth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SignInFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $provider,
        public string $error,
        public ?string $errorCode = null,
    ) {}
}
