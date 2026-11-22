## NativePHP Mobile Social Auth

- `ikromjon/nativephp-mobile-social-auth` adds native **Sign in with Apple** (iOS, `ASAuthorizationController`) and
  **Google Sign-In** (iOS: GoogleSignIn SDK, Android: Credential Manager) to NativePHP Mobile apps, through the platform
  SDKs and their system sign-in sheets rather than a login page inside the app.
- Requires `nativephp/mobile` ^3.3.11 or ^4.6.1 (NativePHP 3.x and 4.x), PHP 8.3+, iOS 18+ and Android API 29+.
  Prefer the newest NativePHP (3.3.12+ / 4.8+), which also signs native events. The full documentation is
  `vendor/ikromjon/nativephp-mobile-social-auth/README.md`.
- If Google sign-in creates or signs in to the user's account in an iOS app, App Store Guideline 4.8 requires an
  equivalent login that limits data collection to name and email, lets users keep their email private and does not
  track them for advertising without consent. Sign in with Apple qualifies, so offer both buttons on iOS.
- Apple sign-in exists on iOS only: on Android it fails with `UNSUPPORTED_PLATFORM`, so show the Apple button on iOS
  only. On NativePHP 4.7+ check with `Native\Mobile\Facades\System::isIos()` (the `ios` Blade conditional in views).
  Before 4.7 do not rely on either: on NativePHP 3 they ask the device through `Device.GetInfo`, which only the separate
  `nativephp/mobile-device` plugin provides, so without it they are `false` on every device, and on 4.0 to 4.6 they can
  be `false` on the iOS simulator. On those versions use `env('NATIVEPHP_PLATFORM') === 'ios'`; that variable is set in
  device and simulator builds but not under `native:jump`.

### Installing and upgrading

- Install with `composer require "ikromjon/nativephp-mobile-social-auth:^1.1.1" -W`. Keep the explicit `^1.1.1` and
  `-W`: if the app's `nativephp/mobile` constraint or lock file predates 3.3.11 / 4.6.1, a plain require quietly
  installs 1.1.0, whose events are refused once NativePHP reaches 3.3.11 / 4.6.1 (GHSA-83jv-8h7x-287p).
- Never update `nativephp/mobile` and this plugin separately. Upgrade both together:
  `composer require "nativephp/mobile:^4.6.1" "ikromjon/nativephp-mobile-social-auth:^1.1.1" -W` (on NativePHP 3,
  `"nativephp/mobile:^3.3.11"`).
- Register the plugin, or NativePHP leaves it out of the build: `appleSignIn()` and `googleSignIn()` then return `null`,
  `checkAppleCredentialState()` returns `'unknown'`, `signOut()` returns `false` and no event arrives. If the app has
  no `app/Providers/NativeServiceProvider.php` yet, first run
  `php artisan vendor:publish --tag=nativephp-plugins-provider`. Then run
  `php artisan native:plugin:register ikromjon/nativephp-mobile-social-auth` and check it with
  `php artisan native:plugin:list` (4 bridge functions) and `php artisan native:plugin:validate`.
- Android needs API 29. NativePHP reads `android.min_sdk` from the app's `config/nativephp.php`: the config NativePHP 4
  publishes sets it to `env('NATIVEPHP_ANDROID_MIN_SDK', 33)`, so there is nothing to do. A NativePHP 3 config (also
  one kept after upgrading to 4) has no such key, NativePHP then assumes 26 and the build aborts: add
  `'min_sdk' => 29` to its `android` array. `NATIVEPHP_ANDROID_MIN_SDK` alone does not help there.
- After installing, upgrading or changing a Google client ID, run `php artisan native:install --force`, then
  `php artisan native:run`.

### Configuration

- `.env` needs `GOOGLE_IOS_CLIENT_ID` (the iOS OAuth client) and `GOOGLE_SERVER_CLIENT_ID` (the **Web** OAuth client,
  which is the `aud` of every Google ID token on both platforms). `GOOGLE_IOS_REVERSED_CLIENT_ID` is optional: when
  omitted it is derived from the iOS client ID, and the plugin's `post_compile` hook registers it as the iOS URL scheme.
- `GOOGLE_IOS_CLIENT_ID` and `GOOGLE_SERVER_CLIENT_ID` are required plugin secrets: NativePHP will not build without
  them, even in an app that only uses Sign in with Apple.
- Google Cloud needs three OAuth clients in one project: Android (package name and SHA-1), Web and iOS (bundle ID).
- The plugin reads the server client ID through `config('social-auth.google_server_client_id')`, so it survives
  `config:cache`. Do not read these IDs with `env()` in app code.
- The plugin adds the *Sign in with Apple* entitlement to every iOS build, so enable Sign in with Apple on the App ID,
  even in an app that only offers Google. That needs a paid Apple Developer membership: a free Personal Team cannot
  provision it. Android builds need no Apple account.

### Starting sign-in

@verbatim
<code-snippet name="Social Auth facade" lang="php">
use Ikromjon\NativePHP\SocialAuth\Facades\SocialAuth;

SocialAuth::googleSignIn(nonce: $rawNonce);                                  // Google takes the raw nonce
SocialAuth::appleSignIn(['email', 'fullName'], hash('sha256', $rawNonce));  // Apple takes its SHA-256 hash
SocialAuth::checkAppleCredentialState($appleUserId);  // 'authorized', 'revoked', 'not_found', 'transferred', 'unknown'; Android: 'unsupported_platform'
SocialAuth::signOut();                                // Google only: Apple has no sign-out API
</code-snippet>
@endverbatim

- Handle the result **only through events**. On iOS the call also returns an `AuthResult`, but the same result is
  dispatched as an event, so handling both runs your code twice. On Android Google sign-in returns `null` and the
  result arrives only as an event.

### Handling the result

- The events live in `Ikromjon\NativePHP\SocialAuth\Events`:
  - `AppleSignInCompleted`: `userId`, `identityToken`, `authorizationCode`, `email`, `givenName`, `familyName`,
    `displayName`, `state`, `realUserStatus`. Apple sends the email and name only on the **first** sign-in for an
    Apple ID, so persist them straight away.
  - `GoogleSignInCompleted`: `userId`, `identityToken`, `email`, `displayName`, `givenName`, `familyName`, `photoUrl`,
    `accessToken`, `authorizationCode`. Android never sends `accessToken` or `authorizationCode`.
  - `SignInFailed`: `provider`, `error`, `errorCode`. The codes are `CANCELED`, `FAILED`, `INVALID_RESPONSE`,
    `NOT_HANDLED`, `NOT_INTERACTIVE`, `NO_AUTH_IN_KEYCHAIN`, `NO_CREDENTIAL`, `SCOPES_ALREADY_GRANTED`,
    `UNSUPPORTED_PLATFORM`, `MISSING_CONFIG`, `PARSE_ERROR` and `UNKNOWN`. Do not show an error for `CANCELED`.
- A handler takes **named parameters matching the payload keys**: declare only the fields you use, with defaults for
  anything a platform may leave out. Never use a single `$data` array.
- On NativePHP 4 native screens (a `NativeComponent`, which `php artisan native:make Login` creates with its view),
  listen with `#[On(...)]`. This is NativePHP's primary context and needs no web view.

@verbatim
<code-snippet name="Sign-in on a native screen" lang="php">
use Ikromjon\NativePHP\SocialAuth\Events\GoogleSignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Events\SignInFailed;
use Ikromjon\NativePHP\SocialAuth\Facades\SocialAuth;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;

class Login extends NativeComponent
{
    public ?string $error = null;

    public function signInWithGoogle(): void
    {
        SocialAuth::googleSignIn();
    }

    #[On(GoogleSignInCompleted::class)]
    public function googleSignedIn(string $userId, ?string $identityToken = null, ?string $email = null): void
    {
        // Send $identityToken to your server and verify it there before signing anyone in.
    }

    #[On(SignInFailed::class)]
    public function signInFailed(string $provider, string $error, ?string $errorCode = null): void
    {
        if ($errorCode !== 'CANCELED') {
            $this->error = $error;
        }
    }
}
</code-snippet>
@endverbatim

- In legacy web-view apps, Livewire components take the same methods with `#[OnNative(...)]` from
  `Native\Mobile\Attributes\OnNative`. Never use `#[OnNative]` in a `NativeComponent`.
- In JavaScript (Vue, React, Inertia), map the plugin's library in the app's `package.json` `imports` next to
  `#nativephp`, as `"#social-auth": "./vendor/ikromjon/nativephp-mobile-social-auth/resources/js/social-auth.js"`, then
  import it from `#social-auth` (`appleSignIn`, `googleSignIn`, `checkAppleCredentialState`, `signOut`; their promises
  reject when the native call fails, so add a `.catch()`) and listen with `On` from `#nativephp`, NativePHP's own JS
  library (mapped the same way: `"#nativephp": "./vendor/nativephp/mobile/resources/dist/native.js"`), for example
  `On('Ikromjon\\NativePHP\\SocialAuth\\Events\\GoogleSignInCompleted', (payload) => ...)`. NativePHP exports `On`
  and `Off` capitalised; there is no lowercase `on` or `off`. On Android, JavaScript must pass the Web client ID as
  the second argument, `googleSignIn(nonce, import.meta.env.VITE_GOOGLE_SERVER_CLIENT_ID)`; the PHP facade adds it
  from config itself.
- Laravel listeners (`Event::listen`, subscribers) only hear these events when they arrive through a web-view page.
  On a native screen without a web view, use `#[On]`.
- Fields a platform did not fill arrive as `''` in handlers but as `null` in Laravel listeners, so test them with
  `filled()` or `!empty()`.

### Security

- Event payloads and `AuthResult` are **claims made by the device, not proof of identity**. Before NativePHP 3.3.12 /
  4.8.0 any script in a web view can post a fake event; from those releases the native shell signs its events once
  the native project is rebuilt, but a modified app can still send anything. Send `identityToken` to your server
  and verify the JWT there before signing anyone in:
  check the signature against Google's (`https://www.googleapis.com/oauth2/v3/certs`) or Apple's
  (`https://appleid.apple.com/auth/keys`) keys, `aud` (`GOOGLE_SERVER_CLIENT_ID` or the app's bundle ID), `iss`
  (`https://accounts.google.com` or `accounts.google.com` for Google, `https://appleid.apple.com` for Apple), `exp`, and
  `nonce` when you sent one. Issue the nonce from your server, which is where it is checked.
- Never put a Google client secret in the app.

### Testing

- Off the device the bridge calls do nothing and return `null` or a default. On NativePHP 4, test screens with
  `Native::test()` instead (`php artisan native:make-test Login` scaffolds one). It needs no device or simulator.

@verbatim
<code-snippet name="Testing a sign-in screen" lang="php">
use App\NativeComponents\Login;
use Ikromjon\NativePHP\SocialAuth\Events\GoogleSignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Events\SignInFailed;
use Ikromjon\NativePHP\SocialAuth\Facades\SocialAuth;
use Native\Mobile\Testing\Native;

Native::test(Login::class)
    ->call('signInWithGoogle')
    ->assertNativeCalled('SocialAuth.GoogleSignIn')
    ->emitNative(SignInFailed::class, ['provider' => 'google', 'error' => 'No network', 'errorCode' => 'FAILED'])
    ->assertSet('error', 'No network');

// Deliver a sign-in the way the device does, then assert what your handler did with it.
Native::test(Login::class)->emitNative(GoogleSignInCompleted::class, [
    'userId' => '1234567890',
    'identityToken' => 'header.payload.signature',
    'email' => 'user@example.com',
]);

// Script what a bridge call returns.
Native::fakeBridge()->respondTo('SocialAuth.CheckAppleCredentialState', ['state' => 'revoked']);
expect(SocialAuth::checkAppleCredentialState('001234.abcdef.1234'))->toBe('revoked');
</code-snippet>
@endverbatim

- For web-view apps, test a Livewire `#[OnNative]` handler with
  `Livewire::test(SignIn::class)->dispatch('native:'.GoogleSignInCompleted::class, userId: '1234567890')`: the device
  hands events to Livewire inside the page, not through the server. To test Laravel listeners, post the event the way
  the device does: `$this->postJson('/_native/api/events', ['event' => GoogleSignInCompleted::class, 'payload' => [...]])`.
  Empty strings arrive there as `null`, and on 3.3.12+ / 4.8+ the post is refused with 403 if `NATIVEPHP_EVENTS_SIGNED`
  is `1`.
