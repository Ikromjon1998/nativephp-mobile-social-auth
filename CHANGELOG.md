# Changelog

All notable changes to this project will be documented in this file.

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
