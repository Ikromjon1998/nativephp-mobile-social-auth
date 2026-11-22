<?php

namespace Ikromjon\NativePHP\SocialAuth\Tests\Fixtures;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * SignInFailed in every respect but the DispatchableFromNative marker: a class
 * a page could name if the events endpoint built whatever it was given. It
 * counts its constructions, so a test can tell "refused" from "built and then
 * dropped".
 */
class LookalikeEvent
{
    use Dispatchable, SerializesModels;

    public static int $constructed = 0;

    public function __construct(
        public string $provider,
        public string $error,
        public ?string $errorCode = null,
    ) {
        self::$constructed++;
    }
}
