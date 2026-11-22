<?php

use Ikromjon\NativePHP\SocialAuth\Events\SignInFailed;
use Ikromjon\NativePHP\SocialAuth\Tests\NativeAppTestCase;
use Ikromjon\NativePHP\SocialAuth\Tests\Support\DocumentedExamples;
use Illuminate\Support\Facades\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\System;
use Native\Mobile\Testing\Native;

uses(NativeAppTestCase::class);

/**
 * The README's recommended NativePHP 4 example, run as printed: its Login
 * class, its Blade view at the path the view's first line names, and its
 * Native::test() chain. A view that uses an element core does not register
 * fails here, the way it would in a buyer's app.
 */
const README_LOGIN = 'App\NativeComponents\Login';

function readmeNativeScreen(): array
{
    $blocks = DocumentedExamples::readmeBlocks('### Native screens (NativePHP 4, recommended)');

    expect(array_column($blocks, 0))->toBe(['php', 'blade', 'php']);

    return array_column($blocks, 1);
}

beforeEach(function () {
    if (! class_exists(NativeComponent::class)) {
        $this->markTestSkipped('SuperNative screens need nativephp/mobile 4.');
    }

    [$class, $view] = readmeNativeScreen();

    DocumentedExamples::declare(README_LOGIN, $class);

    expect($view)->toMatch('#^\{\{-- resources/views/(native/[\w/-]+\.blade\.php) --\}\}\n#');
    preg_match('#^\{\{-- resources/views/(native/[\w/-]+\.blade\.php) --\}\}\n#', $view, $path);

    $views = sys_get_temp_dir().'/social-auth-readme-views-'.getmypid();
    @mkdir(dirname($views.'/'.$path[1]), 0777, true);
    file_put_contents($views.'/'.$path[1], $view);
    View::addLocation($views);
});

test('the README native screen renders and its test passes as written', function () {
    [, , $test] = readmeNativeScreen();

    eval($test);
});

test('the README view starts Google sign-in and shows a failure', function () {
    Native::test(README_LOGIN)
        ->assertSee('Sign in with Google')
        ->tap('Sign in with Google')
        ->assertNativeCalled('SocialAuth.GoogleSignIn')
        ->emitNative(SignInFailed::class, ['provider' => 'google', 'error' => 'No network', 'errorCode' => 'FAILED'])
        ->assertSee('No network');
});

test('the README view offers Sign in with Apple on iOS only', function () {
    // From 4.7, Native::test()'s platform also decides @ios; before, @ios asks the device.
    if (! method_exists(System::class, 'rememberPlatform')) {
        $this->markTestSkipped('@ios follows Native::test() platforms from nativephp/mobile 4.7.');
    }

    Native::test(README_LOGIN, platform: 'ios')
        ->tap('Sign in with Apple')
        ->assertNativeCalled('SocialAuth.AppleSignIn');

    Native::test(README_LOGIN, platform: 'android')
        ->assertSee('Sign in with Google')
        ->assertDontSee('Sign in with Apple');
});
