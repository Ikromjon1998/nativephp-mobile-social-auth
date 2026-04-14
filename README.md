# NativePHP Mobile Social Auth

Native Apple Sign-In and Google Sign-In for NativePHP mobile apps. Provides native authentication UI (not browser-based redirects) for a seamless sign-in experience.

## Features

- **Apple Sign-In** — Native `ASAuthorizationController` on iOS (required by App Store for apps with third-party login)
- **Google Sign-In** — Native Google Sign-In SDK on iOS, Credential Manager on Android
- **Identity tokens** — JWT tokens for server-side verification
- **User info** — Name, email, profile photo
- **Nonce support** — Replay protection for both providers
- **Credential state** — Check if Apple credential is still valid
- **Events** — Livewire `#[OnNative]` and JS `On()` event listeners

## Platform Support

| Feature | iOS | Android |
|---------|-----|---------|
| Apple Sign-In | Yes | No (Apple limitation) |
| Google Sign-In | Yes | Yes |
| Credential State Check | Yes | No |
| Sign Out | Yes (Google only) | Yes (Google only) |

> Apple Sign-In is only available on iOS. On Android, calling `appleSignIn()` returns `null` and dispatches a `SignInFailed` event. Apple does not provide a native Android SDK for Sign in with Apple.

## Requirements

- PHP 8.2+
- Laravel 11, 12, or 13
- NativePHP Mobile 3.x
- iOS 18.0+
- Android API 29+

## Installation

```bash
composer require ikromjon/nativephp-mobile-social-auth
```

The service provider and facade are auto-discovered by Laravel.

## Configuration

### Google Sign-In Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create OAuth 2.0 credentials:
   - **iOS Client ID** — for the iOS app
   - **Web Client ID** — for server-side token verification (used as `serverClientId`)
3. Add to your `.env` file:

```env
GOOGLE_IOS_CLIENT_ID=your-ios-client-id.apps.googleusercontent.com
GOOGLE_SERVER_CLIENT_ID=your-web-client-id.apps.googleusercontent.com
```

### Apple Sign-In Setup

Apple Sign-In requires the `com.apple.developer.applesignin` entitlement, which is automatically added by this plugin via `nativephp.json`. Ensure your Apple Developer account has "Sign in with Apple" enabled for your App ID.

## Usage

### Livewire (Recommended)

```php
<?php

namespace App\Livewire;

use Ikromjon\NativePHP\SocialAuth\Facades\SocialAuth;
use Ikromjon\NativePHP\SocialAuth\Events\AppleSignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Events\GoogleSignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Events\SignInFailed;
use Livewire\Attributes\OnNative;
use Livewire\Component;

class LoginScreen extends Component
{
    public ?string $error = null;

    public function signInWithApple(): void
    {
        // Generate a nonce for security (hash it with SHA256 for Apple)
        $rawNonce = bin2hex(random_bytes(16));
        session(['auth_nonce' => $rawNonce]);

        $result = SocialAuth::appleSignIn(
            scopes: ['email', 'fullName'],
            nonce: hash('sha256', $rawNonce),
        );

        if ($result) {
            // First sign-in: email and name are available
            // Subsequent sign-ins: only userId and identityToken
            $this->handleAppleAuth($result);
        }
    }

    public function signInWithGoogle(): void
    {
        $nonce = bin2hex(random_bytes(16));
        session(['auth_nonce' => $nonce]);

        $result = SocialAuth::googleSignIn(nonce: $nonce);

        if ($result) {
            $this->handleGoogleAuth($result);
        }
    }

    #[OnNative(AppleSignInCompleted::class)]
    public function onAppleSignIn($payload): void
    {
        // Alternative: handle via event instead of return value
    }

    #[OnNative(GoogleSignInCompleted::class)]
    public function onGoogleSignIn($payload): void
    {
        // Alternative: handle via event instead of return value
    }

    #[OnNative(SignInFailed::class)]
    public function onSignInFailed($payload): void
    {
        if ($payload['errorCode'] !== 'CANCELED') {
            $this->error = $payload['error'];
        }
    }

    public function render()
    {
        return view('livewire.login-screen');
    }

    private function handleAppleAuth($result): void
    {
        // Verify identity token server-side with Apple's public keys
        // Create or find user, log them in
        // IMPORTANT: Apple only sends email/name on FIRST sign-in!
        // You must persist this data immediately.
    }

    private function handleGoogleAuth($result): void
    {
        // Verify identity token with Google
        // Create or find user, log them in
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

### React / Vue / Svelte (JavaScript)

```javascript
import { On, Off } from '#nativephp';
import socialAuth from 'vendor/ikromjon/nativephp-mobile-social-auth/resources/js/social-auth';

// Apple Sign-In
async function handleAppleSignIn() {
    try {
        const result = await socialAuth.appleSignIn(['email', 'fullName'], nonce);
        if (result) {
            console.log('Apple user:', result.userId);
            console.log('ID token:', result.identityToken);
            // Send token to your backend for verification
        }
    } catch (error) {
        console.error('Apple Sign-In failed:', error);
    }
}

// Google Sign-In
async function handleGoogleSignIn() {
    try {
        const result = await socialAuth.googleSignIn(nonce);
        if (result) {
            console.log('Google user:', result.email);
            console.log('ID token:', result.identityToken);
        }
    } catch (error) {
        console.error('Google Sign-In failed:', error);
    }
}

// Listen for events
On('Ikromjon\\NativePHP\\SocialAuth\\Events\\SignInFailed', (payload) => {
    if (payload.errorCode !== 'CANCELED') {
        alert(`Sign-in failed: ${payload.error}`);
    }
});

// Sign out from Google
await socialAuth.signOut();

// Check Apple credential validity
const state = await socialAuth.checkAppleCredentialState('apple-user-id');
// state: 'authorized', 'revoked', 'not_found', 'transferred', 'unknown'
```

## API Reference

### `SocialAuth::appleSignIn(array $scopes, ?string $nonce, ?string $state): ?AuthResult`

Initiates native Apple Sign-In. Returns `AuthResult` on success, `null` on failure.

**Parameters:**
- `$scopes` — Requested scopes: `['email', 'fullName']` (default: both)
- `$nonce` — SHA256-hashed nonce for replay protection
- `$state` — Optional state string echoed back in response

**Important:** Apple only returns `email` and `givenName`/`familyName` on the **first** sign-in. Subsequent sign-ins return only `userId` and `identityToken`. You must persist user info on first authentication.

### `SocialAuth::googleSignIn(?string $nonce): ?AuthResult`

Initiates native Google Sign-In. Returns `AuthResult` on success, `null` on failure.

**Parameters:**
- `$nonce` — Optional nonce for replay protection

### `SocialAuth::checkAppleCredentialState(string $userId): string`

Checks if an Apple credential is still valid. iOS only.

**Returns:** `'authorized'`, `'revoked'`, `'not_found'`, `'transferred'`, or `'unknown'`

### `SocialAuth::signOut(): bool`

Signs out from Google. Apple Sign-In has no sign-out API.

### AuthResult Data Class

| Property | Type | Description |
|----------|------|-------------|
| `provider` | `string` | `'apple'` or `'google'` |
| `userId` | `?string` | Unique user identifier |
| `identityToken` | `?string` | JWT for server-side verification |
| `authorizationCode` | `?string` | One-time code for token exchange |
| `accessToken` | `?string` | OAuth access token (Google only) |
| `email` | `?string` | User email |
| `givenName` | `?string` | First name |
| `familyName` | `?string` | Last name |
| `displayName` | `?string` | Full display name |
| `photoUrl` | `?string` | Profile photo URL (Google only) |
| `nonce` | `?string` | Echoed nonce |
| `state` | `?string` | Echoed state (Apple only) |
| `realUserStatus` | `?string` | Apple fraud detection: `'likelyReal'`, `'unknown'`, `'unsupported'` |

### Events

| Event | When | Payload |
|-------|------|---------|
| `AppleSignInCompleted` | Apple Sign-In succeeds | `userId`, `identityToken`, `authorizationCode`, `email`, `givenName`, `familyName` |
| `GoogleSignInCompleted` | Google Sign-In succeeds | `userId`, `identityToken`, `email`, `displayName`, `photoUrl` |
| `SignInFailed` | Any sign-in fails | `provider`, `error`, `errorCode` |

**Error codes:** `CANCELED`, `FAILED`, `INVALID_RESPONSE`, `NOT_HANDLED`, `NOT_INTERACTIVE`, `NO_AUTH_IN_KEYCHAIN`, `NO_CREDENTIAL`, `UNSUPPORTED_PLATFORM`, `MISSING_CONFIG`, `PARSE_ERROR`, `UNKNOWN`

## Server-Side Token Verification

The identity tokens returned by both providers are JWTs that should be verified server-side:

- **Apple:** Verify against Apple's public keys at `https://appleid.apple.com/auth/keys`
- **Google:** Verify against Google's public keys at `https://www.googleapis.com/oauth2/v3/certs`

Use a JWT library like `firebase/php-jwt` for verification.

## Support

- Issues: [GitHub Issues](https://github.com/Ikromjon1998/nativephp-mobile-social-auth/issues)
- Email: ikromjon98.98@icloud.com
