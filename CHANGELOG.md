# Changelog

All notable changes to this project will be documented in this file.

## [1.1.1] - 2026-11-22

### Fixed
- Events are no longer refused on NativePHP Mobile 3.3.11 / 4.6.1+ (implement DispatchableFromNative, GHSA-83jv-8h7x-287p)
- Android: `googleSignIn()` called from JavaScript failed with `MISSING_CONFIG`, because only the PHP facade sent the Web client ID that Credential Manager needs. The JavaScript function now takes it as a second argument, `googleSignIn(nonce, import.meta.env.VITE_GOOGLE_SERVER_CLIENT_ID)`; iOS keeps reading `GIDServerClientID` from Info.plist.

### Added
- Laravel Boost guidelines (`resources/boost/guidelines/core.blade.php`), so AI coding assistants use the plugin the way NativePHP 4 expects: install and upgrade steps, configuration, `#[On]` on native screens (and Livewire or JavaScript in web-view apps), every event field and error code, server-side token verification, and `Native::test()` tests. `php artisan boost:install` loads them into an app that uses Boost. The tests check the guidelines against the code, and run the examples they teach.
- Marketplace metadata in `nativephp.json` (name, description, keywords, category, license, author, homepage, platforms), in the format `native:plugin:create` scaffolds. The description in `nativephp.json` and `composer.json` now names login and OAuth, which buyers search for.

### Changed
- Requires nativephp/mobile ^3.3.11|^4.6.1

### Documentation
- README: NativePHP 3.x and 4.x side by side. Native screens (`NativeComponent` with `#[On]`, tested with `Native::test()`) are the recommended context; the Livewire example is for web-view apps. The tests run the README's native-screen class, view and test as printed.
- Version-specific details:
  - The Android minimum-SDK step applies only to a `config/nativephp.php` without `min_sdk` (NativePHP 3's, or one kept from it).
  - `System::isIos()` / `@ios` are reliable from 4.7, and on NativePHP 3 need the `nativephp/mobile-device` plugin.
  - Native events are signed from 3.3.12 / 4.8.
  - Simulator builds drop the Sign in with Apple entitlement before 4.7.
- App Store Guideline 4.8 no longer names Sign in with Apple: it asks for an equivalent login that limits data collection to name and email, keeps the email private and does not track for advertising without consent. Sign in with Apple still satisfies it.
- Corrected API details:
  - what later Apple sign-ins return, and that `appleSignIn()` / `googleSignIn()` return `null` on cancel or failure on iOS;
  - the full `realUserStatus` and `checkAppleCredentialState()` values, and that `AuthResult::$nonce` is always `null`;
  - empty fields in `#[On]` handlers;
  - three Google OAuth clients and the required build secrets, and the paid Apple membership for every iOS build;
  - the JavaScript import map, and verifying the nonce on your server.

### Upgrade notes
- Since [GHSA-83jv-8h7x-287p](https://github.com/NativePHP/mobile-air/security/advisories/GHSA-83jv-8h7x-287p), `POST /_native/api/events` answers `{"success": false}` for any class that does not implement `Native\Mobile\Events\Contracts\DispatchableFromNative`. With 1.1.0 on a patched core, `AppleSignInCompleted`, `GoogleSignInCompleted` and `SignInFailed` were refused there, so Laravel listeners (`Event::listen`, event subscribers) on web-view pages never ran. Livewire `#[OnNative]` and JavaScript `On(...)` handlers still fired, because the web view hands events to them directly, and so did `#[On]` on NativePHP 4 native screens.
- The marker interface only exists from nativephp/mobile 3.3.11 and 4.6.1, so require both packages together, then rebuild: `composer require "nativephp/mobile:^4.6.1" "ikromjon/nativephp-mobile-social-auth:^1.1.1" -W` (on NativePHP 3, `"nativephp/mobile:^3.3.11"`) and `php artisan native:install --force`. Updating either package on its own can leave 1.1.0 installed without an error: `composer update nativephp/mobile` installs a patched NativePHP next to plugin 1.1.0, and a plain `composer require` / `composer update` of the plugin falls back to 1.1.0 when the app's constraint (for example `~4.5.0`) or its lock file holds NativePHP below 3.3.11 / 4.6.1, so the next NativePHP update lands next to 1.1.0.
- No event names or payloads changed, and PHP apps need no configuration change. JavaScript apps that sign in with Google on Android add `VITE_GOOGLE_SERVER_CLIENT_ID="${GOOGLE_SERVER_CLIENT_ID}"` to `.env` and pass it as the second argument of `googleSignIn()`.

## [1.1.0] - 2026-09-05

### Fixed
- iOS: Google Sign-In terminated the app instead of starting. The `url_schemes` manifest key added in 1.1.0-beta.2 is not read by NativePHP Mobile (3.3.x or 4.x), so the reversed client ID was never registered and `GIDSignIn` raised an uncaught `NSException`. The scheme is now written by a `post_compile` hook (`social-auth:register-url-scheme`), which patches every Info.plist in the generated project -- including `NativePHP-simulator-Info.plist`, which the simulator target builds against.
- iOS: a missing or misconfigured URL scheme now dispatches `SignInFailed` with `MISSING_CONFIG` instead of crashing. Swift cannot catch the Objective-C exception `GIDSignIn` raises, so the bridge checks `CFBundleURLTypes` before calling it.
- `signOut()` always returned `false`, and `checkAppleCredentialState()` always returned `'unknown'`, on a real device. Both read the response as `$decoded['data'][...]`, but the native layer builds responses with `BridgeResponse.success(data:)`, which returns the payload flat with no `data` envelope. Both shapes are now accepted. Confirmed on an iPhone 17 simulator: `signOut()` now returns `true`.

### Changed
- `GOOGLE_IOS_REVERSED_CLIENT_ID` is now **optional** and derived from `GOOGLE_IOS_CLIENT_ID` when not set. It was introduced as a required secret in 1.1.0-beta.2, which would have failed the build of every existing app that upgraded without adding a new `.env` line. Set it explicitly only if your reversed ID differs from the derived form.
- Documented the install steps NativePHP requires but the README omitted: publishing and registering the plugin in `NativeServiceProvider`, and setting `android.min_sdk` to 29 in a published `config/nativephp.php` (the `NATIVEPHP_ANDROID_MIN_SDK` variable NativePHP's own error suggests is not read by anything).

### Upgrade notes
- No configuration changes are required. Run `php artisan native:install --force` and rebuild so the URL scheme is registered.
- `signOut()` and `checkAppleCredentialState()` previously returned `false` and `'unknown'` unconditionally. Code written around those constant values will now see real results.
- 1.1.0-beta.2 was never tagged, so no released version ever shipped the `url_schemes` regression.

## [1.1.0-beta.2] - 2026-09-04

### Fixed
- iOS: Google Sign-In could not start. The manifest registered the raw `GOOGLE_IOS_CLIENT_ID` as the app's URL scheme, but GoogleSignIn-iOS requires the *reversed* client ID (`com.googleusercontent.apps.<id>`) and raises `Your app is missing support for the following URL schemes` otherwise. A new required secret `GOOGLE_IOS_REVERSED_CLIENT_ID` is now used for the URL scheme.
- iOS: the Apple Sign-In sheet could fail to appear. `ASAuthorizationController.presentationContextProvider` is a weak reference and the provider object was a closure-local, so it was deallocated before the system asked for a presentation anchor. It is now retained for the duration of the request, and the key window is preferred as the anchor. The completion handler is also registered before the request is dispatched.
- Android: `SignOut` reported `signedOut: true` when clearing the credential state timed out after 5 s. It now returns a `SIGN_OUT_TIMEOUT` error.

### Added
- `AppleSignInCompleted` now also carries `displayName`, `state` and `realUserStatus`; `GoogleSignInCompleted` now also carries `accessToken` and `authorizationCode` (iOS only -- Android Credential Manager issues neither). With events as the single documented handling path, they no longer lose data that the synchronous `AuthResult` return had.

### Changed
- `nativephp/mobile` requirement widened to `^3.0|^4.0`; the v4 upgrade guide lists no changes affecting third-party plugins.

### Upgrade notes
- Add `GOOGLE_IOS_REVERSED_CLIENT_ID` to your `.env` (Google Cloud Console shows it as "iOS URL scheme" on the iOS OAuth client) and run `php artisan native:install --force`.

## [1.1.0-beta.1] - 2026-07-17

### Fixed
- iOS: Google ID tokens are now issued with `aud` = `GOOGLE_SERVER_CLIENT_ID` (added `GIDServerClientID` to the generated Info.plist), matching Android and the README's server-side verification instructions. Previously iOS tokens carried the iOS client ID as `aud`, so verification that worked on Android failed on iOS. This also makes GoogleSignIn-iOS populate `serverAuthCode` (returned as `authorizationCode`), which was always missing on iOS before.

- Android: `GoogleSignInCompleted` never reached PHP. The native payload carried a `provider` key that the event constructor does not declare, and NativePHP dispatches events via `new $event(...$payload)` — so every successful Android Google sign-in died with `Unknown named parameter $provider`, which the NativePHP bridge swallows silently. The key is now omitted, making the Android payload identical to the iOS one. This matters especially alongside the events-only guidance below, which relies on the event firing on both platforms.

- `GOOGLE_SERVER_CLIENT_ID` was read with a raw `env()` call at runtime, which returns null once config is cached (`php artisan config:cache` / `optimize`) — producing `MISSING_CONFIG` errors only in production builds. It is now read from the plugin's mergeable `config/social-auth.php`; `config('services.google.client_id')` is still honored as a fallback for existing setups.

### Added
- Publishable config file: `php artisan vendor:publish --tag=social-auth-config`.

### Changed
- README examples now handle sign-in results exclusively via events — the single path that works identically on iOS and Android. The previous examples handled both the iOS synchronous return and the events, which ran sign-in handlers twice on iOS. (Docs and phpdoc only; native behavior is unchanged.)

### Upgrade notes
- Run `php artisan native:install --force` after upgrading — the manifest change adds `GIDServerClientID` to the regenerated Info.plist.
- Server-side: iOS clients still on plugin ≤ 1.0.2 send Google ID tokens with `aud` = the iOS client ID; temporarily accept both audiences during rollout if you have existing installs.

## [1.0.2] - 2026-07-03

### Fixed
- iOS build failure `error: extra arguments at positions #4, #5 in call` when compiling `SocialAuthFunctions.swift` ([#1](https://github.com/Ikromjon1998/nativephp-mobile-social-auth/issues/1)). The plugin called `signIn(withPresenting:hint:additionalScopes:nonce:completion:)`, but no stable GoogleSignIn-iOS 8.x release exposes a nonce parameter — that overload first shipped in GoogleSignIn-iOS 9.0.0. The pod requirement is now `~> 9.0`, so Google Sign-In on iOS compiles again and keeps full nonce support (parity with Android).

### Changed
- GoogleSignIn-iOS pod requirement bumped from `~> 8.0` to `~> 9.0`. This transitively bumps AppAuth to 2.x and GTMAppAuth to 5.x; run `php artisan native:install --force` after upgrading so the Podfile is regenerated.
- iOS Google Sign-In now always uses the single `signIn(withPresenting:hint:additionalScopes:nonce:completion:)` call; passing no nonce behaves exactly like the base overload.

## [1.0.1] - 2026-04-17

### Fixed
- `GOOGLE_SERVER_CLIENT_ID` is read from Laravel config/`.env` at runtime and passed from PHP to the native layer, so no manual Android string resources are needed.

## [1.0.0] - 2026-04-17

### Changed
- Bridge API signatures aligned with NativePHP SDK patterns; general plugin quality and developer-experience improvements.
- PHP requirement bumped from `^8.2` to `^8.3` to match `nativephp/mobile`.

### Fixed
- Android: stable `userId` extracted from the ID token `sub` claim instead of using the email address.
- Android: `SignOut` reports an error when clearing the credential state fails.
- iOS: nonce passed through to Google Sign-In (this introduced the GoogleSignIn 8.x build failure later fixed in 1.0.2).

## [0.1.0] - 2026-04-14

### Added
- Native Apple Sign-In (iOS) using AuthenticationServices framework
- Native Google Sign-In using Credential Manager (Android) and Google Sign-In SDK (iOS)
- Apple credential state checking (authorized, revoked, not_found)
- Google sign-out support
- Event-driven architecture: AppleSignInCompleted, GoogleSignInCompleted, SignInFailed
- AuthResult data class with 13 fields (provider, userId, identityToken, etc.)
- PHP Facade for clean API access
- JavaScript bridge for SPA frameworks (Vue, React, Inertia)
- Nonce and state parameter support for replay protection
- Platform-specific graceful degradation (Apple Sign-In on Android returns UNSUPPORTED_PLATFORM)
