<?php

namespace Ikromjon\NativePHP\SocialAuth;

use Ikromjon\NativePHP\SocialAuth\Data\AuthResult;

class SocialAuth
{
    /**
     * Initiate native Apple Sign-In.
     *
     * Returns user credentials including identity token, authorization code,
     * and user info (email/name only on first sign-in).
     *
     * @param  array<string>  $scopes  Requested scopes: 'email', 'fullName'
     * @param  string|null  $nonce  Optional nonce for replay protection (hash with SHA256 before passing)
     * @param  string|null  $state  Optional state parameter echoed back in response
     */
    public function appleSignIn(array $scopes = ['email', 'fullName'], ?string $nonce = null, ?string $state = null): ?AuthResult
    {
        if (function_exists('nativephp_call')) {
            $params = ['scopes' => $scopes];
            if ($nonce !== null) {
                $params['nonce'] = $nonce;
            }
            if ($state !== null) {
                $params['state'] = $state;
            }

            $result = nativephp_call('SocialAuth.AppleSignIn', json_encode($params));
            if ($result) {
                $decoded = json_decode($result, true);
                if (isset($decoded['data']) && ($decoded['data']['status'] ?? '') === 'success') {
                    return AuthResult::fromArray($decoded['data']);
                }
                if (($decoded['status'] ?? '') === 'success') {
                    return AuthResult::fromArray($decoded);
                }
            }
        }

        return null;
    }

    /**
     * Initiate native Google Sign-In.
     *
     * Returns user credentials including ID token, access token, and user profile.
     *
     * @param  string|null  $nonce  Optional nonce for replay protection
     */
    public function googleSignIn(?string $nonce = null): ?AuthResult
    {
        if (function_exists('nativephp_call')) {
            $params = [];
            if ($nonce !== null) {
                $params['nonce'] = $nonce;
            }

            $result = nativephp_call('SocialAuth.GoogleSignIn', json_encode($params));
            if ($result) {
                $decoded = json_decode($result, true);
                if (isset($decoded['data']) && ($decoded['data']['status'] ?? '') === 'success') {
                    return AuthResult::fromArray($decoded['data']);
                }
                if (($decoded['status'] ?? '') === 'success') {
                    return AuthResult::fromArray($decoded);
                }
            }
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
        if (function_exists('nativephp_call')) {
            $result = nativephp_call('SocialAuth.CheckAppleCredentialState', json_encode([
                'userId' => $userId,
            ]));
            if ($result) {
                $decoded = json_decode($result, true);

                return $decoded['data']['state'] ?? 'unknown';
            }
        }

        return 'unknown';
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
        if (function_exists('nativephp_call')) {
            $result = nativephp_call('SocialAuth.SignOut', '{}');
            if ($result) {
                $decoded = json_decode($result, true);

                return ($decoded['data']['signedOut'] ?? false) === true;
            }
        }

        return false;
    }
}
