<?php

namespace Ikromjon\NativePHP\SocialAuth\Exceptions;

use RuntimeException;

class MissingProviderConfigException extends RuntimeException
{
    /**
     * @param  array<int, string>  $missing
     */
    public static function for(string $provider, array $missing): self
    {
        return new self(sprintf(
            'Provider [%s] is missing required configuration: %s. '
            .'Add it under `providers.%s` in config/social-auth.php. '
            .'Never add a client secret there — it ships inside the app binary; '
            .'exchange the authorization code on your server instead.',
            $provider,
            implode(', ', $missing),
            $provider,
        ));
    }
}
