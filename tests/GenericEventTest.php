<?php

use Ikromjon\NativePHP\SocialAuth\Events\AppleSignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Events\GoogleSignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Events\SignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Tests\TestCase;
use Illuminate\Support\Facades\Event;

uses(TestCase::class);

/**
 * The generic event is mirrored from the provider-specific ones in PHP rather
 * than dispatched a second time by the native layer. That is what keeps 1.2.0
 * backwards compatible: the native payload is untouched, so existing handlers
 * still fire exactly once.
 */
it('mirrors a google sign-in as the generic event', function () {
    $seen = null;
    Event::listen(SignInCompleted::class, function ($event) use (&$seen) {
        $seen = $event;
    });

    GoogleSignInCompleted::dispatch(
        userId: '42',
        identityToken: 'jwt',
        email: 'a@example.com',
        displayName: 'A Person',
        givenName: 'A',
        familyName: 'Person',
        photoUrl: 'https://example.com/a.png',
        accessToken: 'at',
        authorizationCode: 'code',
    );

    expect($seen)->toBeInstanceOf(SignInCompleted::class);
    expect($seen->provider)->toBe('google');
    expect($seen->userId)->toBe('42');
    expect($seen->identityToken)->toBe('jwt');
    expect($seen->accessToken)->toBe('at');
    expect($seen->authorizationCode)->toBe('code');
    expect($seen->photoUrl)->toBe('https://example.com/a.png');
});

it('mirrors an apple sign-in as the generic event', function () {
    $seen = null;
    Event::listen(SignInCompleted::class, function ($event) use (&$seen) {
        $seen = $event;
    });

    AppleSignInCompleted::dispatch(
        userId: '001.abc',
        identityToken: 'jwt',
        authorizationCode: 'code',
        email: 'a@example.com',
        givenName: 'A',
        familyName: 'Person',
        displayName: 'A Person',
        state: 'st',
        realUserStatus: 'likelyReal',
    );

    expect($seen->provider)->toBe('apple');
    expect($seen->userId)->toBe('001.abc');
    expect($seen->state)->toBe('st');
    expect($seen->realUserStatus)->toBe('likelyReal');
});

it('mirrors exactly once per sign-in', function () {
    $count = 0;
    Event::listen(SignInCompleted::class, function () use (&$count) {
        $count++;
    });

    GoogleSignInCompleted::dispatch(userId: '42');

    expect($count)->toBe(1);
});

it('can be switched off', function () {
    config()->set('social-auth.dispatch_generic_event', false);

    $fired = false;
    Event::listen(SignInCompleted::class, function () use (&$fired) {
        $fired = true;
    });

    GoogleSignInCompleted::dispatch(userId: '42');

    expect($fired)->toBeFalse();
});

/** Existing handlers must keep working untouched. */
it('leaves the provider-specific events firing', function () {
    $fired = false;
    Event::listen(GoogleSignInCompleted::class, function () use (&$fired) {
        $fired = true;
    });

    GoogleSignInCompleted::dispatch(userId: '42');

    expect($fired)->toBeTrue();
});

/**
 * NativePHP dispatches native events as `new $event(...$payload)`, so a payload
 * key the constructor does not declare is a fatal error the Android bridge
 * swallows. The generic event must therefore accept every field the
 * provider-specific events carry.
 */
it('declares every field the provider events can carry', function () {
    $generic = collect((new ReflectionClass(SignInCompleted::class))->getConstructor()->getParameters())
        ->map(fn ($p) => $p->getName());

    foreach ([AppleSignInCompleted::class, GoogleSignInCompleted::class] as $event) {
        $params = collect((new ReflectionClass($event))->getConstructor()->getParameters())
            ->map(fn ($p) => $p->getName());

        expect($generic->all())->toContain(...$params->all());
    }
});
