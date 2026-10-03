# NativePHP Mobile Social Auth

[![Tests](https://github.com/Ikromjon1998/nativephp-mobile-social-auth/actions/workflows/tests.yml/badge.svg)](https://github.com/Ikromjon1998/nativephp-mobile-social-auth/actions/workflows/tests.yml)
[![NativePHP Plugin](https://img.shields.io/badge/NativePHP-Plugin-6d28d9)](https://nativephp.com/plugins/ikromjon/nativephp-mobile-social-auth)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4)](composer.json)
[![License: Commercial](https://img.shields.io/badge/License-Commercial-blue)](LICENSE)

Native Apple Sign-In and Google Sign-In for NativePHP mobile apps. Uses native platform SDKs (not browser-based redirects) for a seamless sign-in experience.

> **App Store Requirement:** If your app offers any third-party sign-in (Google, Facebook, etc.), Apple requires you to also offer Sign in with Apple. Apps that don't comply will be rejected during App Store review. ([Apple Guideline 4.8](https://developer.apple.com/app-store/review/guidelines/#sign-in-with-apple))

## Features

- **Apple Sign-In** -- Native `ASAuthorizationController` on iOS with Face ID / Touch ID
- **Google Sign-In** -- Native Credential Manager on Android, Google Sign-In SDK on iOS
- **Identity tokens** -- JWT tokens for server-side verification
- **User info** -- Name, email, profile photo
- **Nonce support** -- Replay protection for both providers
- **Credential state** -- Check if an Apple credential is still valid
- **Any OAuth provider** -- GitHub, X, Discord and the rest through the system browser, config only, no native code
- **Events** -- Livewire `#[OnNative]` and JS event listeners

## Platform Support

| Feature | iOS | Android |
|---------|-----|---------|
| Apple Sign-In | Yes (native) | Yes (browser flow, [needs an https redirect](#apple-sign-in-on-android)) |
| Google Sign-In | Yes | Yes |
| Browser-based OAuth providers | Yes | Yes |
| Credential State Check | Yes (Apple) | No |
| Sign Out | Yes (Google) | Yes (Google) |

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13
- NativePHP Mobile 3.x
- iOS 18.0+ / Android API 29+ (see [Installation](#android-raise-the-minimum-sdk) -- the Android
  minimum is not NativePHP's default)
- A **paid** Apple Developer Program membership for Apple Sign-In. The
  `com.apple.developer.applesignin` entitlement cannot be provisioned by a free Personal Team, and
  the iOS Simulator refuses to launch a build carrying it without a provisioning profile. Google
  Sign-In needs no Apple account.

## Installation

```bash
composer require ikromjon/nativephp-mobile-social-auth
```

On Laravel 13 add `-W`: NativePHP Mobile 3.x pins `guzzlehttp/guzzle ^7.9` while Laravel 13 ships
Guzzle 8, so Composer needs permission to downgrade it.

The service provider and facade are auto-discovered by Laravel. **Auto-discovery is not enough on its
own** -- NativePHP will not compile a plugin into a build unless it is also listed in your
`NativeServiceProvider`:

```bash
php artisan vendor:publish --tag=nativephp-plugins-provider
php artisan native:plugin:register ikromjon/nativephp-mobile-social-auth
```

Skip these and the app still builds, but every bridge call silently returns `null`. Confirm with:

```bash
php artisan native:plugin:list      # should list 4 registered bridge functions
php artisan native:plugin:validate  # should report OK
```

### Android: raise the minimum SDK

This plugin requires Android API 29, and NativePHP defaults to 26. If you skip this, the build
aborts before compiling.

The build error tells you to set `NATIVEPHP_ANDROID_MIN_SDK` in `.env` -- **that does not work.**
Nothing in NativePHP Mobile 3.3.x reads that variable; the value is read only from
`config('nativephp.android.min_sdk')`, and the shipped config never defines that key. Copy the
package config into your app and add it:

```bash
cp vendor/nativephp/mobile/config/nativephp.php config/nativephp.php
```

```php
// config/nativephp.php
'android' => [
    'min_sdk' => 29,
    // ... leave the rest of the array as shipped
],
```

Then build the native projects:

```bash
php artisan native:install --force
```

## Configuration

### 1. Google Cloud Console Setup

You need **two** OAuth client IDs from the same Google Cloud project:

**Step 1: Create a project & consent screen**
1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project (or select existing)
3. Go to **APIs & Services > OAuth consent screen**
4. Choose **External**, fill in app name and email
5. Add scopes: `email`, `profile`
6. Add your test email under **Test users** (required while in testing mode)

**Step 2: Create an Android OAuth client**
1. Go to **Credentials > Create Credentials > OAuth client ID**
2. Application type: **Android**
3. Package name: your `NATIVEPHP_APP_ID` from `.env` (e.g. `com.yourcompany.yourapp`)
4. SHA-1 fingerprint -- get it with:
   ```bash
   cd nativephp/android && ./gradlew signingReport
   ```
5. Click **Create** (you won't use this client ID directly -- Google uses it to verify your app's signing key)

**Step 3: Create a Web OAuth client**
1. **Credentials > Create Credentials > OAuth client ID**
2. Application type: **Web application**
3. No redirect URIs needed
4. Click **Create**
5. Copy the **Client ID** -- this is your `GOOGLE_SERVER_CLIENT_ID`

**Step 4: Create an iOS OAuth client** (if targeting iOS)
1. **Credentials > Create Credentials > OAuth client ID**
2. Application type: **iOS**
3. Bundle ID: your `NATIVEPHP_APP_ID` from `.env`
4. Click **Create**
5. Copy the **Client ID** -- this is your `GOOGLE_IOS_CLIENT_ID`
6. *(Optional)* Copy the **iOS URL scheme** shown below it (the client ID reversed, `com.googleusercontent.apps.123456789-abc`) -- this is `GOOGLE_IOS_REVERSED_CLIENT_ID`. It is registered as the OAuth callback URL scheme, without which Google Sign-In cannot start. You only need to set it if your reversed ID differs from the default form; otherwise the plugin derives it from `GOOGLE_IOS_CLIENT_ID`.

> **Why three client IDs?** The Android client verifies your app's signing key. The Web client ID is used by Android Credential Manager and for backend token verification. The iOS client ID configures the Google Sign-In SDK on iOS.

**Step 5: Add credentials to your `.env`**

```env
GOOGLE_IOS_CLIENT_ID=123456789-abc.apps.googleusercontent.com
# Optional -- derived from GOOGLE_IOS_CLIENT_ID when omitted:
# GOOGLE_IOS_REVERSED_CLIENT_ID=com.googleusercontent.apps.123456789-abc
GOOGLE_SERVER_CLIENT_ID=123456789-xyz.apps.googleusercontent.com
```

The plugin picks up `GOOGLE_SERVER_CLIENT_ID` from your `.env` out of the box — it is read through the plugin's own `social-auth` config, so it keeps working after `php artisan config:cache` — and passes it to the native SDK automatically. No manual Android string resources needed.

To customize, you can optionally publish the config file:

```bash
php artisan vendor:publish --tag=social-auth-config
```

### 2. Apple Sign-In Setup

The `com.apple.developer.applesignin` entitlement is automatically added by this plugin. You need to:

1. Log in to [Apple Developer Portal](https://developer.apple.com/account)
2. Go to **Certificates, Identifiers & Profiles > Identifiers**
3. Select your App ID (matching `NATIVEPHP_APP_ID`)
4. Enable **Sign in with Apple** capability
5. Save

No `.env` configuration needed for Apple -- it uses the native iOS SDK directly.

## Usage

### Important: Platform Behavior Differences

| | iOS | Android |
|---|---|---|
| **Apple Sign-In** | Returns `AuthResult` directly | Returns `null` (unsupported) |
| **Google Sign-In** | Returns `AuthResult` directly | Returns `null`; result arrives via event |

On **iOS**, bridge calls block until the user completes or cancels sign-in, then return the result synchronously. The **same result is also dispatched** as an `AppleSignInCompleted` / `GoogleSignInCompleted` event — the synchronous return is a convenience only.

On **Android**, Google Sign-In is asynchronous -- the call returns immediately, and the result is delivered via `GoogleSignInCompleted` or `SignInFailed` events.

**Recommended pattern:** Handle results via event listeners as the **single** handling path — events fire on both platforms. Do not handle the return value AND register listeners for the same sign-in, or your handler runs twice on iOS:

### Livewire (Recommended)

```php
<?php

namespace App\Livewire;

use Ikromjon\NativePHP\SocialAuth\Data\AuthResult;
use Ikromjon\NativePHP\SocialAuth\Events\AppleSignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Events\GoogleSignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Events\SignInFailed;
use Ikromjon\NativePHP\SocialAuth\Facades\SocialAuth;
use Livewire\Component;
use Native\Mobile\Attributes\OnNative;

class LoginScreen extends Component
{
    public ?string $error = null;

    public function signInWithApple()
    {
        $rawNonce = bin2hex(random_bytes(16));
        session(['auth_nonce' => $rawNonce]);

        // The result is handled by the #[OnNative] listeners below --
        // identically on iOS and Android. (On iOS the call also returns
        // the result synchronously; it is intentionally unused here.)
        SocialAuth::appleSignIn(
            scopes: ['email', 'fullName'],
            nonce: hash('sha256', $rawNonce),
        );
    }

    public function signInWithGoogle()
    {
        $nonce = bin2hex(random_bytes(16));
        session(['auth_nonce' => $nonce]);

        // The result is handled by the #[OnNative] listeners below --
        // identically on iOS and Android. (On iOS the call also returns
        // the result synchronously; it is intentionally unused here.)
        SocialAuth::googleSignIn(nonce: $nonce);
    }

    // Event handlers use NAMED PARAMETERS matching the event payload keys.
    // Do NOT use a single $data array — Livewire dispatches each key as a named argument.

    #[OnNative(AppleSignInCompleted::class)]
    public function onAppleSignIn(
        string $userId = '',
        ?string $identityToken = null,
        ?string $authorizationCode = null,
        ?string $email = null,
        ?string $givenName = null,
        ?string $familyName = null,
        ?string $displayName = null,
        ?string $state = null,
        ?string $realUserStatus = null,
    ) {
        if (!empty($userId)) {
            $this->handleSignIn([
                'provider' => 'apple',
                'userId' => $userId,
                'identityToken' => $identityToken,
                'email' => $email,
                'givenName' => $givenName,
                'familyName' => $familyName,
            ]);
        }
    }

    #[OnNative(GoogleSignInCompleted::class)]
    public function onGoogleSignIn(
        string $userId = '',
        ?string $identityToken = null,
        ?string $email = null,
        ?string $displayName = null,
        ?string $givenName = null,
        ?string $familyName = null,
        ?string $photoUrl = null,
        ?string $accessToken = null,
        ?string $authorizationCode = null,
    ) {
        if (!empty($userId)) {
            $this->handleSignIn([
                'provider' => 'google',
                'userId' => $userId,
                'identityToken' => $identityToken,
                'email' => $email,
                'displayName' => $displayName,
                'givenName' => $givenName,
                'familyName' => $familyName,
                'photoUrl' => $photoUrl,
            ]);
        }
    }

    #[OnNative(SignInFailed::class)]
    public function onSignInFailed(
        string $provider = '',
        string $error = '',
        ?string $errorCode = null,
    ) {
        if ($errorCode !== 'CANCELED') {
            $this->error = !empty($error) ? $error : 'Sign-in failed.';
        }
    }

    private function handleSignIn(array $data)
    {
        // Verify identity token server-side, then create/find user
        // IMPORTANT: Apple only sends email/name on FIRST sign-in!
        // You must persist this data immediately.
        return $this->redirect('/dashboard');
    }

    public function render()
    {
        return view('livewire.login-screen');
    }
}
```

```blade
{{-- resources/views/livewire/login-screen.blade.php --}}
<div class="flex flex-col gap-4 p-6">
    @if($error)
        <div class="bg-red-100 text-red-700 p-3 rounded">{{ $error }}</div>
    @endif

    <button
        wire:click="signInWithApple"
        class="flex items-center justify-center gap-2 bg-black text-white rounded-lg py-3 px-6 font-medium"
    >
        Sign in with Apple
    </button>

    <button
        wire:click="signInWithGoogle"
        class="flex items-center justify-center gap-2 bg-white text-gray-700 border border-gray-300 rounded-lg py-3 px-6 font-medium"
    >
        Sign in with Google
    </button>
</div>
```

### JavaScript (Vue / React / Inertia)

```javascript
import { On } from '#nativephp';
import socialAuth from 'vendor/ikromjon/nativephp-mobile-social-auth/resources/js/social-auth';

// Generate nonce client-side
function generateNonce() {
    const array = new Uint8Array(16);
    crypto.getRandomValues(array);
    return Array.from(array, b => b.toString(16).padStart(2, '0')).join('');
}

async function sha256Hex(value) {
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value));
    return Array.from(new Uint8Array(digest), b => b.toString(16).padStart(2, '0')).join('');
}

// Google Sign-In
async function handleGoogleSignIn() {
    const nonce = generateNonce();
    // The result is handled by the On(...) listeners below -- identically
    // on iOS and Android. (On iOS the promise also resolves with the
    // result; it is intentionally unused here.)
    await socialAuth.googleSignIn(nonce);
}

// Apple Sign-In
async function handleAppleSignIn() {
    const rawNonce = generateNonce();
    // Apple expects the SHA-256 hash of the nonce -- keep rawNonce for server-side verification
    await socialAuth.appleSignIn(['email', 'fullName'], await sha256Hex(rawNonce));
}

// Single handling path: these events fire on both platforms
On('Ikromjon\\NativePHP\\SocialAuth\\Events\\GoogleSignInCompleted', (payload) => {
    sendTokenToBackend(payload.identityToken);
});

On('Ikromjon\\NativePHP\\SocialAuth\\Events\\AppleSignInCompleted', (payload) => {
    sendTokenToBackend(payload.identityToken);
});

On('Ikromjon\\NativePHP\\SocialAuth\\Events\\SignInFailed', (payload) => {
    if (payload.errorCode !== 'CANCELED') {
        alert(`Sign-in failed: ${payload.error}`);
    }
});
```

## API Reference

### `SocialAuth::signIn(string $provider, array $options = []): ?AuthResult`

Signs in with any registered provider. The provider-specific methods below are thin wrappers around
this one and remain the ergonomic choice for Apple and Google — reach for `signIn()` when the
provider is dynamic, such as a loop rendering a button per configured provider.

- `$provider` — a key from the provider registry (`'apple'`, `'google'`, or one you configured)
- `$options` — `scopes`, `nonce`, `state`. Null values are dropped; anything you pass overrides the provider's defaults.

Throws `UnknownProviderException` if the provider is not registered, before any bridge call is made.

```php
$result = SocialAuth::signIn('google', ['nonce' => $nonce]);
```

### `SocialAuth::appleSignIn(array $scopes, ?string $nonce, ?string $state): ?AuthResult`

Initiates native Apple Sign-In. Returns `AuthResult` on iOS, `null` on Android.

- `$scopes` -- Requested scopes: `['email', 'fullName']` (default: both)
- `$nonce` -- SHA256-hashed nonce for replay protection
- `$state` -- Optional state string echoed back in response

> **Important:** Apple only returns `email` and `givenName`/`familyName` on the **first** sign-in. Subsequent sign-ins return only `userId` and `identityToken`. You must persist user info on first authentication.

### `SocialAuth::googleSignIn(?string $nonce): ?AuthResult`

Initiates native Google Sign-In. Returns `AuthResult` on iOS, `null` on Android (result via event).

- `$nonce` -- Optional nonce for replay protection (raw string, not hashed). Supported on both platforms: Android via Credential Manager, iOS via GoogleSignIn-iOS 9.x. The nonce comes back as the `nonce` claim inside the ID token -- verify it server-side.

### `SocialAuth::checkAppleCredentialState(string $userId): string`

Checks if an Apple credential is still valid. iOS only.

Returns: `'authorized'`, `'revoked'`, `'not_found'`, `'transferred'`, or `'unknown'`

### `SocialAuth::signOut(?string $provider = null): bool`

Signs out and clears credential state.

Passing no provider signs out of every provider that offers a sign-out API — today that means
Google, which is exactly what this method has always done. Passing a provider name scopes the call
to it. Apple has no sign-out API (sessions are managed in system settings), so `signOut('apple')`
returns `false` without calling the bridge.

### AuthResult

| Property | Type | Apple | Google |
|----------|------|-------|--------|
| `provider` | `string` | `'apple'` | `'google'` |
| `userId` | `?string` | Unique Apple user ID | Google user ID |
| `identityToken` | `?string` | JWT | JWT |
| `authorizationCode` | `?string` | One-time code | Server auth code |
| `accessToken` | `?string` | -- | OAuth access token |
| `email` | `?string` | First sign-in only | Always |
| `givenName` | `?string` | First sign-in only | Always |
| `familyName` | `?string` | First sign-in only | Always |
| `displayName` | `?string` | First sign-in only | Always |
| `photoUrl` | `?string` | -- | Profile photo URL |
| `nonce` | `?string` | Returned as `nonce` claim inside `identityToken` | Returned as `nonce` claim inside `identityToken` |
| `state` | `?string` | Echoed | -- |
| `realUserStatus` | `?string` | `'likelyReal'` / `'unknown'` | -- |

### Events

| Event | Payload |
|-------|---------|
| `AppleSignInCompleted` | `userId`, `identityToken`, `authorizationCode`, `email`, `givenName`, `familyName`, `displayName`, `state`, `realUserStatus` |
| `GoogleSignInCompleted` | `userId`, `identityToken`, `email`, `displayName`, `givenName`, `familyName`, `photoUrl`, `accessToken`, `authorizationCode` (iOS only -- Android Credential Manager issues neither) |
| `SignInCompleted` | `provider`, plus every field above. Fires for **every** provider |
| `SignInFailed` | `provider`, `error`, `errorCode` |

Event payloads carry every field of `AuthResult` except `provider` and `nonce` (the nonce is inside `identityToken`). Fields the platform did not return arrive as empty strings, not `null` -- check with `!empty()` / `filled()`, not `!== null`.

> **Listen to `SignInCompleted` *or* the provider-specific event, never both** -- `SignInCompleted`
> is mirrored from the provider-specific events, so a handler registered on both runs twice.
> `SignInCompleted` is the one to prefer in new code: it carries `provider` and fires for providers
> added in future releases without further changes. Set `social-auth.dispatch_generic_event` to
> `false` to switch the mirroring off entirely.

**Error codes:** `CANCELED`, `FAILED`, `INVALID_RESPONSE`, `NOT_HANDLED`, `NOT_INTERACTIVE`, `NO_AUTH_IN_KEYCHAIN`, `NO_CREDENTIAL`, `SCOPES_ALREADY_GRANTED`, `UNSUPPORTED_PLATFORM`, `MISSING_CONFIG`, `PARSE_ERROR`, `INVALID_PARAMS`, `OAUTH_FAILED`, `STATE_MISMATCH`, `NO_BROWSER`, `UNKNOWN`

The last four are specific to browser-based providers: `OAUTH_FAILED` is a provider-side refusal, `STATE_MISMATCH` means the redirect did not belong to the request this app started, and `NO_BROWSER` means the device has no browser able to run the flow.

## Provider Registry

Providers are resolved through a registry rather than hardcoded, so credentials live in config and
the set of providers is open.

Built-in definitions (which bridge function to call, whether the platform offers a sign-out API)
ship with the plugin. Anything you put under `providers` in `config/social-auth.php` is merged
**over** them per provider, so you only list what you are changing:

```php
'providers' => [
    'google' => [
        'server_client_id' => env('GOOGLE_SERVER_CLIENT_ID'),
        'ios_client_id' => env('GOOGLE_IOS_CLIENT_ID'),
        'ios_reversed_client_id' => env('GOOGLE_IOS_REVERSED_CLIENT_ID'),
    ],
],
```

Merging happens per provider rather than through Laravel's `mergeConfigFrom`, which only merges at
the top level. That matters: if it merged shallowly, publishing this config file today would
silently drop any provider added in a later release.

Custom entries are registered too, and any that declare a `redirect_scheme` get it written into the
iOS `Info.plist` automatically by the same `post_compile` hook that handles Google:

```php
'providers' => [
    'github' => [
        'driver' => 'oauth',
        'url_scheme' => 'redirect_scheme',
        'redirect_scheme' => 'myapp-github',
    ],
],
```

> **Never put a client secret in this file.** It is compiled into the app binary and can be
> extracted from it. Providers that need a secret to exchange an authorization code must do that
> exchange on your server.

### The `oauth` driver

Providers with no native SDK — GitHub, X, Discord, LinkedIn, Twitch, GitLab — run an
authorization-code flow with PKCE through the system browser: `ASWebAuthenticationSession` on iOS,
Custom Tabs on Android. One implementation serves all of them, so adding a provider is a config
entry rather than native code.

**No tokens come back to the app.** Redeeming the code requires a client secret, and a secret
compiled into an app binary is a secret anyone can extract. What you get is an authorization code
bound to a PKCE verifier, which your server exchanges:

```
app  ──▶ authorize URL + code_challenge  ──▶ provider
app  ◀── code (via custom scheme)        ◀── provider
app  ──▶ code + code_verifier            ──▶ your server ──▶ tokens
```

Handle it through the `AuthorizationCodeReceived` event, which carries `provider`,
`authorizationCode`, `codeVerifier`, `state` and `redirectUri`. It is deliberately not a
`SignInCompleted`: nothing is known about the user until your server completes the exchange.

```php
Event::listen(AuthorizationCodeReceived::class, function ($event) {
    // Exchange server-side — the client secret must never reach the app.
    $tokens = Http::asForm()->post('https://github.com/login/oauth/access_token', [
        'client_id' => config('services.github.client_id'),
        'client_secret' => config('services.github.client_secret'),
        'code' => $event->authorizationCode,
        'code_verifier' => $event->codeVerifier,
        'redirect_uri' => $event->redirectUri,
    ]);
});
```

> **The redirect scheme must be your application ID on Android.** The redirect activity's
> intent-filter is bound to `${applicationId}`, which the Android Gradle plugin substitutes at build
> time — NativePHP does not substitute `${ENV}` placeholders into `AndroidManifest.xml`, so a
> per-app scheme cannot be declared there. Set `redirect_scheme` to your application ID and register
> `<your.application.id>://callback` with the provider. iOS follows the same value, so one setting
> covers both.

`state` and the PKCE verifier are generated per call and compared natively, since that is the only
place holding both the sent and returned values. A mismatch reports `STATE_MISMATCH` rather than
completing.

#### Apple Sign-In on Android

Android has no native Apple SDK, so Apple runs through this driver too — with one wrinkle that is
Apple's, not the plugin's:

> Apple requires `response_mode=form_post` whenever `name` or `email` scopes are requested, and
> form-posts the result to an **https URL**, not a custom scheme. So the Android flow must round-trip
> through an endpoint you control, which then redirects to `<your.application.id>://callback`.

Set `redirect_uri` (not `redirect_scheme`) to that https endpoint for Apple. This is worth doing:
App Store Guideline 4.8 requires offering Apple Sign-In alongside any other third-party sign-in, and
without this an Android build cannot satisfy it.

## Server-Side Token Verification

Identity tokens are JWTs that **must** be verified server-side before trusting the user's identity.

Google ID tokens from **both** platforms carry `aud` = your `GOOGLE_SERVER_CLIENT_ID` (Android sets it via `setServerClientId`, iOS via the `GIDServerClientID` Info.plist key), so the single `aud` check below covers both.

> **Migration note:** On iOS, plugin versions ≤ 1.0.2 issued Google ID tokens with `aud` = your `GOOGLE_IOS_CLIENT_ID`. If you have existing installs, temporarily accept both audiences server-side until all clients are updated.

```php
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

// Google verification
//
// Note on `azp`: a real token from this plugin carries `aud` = your server
// client ID and `azp` = the *platform* client ID (the iOS or Android client
// that requested it). Check `aud`; do not compare `azp` against the server
// client ID, or every mobile sign-in will be rejected.
$googleKeys = json_decode(
    file_get_contents('https://www.googleapis.com/oauth2/v3/certs'), true
);
$decoded = JWT::decode($identityToken, JWK::parseKeySet($googleKeys));
// Verify: $decoded->aud === your GOOGLE_SERVER_CLIENT_ID
// Verify: $decoded->iss === 'https://accounts.google.com'
// If you passed a nonce to googleSignIn():
// Verify: $decoded->nonce === session('auth_nonce')

// Apple verification
$appleKeys = json_decode(
    file_get_contents('https://appleid.apple.com/auth/keys'), true
);
$decoded = JWT::decode($identityToken, JWK::parseKeySet($appleKeys));
// Verify: $decoded->aud === your app's bundle ID
// Verify: $decoded->iss === 'https://appleid.apple.com'
// If you passed a nonce to appleSignIn() (SHA-256 of the raw nonce):
// Verify: $decoded->nonce === hash('sha256', session('auth_nonce'))
```

Install the JWT library: `composer require firebase/php-jwt`

## Known issues

**`System::isIos()` / `System::isAndroid()` return `false` inside the app**

Not a fault of this plugin, but it affects any platform-conditional code written around it:
`Device::getInfo()` returns `null` on the iOS simulator, so both helpers report `false` and code
silently takes its "not on a device" branch. Read `env('NATIVEPHP_PLATFORM')` instead -- the native
runtime exports it into `$_SERVER` before Laravel boots, so it also survives `config:cache`.

## How the iOS URL scheme is registered

GoogleSignIn-iOS will not start unless the reversed client ID is registered in `CFBundleURLTypes`,
and it reports a missing scheme by raising an uncaught `NSException` -- which terminates the app
rather than returning an error.

The plugin manifest cannot express this. `url_schemes` is **not** a key NativePHP Mobile reads
(neither 3.3.x nor 4.x), and the supported `info_plist` route only handles flat strings and flat
arrays of strings, not the array-of-dicts `CFBundleURLTypes` requires.

So the plugin registers it from a `post_compile` hook instead
(`social-auth:register-url-scheme`), which runs after NativePHP has finished rewriting the
Info.plist and before Xcode builds. **This is automatic -- there is nothing to configure.** For
reference, it:

- writes its own entry, tagged `CFBundleURLName = ikromjon.social-auth.google`, so it never collides
  with NativePHP's deeplink entry (which is refilled from `NATIVEPHP_DEEPLINK_SCHEME` each build);
- patches **every** Info.plist in the generated project -- the device target builds against
  `NativePHP/Info.plist` and the simulator target against `NativePHP-simulator-Info.plist`;
- updates its entry in place on rebuilds rather than duplicating it, and rewrites it if the client
  ID changes;
- derives the reversed ID from `GOOGLE_IOS_CLIENT_ID` when `GOOGLE_IOS_REVERSED_CLIENT_ID` is absent.

As a second line of defence, the Swift bridge checks `CFBundleURLTypes` *before* calling
`GIDSignIn`. Swift cannot catch an Objective-C `NSException`, so this cannot be wrapped in
`do/catch`; if the scheme is missing the plugin dispatches `SignInFailed` with `MISSING_CONFIG`
instead of letting the app die.

## Troubleshooting

**iOS build fails: `error: extra arguments at positions #4, #5 in call` in SocialAuthFunctions.swift**
- Plugin versions up to 1.0.1 pinned GoogleSignIn-iOS `~> 8.0` while calling the nonce sign-in overload, which only exists in GoogleSignIn-iOS 9.0+. Upgrade the plugin (`composer update ikromjon/nativephp-mobile-social-auth`), then run `php artisan native:install --force` so the regenerated Podfile resolves GoogleSignIn `~> 9.0`. If CocoaPods then reports a dependency conflict, another pod in your project is pinning AppAuth 1.x / GTMAppAuth 4.x -- update that dependency, since GoogleSignIn 9.x requires AppAuth 2.x and GTMAppAuth 5.x.

**"Developer console is not set up correctly" (Android)**
- Ensure you have BOTH an Android client AND a Web client in the same Google Cloud project
- The Android client must have the correct package name and SHA-1 fingerprint

**"MISSING_CONFIG" error**
- Check that `GOOGLE_SERVER_CLIENT_ID` is set in your `.env` file

**Build aborts: "Missing required plugin secrets" although the values are in `.env`**
- Run `php artisan config:clear` before building. Once `config:cache` has run, Laravel stops loading
  `.env`, so every `env()` call returns null -- and NativePHP reads plugin secrets through `env()`.
  This does not affect the built app: values reach it through config, which is why
  `GOOGLE_SERVER_CLIENT_ID` still resolves at runtime with the config cached.

**Build aborts: "Plugin ... requires Android API level 29, but your min SDK is 26"**
- Setting `NATIVEPHP_ANDROID_MIN_SDK` in `.env` as the message suggests has no effect -- nothing
  reads it. Define `android.min_sdk` in a published `config/nativephp.php` instead; see
  [Installation](#android-raise-the-minimum-sdk).

**Every bridge call returns `null` and no events fire**
- The plugin is installed but not registered. Run `php artisan native:plugin:list` -- if it appears
  under "Unregistered Plugins", run `php artisan native:plugin:register
  ikromjon/nativephp-mobile-social-auth` and rebuild.

**Apple Sign-In fails with `AuthorizationError error 1000` and no sheet appears**
- The entitlement is not in the built binary. Check with
  `codesign -d --entitlements - /path/to/YourApp.app`; empty output means it was dropped. This
  happens when `NATIVEPHP_DEVELOPMENT_TEAM` is unset, because the app is then ad-hoc signed. A free
  Personal Team is not sufficient -- see [Requirements](#requirements).

**Code changes do not appear after `native:run`**
- The app can keep serving the previous build's files. Force a clean extraction:
  `xcrun simctl uninstall <udid> <your.app.id>` (iOS) or `adb uninstall <your.app.id>` (Android)
  before re-running.

**Google Sign-In returns null on Android**
- This is expected. On Android, Google Sign-In is async. Use `#[OnNative(GoogleSignInCompleted::class)]` to receive the result.

**Apple email/name are null**
- Apple only provides email and name on the **first** sign-in. After that, only `userId` and `identityToken` are returned. To reset during development: Settings > Apple ID > Sign-In & Security > Sign in with Apple > Your App > Stop Using Apple ID.

**App Store rejection for missing Apple Sign-In**
- If your app offers Google (or any third-party) sign-in, you must also offer Apple Sign-In. This plugin handles both.

## Support

- Issues: [GitHub Issues](https://github.com/Ikromjon1998/nativephp-mobile-social-auth/issues)
- Email: ikromjon98.98@icloud.com

## License

This is a **commercial plugin** distributed through the official NativePHP plugin marketplace:

👉 **[nativephp.com/plugins/ikromjon/nativephp-mobile-social-auth](https://nativephp.com/plugins/ikromjon/nativephp-mobile-social-auth)**

Use is governed by the [End User License Agreement](LICENSE). Redistribution or resale is not permitted.
