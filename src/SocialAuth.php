<?php

namespace Ikromjon\NativePHP\SocialAuth;

use Ikromjon\NativePHP\SocialAuth\Data\AuthResult;
use Ikromjon\NativePHP\SocialAuth\Exceptions\MissingProviderConfigException;
use Ikromjon\NativePHP\SocialAuth\Exceptions\UnknownProviderException;
use Ikromjon\NativePHP\SocialAuth\Exceptions\UnsupportedDriverException;
use Ikromjon\NativePHP\SocialAuth\Providers\PkceChallenge;
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
        $driver = $this->driverFor($definition);

        return match ($driver) {
            'native' => $this->nativeSignIn($provider, $definition, $options),
            'oauth' => $this->oauthSignIn($provider, $definition, $options),
            default => throw UnsupportedDriverException::for($provider, (string) $driver),
        };
    }

    /**
     * The driver to use on the platform this is running on.
     *
     * A provider can name a per-platform override — `android_driver` — for the
     * case where one platform has a vendor SDK and the other does not. Apple is
     * the reason this exists: native on iOS, browser flow on Android.
     *
     * @param  array<string, mixed>  $definition
     */
    protected function driverFor(array $definition): string
    {
        $platform = $this->platform();

        if ($platform !== null) {
            $override = $definition[$platform.'_driver'] ?? null;

            if (is_string($override) && $override !== '') {
                return $override;
            }
        }

        return $definition['driver'] ?? 'native';
    }

    /**
     * The platform the app is running on, or null off-device.
     *
     * Read from the environment rather than through `System::isIos()`, which
     * reports false on both platforms when `Device::getInfo()` returns null.
     * The native runtime exports this before Laravel boots, so it also survives
     * `config:cache`.
     */
    protected function platform(): ?string
    {
        $value = $_SERVER['NATIVEPHP_PLATFORM'] ?? env('NATIVEPHP_PLATFORM');

        return is_string($value) && $value !== '' ? strtolower($value) : null;
    }

    /**
     * Sign in through a bridge function backed by a vendor SDK.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $options
     */
    protected function nativeSignIn(string $provider, array $definition, array $options): ?AuthResult
    {
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
     * Sign in through the system browser, returning an authorization code.
     *
     * No tokens come back here, by design: redeeming the code needs a client
     * secret, and a secret shipped inside an app binary is a secret anyone can
     * extract. The code is bound to a PKCE verifier so an intercepted redirect
     * is not enough to redeem it, and both halves go to your own server to be
     * exchanged.
     *
     * On Android the browser hands control back asynchronously, so the result
     * arrives as an AuthorizationCodeReceived event rather than a return value.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $options
     */
    protected function oauthSignIn(string $provider, array $definition, array $options): ?AuthResult
    {
        $pkce = isset($options['code_verifier'])
            ? PkceChallenge::fromVerifier((string) $options['code_verifier'])
            : PkceChallenge::generate();

        $state = $options['state'] ?? bin2hex(random_bytes(16));

        $payload = $this->payload($this->call('SocialAuth.OAuthSignIn', [
            'provider' => $provider,
            'authorizeUrl' => $this->requiredValue($provider, $definition, 'authorize_url'),
            'clientId' => $this->requiredValue($provider, $definition, 'client_id'),
            'redirectUri' => $this->redirectUri($provider, $definition),
            'scopes' => $definition['scopes'] ?? [],
            'state' => $state,
            'codeChallenge' => $pkce->challenge,
            'codeChallengeMethod' => $pkce->method,
            // Echoed back in the event so the code and its verifier travel together.
            'codeVerifier' => $pkce->verifier,
            'extraParams' => $definition['extra_params'] ?? [],
        ]));

        if (($payload['status'] ?? '') !== 'success') {
            return null;
        }

        return AuthResult::fromArray($payload + [
            'provider' => $provider,
            'codeVerifier' => $pkce->verifier,
        ]);
    }

    /**
     * Where the provider sends the browser once the user approves.
     *
     * Most providers accept a custom scheme, which is what `redirect_scheme`
     * builds. Apple is the exception — it form-posts to an https endpoint — so
     * an explicit `redirect_uri` always wins.
     *
     * @param  array<string, mixed>  $definition
     */
    protected function redirectUri(string $provider, array $definition): string
    {
        $explicit = $definition['redirect_uri'] ?? null;

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $scheme = $definition['redirect_scheme'] ?? null;

        if (! is_string($scheme) || $scheme === '') {
            throw MissingProviderConfigException::for($provider, ['redirect_uri or redirect_scheme']);
        }

        return $scheme.'://callback';
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function requiredValue(string $provider, array $definition, string $key): string
    {
        $value = $definition[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw MissingProviderConfigException::for($provider, [$key]);
        }

        return $value;
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
