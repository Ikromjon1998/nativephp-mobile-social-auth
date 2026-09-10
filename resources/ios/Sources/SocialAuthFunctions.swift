import Foundation
import AuthenticationServices
import UIKit
import GoogleSignIn

enum SocialAuthFunctions {

    // MARK: - Apple Sign-In

    class AppleSignIn: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let scopes = parameters["scopes"] as? [String] ?? ["email", "fullName"]
            let nonce = parameters["nonce"] as? String
            let state = parameters["state"] as? String

            let provider = ASAuthorizationAppleIDProvider()
            let request = provider.createRequest()

            var requestedScopes: [ASAuthorization.Scope] = []
            if scopes.contains("email") {
                requestedScopes.append(.email)
            }
            if scopes.contains("fullName") {
                requestedScopes.append(.fullName)
            }
            request.requestedScopes = requestedScopes

            if let nonce = nonce {
                request.nonce = nonce
            }
            if let state = state {
                request.state = state
            }

            let delegate = AppleSignInDelegate()
            let controller = ASAuthorizationController(authorizationRequests: [request])
            controller.delegate = delegate

            let semaphore = DispatchSemaphore(value: 0)

            // Register the completion handler before anything can fire it.
            delegate.onComplete = {
                semaphore.signal()
            }

            DispatchQueue.main.async {
                if let windowScene = UIApplication.shared.connectedScenes.first as? UIWindowScene,
                   let window = windowScene.windows.first(where: { $0.isKeyWindow }) ?? windowScene.windows.first {
                    // `presentationContextProvider` is a weak reference: keep the provider
                    // alive on the delegate for the duration of the request, otherwise it is
                    // deallocated when this closure returns and the sheet never appears.
                    let contextProvider = AppleSignInPresentationContext(window: window)
                    delegate.contextProvider = contextProvider
                    controller.presentationContextProvider = contextProvider
                }
                controller.performRequests()
            }

            semaphore.wait()

            if let error = delegate.error {
                let errorCode: String
                if let authError = error as? ASAuthorizationError {
                    switch authError.code {
                    case .canceled:
                        errorCode = "CANCELED"
                    case .failed:
                        errorCode = "FAILED"
                    case .invalidResponse:
                        errorCode = "INVALID_RESPONSE"
                    case .notHandled:
                        errorCode = "NOT_HANDLED"
                    case .notInteractive:
                        errorCode = "NOT_INTERACTIVE"
                    default:
                        errorCode = "UNKNOWN"
                    }
                } else {
                    errorCode = "UNKNOWN"
                }

                // Dispatch failure event
                DispatchQueue.main.async {
                    LaravelBridge.shared.send?(
                        "Ikromjon\\NativePHP\\SocialAuth\\Events\\SignInFailed",
                        [
                            "provider": "apple",
                            "error": error.localizedDescription,
                            "errorCode": errorCode,
                        ]
                    )
                }

                return BridgeResponse.error(code: "APPLE_SIGN_IN_FAILED", message: error.localizedDescription)
            }

            guard let credential = delegate.credential else {
                return BridgeResponse.error(code: "APPLE_SIGN_IN_FAILED", message: "No credential received")
            }

            var result: [String: Any] = [
                "status": "success",
                "provider": "apple",
                "userId": credential.user,
            ]

            if let identityToken = credential.identityToken,
               let tokenString = String(data: identityToken, encoding: .utf8) {
                result["identityToken"] = tokenString
            }

            if let authorizationCode = credential.authorizationCode,
               let codeString = String(data: authorizationCode, encoding: .utf8) {
                result["authorizationCode"] = codeString
            }

            if let email = credential.email {
                result["email"] = email
            }

            if let fullName = credential.fullName {
                if let givenName = fullName.givenName {
                    result["givenName"] = givenName
                }
                if let familyName = fullName.familyName {
                    result["familyName"] = familyName
                }
                let displayName = [fullName.givenName, fullName.familyName]
                    .compactMap { $0 }
                    .joined(separator: " ")
                if !displayName.isEmpty {
                    result["displayName"] = displayName
                }
            }

            switch credential.realUserStatus {
            case .likelyReal:
                result["realUserStatus"] = "likelyReal"
            case .unknown:
                result["realUserStatus"] = "unknown"
            case .unsupported:
                result["realUserStatus"] = "unsupported"
            @unknown default:
                result["realUserStatus"] = "unknown"
            }

            if let state = credential.state {
                result["state"] = state
            }

            // Dispatch success event
            DispatchQueue.main.async {
                LaravelBridge.shared.send?(
                    "Ikromjon\\NativePHP\\SocialAuth\\Events\\AppleSignInCompleted",
                    [
                        "userId": credential.user,
                        "identityToken": result["identityToken"] as? String ?? "",
                        "authorizationCode": result["authorizationCode"] as? String ?? "",
                        "email": result["email"] as? String ?? "",
                        "givenName": result["givenName"] as? String ?? "",
                        "familyName": result["familyName"] as? String ?? "",
                        "displayName": result["displayName"] as? String ?? "",
                        "state": result["state"] as? String ?? "",
                        "realUserStatus": result["realUserStatus"] as? String ?? "",
                    ]
                )
            }

            return BridgeResponse.success(data: result)
        }
    }

    // MARK: - Browser-based OAuth

    /// Authorization-code flow for providers with no native SDK.
    ///
    /// ASWebAuthenticationSession is the only sanctioned way to do this on iOS:
    /// it shares the Safari cookie jar, so an already-signed-in user is not asked
    /// to type a password again, and Apple rejects apps that collect third-party
    /// credentials in an embedded WKWebView.
    ///
    /// No tokens are returned. Redeeming the code needs a client secret, which
    /// cannot live in an app binary, so the code and its PKCE verifier go back to
    /// PHP and on to the app's own server.
    class OAuthSignIn: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let provider = parameters["provider"] as? String ?? "oauth"

            guard let authorizeUrl = parameters["authorizeUrl"] as? String,
                  let clientId = parameters["clientId"] as? String,
                  let redirectUri = parameters["redirectUri"] as? String else {
                return Self.fail(provider, "MISSING_CONFIG", "authorizeUrl, clientId and redirectUri are all required.")
            }

            let state = parameters["state"] as? String
            let codeVerifier = parameters["codeVerifier"] as? String

            guard let callbackScheme = Self.callbackScheme(redirectUri) else {
                return Self.fail(provider, "MISSING_CONFIG", "redirectUri \(redirectUri) has no scheme to listen on.")
            }

            guard let url = Self.buildAuthorizeUrl(parameters, authorizeUrl: authorizeUrl,
                                                   clientId: clientId, redirectUri: redirectUri) else {
                return Self.fail(provider, "INVALID_PARAMS", "authorizeUrl \(authorizeUrl) is not a valid URL.")
            }

            let semaphore = DispatchSemaphore(value: 0)
            var callbackUrl: URL?
            var sessionError: Error?

            // Held outside the closure: ASWebAuthenticationSession keeps only a
            // weak reference to its presentation context provider, and the Apple
            // Sign-In sheet already went missing once for exactly this reason.
            var contextProvider: OAuthPresentationContext?
            var session: ASWebAuthenticationSession?

            DispatchQueue.main.async {
                session = ASWebAuthenticationSession(
                    url: url,
                    callbackURLScheme: callbackScheme
                ) { url, error in
                    callbackUrl = url
                    sessionError = error
                    semaphore.signal()
                }

                contextProvider = OAuthPresentationContext()
                session?.presentationContextProvider = contextProvider
                // Without this the flow reuses the Safari session silently, which
                // makes "sign in as a different user" impossible.
                session?.prefersEphemeralWebBrowserSession = false
                session?.start()
            }

            semaphore.wait()

            // Keep both alive until the flow has finished.
            _ = contextProvider
            _ = session

            if let error = sessionError {
                if (error as? ASWebAuthenticationSessionError)?.code == .canceledLogin {
                    return Self.fail(provider, "CANCELED", error.localizedDescription)
                }

                return Self.fail(provider, "OAUTH_FAILED", error.localizedDescription)
            }

            guard let callbackUrl = callbackUrl else {
                return Self.fail(provider, "INVALID_RESPONSE", "The browser returned no callback URL.")
            }

            let query = Self.queryItems(callbackUrl)

            // Providers report a refusal in the redirect rather than as a failure.
            if let providerError = query["error"] {
                let description = query["error_description"] ?? providerError

                if providerError == "access_denied" {
                    return Self.fail(provider, "CANCELED", description)
                }

                return Self.fail(provider, "OAUTH_FAILED", description)
            }

            guard let code = query["code"], !code.isEmpty else {
                return Self.fail(provider, "INVALID_RESPONSE", "The callback carried no authorization code.")
            }

            // Compared here because this is the only place holding both values.
            // A mismatch means the response is not the one this app asked for.
            if let expected = state, !expected.isEmpty, query["state"] != expected {
                return Self.fail(provider, "STATE_MISMATCH", "The state returned by the provider did not match the one sent.")
            }

            DispatchQueue.main.async {
                LaravelBridge.shared.send?(
                    "Ikromjon\\NativePHP\\SocialAuth\\Events\\AuthorizationCodeReceived",
                    [
                        "provider": provider,
                        "authorizationCode": code,
                        "codeVerifier": codeVerifier ?? "",
                        "state": query["state"] ?? "",
                        "redirectUri": redirectUri,
                    ]
                )
            }

            return BridgeResponse.success(data: [
                "status": "success",
                "provider": provider,
                "authorizationCode": code,
                "state": query["state"] ?? "",
            ])
        }

        /// ASWebAuthenticationSession matches the callback on scheme alone.
        static func callbackScheme(_ redirectUri: String) -> String? {
            guard let scheme = URL(string: redirectUri)?.scheme, !scheme.isEmpty else {
                return nil
            }

            // An https redirect is a server round-trip (Apple's form_post flow),
            // which this session cannot intercept.
            return (scheme == "http" || scheme == "https") ? nil : scheme
        }

        static func buildAuthorizeUrl(_ parameters: [String: Any], authorizeUrl: String,
                                      clientId: String, redirectUri: String) -> URL? {
            guard var components = URLComponents(string: authorizeUrl) else {
                return nil
            }

            var items = components.queryItems ?? []
            items.append(URLQueryItem(name: "response_type", value: "code"))
            items.append(URLQueryItem(name: "client_id", value: clientId))
            items.append(URLQueryItem(name: "redirect_uri", value: redirectUri))

            if let scopes = parameters["scopes"] as? [String], !scopes.isEmpty {
                items.append(URLQueryItem(name: "scope", value: scopes.joined(separator: " ")))
            }
            if let state = parameters["state"] as? String, !state.isEmpty {
                items.append(URLQueryItem(name: "state", value: state))
            }
            if let challenge = parameters["codeChallenge"] as? String, !challenge.isEmpty {
                items.append(URLQueryItem(name: "code_challenge", value: challenge))
                items.append(URLQueryItem(name: "code_challenge_method",
                                          value: parameters["codeChallengeMethod"] as? String ?? "S256"))
            }
            if let extra = parameters["extraParams"] as? [String: Any] {
                for (key, value) in extra {
                    items.append(URLQueryItem(name: key, value: String(describing: value)))
                }
            }

            components.queryItems = items

            return components.url
        }

        /// Reads the callback's query, falling back to the fragment for the
        /// providers that answer there instead.
        static func queryItems(_ url: URL) -> [String: String] {
            var found: [String: String] = [:]

            let components = URLComponents(url: url, resolvingAgainstBaseURL: false)

            for item in components?.queryItems ?? [] {
                found[item.name] = item.value
            }

            if found.isEmpty, let fragment = components?.fragment {
                for pair in fragment.split(separator: "&") {
                    let parts = pair.split(separator: "=", maxSplits: 1)
                    if parts.count == 2 {
                        found[String(parts[0])] = String(parts[1]).removingPercentEncoding
                    }
                }
            }

            return found
        }

        static func fail(_ provider: String, _ code: String, _ message: String) -> [String: Any] {
            DispatchQueue.main.async {
                LaravelBridge.shared.send?(
                    "Ikromjon\\NativePHP\\SocialAuth\\Events\\SignInFailed",
                    ["provider": provider, "error": message, "errorCode": code]
                )
            }

            return BridgeResponse.error(code: code, message: message)
        }
    }

    // MARK: - Google Sign-In

    class GoogleSignIn: BridgeFunction {
        /// Why this exists: GIDSignIn reports missing configuration by raising an
        /// Objective-C NSException, which Swift cannot catch, so the process
        /// aborts and the user simply loses the app. Checking first turns that
        /// into an ordinary SignInFailed event.
        static func configurationError() -> String? {
            guard let clientId = Bundle.main.object(forInfoDictionaryKey: "GIDClientID") as? String,
                  !clientId.isEmpty else {
                return "GIDClientID is missing from Info.plist. Set GOOGLE_IOS_CLIENT_ID in your .env "
                    + "and re-run: php artisan native:install --force"
            }

            let expected = "com.googleusercontent.apps."
                + clientId.replacingOccurrences(of: ".apps.googleusercontent.com", with: "")

            let urlTypes = Bundle.main.object(forInfoDictionaryKey: "CFBundleURLTypes") as? [[String: Any]] ?? []

            for urlType in urlTypes {
                if let schemes = urlType["CFBundleURLSchemes"] as? [String], schemes.contains(expected) {
                    return nil
                }
            }

            return "The URL scheme \(expected) is not registered in CFBundleURLTypes, so Google Sign-In "
                + "cannot start. Set GOOGLE_IOS_REVERSED_CLIENT_ID in your .env and re-run: "
                + "php artisan native:install --force"
        }

        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let nonce = parameters["nonce"] as? String

            if let message = Self.configurationError() {
                DispatchQueue.main.async {
                    LaravelBridge.shared.send?(
                        "Ikromjon\\NativePHP\\SocialAuth\\Events\\SignInFailed",
                        [
                            "provider": "google",
                            "error": message,
                            "errorCode": "MISSING_CONFIG",
                        ]
                    )
                }

                return BridgeResponse.error(code: "MISSING_CONFIG", message: message)
            }

            let semaphore = DispatchSemaphore(value: 0)
            var signInResult: GIDSignInResult?
            var signInError: Error?

            DispatchQueue.main.async {
                guard let windowScene = UIApplication.shared.connectedScenes.first as? UIWindowScene,
                      let rootViewController = windowScene.windows.first?.rootViewController else {
                    signInError = NSError(
                        domain: "SocialAuth",
                        code: -1,
                        userInfo: [NSLocalizedDescriptionKey: "No root view controller found"]
                    )
                    semaphore.signal()
                    return
                }

                // The nonce overload requires GoogleSignIn-iOS >= 9.0 (pinned in
                // nativephp.json); passing nil behaves like the base overload.
                GIDSignIn.sharedInstance.signIn(withPresenting: rootViewController, hint: nil, additionalScopes: nil, nonce: nonce) { result, error in
                    signInResult = result
                    signInError = error
                    semaphore.signal()
                }
            }

            semaphore.wait()

            if let error = signInError {
                let errorCode: String
                let gidError = error as NSError
                switch gidError.code {
                case GIDSignInError.canceled.rawValue:
                    errorCode = "CANCELED"
                case GIDSignInError.hasNoAuthInKeychain.rawValue:
                    errorCode = "NO_AUTH_IN_KEYCHAIN"
                case GIDSignInError.scopesAlreadyGranted.rawValue:
                    errorCode = "SCOPES_ALREADY_GRANTED"
                default:
                    errorCode = "UNKNOWN"
                }

                DispatchQueue.main.async {
                    LaravelBridge.shared.send?(
                        "Ikromjon\\NativePHP\\SocialAuth\\Events\\SignInFailed",
                        [
                            "provider": "google",
                            "error": error.localizedDescription,
                            "errorCode": errorCode,
                        ]
                    )
                }

                return BridgeResponse.error(code: "GOOGLE_SIGN_IN_FAILED", message: error.localizedDescription)
            }

            guard let result = signInResult else {
                return BridgeResponse.error(code: "GOOGLE_SIGN_IN_FAILED", message: "No sign-in result received")
            }

            let user = result.user
            var responseData: [String: Any] = [
                "status": "success",
                "provider": "google",
            ]

            if let userId = user.userID {
                responseData["userId"] = userId
            }

            if let idToken = user.idToken?.tokenString {
                responseData["identityToken"] = idToken
            }

            responseData["accessToken"] = user.accessToken.tokenString

            if let profile = user.profile {
                responseData["email"] = profile.email
                responseData["displayName"] = profile.name
                responseData["givenName"] = profile.givenName
                responseData["familyName"] = profile.familyName
                if profile.hasImage {
                    let dimension: UInt = 200
                    if let imageURL = profile.imageURL(withDimension: dimension) {
                        responseData["photoUrl"] = imageURL.absoluteString
                    }
                }
            }

            if let serverAuthCode = result.serverAuthCode {
                responseData["authorizationCode"] = serverAuthCode
            }

            DispatchQueue.main.async {
                LaravelBridge.shared.send?(
                    "Ikromjon\\NativePHP\\SocialAuth\\Events\\GoogleSignInCompleted",
                    [
                        "userId": responseData["userId"] as? String ?? "",
                        "identityToken": responseData["identityToken"] as? String ?? "",
                        "email": responseData["email"] as? String ?? "",
                        "displayName": responseData["displayName"] as? String ?? "",
                        "givenName": responseData["givenName"] as? String ?? "",
                        "familyName": responseData["familyName"] as? String ?? "",
                        "photoUrl": responseData["photoUrl"] as? String ?? "",
                        "accessToken": responseData["accessToken"] as? String ?? "",
                        "authorizationCode": responseData["authorizationCode"] as? String ?? "",
                    ]
                )
            }

            return BridgeResponse.success(data: responseData)
        }
    }

    // MARK: - Check Apple Credential State

    class CheckAppleCredentialState: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let userId = parameters["userId"] as? String else {
                return BridgeResponse.error(code: "INVALID_PARAMS", message: "userId is required")
            }

            let provider = ASAuthorizationAppleIDProvider()
            let semaphore = DispatchSemaphore(value: 0)
            var credentialState: String = "unknown"

            provider.getCredentialState(forUserID: userId) { state, error in
                if error != nil {
                    credentialState = "unknown"
                } else {
                    switch state {
                    case .authorized:
                        credentialState = "authorized"
                    case .revoked:
                        credentialState = "revoked"
                    case .notFound:
                        credentialState = "not_found"
                    case .transferred:
                        credentialState = "transferred"
                    @unknown default:
                        credentialState = "unknown"
                    }
                }
                semaphore.signal()
            }

            semaphore.wait()

            return BridgeResponse.success(data: ["state": credentialState])
        }
    }

    // MARK: - Sign Out

    class SignOut: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            GIDSignIn.sharedInstance.signOut()
            return BridgeResponse.success(data: ["signedOut": true])
        }
    }
}

// MARK: - Apple Sign-In Helpers

private class AppleSignInDelegate: NSObject, ASAuthorizationControllerDelegate {
    var credential: ASAuthorizationAppleIDCredential?
    var error: Error?
    var onComplete: (() -> Void)?
    var contextProvider: AppleSignInPresentationContext?

    func authorizationController(controller: ASAuthorizationController, didCompleteWithAuthorization authorization: ASAuthorization) {
        if let appleIDCredential = authorization.credential as? ASAuthorizationAppleIDCredential {
            self.credential = appleIDCredential
        }
        onComplete?()
    }

    func authorizationController(controller: ASAuthorizationController, didCompleteWithError error: Error) {
        self.error = error
        onComplete?()
    }
}

private class AppleSignInPresentationContext: NSObject, ASAuthorizationControllerPresentationContextProviding {
    let window: UIWindow

    init(window: UIWindow) {
        self.window = window
    }

    func presentationAnchor(for controller: ASAuthorizationController) -> ASPresentationAnchor {
        return window
    }
}

/// Supplies the window ASWebAuthenticationSession presents from.
///
/// Retained by the caller for the life of the request: the session holds this
/// only weakly, and a deallocated provider means the sheet never appears.
class OAuthPresentationContext: NSObject, ASWebAuthenticationPresentationContextProviding {
    func presentationAnchor(for session: ASWebAuthenticationSession) -> ASPresentationAnchor {
        guard let windowScene = UIApplication.shared.connectedScenes.first as? UIWindowScene else {
            return ASPresentationAnchor()
        }

        return windowScene.windows.first(where: { $0.isKeyWindow })
            ?? windowScene.windows.first
            ?? ASPresentationAnchor()
    }
}
