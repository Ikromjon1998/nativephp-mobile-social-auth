<?php

namespace Ikromjon\NativePHP\SocialAuth;

use Ikromjon\NativePHP\SocialAuth\Data\AuthResult;
use Illuminate\Container\Container;

class SocialAuth
{
    /**
     * Initiate native Apple Sign-In.
     *
     * Returns user credentials including identity token, authorization code,
     * and user info (email/name only on first sign-in).
     *
     * Prefer handling the result via the AppleSignInCompleted event; on iOS
     * the same result is also dispatched as an event, so handling both the
     * return value and the event runs your handler twice.
     *
     * @param  array<string>  $scopes  Requested scopes: 'email', 'fullName'
     * @param  string|null  $nonce  Optional nonce for replay protection (hash with SHA256 before passing)
     * @param  string|null  $state  Optional state parameter echoed back in response
     */
    public function appleSignIn(array $scopes = ['email', 'fullName'], ?string $nonce = null, ?string $state = null): ?AuthResult
    {
        $params = ['scopes' => $scopes];

        if ($nonce !== null) {
            $params['nonce'] = $nonce;
        }

        if ($state !== null) {
            $params['state'] = $state;
        }

        $payload = $this->payload($this->call('SocialAuth.AppleSignIn', $params));

        if (($payload['status'] ?? '') === 'success') {
            return AuthResult::fromArray($payload);
        }

        return null;
    }

    /**
     * Initiate native Google Sign-In.
     *
     * Returns user credentials including ID token, access token, and user profile.
     *
     * Prefer handling the result via the GoogleSignInCompleted event; on iOS
     * the same result is also dispatched as an event, so handling both the
     * return value and the event runs your handler twice.
     *
     * @param  string|null  $nonce  Optional nonce for replay protection (raw string; returned as the `nonce` claim inside the ID token)
     */
    public function googleSignIn(?string $nonce = null): ?AuthResult
    {
        $params = [];

        if ($nonce !== null) {
            $params['nonce'] = $nonce;
        }

        $serverClientId = $this->serverClientId();

        if ($serverClientId) {
            $params['serverClientId'] = $serverClientId;
        }

        $payload = $this->payload($this->call('SocialAuth.GoogleSignIn', $params));

        if (($payload['status'] ?? '') === 'success') {
            return AuthResult::fromArray($payload);
        }

        return null;
    }

    /**
     * Check if an Apple Sign-In credential is still valid.
     *
     * @param  string  $userId  The Apple user identifier from a previous sign-in
     * @return string 'authorized', 'revoked', 'not_found', or 'unknown'
     */
    public function checkAppleCredentialState(string $userId): string
    {
        $payload = $this->payload($this->call('SocialAuth.CheckAppleCredentialState', ['userId' => $userId]));

        return $payload['state'] ?? 'unknown';
    }

    /**
     * Sign out from Google.
     *
     * Apple Sign-In has no sign-out API — users manage Apple ID sessions
     * through system settings.
     *
     * @return bool True if sign-out succeeded
     */
    public function signOut(): bool
    {
        $payload = $this->payload($this->call('SocialAuth.SignOut'));

        return ($payload['signedOut'] ?? false) === true;
    }

    /**
     * The server client ID handed to the native SDK.
     *
     * Read through config rather than env() so it survives `config:cache`.
     * The container check keeps the class usable outside a booted application,
     * which unit tests rely on.
     */
    protected function serverClientId(): ?string
    {
        if (! Container::getInstance()->bound('config')) {
            return null;
        }

        return config('social-auth.google_server_client_id') ?: config('services.google.client_id');
    }

    /**
     * Invoke a bridge function, or return null when off-device.
     *
     * Kept separate so tests can substitute a canned response without a
     * running native runtime.
     *
     * @param  array<string, mixed>  $params
     */
    protected function call(string $method, array $params = []): ?string
    {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        return nativephp_call($method, json_encode($params) ?: '{}');
    }

    /**
     * Decode a bridge response into a flat payload.
     *
     * The native layer builds responses with `BridgeResponse.success(data:)`,
     * which returns the payload flat, but some transports wrap it in a `data`
     * envelope. Reading only one of those shapes is how `signOut()` came to
     * always report false and `checkAppleCredentialState()` always 'unknown',
     * so both are unwrapped here.
     *
     * @return array<string, mixed>
     */
    private function payload(?string $response): array
    {
        if ($response === null || $response === '') {
            return [];
        }

        $decoded = json_decode($response, true);

        if (! is_array($decoded)) {
            return [];
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return $decoded['data'];
        }

        return $decoded;
    }
}
