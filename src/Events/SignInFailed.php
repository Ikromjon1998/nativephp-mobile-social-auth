<?php

namespace Ikromjon\NativePHP\SocialAuth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

class SignInFailed implements DispatchableFromNative
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $provider,
        public string $error,
        public ?string $errorCode = null,
    ) {}
}
