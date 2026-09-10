<?php

namespace Ikromjon\NativePHP\SocialAuth\Providers;

use Ikromjon\NativePHP\SocialAuth\Exceptions\UnknownProviderException;
use Illuminate\Container\Container;

/**
 * Resolves a provider name to the definition the rest of the plugin works from.
 *
 * Built-in providers are defined here rather than in the published config
 * because their structural fields — which bridge function to call, whether the
 * platform offers a sign-out API — are not something an integrator tunes. What
 * *is* tunable (client IDs, scopes, custom providers) comes from
 * `config('social-auth.providers')` and is merged over the built-ins per key.
 *
 * Merging here rather than relying on `mergeConfigFrom()` is deliberate:
 * Laravel merges package config only at the top level, so an integrator who
 * publishes `config/social-auth.php` today would silently lose any provider
 * added in a later release.
 */
class ProviderRegistry
{
    /**
     * Providers the plugin ships native support for.
     *
     * `params` maps a bridge parameter name to the config key it is read from,
     * so provider-specific wiring stays declarative and survives config:cache.
     *
     * @var array<string, array<string, mixed>>
     */
    private const BUILT_IN = [
        'apple' => [
            'driver' => 'native',
            'bridge' => 'SocialAuth.AppleSignIn',
            'defaults' => ['scopes' => ['email', 'fullName']],
            // Apple has no sign-out API; sessions are managed in system settings.
            'supports_sign_out' => false,
            'platforms' => ['ios'],
        ],
        'google' => [
            'driver' => 'native',
            'bridge' => 'SocialAuth.GoogleSignIn',
            'params' => ['serverClientId' => 'server_client_id'],
            'supports_sign_out' => true,
            'platforms' => ['ios', 'android'],
            'url_scheme' => 'google_reversed',
        ],
    ];

    /**
     * Every known provider, built-ins merged with configured ones.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $configured = $this->configuredProviders();

        $names = array_unique(array_merge(
            array_keys(self::BUILT_IN),
            array_keys($configured),
        ));

        $providers = [];

        foreach ($names as $name) {
            $providers[$name] = array_merge(
                self::BUILT_IN[$name] ?? [],
                $configured[$name] ?? [],
            );
        }

        return $providers;
    }

    public function has(string $provider): bool
    {
        return array_key_exists($provider, $this->all());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws UnknownProviderException
     */
    public function get(string $provider): array
    {
        $providers = $this->all();

        if (! array_key_exists($provider, $providers)) {
            throw UnknownProviderException::for($provider, array_keys($providers));
        }

        return $providers[$provider];
    }

    /**
     * A single config value for a provider.
     *
     * Google's server client ID keeps its historical fallback chain so setups
     * written against 1.0.x and 1.1.x keep resolving without edits.
     */
    public function value(string $provider, string $key): mixed
    {
        $definition = $this->get($provider);

        $value = $definition[$key] ?? null;

        if ($value !== null && $value !== '') {
            return $value;
        }

        if ($provider === 'google' && $key === 'server_client_id') {
            return $this->config('social-auth.google_server_client_id')
                ?: $this->config('services.google.client_id');
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function configuredProviders(): array
    {
        $configured = $this->config('social-auth.providers');

        if (! is_array($configured)) {
            return [];
        }

        return array_filter($configured, 'is_array');
    }

    /**
     * Read config, tolerating a container that has not booted one.
     *
     * The registry is used by unit tests and by the build-time hook, neither of
     * which is guaranteed a full application.
     */
    private function config(string $key): mixed
    {
        if (! Container::getInstance()->bound('config')) {
            return null;
        }

        return config($key);
    }
}
