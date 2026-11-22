<?php

namespace Ikromjon\NativePHP\SocialAuth\Tests\Fixtures;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

/**
 * LookalikeEvent with the DispatchableFromNative marker, and a counter of its
 * own: the control showing that the marker alone decides whether the events
 * endpoint builds the class.
 */
class MarkedLookalikeEvent implements DispatchableFromNative
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
