<?php

use Ikromjon\NativePHP\SocialAuth\Events\GoogleSignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Tests\Fixtures\SignInScreen;
use Ikromjon\NativePHP\SocialAuth\Tests\NativeAppTestCase;
use Ikromjon\NativePHP\SocialAuth\Tests\Support\NativeDispatchSites;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Testing\Native;

uses(NativeAppTestCase::class);

/**
 * NativePHP 4 builds screens as NativeComponents, the primary place it
 * expects plugins to work. A screen hears device events through #[On],
 * fed by the native event queue rather than /_native/api/events, and the
 * listener's parameters are filled from the payload by name. NativePHP 3
 * has no such screens.
 */
beforeEach(function () {
    if (! class_exists(NativeComponent::class)) {
        $this->markTestSkipped('SuperNative screens need nativephp/mobile 4.');
    }
});

test('a screen starts sign-in through the facade', function () {
    Native::test(SignInScreen::class)
        ->call('signInWithApple')
        ->call('signInWithGoogle')
        ->assertNativeCalled('SocialAuth.AppleSignIn', fn (array $params) => $params['scopes'] === ['email', 'fullName'])
        ->assertNativeCalled('SocialAuth.GoogleSignIn');
});

test('every native dispatch site reaches #[On] on a screen', function (array $site) {
    $screen = Native::test(SignInScreen::class)->emitNative($site['event'], $site['payload']);

    expect($screen->get('received')[$site['event']] ?? null)->toMatchArray($site['payload']);
})->with(fn () => NativeDispatchSites::dataset());

test('fields a platform does not send fall back to the handler defaults', function () {
    // Android's Credential Manager issues neither an access token nor an authorization code.
    $screen = Native::test(SignInScreen::class)->emitNative(GoogleSignInCompleted::class, [
        'userId' => '1234567890',
        'identityToken' => 'header.payload.signature',
        'email' => 'user@example.com',
    ]);

    expect($screen->get('received')[GoogleSignInCompleted::class])
        ->toMatchArray(['userId' => '1234567890', 'accessToken' => null, 'authorizationCode' => null]);
});
