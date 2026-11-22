<?php

use Ikromjon\NativePHP\SocialAuth\Events\GoogleSignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Events\SignInFailed;
use Ikromjon\NativePHP\SocialAuth\Facades\SocialAuth;
use Ikromjon\NativePHP\SocialAuth\Tests\Fixtures\LoginScreen;
use Ikromjon\NativePHP\SocialAuth\Tests\NativeAppTestCase;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Testing\Native;

uses(NativeAppTestCase::class);

/**
 * What resources/boost/guidelines/core.blade.php teaches an assistant to
 * write, run against NativePHP 4: the Login screen, its test, and scripting
 * a bridge answer with Native::fakeBridge().
 */
beforeEach(function () {
    if (! class_exists(NativeComponent::class)) {
        $this->markTestSkipped('SuperNative screens need nativephp/mobile 4.');
    }
});

test('the guidelines test for the Login screen passes as written', function () {
    Native::test(LoginScreen::class)
        ->call('signInWithGoogle')
        ->assertNativeCalled('SocialAuth.GoogleSignIn')
        ->emitNative(SignInFailed::class, ['provider' => 'google', 'error' => 'No network', 'errorCode' => 'FAILED'])
        ->assertSet('error', 'No network');
});

test('a handler that declares only some fields still gets them from a full payload', function () {
    // Everything iOS sends; the Login screen asks for three of these fields.
    $screen = Native::test(LoginScreen::class)->emitNative(GoogleSignInCompleted::class, [
        'userId' => '1234567890',
        'identityToken' => 'header.payload.signature',
        'email' => 'user@example.com',
        'displayName' => 'Jane Doe',
        'givenName' => 'Jane',
        'familyName' => 'Doe',
        'photoUrl' => 'https://example.com/photo.jpg',
        'accessToken' => 'access-token',
        'authorizationCode' => 'server-auth-code',
    ]);

    expect($screen->get('received'))->toBe([
        'userId' => '1234567890',
        'identityToken' => 'header.payload.signature',
        'email' => 'user@example.com',
    ]);
});

test('a canceled sign-in is not reported as an error', function () {
    Native::test(LoginScreen::class)
        ->emitNative(SignInFailed::class, ['provider' => 'google', 'error' => 'The user canceled', 'errorCode' => 'CANCELED'])
        ->assertSet('error', null);
});

test('a test can script what the device answers', function () {
    Native::fakeBridge()
        ->respondTo('SocialAuth.CheckAppleCredentialState', ['state' => 'revoked'])
        ->respondTo('SocialAuth.SignOut', ['signedOut' => true]);

    expect(SocialAuth::checkAppleCredentialState('001234.abcdef.1234'))->toBe('revoked')
        ->and(SocialAuth::signOut())->toBeTrue();
});
