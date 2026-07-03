# Changelog

All notable changes to this project will be documented in this file.

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
