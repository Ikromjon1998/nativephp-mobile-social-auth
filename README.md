# NativePHP Mobile Social Auth

[![NativePHP Plugin](https://img.shields.io/badge/NativePHP-Plugin-6d28d9)](https://nativephp.com/plugins/ikromjon/nativephp-mobile-social-auth)
[![NativePHP Mobile 3.x | 4.x](https://img.shields.io/badge/NativePHP%20Mobile-3.x%20%7C%204.x-6d28d9)](#requirements)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4)](composer.json)
[![License: Commercial](https://img.shields.io/badge/License-Commercial-blue)](LICENSE)

Native social login for NativePHP Mobile 3.x and 4.x apps: **Sign in with Apple** on iOS and **Google Sign-In** (OAuth) on iOS and Android. It signs in through the platform SDKs and their system sign-in sheets -- Apple's sheet, Android's Credential Manager, and on iOS Google's SDK, which shows Google's page in a system sheet -- rather than a login page inside your app, and hands your backend an ID token it can verify.

<!-- Sign-in demo: record a short GIF of a Google or Apple sign-in, host it somewhere public (images
     inside a private repository do not load on the marketplace page), then replace this comment with:
<p align="center"><img src="https://YOUR-HOST/sign-in.gif" alt="Signing in with Google in a NativePHP app" width="320"></p>
-->

> **App Store Requirement:** If your iOS app uses a third-party sign-in such as Google for the user's account, Apple also requires an equivalent login that limits data collection to name and email, lets users keep their email private, and does not track them for advertising without consent. Sign in with Apple meets all three, so offering it alongside Google satisfies the rule. Apps that don't comply will be rejected during App Store review. ([Apple Guideline 4.8](https://developer.apple.com/app-store/review/guidelines/#login-services))

## Features

- **Platform SDKs and system sign-in sheets** -- Sign in with Apple through `ASAuthorizationController` on iOS (Face ID / Touch ID); Google Sign-In through Credential Manager on Android and the Google Sign-In SDK on iOS
- **NativePHP Mobile 3.x and 4.x** -- tested in CI against 3.3.11, the newest 3.3.x, 4.6.1, 4.7.x and 4.8.x. The events implement `DispatchableFromNative`, so on the patched NativePHP releases they still reach Laravel listeners from web-view pages
- **Every way to listen** -- `#[On]` on NativePHP 4 native screens, Livewire `#[OnNative]`, JavaScript `On(...)`, and Laravel listeners from web-view pages (see [Upgrading](#upgrading))
- **Tokens your server can verify** -- Google ID tokens whose `aud` is your Web client ID on both platforms, and the same Google user ID (the token's `sub`) on iOS and Android
- **Nonce support** -- Replay protection for both providers
- **More from Google on iOS** -- The server auth code and the access token
- **User info** -- Name, email, profile photo
- **Credential state and sign-out** -- Check whether an Apple credential is still valid; sign out of Google
- **AI guidelines** -- [Laravel Boost](#laravel-boost-guidelines) guidelines for coding assistants

## Platform Support

| Feature | iOS | Android |
|---------|-----|---------|
| Apple Sign-In | Yes | No (Apple offers no Android SDK) |
| Google Sign-In | Yes | Yes |
| Credential State Check | Yes (Apple) | No |
| Sign Out | Yes (Google) | Yes (Google) |

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13
- NativePHP Mobile 3.3.11+ or 4.6.1+ (the first releases with the `DispatchableFromNative` event
  marker -- see [Upgrading](#upgrading))
- iOS 18.0+ / Android API 29+ (NativePHP 4's config already uses 33; on NativePHP 3 see
  [Android: raise the minimum SDK](#android-raise-the-minimum-sdk))
- A **paid** Apple Developer Program membership for iOS builds. The
  `com.apple.developer.applesignin` entitlement cannot be provisioned by a free Personal Team, and
  the iOS Simulator refuses to launch a build carrying it without a provisioning profile. The plugin
  adds that entitlement to every iOS build, so this applies even to an iOS app that only offers
  Google Sign-In. Android builds need no Apple account.

## Installation

```bash
composer require "ikromjon/nativephp-mobile-social-auth:^1.1.1" -W
```

Ask for `^1.1.1` explicitly and keep `-W`. If your app's `nativephp/mobile` constraint, or the
version in its `composer.lock`, predates 3.3.11 / 4.6.1, a plain `composer require` quietly
installs plugin 1.1.0 instead, which loses events once NativePHP is updated (see
[Upgrading](#upgrading)). With `-W` Composer moves NativePHP up within your constraint, or fails
loudly if your constraint does not allow it.

The service provider and facade are auto-discovered by Laravel. **Auto-discovery is not enough on its
own** -- NativePHP will not compile a plugin into a build unless it is also listed in your
`NativeServiceProvider`:

```bash
php artisan vendor:publish --tag=nativephp-plugins-provider
php artisan native:plugin:register ikromjon/nativephp-mobile-social-auth
```

Skip these and the app still builds (`native:run` only warns that the plugin is not registered), but
the native code is left out: `appleSignIn()` and `googleSignIn()` return `null`,
`checkAppleCredentialState()` returns `'unknown'`, `signOut()` returns `false`, and no events fire.
Confirm with:

```bash
php artisan native:plugin:list      # should list 4 registered bridge functions
php artisan native:plugin:validate  # should report OK
```

### Android: raise the minimum SDK

This plugin requires Android API 29. NativePHP reads the minimum from `android.min_sdk` in your
app's `config/nativephp.php`:

- **NativePHP 4, with the config NativePHP 4 published:** nothing to do. That config sets
  `'min_sdk' => env('NATIVEPHP_ANDROID_MIN_SDK', 33)`.
- **NativePHP 3, or an app upgraded from it that kept its old config:** that config has no
  `min_sdk` key, NativePHP then assumes API 26, and the build aborts before compiling. The error
  tells you to set `NATIVEPHP_ANDROID_MIN_SDK` in `.env` -- **that does not work here**, because only
  the config key is read and your config does not have it.

Add the key to the `android` array of your `config/nativephp.php` (if the file is missing, publish
it first with `php artisan vendor:publish --tag=nativephp-mobile-config`):

```php
// config/nativephp.php
'android' => [
    'min_sdk' => 29,
    // ... leave the rest of the array as it is
],
```

Then build the native projects:

```bash
php artisan native:install --force
```

### Laravel Boost guidelines

The package ships [Laravel Boost](https://github.com/laravel/boost) guidelines in
`resources/boost/guidelines/core.blade.php`, so AI coding assistants learn this plugin's API,
events, error codes and pitfalls the way NativePHP 4 expects them to be used. If your app uses
Boost, run `php artisan boost:install` after installing or upgrading the plugin to load them.

## Upgrading

### To 1.1.1: NativePHP Mobile 3.3.11 / 4.6.1 or newer

NativePHP Mobile 4.6.1 and 3.3.11 fixed
[GHSA-83jv-8h7x-287p](https://github.com/NativePHP/mobile-air/security/advisories/GHSA-83jv-8h7x-287p):
the endpoint that native code posts events to now refuses any event class that does not implement
`Native\Mobile\Events\Contracts\DispatchableFromNative`. This plugin's events implement it from
1.1.1, and the interface only exists from those NativePHP releases, so require both together and
rebuild:

```bash
composer require "nativephp/mobile:^4.6.1" "ikromjon/nativephp-mobile-social-auth:^1.1.1" -W
php artisan native:install --force
```

On NativePHP 3 use `"nativephp/mobile:^3.3.11"`, and if you pin minor versions keep your style
(for example `~4.7.0`). Do not update the two packages separately: `composer update nativephp/mobile`
on its own happily installs a patched NativePHP next to plugin 1.1.0, and
`composer update ikromjon/nativephp-mobile-social-auth` keeps 1.1.0 while NativePHP is locked below
the patched releases -- in both cases without an error. Check the result with
`composer show ikromjon/nativephp-mobile-social-auth`.

With plugin 1.1.0 or older on a patched NativePHP, the sign-in events still reach Livewire
`#[OnNative]` and JavaScript `On(...)` handlers, but Laravel listeners (`Event::listen`, event
subscribers) on web-view pages never run.

On NativePHP 4 native (SuperNative) screens, `#[On]` on the screen always receives these events,
through NativePHP's native event queue. Laravel listeners only receive them through a live page web
view, because the plugin cannot implement NativePHP 4's `BroadcastsGlobally` while it also supports
NativePHP 3. When both exist, both run.

## Configuration

### 1. Google Cloud Console Setup

You need **three** OAuth clients in the same Google Cloud project -- Android, Web and iOS. The Web and iOS client IDs go into your `.env`:

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

**Step 4: Create an iOS OAuth client** (Android-only apps too: NativePHP will not build without `GOOGLE_IOS_CLIENT_ID`, although Android never uses it)
1. **Credentials > Create Credentials > OAuth client ID**
2. Application type: **iOS**
3. Bundle ID: your `NATIVEPHP_APP_ID` from `.env`
4. Click **Create**
5. Copy the **Client ID** -- this is your `GOOGLE_IOS_CLIENT_ID`
6. *(Optional)* Copy the **iOS URL scheme** shown below it (the client ID reversed, `com.googleusercontent.apps.123456789-abc`) -- this is `GOOGLE_IOS_REVERSED_CLIENT_ID`. It is registered as the OAuth callback URL scheme, without which Google Sign-In cannot start. You can leave it unset: the plugin derives it from `GOOGLE_IOS_CLIENT_ID`. If you do set it, use exactly the value Google shows.

> **Why three client IDs?** The Android client verifies your app's signing key. The Web client ID is the audience (`aud`) of every Google ID token: Android's Credential Manager asks for it, the Google Sign-In SDK on iOS reads it from Info.plist as `GIDServerClientID`, and your backend verifies tokens against it. The iOS client ID configures the Google Sign-In SDK on iOS.

**Step 5: Add credentials to your `.env`**

```env
GOOGLE_IOS_CLIENT_ID=123456789-abc.apps.googleusercontent.com
# Optional -- derived from GOOGLE_IOS_CLIENT_ID when omitted:
# GOOGLE_IOS_REVERSED_CLIENT_ID=com.googleusercontent.apps.123456789-abc
GOOGLE_SERVER_CLIENT_ID=123456789-xyz.apps.googleusercontent.com
```

The plugin picks up `GOOGLE_SERVER_CLIENT_ID` from your `.env` out of the box — it is read through the plugin's own `social-auth` config, so it keeps working after `php artisan config:cache` — and the PHP facade passes it to the native SDK automatically. No manual Android string resources needed. If you start Google Sign-In from JavaScript instead, pass the ID yourself on Android: see [JavaScript](#javascript-vue--react--inertia).

`GOOGLE_IOS_CLIENT_ID` and `GOOGLE_SERVER_CLIENT_ID` are required plugin secrets, so NativePHP refuses to build without them -- on Android as well as iOS, and even in an app that only uses Sign in with Apple.

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

No `.env` configuration is needed for Apple itself -- it uses the native iOS SDK directly. The build still needs `GOOGLE_IOS_CLIENT_ID` and `GOOGLE_SERVER_CLIENT_ID` from [Google Cloud Console Setup](#1-google-cloud-console-setup), even if you never offer Google Sign-In: they are required plugin secrets.

## Usage

### Important: Platform Behavior Differences

| | iOS | Android |
|---|---|---|
| **Apple Sign-In** | Returns `AuthResult` on success; `null` if the user cancels or sign-in fails | Returns `null` (unsupported) |
| **Google Sign-In** | Returns `AuthResult` on success; `null` if the user cancels or sign-in fails | Returns `null`; result arrives via event |

On **iOS**, bridge calls block until the user completes or cancels sign-in, then return the result synchronously. The **same result is also dispatched** as an `AppleSignInCompleted` / `GoogleSignInCompleted` event — the synchronous return is a convenience only.

On **Android**, Google Sign-In is asynchronous -- the call returns immediately, and the result is delivered via `GoogleSignInCompleted` or `SignInFailed` events.

**Recommended pattern:** Handle results via event listeners as the **single** handling path — events fire on both platforms. Do not handle the return value AND register listeners for the same sign-in, or your handler runs twice on iOS:

### Native screens (NativePHP 4, recommended)

On NativePHP 4 a screen is a `NativeComponent` (`php artisan native:make Login` creates the class
and its view). Start sign-in from a method and handle the result in `#[On]` methods. This needs no
web view and works the same on iOS and Android:

```php
<?php

namespace App\NativeComponents;

use Ikromjon\NativePHP\SocialAuth\Events\AppleSignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Events\GoogleSignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Events\SignInFailed;
use Ikromjon\NativePHP\SocialAuth\Facades\SocialAuth;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;

class Login extends NativeComponent
{
    public ?string $error = null;

    public function signInWithApple(): void
    {
        SocialAuth::appleSignIn(['email', 'fullName']);
    }

    public function signInWithGoogle(): void
    {
        SocialAuth::googleSignIn();
    }

    // Parameters are named after the payload keys. Declare only the fields you use:
    // NativePHP drops the others. A field the platform did not send takes your default;
    // one it sent empty arrives as '' (see Events), so test with filled().
    #[On(AppleSignInCompleted::class)]
    public function appleSignedIn(
        string $userId,
        ?string $identityToken = null,
        ?string $email = null,
        ?string $givenName = null,
        ?string $familyName = null,
    ): void {
        // Apple sends the email and name only on the first sign-in, so store them now.
        // Send $identityToken to your server and verify it there (see Server-Side Token Verification).
    }

    #[On(GoogleSignInCompleted::class)]
    public function googleSignedIn(string $userId, ?string $identityToken = null, ?string $email = null): void
    {
        // Send $identityToken to your server and verify it there.
    }

    #[On(SignInFailed::class)]
    public function signInFailed(string $provider, string $error, ?string $errorCode = null): void
    {
        if ($errorCode !== 'CANCELED') {
            $this->error = $error;
        }
    }
}
```

```blade
{{-- resources/views/native/login.blade.php --}}
<native:column class="gap-3 p-4">
    @ios
        <native:pressable @tap="signInWithApple">
            <native:text>Sign in with Apple</native:text>
        </native:pressable>
    @endios
    <native:pressable @tap="signInWithGoogle">
        <native:text>Sign in with Google</native:text>
    </native:pressable>
    @if ($error)
        <native:text>{{ $error }}</native:text>
    @endif
</native:column>
```

The view uses only elements NativePHP 4 itself provides. A `<native:button>` needs the
`nativephp/mobile-ui` plugin; without it the screen fails with "Unknown native element type: button".
Style the Apple control to Apple's Sign in with Apple button guidelines before you ship. Before
NativePHP 4.7, `@ios` can be `false` on the iOS simulator; see [Known issues](#known-issues).
Use `#[On]` in a `NativeComponent`, never `#[OnNative]`, which is for Livewire components in a web
view. Test the screen without a device using `Native::test()`:

```php
use App\NativeComponents\Login;
use Ikromjon\NativePHP\SocialAuth\Events\SignInFailed;
use Native\Mobile\Testing\Native;

Native::test(Login::class)
    ->call('signInWithGoogle')
    ->assertNativeCalled('SocialAuth.GoogleSignIn')
    ->emitNative(SignInFailed::class, ['provider' => 'google', 'error' => 'No network', 'errorCode' => 'FAILED'])
    ->assertSet('error', 'No network');
```

### Livewire (web-view apps)

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

Map the plugin's library in your app's `package.json` next to NativePHP's own, so both resolve as
subpath imports:

```json
"imports": {
    "#nativephp": "./vendor/nativephp/mobile/resources/dist/native.js",
    "#social-auth": "./vendor/ikromjon/nativephp-mobile-social-auth/resources/js/social-auth.js"
}
```

```javascript
import { On } from '#nativephp';
import socialAuth from '#social-auth';

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
    // result; it is intentionally unused here.) The promise rejects when the
    // native call fails -- on iOS also when the user cancels -- and the
    // SignInFailed event carries the reason, so the rejection is ignored here.
    // Android's Credential Manager needs your Web client ID. The PHP facade adds it from
    // config; from JavaScript pass it in (iOS reads it from Info.plist and ignores it).
    // In .env: VITE_GOOGLE_SERVER_CLIENT_ID="${GOOGLE_SERVER_CLIENT_ID}"
    await socialAuth.googleSignIn(nonce, import.meta.env.VITE_GOOGLE_SERVER_CLIENT_ID).catch(() => {});
}

// Apple Sign-In
async function handleAppleSignIn() {
    const rawNonce = generateNonce();
    // Apple expects the SHA-256 hash of the nonce -- keep rawNonce for server-side verification
    await socialAuth.appleSignIn(['email', 'fullName'], await sha256Hex(rawNonce)).catch(() => {});
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

### `SocialAuth::appleSignIn(array $scopes, ?string $nonce, ?string $state): ?AuthResult`

Initiates native Apple Sign-In. Returns `AuthResult` on iOS when sign-in succeeds; `null` when the user cancels or sign-in fails (a `SignInFailed` event carries the reason), and always on Android (unsupported).

- `$scopes` -- Requested scopes: `['email', 'fullName']` (default: both)
- `$nonce` -- SHA256-hashed nonce for replay protection
- `$state` -- Optional state string echoed back in response

> **Important:** Apple only returns `email` and `givenName`/`familyName` (and so `displayName`) on the **first** sign-in. Later sign-ins still return `userId`, `identityToken` and a new one-time `authorizationCode` (and `state` if you passed one), but no email or name. The email is still in the `email` claim of the verified `identityToken`; the name is not available anywhere. You must persist user info on first authentication.

### `SocialAuth::googleSignIn(?string $nonce): ?AuthResult`

Initiates native Google Sign-In. Returns `AuthResult` on iOS when sign-in succeeds; `null` when the user cancels or sign-in fails (a `SignInFailed` event carries the reason), and always on Android (result via event).

- `$nonce` -- Optional nonce for replay protection (raw string, not hashed). Supported on both platforms: Android via Credential Manager, iOS via GoogleSignIn-iOS 9.x. The nonce comes back as the `nonce` claim inside the ID token -- verify it server-side.

### `SocialAuth::checkAppleCredentialState(string $userId): string`

Checks if an Apple credential is still valid. iOS only.

Returns: `'authorized'`, `'revoked'`, `'not_found'`, `'transferred'`, or `'unknown'` on iOS, and `'unsupported_platform'` on Android.

### `SocialAuth::signOut(): bool`

Signs out from Google and clears credential state. Apple has no sign-out API.

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
| `nonce` | `?string` | Always `null` -- the nonce is the `nonce` claim inside `identityToken` | Always `null` -- the nonce is the `nonce` claim inside `identityToken` |
| `state` | `?string` | Echoed | -- |
| `realUserStatus` | `?string` | `'likelyReal'` / `'unknown'` / `'unsupported'` | -- |

### Events

| Event | Payload |
|-------|---------|
| `AppleSignInCompleted` | `userId`, `identityToken`, `authorizationCode`, `email`, `givenName`, `familyName`, `displayName`, `state`, `realUserStatus` |
| `GoogleSignInCompleted` | `userId`, `identityToken`, `email`, `displayName`, `givenName`, `familyName`, `photoUrl`, `accessToken`, `authorizationCode` (iOS only -- Android Credential Manager issues neither) |
| `SignInFailed` | `provider`, `error`, `errorCode` |

Each event carries the `AuthResult` fields its provider fills (those not marked `--` in the table above) except `provider` and `nonce` (the nonce is inside `identityToken`). Optional fields the platform did not return arrive as empty strings in `#[On]` (NativePHP 4 native screens), `#[OnNative]` and `On(...)` handlers (except `accessToken` and `authorizationCode` on Android, which are not sent at all: `undefined` in `On(...)`, the parameter default in `#[On]` and `#[OnNative]`), but as `null` in Laravel listeners, because Laravel's default `ConvertEmptyStringsToNull` middleware runs on the request that delivers events to them. Native screens get events from NativePHP's event queue, where no middleware runs, so in the `#[On]` example above `$email` is `''`, not its `null` default, on every Apple sign-in after the first -- check with `!empty()` / `filled()`, which handles both, not `!== null`.

**Error codes:** `CANCELED`, `FAILED`, `INVALID_RESPONSE`, `NOT_HANDLED`, `NOT_INTERACTIVE`, `NO_AUTH_IN_KEYCHAIN`, `NO_CREDENTIAL`, `SCOPES_ALREADY_GRANTED`, `UNSUPPORTED_PLATFORM`, `MISSING_CONFIG`, `PARSE_ERROR`, `UNKNOWN`

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
// Verify: in_array($decoded->iss, ['https://accounts.google.com', 'accounts.google.com'], true)
// If you passed a nonce to googleSignIn():
// Verify: $decoded->nonce === the raw nonce your server issued for this sign-in

// Apple verification
$appleKeys = json_decode(
    file_get_contents('https://appleid.apple.com/auth/keys'), true
);
$decoded = JWT::decode($identityToken, JWK::parseKeySet($appleKeys));
// Verify: $decoded->aud === your app's bundle ID
// Verify: $decoded->iss === 'https://appleid.apple.com'
// If you passed a nonce to appleSignIn() (SHA-256 of the raw nonce):
// Verify: $decoded->nonce === hash('sha256', the raw nonce your server issued)
```

Keep the nonce where the verification runs. The Livewire example above keeps it in `session()`,
which lives in the Laravel app on the phone, where your backend cannot read it. For replay
protection, issue the nonce from your API before sign-in, pass it to `googleSignIn()` /
`appleSignIn()`, and look it up there when the token comes back.

Install the JWT library: `composer require firebase/php-jwt`

## Known issues

**`System::isIos()` / `System::isAndroid()` return `false` inside the app (NativePHP before 4.7)**

Not a fault of this plugin, but it affects any platform-conditional code written around it, such
as showing the Apple button on iOS only: before NativePHP 4.7, both helpers, and the `@ios` /
`@android` Blade directives built on them, ask the device through `Device::getInfo()` and report
`false` when it returns `null`. On NativePHP 3 it does so on every device unless the
`nativephp/mobile-device` plugin is installed, because the 3.x core registers no `Device.GetInfo`;
on 4.x before 4.7 it has been seen on the iOS simulator. Code then silently takes its "not on a
device" branch. Read
`env('NATIVEPHP_PLATFORM')` instead -- the native runtime exports it into `$_SERVER` before Laravel
boots, so it also survives `config:cache`. It is not set under `native:jump`, where PHP runs on
your computer.

NativePHP 4.7 fixed this: the helpers read `NATIVEPHP_PLATFORM` themselves and ask the device under
`native:jump`, so on 4.7+ use `System::isIos()` and `@ios` directly.

## How the iOS URL scheme is registered

GoogleSignIn-iOS will not start unless the reversed client ID is registered in `CFBundleURLTypes`,
and it reports a missing scheme by raising an uncaught `NSException` -- which terminates the app
rather than returning an error.

The plugin manifest cannot express this. `url_schemes` is **not** a key NativePHP Mobile reads
(neither 3.3.x nor 4.x), and before NativePHP 4.4.0 the supported `info_plist` route only handles
flat strings and flat arrays of strings, not the array-of-dicts `CFBundleURLTypes` requires. 4.4.0
added nested merging, but the plugin supports NativePHP 3 as well, so it uses one mechanism on every
version.

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
- Plugin versions up to 1.0.1 pinned GoogleSignIn-iOS `~> 8.0` while calling the nonce sign-in overload, which only exists in GoogleSignIn-iOS 9.0+. Upgrade the plugin together with NativePHP as described in [Upgrading](#upgrading), then run `php artisan native:install --force` so the regenerated Podfile resolves GoogleSignIn `~> 9.0`. If CocoaPods then reports a dependency conflict, another pod in your project is pinning AppAuth 1.x / GTMAppAuth 4.x -- update that dependency, since GoogleSignIn 9.x requires AppAuth 2.x and GTMAppAuth 5.x.

**"Developer console is not set up correctly" (Android)**
- Ensure you have BOTH an Android client AND a Web client in the same Google Cloud project
- The Android client must have the correct package name and SHA-1 fingerprint

**"MISSING_CONFIG" error**
- Android: no Web client ID reached Credential Manager. Check that `GOOGLE_SERVER_CLIENT_ID` is set in
  your `.env`; when you start sign-in from JavaScript, pass it as the second argument of
  `googleSignIn()` (see [JavaScript](#javascript-vue--react--inertia)).
- iOS: the built Info.plist has no `GIDClientID` or no Google URL scheme -- the `error` text says
  which. Check `GOOGLE_IOS_CLIENT_ID` (and `GOOGLE_IOS_REVERSED_CLIENT_ID` if you set it), then run
  `php artisan native:install --force` and rebuild.

**Build aborts: "Missing required plugin secrets" although the values are in `.env`**
- Run `php artisan config:clear` before building. Once `config:cache` has run, Laravel stops loading
  `.env`, so every `env()` call returns null -- and NativePHP reads plugin secrets through `env()`.
  This does not affect the built app: values reach it through config, which is why
  `GOOGLE_SERVER_CLIENT_ID` still resolves at runtime with the config cached.

**Build aborts: "Plugin ... requires Android API level 29, but your min SDK is 26"**
- Setting `NATIVEPHP_ANDROID_MIN_SDK` in `.env` as the message suggests has no effect when your
  `config/nativephp.php` has no `min_sdk` key (a NativePHP 3 config) -- only the key is read. Add
  `android.min_sdk` to that file instead; see
  [Installation](#android-raise-the-minimum-sdk).

**`appleSignIn()` / `googleSignIn()` return `null` on every platform and no events fire**
- The plugin is installed but not registered (`checkAppleCredentialState()` then returns `'unknown'`
  and `signOut()` `false`). Run `php artisan native:plugin:list` -- if it appears under "Unregistered
  Plugins", run `php artisan native:plugin:register ikromjon/nativephp-mobile-social-auth` and
  rebuild. If it reports that the `NativeServiceProvider` is not published, run
  `php artisan vendor:publish --tag=nativephp-plugins-provider` first.

**Apple Sign-In fails with `AuthorizationError error 1000` and no sheet appears**
- The entitlement is not in the built app. Check with
  `codesign -d --entitlements - /path/to/YourApp.app`; if `com.apple.developer.applesignin` is not
  listed, it was dropped. Before NativePHP 4.7 every simulator build drops it -- the simulator
  target was not signed with the app's entitlements (fixed in 4.7.0) -- so test Sign in with Apple
  on a device there. A device build must be signed by a paid team (`NATIVEPHP_DEVELOPMENT_TEAM`);
  a free Personal Team is not sufficient -- see [Requirements](#requirements).

**Code changes do not appear after `native:run`**
- Unless `NATIVEPHP_APP_VERSION` is `DEBUG` (the default, which re-extracts the bundled app on
  every launch), the app only replaces its extracted Laravel code when the version or build number
  changes, so a rebuild with the same pair keeps serving the previous build's files. Keep
  `NATIVEPHP_APP_VERSION=DEBUG` while developing or bump the build number; uninstalling
  (`xcrun simctl uninstall <udid> <your.app.id>` / `adb uninstall <your.app.id>`) also forces a
  clean extraction but deletes the app's data.

**Google Sign-In returns null on Android**
- This is expected. On Android, Google Sign-In is async. Receive the result through the events: `#[On(GoogleSignInCompleted::class)]` on a NativePHP 4 native screen, `#[OnNative(GoogleSignInCompleted::class)]` in a Livewire component.

**Apple email/name are null**
- Apple only provides email and name on the **first** sign-in. Later sign-ins still return `userId`, `identityToken` and a new `authorizationCode`, but no email or name (the email is still in the `email` claim of the `identityToken`). To reset during development: Settings > [your name] > Sign-In & Security > Sign in with Apple > your app, then stop using Sign in with Apple for it.

**App Store rejection under Guideline 4.8 (Login Services)**
- If Google sign-in sets up the user's account in your iOS app, Apple requires an equivalent login that limits data collection to name and email, lets users keep their email private, and does not track them for advertising without consent. Sign in with Apple meets all three, and this plugin provides both.

## Support

- Email: ikromjon98.98@icloud.com. Please include the plugin and NativePHP versions (`composer show ikromjon/nativephp-mobile-social-auth` and `composer show nativephp/mobile`), the platform, and the `SignInFailed` `errorCode` if there is one.

## License

This is a **commercial plugin** distributed through the official NativePHP plugin marketplace:

👉 **[nativephp.com/plugins/ikromjon/nativephp-mobile-social-auth](https://nativephp.com/plugins/ikromjon/nativephp-mobile-social-auth)**

Use is governed by the [End User License Agreement](LICENSE). Redistribution or resale is not permitted.
