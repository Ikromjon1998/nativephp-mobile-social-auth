<?php

namespace Ikromjon\NativePHP\SocialAuth\Providers;

/**
 * A PKCE verifier/challenge pair (RFC 7636).
 *
 * PKCE is what makes an authorization-code flow safe in a public client. The
 * app holds no secret, so anyone who intercepts the redirect could otherwise
 * redeem the code; binding it to a verifier the interceptor never saw closes
 * that. Generated here rather than natively so the same code path — and the
 * same tests — cover both platforms.
 */
class PkceChallenge
{
    private function __construct(
        public readonly string $verifier,
        public readonly string $challenge,
        public readonly string $method = 'S256',
    ) {}

    public static function generate(): self
    {
        // RFC 7636 allows 43-128 characters; 64 random bytes lands at 86.
        return self::fromVerifier(self::base64Url(random_bytes(64)));
    }

    /**
     * Rebuild the pair from a caller-supplied verifier.
     *
     * Useful when the verifier is persisted server-side before the flow starts
     * rather than travelling back through the app.
     */
    public static function fromVerifier(string $verifier): self
    {
        return new self(
            verifier: $verifier,
            challenge: self::base64Url(hash('sha256', $verifier, true)),
        );
    }

    /** Base64url without padding, which the spec requires. */
    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
