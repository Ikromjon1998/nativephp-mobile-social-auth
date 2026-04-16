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

            DispatchQueue.main.async {
                if let windowScene = UIApplication.shared.connectedScenes.first as? UIWindowScene,
                   let window = windowScene.windows.first {
                    let contextProvider = AppleSignInPresentationContext(window: window)
                    controller.presentationContextProvider = contextProvider
                }
                controller.performRequests()
            }

            delegate.onComplete = {
                semaphore.signal()
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
                    ]
                )
            }

            return BridgeResponse.success(data: result)
        }
    }

    // MARK: - Google Sign-In

    class GoogleSignIn: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let nonce = parameters["nonce"] as? String

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

                GIDSignIn.sharedInstance.signIn(withPresenting: rootViewController) { result, error in
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
