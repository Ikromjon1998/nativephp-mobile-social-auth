<?php

namespace Ikromjon\NativePHP\SocialAuth\Exceptions;

use InvalidArgumentException;

class UnknownProviderException extends InvalidArgumentException
{
    /**
     * @param  array<int, string>  $known
     */
    public static function for(string $provider, array $known): self
    {
        sort($known);

        return new self(sprintf(
            'Unknown social auth provider [%s]. Known providers: %s. '
            .'Add custom providers under the `providers` key of config/social-auth.php.',
            $provider,
            implode(', ', $known) ?: 'none',
        ));
    }
}
