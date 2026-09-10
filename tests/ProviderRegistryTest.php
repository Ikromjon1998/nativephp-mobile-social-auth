<?php

use Ikromjon\NativePHP\SocialAuth\Exceptions\UnknownProviderException;
use Ikromjon\NativePHP\SocialAuth\Providers\ProviderRegistry;
use Ikromjon\NativePHP\SocialAuth\Tests\TestCase;

uses(TestCase::class);

/**
 * The registry is the seam every other layer resolves a provider through, so
 * these pin the two properties the rest of the plugin leans on: built-ins are
 * always present, and configuration is merged over them rather than replacing
 * them.
 */
it('ships apple and google as built-in providers', function () {
    $registry = new ProviderRegistry;

    expect($registry->has('apple'))->toBeTrue();
    expect($registry->has('google'))->toBeTrue();
    expect($registry->get('google')['bridge'])->toBe('SocialAuth.GoogleSignIn');
    expect($registry->get('apple')['bridge'])->toBe('SocialAuth.AppleSignIn');
});

/**
 * Laravel merges published package config only at the top level, so relying on
 * mergeConfigFrom would drop built-in providers for anyone who published the
 * config before a later release added one. The registry merges per provider.
 */
it('keeps built-in wiring when config names a provider', function () {
    config()->set('social-auth.providers', [
        'google' => ['server_client_id' => 'from-config'],
    ]);

    $definition = (new ProviderRegistry)->get('google');

    expect($definition['bridge'])->toBe('SocialAuth.GoogleSignIn');
    expect($definition['server_client_id'])->toBe('from-config');
});

it('registers providers that exist only in config', function () {
    config()->set('social-auth.providers', [
        'github' => ['driver' => 'oauth', 'client_id' => 'gh-client'],
    ]);

    $registry = new ProviderRegistry;

    expect($registry->has('github'))->toBeTrue();
    expect($registry->get('github')['driver'])->toBe('oauth');
    expect($registry->has('apple'))->toBeTrue();
});

it('throws a helpful error for an unknown provider', function () {
    (new ProviderRegistry)->get('myspace');
})->throws(UnknownProviderException::class, 'Unknown social auth provider [myspace]');

it('ignores config entries that are not arrays', function () {
    config()->set('social-auth.providers', ['broken' => 'nonsense']);

    expect((new ProviderRegistry)->has('broken'))->toBeFalse();
});

/**
 * Setups written against 1.0.x and 1.1.x configured the server client ID at the
 * top level, or via Laravel's own services config. Both must keep resolving.
 */
it('falls back to the pre-1.2 google server client id keys', function () {
    config()->set('social-auth.providers.google.server_client_id', null);
    config()->set('social-auth.google_server_client_id', 'legacy-top-level');

    expect((new ProviderRegistry)->value('google', 'server_client_id'))->toBe('legacy-top-level');

    config()->set('social-auth.google_server_client_id', null);
    config()->set('services.google.client_id', 'legacy-services');

    expect((new ProviderRegistry)->value('google', 'server_client_id'))->toBe('legacy-services');
});

it('prefers the provider entry over the legacy keys', function () {
    config()->set('social-auth.providers.google.server_client_id', 'current');
    config()->set('social-auth.google_server_client_id', 'legacy-top-level');

    expect((new ProviderRegistry)->value('google', 'server_client_id'))->toBe('current');
});
