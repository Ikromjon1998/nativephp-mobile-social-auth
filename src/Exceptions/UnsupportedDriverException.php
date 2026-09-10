<?php

namespace Ikromjon\NativePHP\SocialAuth\Exceptions;

use RuntimeException;

class UnsupportedDriverException extends RuntimeException
{
    /**
     * A `native` provider is only reachable through a bridge function the
     * plugin actually compiles, so a config-only one cannot work.
     */
    public static function missingBridge(string $provider): self
    {
        return new self(sprintf(
            'Provider [%s] uses the `native` driver but declares no `bridge` function. '
            .'Native providers require Swift/Kotlin shipped with the plugin; a provider added '
            .'purely in config should use the `oauth` driver instead.',
            $provider,
        ));
    }

    public static function for(string $provider, string $driver): self
    {
        return new self(sprintf(
            'Provider [%s] declares an unknown driver [%s]. Supported drivers: native, oauth.',
            $provider,
            $driver,
        ));
    }
}
