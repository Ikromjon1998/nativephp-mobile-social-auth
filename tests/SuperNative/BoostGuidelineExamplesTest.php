<?php

use Ikromjon\NativePHP\SocialAuth\Events\GoogleSignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Events\SignInFailed;
use Ikromjon\NativePHP\SocialAuth\Facades\SocialAuth;
use Ikromjon\NativePHP\SocialAuth\Tests\Fixtures\LoginScreen;
use Ikromjon\NativePHP\SocialAuth\Tests\NativeAppTestCase;
use Ikromjon\NativePHP\SocialAuth\Tests\Support\DocumentedExamples;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Testing\Native;

uses(NativeAppTestCase::class);

/**
 * What resources/boost/guidelines/core.blade.php teaches an assistant to
 * write, cut out of the shipped file and run against NativePHP 4: the Login
 * screen and the test the guidelines give for it. The screen gets a render()
 * added, because the guidelines leave the view to the app.
 */
const GUIDELINES_LOGIN = 'Ikromjon\NativePHP\SocialAuth\Tests\Guidelines\Login';

beforeEach(function () {
    if (! class_exists(NativeComponent::class)) {
        $this->markTestSkipped('SuperNative screens need nativephp/mobile 4.');
    }

    $render = <<<'PHP'

            public function render(): \Native\Mobile\Edge\Element
            {
                return \Native\Mobile\Edge\Elements\Column::make(\Native\Mobile\Edge\Elements\Text::make($this->error ?? 'Sign in'));
            }
        }
        PHP;

    DocumentedExamples::declare(
        GUIDELINES_LOGIN,
        'namespace Ikromjon\NativePHP\SocialAuth\Tests\Guidelines;'."\n"
            .preg_replace('/\}\s*$/', $render, DocumentedExamples::guidelineSnippet('Sign-in on a native screen'), 1),
    );
});

test('the guidelines test for the Login screen passes as written', function () {
    $test = DocumentedExamples::guidelineSnippet('Testing a sign-in screen');

    // The snippet tests the app's own Login screen; point it at the one above.
    $test = str_replace('use App\NativeComponents\Login;', 'use '.GUIDELINES_LOGIN.';', $test, $replaced);

    expect($replaced)->toBe(1);

    eval($test);
});

test('a canceled sign-in is not reported as an error', function () {
    Native::test(GUIDELINES_LOGIN)
        ->emitNative(SignInFailed::class, ['provider' => 'google', 'error' => 'The user canceled', 'errorCode' => 'CANCELED'])
        ->assertSet('error', null);
});

test('the recording copy of the Login screen keeps the guidelines handlers', function () {
    $handler = fn (string $class, string $method) => [
        array_map(fn (ReflectionAttribute $on) => $on->getArguments(), (new ReflectionMethod($class, $method))->getAttributes(On::class)),
        array_map(
            fn (ReflectionParameter $p) => [$p->getName(), (string) $p->getType(), $p->isDefaultValueAvailable() ? $p->getDefaultValue() : 'required'],
            (new ReflectionMethod($class, $method))->getParameters(),
        ),
    ];

    foreach (['googleSignedIn', 'signInFailed'] as $method) {
        expect($handler(LoginScreen::class, $method))->toBe($handler(GUIDELINES_LOGIN, $method));
    }
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

test('a test can script what the device answers', function () {
    Native::fakeBridge()
        ->respondTo('SocialAuth.CheckAppleCredentialState', ['state' => 'revoked'])
        ->respondTo('SocialAuth.SignOut', ['signedOut' => true]);

    expect(SocialAuth::checkAppleCredentialState('001234.abcdef.1234'))->toBe('revoked')
        ->and(SocialAuth::signOut())->toBeTrue();
});
