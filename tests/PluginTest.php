<?php

use Ikromjon\NativePHP\SocialAuth\Data\AuthResult;
use Ikromjon\NativePHP\SocialAuth\Events\AppleSignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Events\GoogleSignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Events\SignInFailed;
use Ikromjon\NativePHP\SocialAuth\Facades\SocialAuth as SocialAuthFacade;
use Ikromjon\NativePHP\SocialAuth\SocialAuth;
use Ikromjon\NativePHP\SocialAuth\SocialAuthServiceProvider;

// Manifest validation

test('nativephp.json is valid JSON', function () {
    $path = dirname(__DIR__).'/nativephp.json';
    expect(file_exists($path))->toBeTrue();

    $json = json_decode(file_get_contents($path), true);
    expect(json_last_error())->toBe(JSON_ERROR_NONE);
    expect($json)->toHaveKey('namespace');
    expect($json)->toHaveKey('bridge_functions');
});

test('nativephp.json has required bridge functions', function () {
    $json = json_decode(file_get_contents(dirname(__DIR__).'/nativephp.json'), true);
    $functions = collect($json['bridge_functions'])->pluck('name')->toArray();

    expect($functions)->toContain('SocialAuth.AppleSignIn');
    expect($functions)->toContain('SocialAuth.GoogleSignIn');
    expect($functions)->toContain('SocialAuth.CheckAppleCredentialState');
    expect($functions)->toContain('SocialAuth.SignOut');
});

test('each bridge function has ios and android mappings', function () {
    $json = json_decode(file_get_contents(dirname(__DIR__).'/nativephp.json'), true);

    foreach ($json['bridge_functions'] as $fn) {
        expect($fn)->toHaveKey('ios');
        expect($fn)->toHaveKey('android');
        expect($fn)->toHaveKey('description');
    }
});

test('ios info plist configures google client ids', function () {
    $json = json_decode(file_get_contents(dirname(__DIR__).'/nativephp.json'), true);

    expect($json['ios']['info_plist']['GIDClientID'])->toBe('${GOOGLE_IOS_CLIENT_ID}');
    expect($json['ios']['info_plist']['GIDServerClientID'])->toBe('${GOOGLE_SERVER_CLIENT_ID}');
});

// GIDSignIn checks CFBundleURLTypes for the *reversed* client ID and raises
// "Your app is missing support for the following URL schemes" otherwise.

test('the google url scheme is registered by a hook, not by a url_schemes key', function () {
    $json = json_decode(file_get_contents(dirname(__DIR__).'/nativephp.json'), true);

    // `url_schemes` is not a key NativePHP Mobile reads — not in 3.3.x and not
    // in 4.x — so declaring it silently registered nothing and Google Sign-In
    // crashed the app on iOS. A post_compile hook writes the entry instead.
    expect($json['ios'])->not->toHaveKey('url_schemes');
    expect($json['hooks']['post_compile'] ?? null)->toBe('social-auth:register-url-scheme');

    // Optional on purpose: the hook derives it from GOOGLE_IOS_CLIENT_ID, so
    // marking it required would fail the build of every existing app that
    // upgrades without adding a new .env line.
    expect($json['secrets'])->toHaveKey('GOOGLE_IOS_REVERSED_CLIENT_ID');
    expect($json['secrets']['GOOGLE_IOS_REVERSED_CLIENT_ID']['required'])->toBeFalse();
});

test('the ios bridge refuses to sign in when the url scheme is unregistered', function () {
    $swift = file_get_contents(dirname(__DIR__).'/resources/ios/Sources/SocialAuthFunctions.swift');

    // GIDSignIn reports this by raising an NSException, which Swift cannot
    // catch, so the check has to happen before the call rather than around it.
    expect($swift)->toContain('static func configurationError()');
    expect($swift)->toContain('CFBundleURLTypes');
    expect($swift)->toContain('MISSING_CONFIG');

    $guardPos = strpos($swift, 'if let message = Self.configurationError()');
    $signInPos = strpos($swift, 'GIDSignIn.sharedInstance.signIn(withPresenting:');

    expect($guardPos)->not->toBeFalse();
    expect($guardPos)->toBeLessThan($signInPos);
});

test('composer allows nativephp mobile v3 and v4', function () {
    $json = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true);

    expect($json['require']['nativephp/mobile'])->toBe('^3.0|^4.0');
});

// The Apple presentation context provider is held weakly by ASAuthorizationController.

test('swift keeps a strong reference to the apple presentation context provider', function () {
    $swift = file_get_contents(dirname(__DIR__).'/resources/ios/Sources/SocialAuthFunctions.swift');

    expect($swift)->toContain('var contextProvider: AppleSignInPresentationContext?');
    expect($swift)->toContain('delegate.contextProvider = contextProvider');
});

test('kotlin sign-out treats a latch timeout as failure', function () {
    $kotlin = file_get_contents(dirname(__DIR__).'/resources/android/src/SocialAuthFunctions.kt');

    expect($kotlin)->toMatch('/val\s+\w+\s*=\s*latch\.await\(/');
    expect($kotlin)->toContain('SIGN_OUT_TIMEOUT');
});

// Events are the documented single handling path, so they must not lose fields
// that the synchronous AuthResult return carries.

test('event constructors expose every auth result field except provider and nonce', function (string $event, array $platformOmits) {
    $authFields = array_map(fn ($p) => $p->getName(), (new ReflectionClass(AuthResult::class))->getConstructor()->getParameters());
    $eventFields = array_map(fn ($p) => $p->getName(), (new ReflectionClass($event))->getConstructor()->getParameters());

    // provider is implicit in the event class; nonce travels inside identityToken;
    // codeVerifier belongs only to the browser flow, which reports through
    // AuthorizationCodeReceived rather than these SDK-backed events.
    $expected = array_values(array_diff($authFields, ['provider', 'nonce', 'codeVerifier'], $platformOmits));

    expect(array_values(array_diff($expected, $eventFields)))->toBe([]);
})->with([
    [AppleSignInCompleted::class, ['accessToken', 'photoUrl']],
    [GoogleSignInCompleted::class, ['state', 'realUserStatus']],
]);

test('events are registered in manifest', function () {
    $json = json_decode(file_get_contents(dirname(__DIR__).'/nativephp.json'), true);

    expect($json['events'])->toContain('Ikromjon\\NativePHP\\SocialAuth\\Events\\AppleSignInCompleted');
    expect($json['events'])->toContain('Ikromjon\\NativePHP\\SocialAuth\\Events\\GoogleSignInCompleted');
    expect($json['events'])->toContain('Ikromjon\\NativePHP\\SocialAuth\\Events\\SignInFailed');
});

// Composer validation

test('composer.json has nativephp-plugin type', function () {
    $json = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true);

    expect($json['type'])->toBe('nativephp-plugin');
});

test('composer.json has nativephp manifest reference', function () {
    $json = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true);

    expect($json['extra']['nativephp']['manifest'])->toBe('nativephp.json');
});

// Config file

test('config file exists and defines google server client id', function () {
    $path = dirname(__DIR__).'/config/social-auth.php';
    expect(file_exists($path))->toBeTrue();

    $config = require $path;
    expect($config)->toBeArray()->toHaveKey('google_server_client_id');
});

// Native code existence

test('swift bridge functions file exists', function () {
    expect(file_exists(dirname(__DIR__).'/resources/ios/Sources/SocialAuthFunctions.swift'))->toBeTrue();
});

test('kotlin bridge functions file exists', function () {
    expect(file_exists(dirname(__DIR__).'/resources/android/src/SocialAuthFunctions.kt'))->toBeTrue();
});

test('javascript bridge file exists', function () {
    expect(file_exists(dirname(__DIR__).'/resources/js/social-auth.js'))->toBeTrue();
});

// Swift bridge function classes exist

test('swift file contains all bridge function classes', function () {
    $swift = file_get_contents(dirname(__DIR__).'/resources/ios/Sources/SocialAuthFunctions.swift');

    expect($swift)->toContain('class AppleSignIn: BridgeFunction');
    expect($swift)->toContain('class GoogleSignIn: BridgeFunction');
    expect($swift)->toContain('class CheckAppleCredentialState: BridgeFunction');
    expect($swift)->toContain('class SignOut: BridgeFunction');
});

// The signIn(withPresenting:hint:additionalScopes:nonce:completion:) overload only
// exists in GoogleSignIn-iOS >= 9.0 — no stable 8.x release has a nonce API (issue #1)

test('google sign-in pod version supports the nonce overload used in swift', function () {
    $swift = file_get_contents(dirname(__DIR__).'/resources/ios/Sources/SocialAuthFunctions.swift');
    $manifest = json_decode(file_get_contents(dirname(__DIR__).'/nativephp.json'), true);

    $pod = collect($manifest['ios']['dependencies']['pods'])->firstWhere('name', 'GoogleSignIn');
    expect($pod)->not->toBeNull();

    // Deliberately loose pattern: over-matching can only make this test fail when it
    // needn't (safe); a stricter pattern could silently stop matching after a refactor
    // and let a pod downgrade slip through unnoticed
    if (preg_match('/\.signIn\s*\(.*?nonce\s*:/s', $swift)) {
        preg_match('/(\d+)/', $pod['version'], $matches);
        expect((int) ($matches[1] ?? 0))->toBeGreaterThanOrEqual(9);
    }
});

// Kotlin bridge function classes exist

test('kotlin file contains all bridge function classes', function () {
    $kotlin = file_get_contents(dirname(__DIR__).'/resources/android/src/SocialAuthFunctions.kt');

    expect($kotlin)->toContain('class AppleSignIn');
    expect($kotlin)->toContain('class GoogleSignIn');
    expect($kotlin)->toContain('class CheckAppleCredentialState');
    expect($kotlin)->toContain('class SignOut');
    expect($kotlin)->toContain(': BridgeFunction');
});

// PHP classes exist and are correct

test('service provider class exists', function () {
    expect(class_exists(SocialAuthServiceProvider::class))->toBeTrue();
});

test('social auth class exists with all methods', function () {
    expect(class_exists(SocialAuth::class))->toBeTrue();

    $methods = get_class_methods(SocialAuth::class);
    expect($methods)->toContain('signIn');
    expect($methods)->toContain('appleSignIn');
    expect($methods)->toContain('googleSignIn');
    expect($methods)->toContain('checkAppleCredentialState');
    expect($methods)->toContain('signOut');
});

test('facade class exists', function () {
    expect(class_exists(SocialAuthFacade::class))->toBeTrue();
});

test('event classes exist', function () {
    expect(class_exists(AppleSignInCompleted::class))->toBeTrue();
    expect(class_exists(GoogleSignInCompleted::class))->toBeTrue();
    expect(class_exists(SignInFailed::class))->toBeTrue();
});

// Native event payloads are spread as named arguments into the event constructors
// (NativePHP does `new $event(...$payload)`), so any key a platform sends that the
// constructor does not declare makes the event fail to dispatch -- silently.

test('ios event payload keys match the event constructor', function (string $event, string $short) {
    $source = file_get_contents(dirname(__DIR__).'/resources/ios/Sources/SocialAuthFunctions.swift');

    expect(preg_match('/'.$short.'",\s*\[(.*?)\]\s*\)/s', $source, $payload))->toBe(1);

    preg_match_all('/"([a-zA-Z]+)":/', $payload[1], $keys);
    $parameters = array_map(fn ($p) => $p->getName(), (new ReflectionClass($event))->getConstructor()->getParameters());

    expect($keys[1])->not->toBeEmpty();
    expect(array_diff($keys[1], $parameters))->toBe([]);
})->with([
    [GoogleSignInCompleted::class, 'GoogleSignInCompleted'],
    [AppleSignInCompleted::class, 'AppleSignInCompleted'],
]);

test('android google event payload keys match the event constructor', function () {
    $source = file_get_contents(dirname(__DIR__).'/resources/android/src/SocialAuthFunctions.kt');

    $marker = 'val eventPayload = JSONObject().apply {';
    expect($source)->toContain($marker);

    $body = substr($source, strpos($source, $marker) + strlen($marker));
    $body = substr($body, 0, strpos($body, '}'));

    preg_match_all('/put\("([a-zA-Z]+)"/', $body, $keys);
    $parameters = array_map(fn ($p) => $p->getName(), (new ReflectionClass(GoogleSignInCompleted::class))->getConstructor()->getParameters());

    expect($keys[1])->not->toBeEmpty();
    expect(array_diff($keys[1], $parameters))->toBe([]);
});

// Data classes

test('auth result serializes and deserializes correctly', function () {
    $result = new AuthResult(
        provider: 'apple',
        userId: 'apple-user-123',
        identityToken: 'eyJhbGciOiJSUzI1NiJ9...',
        authorizationCode: 'auth-code-456',
        email: 'user@example.com',
        givenName: 'Jane',
        familyName: 'Doe',
        displayName: 'Jane Doe',
        realUserStatus: 'likelyReal',
    );

    $array = $result->toArray();
    expect($array['provider'])->toBe('apple');
    expect($array['userId'])->toBe('apple-user-123');
    expect($array['identityToken'])->toBe('eyJhbGciOiJSUzI1NiJ9...');
    expect($array['authorizationCode'])->toBe('auth-code-456');
    expect($array['email'])->toBe('user@example.com');
    expect($array['givenName'])->toBe('Jane');
    expect($array['familyName'])->toBe('Doe');
    expect($array['realUserStatus'])->toBe('likelyReal');

    // Roundtrip
    $restored = AuthResult::fromArray($array);
    expect($restored->provider)->toBe('apple');
    expect($restored->userId)->toBe('apple-user-123');
    expect($restored->email)->toBe('user@example.com');
    expect($restored->givenName)->toBe('Jane');
});

test('auth result omits null fields from array', function () {
    $result = new AuthResult(provider: 'google', userId: 'google-123');
    $array = $result->toArray();

    expect($array)->toHaveKey('provider');
    expect($array)->toHaveKey('userId');
    expect($array)->not->toHaveKey('identityToken');
    expect($array)->not->toHaveKey('email');
    expect($array)->not->toHaveKey('photoUrl');
    expect($array)->not->toHaveKey('realUserStatus');
});

test('auth result from array handles missing fields', function () {
    $result = AuthResult::fromArray(['provider' => 'apple']);

    expect($result->provider)->toBe('apple');
    expect($result->userId)->toBeNull();
    expect($result->identityToken)->toBeNull();
    expect($result->email)->toBeNull();
});

// Graceful degradation (no nativephp_call available)

test('social auth methods return defaults when not on device', function () {
    $auth = new SocialAuth;

    expect($auth->appleSignIn())->toBeNull();
    expect($auth->googleSignIn())->toBeNull();
    expect($auth->checkAppleCredentialState('user-123'))->toBe('unknown');
    expect($auth->signOut())->toBeFalse();
});

// Event classes have correct properties

test('apple sign-in completed event has correct properties', function () {
    $event = new AppleSignInCompleted(
        userId: 'apple-123',
        identityToken: 'token',
        authorizationCode: 'code',
        email: 'user@example.com',
        givenName: 'Jane',
        familyName: 'Doe',
    );

    expect($event->userId)->toBe('apple-123');
    expect($event->identityToken)->toBe('token');
    expect($event->email)->toBe('user@example.com');
});

test('google sign-in completed event has correct properties', function () {
    $event = new GoogleSignInCompleted(
        userId: 'google-123',
        identityToken: 'token',
        email: 'user@gmail.com',
        displayName: 'Jane Doe',
        photoUrl: 'https://photo.url/pic.jpg',
    );

    expect($event->userId)->toBe('google-123');
    expect($event->email)->toBe('user@gmail.com');
    expect($event->displayName)->toBe('Jane Doe');
});

test('sign-in failed event has correct properties', function () {
    $event = new SignInFailed(
        provider: 'apple',
        error: 'User canceled',
        errorCode: 'CANCELED',
    );

    expect($event->provider)->toBe('apple');
    expect($event->error)->toBe('User canceled');
    expect($event->errorCode)->toBe('CANCELED');
});

// Browser-based providers: the manifest has to carry the bridge function, the
// Custom Tabs dependency, and the activity the redirect lands on. Any one of
// them missing breaks the flow only at runtime, on device.

test('nativephp.json declares the oauth bridge function', function () {
    $json = json_decode(file_get_contents(dirname(__DIR__).'/nativephp.json'), true);

    $fn = collect($json['bridge_functions'])->firstWhere('name', 'SocialAuth.OAuthSignIn');

    expect($fn)->not->toBeNull();
    expect($fn['ios'])->toBe('SocialAuthFunctions.OAuthSignIn');
    expect($fn['android'])->toBe('com.ikromjon.plugins.socialauth.SocialAuthFunctions.OAuthSignIn');
});

test('nativephp.json registers the authorization code event', function () {
    $json = json_decode(file_get_contents(dirname(__DIR__).'/nativephp.json'), true);

    expect($json['events'])->toContain('Ikromjon\\NativePHP\\SocialAuth\\Events\\AuthorizationCodeReceived');
});

test('nativephp.json depends on androidx browser for custom tabs', function () {
    $json = json_decode(file_get_contents(dirname(__DIR__).'/nativephp.json'), true);

    expect(collect($json['android']['dependencies']['implementation'])
        ->contains(fn (string $d) => str_starts_with($d, 'androidx.browser:browser')))->toBeTrue();
});

/**
 * NativePHP substitutes ${ENV} placeholders into Info.plist but not into
 * AndroidManifest.xml, so a per-app scheme cannot be declared there. The Android
 * Gradle plugin does substitute ${applicationId}, which is why the redirect
 * scheme is the application ID.
 */
test('the redirect activity listens on the application id scheme', function () {
    $json = json_decode(file_get_contents(dirname(__DIR__).'/nativephp.json'), true);

    $activity = collect($json['android']['activities'])
        ->firstWhere('name', 'com.ikromjon.plugins.socialauth.SocialAuthRedirectActivity');

    expect($activity)->not->toBeNull();
    expect($activity['exported'])->toBeTrue();
    expect($activity['launchMode'])->toBe('singleTask');

    $filter = $activity['intent_filters'][0];

    expect($filter['action'])->toBe('android.intent.action.VIEW');
    expect($filter['category'])->toContain('android.intent.category.BROWSABLE');
    expect($filter['data']['scheme'])->toBe('${applicationId}');
});

test('the redirect activity source exists and is in the plugin package', function () {
    $kotlin = file_get_contents(dirname(__DIR__).'/resources/android/src/SocialAuthRedirectActivity.kt');

    expect($kotlin)->toContain('package com.ikromjon.plugins.socialauth');
    expect($kotlin)->toContain('class SocialAuthRedirectActivity');
});
