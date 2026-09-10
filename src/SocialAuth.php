<?php

namespace Ikromjon\NativePHP\SocialAuth;

use Ikromjon\NativePHP\SocialAuth\Data\AuthResult;
use Ikromjon\NativePHP\SocialAuth\Exceptions\UnknownProviderException;
use Ikromjon\NativePHP\SocialAuth\Exceptions\UnsupportedDriverException;
use Ikromjon\NativePHP\SocialAuth\Providers\ProviderRegistry;

class SocialAuth
{
    /**
     * Resolved lazily rather than promoted into the constructor.
     *
     * Subclasses in the wild — and in this suite — replace the constructor to
     * stub the bridge, which would leave a promoted property uninitialised.
     */
    protected ?ProviderRegistry $providerRegistry = null;

    public function __construct(?ProviderRegistry $providers = null)
    {
        $this->providerRegistry = $providers;
    }

    protected function providers(): ProviderRegistry
    {
        return $this->providerRegistry ??= new ProviderRegistry;
    }

    /**
     * Sign in with any registered provider.
     *
     * The provider-specific methods below are thin wrappers around this one and
     * remain the ergonomic path for Apple and Google. Reach for `signIn()` when
     * the provider is dynamic — a button loop over configured providers, say.
     *
     * Prefer handling the result via the SignInCompleted event; on iOS the same
     * result is also dispatched as an event, so handling both the return value
     * and the event runs your handler twice.
     *
     * @param  array<string, mixed>  $options  Provider options: 'scopes', 'nonce', 'state'
     *
     * @throws UnknownProviderException
     * @throws UnsupportedDriverException
     */
    public function signIn(string $provider, array $options = []): ?AuthResult
    {
        $definition = $this->providers()->get($provider);
        $driver = $definition['driver'] ?? 'native';

        if ($driver !== 'native') {
            throw UnsupportedDriverException::for($provider, (string) $driver);
        }

        $bridge = $definition['bridge'] ?? null;

        if (! is_string($bridge) || $bridge === '') {
            throw UnsupportedDriverException::missingBridge($provider);
        }

        $payload = $this->payload($this->call(
            $bridge,
            $this->params($provider, $definition, $options),
        ));

        if (($payload['status'] ?? '') === 'success') {
            return AuthResult::fromArray($payload);
        }

        return null;
    }

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
        return $this->signIn('apple', [
            'scopes' => $scopes,
            'nonce' => $nonce,
            'state' => $state,
        ]);
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
        return $this->signIn('google', ['nonce' => $nonce]);
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
     * Sign out.
     *
     * Passing no provider signs out of every provider that offers a sign-out
     * API, which today means Google — the behaviour this method has always had.
     *
     * Apple Sign-In has no sign-out API: users manage Apple ID sessions through
     * system settings, so `signOut('apple')` reports false without calling the
     * bridge.
     *
     * @return bool True if sign-out succeeded
     *
     * @throws UnknownProviderException
     */
    public function signOut(?string $provider = null): bool
    {
        $params = [];

        if ($provider !== null) {
            if (($this->providers()->get($provider)['supports_sign_out'] ?? false) !== true) {
                return false;
            }

            $params['provider'] = $provider;
        }

        $payload = $this->payload($this->call('SocialAuth.SignOut', $params));

        return ($payload['signedOut'] ?? false) === true;
    }

    /**
     * Build the bridge parameters for a sign-in call.
     *
     * Caller options win over the provider's defaults, and null options are
     * dropped so the native layer sees the same payload it always has.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function params(string $provider, array $definition, array $options): array
    {
        $params = array_merge(
            $definition['defaults'] ?? [],
            array_filter($options, fn ($value) => $value !== null),
        );

        foreach ($definition['params'] ?? [] as $bridgeParam => $configKey) {
            $value = $this->providers()->value($provider, $configKey);

            if ($value !== null && $value !== '') {
                $params[$bridgeParam] = $value;
            }
        }

        return $params;
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
