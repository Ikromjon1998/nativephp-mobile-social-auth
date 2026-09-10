<?php

use Ikromjon\NativePHP\SocialAuth\Events\AuthorizationCodeReceived;
use Ikromjon\NativePHP\SocialAuth\Exceptions\MissingProviderConfigException;
use Ikromjon\NativePHP\SocialAuth\Providers\PkceChallenge;
use Ikromjon\NativePHP\SocialAuth\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('social-auth.providers.github', [
        'driver' => 'oauth',
        'authorize_url' => 'https://github.com/login/oauth/authorize',
        'client_id' => 'gh-client',
        'redirect_scheme' => 'com.example.app',
        'scopes' => ['read:user', 'user:email'],
    ]);
});

it('calls the shared oauth bridge function', function () {
    $bridge = recordingBridge();
    $bridge->signIn('github');

    expect($bridge->method)->toBe('SocialAuth.OAuthSignIn');
});

it('sends the provider configuration the browser flow needs', function () {
    $bridge = recordingBridge();
    $bridge->signIn('github');

    expect($bridge->params['provider'])->toBe('github');
    expect($bridge->params['authorizeUrl'])->toBe('https://github.com/login/oauth/authorize');
    expect($bridge->params['clientId'])->toBe('gh-client');
    expect($bridge->params['scopes'])->toBe(['read:user', 'user:email']);
});

it('builds a callback redirect uri from the scheme', function () {
    $bridge = recordingBridge();
    $bridge->signIn('github');

    expect($bridge->params['redirectUri'])->toBe('com.example.app://callback');
});

/** Apple form-posts to an https endpoint, so an explicit URI has to win. */
it('prefers an explicit redirect uri over the scheme', function () {
    config()->set('social-auth.providers.github.redirect_uri', 'https://example.com/callback');

    $bridge = recordingBridge();
    $bridge->signIn('github');

    expect($bridge->params['redirectUri'])->toBe('https://example.com/callback');
});

it('sends a fresh S256 challenge on every call', function () {
    $first = recordingBridge();
    $first->signIn('github');

    $second = recordingBridge();
    $second->signIn('github');

    expect($first->params['codeChallengeMethod'])->toBe('S256');
    expect($first->params['codeChallenge'])->not->toBe($second->params['codeChallenge']);
    expect($first->params['state'])->not->toBe($second->params['state']);
});

it('derives the challenge from the verifier it sends', function () {
    $bridge = recordingBridge();
    $bridge->signIn('github');

    $expected = rtrim(strtr(base64_encode(hash('sha256', $bridge->params['codeVerifier'], true)), '+/', '-_'), '=');

    expect($bridge->params['codeChallenge'])->toBe($expected);
});

it('accepts a caller-supplied verifier', function () {
    $verifier = str_repeat('a', 64);

    $bridge = recordingBridge();
    $bridge->signIn('github', ['code_verifier' => $verifier]);

    expect($bridge->params['codeVerifier'])->toBe($verifier);
    expect($bridge->params['codeChallenge'])->toBe(PkceChallenge::fromVerifier($verifier)->challenge);
});

it('returns the verifier alongside the code so the pair stays together', function () {
    $bridge = recordingBridge('{"status":"success","authorizationCode":"the-code"}');
    $result = $bridge->signIn('github');

    expect($result?->authorizationCode)->toBe('the-code');
    expect($result?->codeVerifier)->toBe($bridge->params['codeVerifier']);
    expect($result?->provider)->toBe('github');
});

it('returns null when the browser flow did not succeed', function () {
    expect(recordingBridge('{"status":"pending","provider":"github"}')->signIn('github'))->toBeNull();
    expect(recordingBridge(null)->signIn('github'))->toBeNull();
});

it('names the missing key when configuration is incomplete', function () {
    config()->set('social-auth.providers.github.client_id', null);

    expect(fn () => recordingBridge()->signIn('github'))
        ->toThrow(MissingProviderConfigException::class, 'missing required configuration: client_id');
});

it('requires somewhere to redirect back to', function () {
    config()->set('social-auth.providers.github.redirect_scheme', null);

    expect(fn () => recordingBridge()->signIn('github'))
        ->toThrow(MissingProviderConfigException::class, 'redirect_uri or redirect_scheme');
});

/** A secret in the app binary is a secret anyone can extract. */
it('never forwards a client secret to the native layer', function () {
    config()->set('social-auth.providers.github.client_secret', 'should-not-travel');

    $bridge = recordingBridge();
    $bridge->signIn('github');

    expect(json_encode($bridge->params))->not->toContain('should-not-travel');
});

it('passes provider-specific extra parameters through', function () {
    config()->set('social-auth.providers.github.extra_params', ['prompt' => 'consent']);

    $bridge = recordingBridge();
    $bridge->signIn('github');

    expect($bridge->params['extraParams'])->toBe(['prompt' => 'consent']);
});

/**
 * NativePHP dispatches native events as `new $event(...$payload)`, so every key
 * the Kotlin and Swift payloads carry must be a declared constructor parameter.
 * This is the check that would have caught the Android `provider` regression.
 */
it('declares every key the native layers put in the event payload', function () {
    $params = array_map(
        fn ($p) => $p->getName(),
        (new ReflectionClass(AuthorizationCodeReceived::class))->getConstructor()->getParameters(),
    );

    $kotlin = file_get_contents(dirname(__DIR__).'/resources/android/src/SocialAuthRedirectActivity.kt');
    preg_match('/AuthorizationCodeReceived.*?\}/s', $kotlin, $block);
    preg_match_all('/put\("(\w+)"/', $kotlin, $keys);

    expect(array_diff(array_unique($keys[1]), [...$params, 'error', 'errorCode']))->toBe([]);

    $swift = file_get_contents(dirname(__DIR__).'/resources/ios/Sources/SocialAuthFunctions.swift');
    preg_match('/AuthorizationCodeReceived",\s*\[(.*?)\]/s', $swift, $block);

    preg_match_all('/"(\w+)":/', $block[1] ?? '', $keys);

    expect($keys[1])->not->toBeEmpty();
    expect(array_diff($keys[1], $params))->toBe([]);
});

/**
 * Apple has no native SDK on Android, so signIn('apple') has to reach the
 * browser flow there — otherwise an Android build cannot offer Apple Sign-In,
 * which App Store Guideline 4.8 requires alongside any other provider.
 */
describe('apple on android', function () {
    afterEach(function () {
        unset($_SERVER['NATIVEPHP_PLATFORM']);
    });

    it('uses the native bridge on ios', function () {
        $_SERVER['NATIVEPHP_PLATFORM'] = 'ios';

        $bridge = recordingBridge();
        $bridge->signIn('apple');

        expect($bridge->method)->toBe('SocialAuth.AppleSignIn');
    });

    it('falls through to the browser flow on android', function () {
        $_SERVER['NATIVEPHP_PLATFORM'] = 'android';
        config()->set('social-auth.providers.apple', [
            'client_id' => 'com.example.service',
            'redirect_uri' => 'https://example.com/apple/callback',
        ]);

        $bridge = recordingBridge();
        $bridge->signIn('apple');

        expect($bridge->method)->toBe('SocialAuth.OAuthSignIn');
        expect($bridge->params['authorizeUrl'])->toBe('https://appleid.apple.com/auth/authorize');
        expect($bridge->params['clientId'])->toBe('com.example.service');
    });

    /** Apple form-posts the result when name or email scopes are requested. */
    it('requests apple web scopes and form_post on android', function () {
        $_SERVER['NATIVEPHP_PLATFORM'] = 'android';
        config()->set('social-auth.providers.apple', [
            'client_id' => 'com.example.service',
            'redirect_uri' => 'https://example.com/apple/callback',
        ]);

        $bridge = recordingBridge();
        $bridge->signIn('apple');

        expect($bridge->params['scopes'])->toBe(['name', 'email']);
        expect($bridge->params['extraParams'])->toBe(['response_mode' => 'form_post']);
    });

    it('says what is missing when the service id is not configured', function () {
        $_SERVER['NATIVEPHP_PLATFORM'] = 'android';

        expect(fn () => recordingBridge()->signIn('apple'))
            ->toThrow(MissingProviderConfigException::class, 'missing required configuration: client_id');
    });

    /** Off device there is no platform, so the declared driver stands. */
    it('uses the declared driver when no platform is set', function () {
        $bridge = recordingBridge();
        $bridge->signIn('apple');

        expect($bridge->method)->toBe('SocialAuth.AppleSignIn');
    });
});
