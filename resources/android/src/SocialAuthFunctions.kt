package com.ikromjon.plugins.socialauth

import android.net.Uri
import android.os.Handler
import android.os.Looper
import androidx.credentials.CredentialManager
import androidx.credentials.GetCredentialRequest
import androidx.credentials.GetCredentialResponse
import androidx.credentials.exceptions.GetCredentialCancellationException
import androidx.credentials.exceptions.GetCredentialException
import androidx.credentials.exceptions.NoCredentialException
import androidx.browser.customtabs.CustomTabsIntent
import androidx.fragment.app.FragmentActivity
import com.google.android.libraries.identity.googleid.GetGoogleIdOption
import com.google.android.libraries.identity.googleid.GoogleIdTokenCredential
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.utils.NativeActionCoordinator
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import org.json.JSONObject

object SocialAuthFunctions {

    // Apple Sign-In is not supported natively on Android
    class AppleSignIn(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val payload = JSONObject().apply {
                put("provider", "apple")
                put("error", "Apple Sign-In is not available on Android")
                put("errorCode", "UNSUPPORTED_PLATFORM")
            }
            Handler(Looper.getMainLooper()).post {
                NativeActionCoordinator.dispatchEvent(
                    activity,
                    "Ikromjon\\NativePHP\\SocialAuth\\Events\\SignInFailed",
                    payload.toString()
                )
            }

            return BridgeResponse.error(
                "UNSUPPORTED_PLATFORM",
                "Apple Sign-In is not available on Android. Use Google Sign-In instead."
            )
        }
    }

    class GoogleSignIn(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val nonce = parameters["nonce"] as? String

            // Read server client ID: prefer parameter from PHP, fall back to Android resources/meta-data
            val serverClientId = (parameters["serverClientId"] as? String)?.takeIf { it.isNotEmpty() }
                ?: getServerClientId()
            if (serverClientId.isNullOrEmpty()) {
                val payload = JSONObject().apply {
                    put("provider", "google")
                    put("error", "GOOGLE_SERVER_CLIENT_ID is not configured")
                    put("errorCode", "MISSING_CONFIG")
                }
                Handler(Looper.getMainLooper()).post {
                    NativeActionCoordinator.dispatchEvent(
                        activity,
                        "Ikromjon\\NativePHP\\SocialAuth\\Events\\SignInFailed",
                        payload.toString()
                    )
                }
                return BridgeResponse.error(
                    "MISSING_CONFIG",
                    "GOOGLE_SERVER_CLIENT_ID is not configured. Add it to your .env file."
                )
            }

            val credentialManager = CredentialManager.create(activity)

            val googleIdOptionBuilder = GetGoogleIdOption.Builder()
                .setFilterByAuthorizedAccounts(false)
                .setServerClientId(serverClientId)

            if (nonce != null) {
                googleIdOptionBuilder.setNonce(nonce)
            }

            val googleIdOption = googleIdOptionBuilder.build()

            val request = GetCredentialRequest.Builder()
                .addCredentialOption(googleIdOption)
                .build()

            // Launch async — return immediately, deliver result via events
            CoroutineScope(Dispatchers.Main).launch {
                try {
                    val response = credentialManager.getCredential(
                        context = activity,
                        request = request
                    )

                    val googleIdTokenCredential = GoogleIdTokenCredential.createFrom(response.credential.data)

                    // .id is the user's email; use idToken subject as stable userId
                    val email = googleIdTokenCredential.id
                    val stableUserId = try {
                        val parts = googleIdTokenCredential.idToken.split(".")
                        if (parts.size >= 2) {
                            val payload = String(android.util.Base64.decode(parts[1], android.util.Base64.URL_SAFE or android.util.Base64.NO_WRAP))
                            JSONObject(payload).optString("sub", email)
                        } else email
                    } catch (_: Exception) { email }

                    val eventPayload = JSONObject().apply {
                        // No "provider" key: the payload is spread as named arguments into
                        // GoogleSignInCompleted, which declares none. Keep this matching iOS.
                        put("userId", stableUserId)
                        put("identityToken", googleIdTokenCredential.idToken)
                        put("email", email)
                        put("displayName", googleIdTokenCredential.displayName ?: "")
                        put("givenName", googleIdTokenCredential.givenName ?: "")
                        put("familyName", googleIdTokenCredential.familyName ?: "")
                        put("photoUrl", googleIdTokenCredential.profilePictureUri?.toString() ?: "")
                    }
                    NativeActionCoordinator.dispatchEvent(
                        activity,
                        "Ikromjon\\NativePHP\\SocialAuth\\Events\\GoogleSignInCompleted",
                        eventPayload.toString()
                    )
                } catch (e: GetCredentialException) {
                    val errorCode = when (e) {
                        is GetCredentialCancellationException -> "CANCELED"
                        is NoCredentialException -> "NO_CREDENTIAL"
                        else -> "UNKNOWN"
                    }
                    val payload = JSONObject().apply {
                        put("provider", "google")
                        put("error", e.message ?: "Google Sign-In failed")
                        put("errorCode", errorCode)
                    }
                    NativeActionCoordinator.dispatchEvent(
                        activity,
                        "Ikromjon\\NativePHP\\SocialAuth\\Events\\SignInFailed",
                        payload.toString()
                    )
                } catch (e: Exception) {
                    val payload = JSONObject().apply {
                        put("provider", "google")
                        put("error", "Failed to parse Google credential: ${e.message}")
                        put("errorCode", "PARSE_ERROR")
                    }
                    NativeActionCoordinator.dispatchEvent(
                        activity,
                        "Ikromjon\\NativePHP\\SocialAuth\\Events\\SignInFailed",
                        payload.toString()
                    )
                }
            }

            // Return immediately — result comes via events
            return BridgeResponse.success(mapOf("status" to "pending", "provider" to "google"))
        }

        private fun getServerClientId(): String? {
            // Read from string resources (app provides via res/values/strings.xml)
            try {
                val resId = activity.resources.getIdentifier(
                    "google_server_client_id", "string", activity.packageName
                )
                if (resId != 0) {
                    val value = activity.getString(resId)
                    if (value.isNotEmpty()) return value
                }
            } catch (_: Exception) {}

            // Fallback: read from Android meta-data
            try {
                val appInfo = activity.packageManager.getApplicationInfo(
                    activity.packageName,
                    android.content.pm.PackageManager.GET_META_DATA
                )
                val metaData = appInfo.metaData
                if (metaData != null) {
                    val clientId = metaData.getString("GOOGLE_SERVER_CLIENT_ID")
                    if (!clientId.isNullOrEmpty()) return clientId
                }
            } catch (_: Exception) {}

            return null
        }
    }

    /// Authorization-code flow for providers with no native SDK.
    ///
    /// Custom Tabs rather than a WebView: the browser's own cookies mean an
    /// already-signed-in user is not asked to type a password again, and the
    /// user can see the address bar — which is the only way they can tell they
    /// are typing credentials into the real provider.
    ///
    /// No tokens come back. Redeeming the code needs a client secret, which
    /// cannot ship inside an APK, so the code and its PKCE verifier go to PHP
    /// and on to the app's own server.
    class OAuthSignIn(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val provider = parameters["provider"] as? String ?: "oauth"

            val authorizeUrl = (parameters["authorizeUrl"] as? String)?.takeIf { it.isNotEmpty() }
            val clientId = (parameters["clientId"] as? String)?.takeIf { it.isNotEmpty() }
            val redirectUri = (parameters["redirectUri"] as? String)?.takeIf { it.isNotEmpty() }

            if (authorizeUrl == null || clientId == null || redirectUri == null) {
                return fail(activity, provider, "MISSING_CONFIG", "authorizeUrl, clientId and redirectUri are all required.")
            }

            val url = try {
                buildAuthorizeUrl(parameters, authorizeUrl, clientId, redirectUri)
            } catch (e: Exception) {
                return fail(activity, provider, "INVALID_PARAMS", "authorizeUrl $authorizeUrl is not a valid URL: ${e.message}")
            }

            PendingOAuth.start(activity, provider, parameters["state"] as? String,
                parameters["codeVerifier"] as? String, redirectUri)

            try {
                CustomTabsIntent.Builder()
                    .setShowTitle(true)
                    .build()
                    .launchUrl(activity, url)
            } catch (e: Exception) {
                PendingOAuth.clear()

                return fail(activity, provider, "NO_BROWSER", "No browser is available to complete sign-in: ${e.message}")
            }

            // Control passes to the browser; SocialAuthRedirectActivity picks the
            // result back up and dispatches the event.
            return BridgeResponse.success(mapOf("status" to "pending", "provider" to provider))
        }

        private fun buildAuthorizeUrl(
            parameters: Map<String, Any>,
            authorizeUrl: String,
            clientId: String,
            redirectUri: String
        ): Uri {
            val builder = Uri.parse(authorizeUrl).buildUpon()
                .appendQueryParameter("response_type", "code")
                .appendQueryParameter("client_id", clientId)
                .appendQueryParameter("redirect_uri", redirectUri)

            @Suppress("UNCHECKED_CAST")
            val scopes = parameters["scopes"] as? List<String> ?: emptyList()
            if (scopes.isNotEmpty()) {
                builder.appendQueryParameter("scope", scopes.joinToString(" "))
            }

            (parameters["state"] as? String)?.takeIf { it.isNotEmpty() }?.let {
                builder.appendQueryParameter("state", it)
            }

            (parameters["codeChallenge"] as? String)?.takeIf { it.isNotEmpty() }?.let {
                builder.appendQueryParameter("code_challenge", it)
                builder.appendQueryParameter(
                    "code_challenge_method",
                    parameters["codeChallengeMethod"] as? String ?: "S256"
                )
            }

            @Suppress("UNCHECKED_CAST")
            val extra = parameters["extraParams"] as? Map<String, Any> ?: emptyMap()
            for ((key, value) in extra) {
                builder.appendQueryParameter(key, value.toString())
            }

            return builder.build()
        }
    }

    // Apple credential state check is not available on Android
    class CheckAppleCredentialState(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return BridgeResponse.success(mapOf("state" to "unsupported_platform"))
        }
    }

    class SignOut(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val credentialManager = CredentialManager.create(activity)
            val latch = java.util.concurrent.CountDownLatch(1)
            var clearError: Exception? = null

            CoroutineScope(Dispatchers.Main).launch {
                try {
                    credentialManager.clearCredentialState(
                        androidx.credentials.ClearCredentialStateRequest()
                    )
                } catch (e: Exception) {
                    clearError = e
                } finally {
                    latch.countDown()
                }
            }

            val completed = latch.await(5, java.util.concurrent.TimeUnit.SECONDS)

            if (!completed) {
                return BridgeResponse.error(
                    "SIGN_OUT_TIMEOUT",
                    "Timed out waiting for the credential state to be cleared"
                )
            }

            if (clearError != null) {
                return BridgeResponse.error(
                    "SIGN_OUT_FAILED",
                    "Failed to clear credential state: ${clearError?.message}"
                )
            }

            return BridgeResponse.success(mapOf("signedOut" to true))
        }
    }

    /// Dispatches SignInFailed and returns the matching bridge error.
    internal fun fail(
        activity: FragmentActivity,
        provider: String,
        errorCode: String,
        message: String
    ): Map<String, Any> {
        val payload = JSONObject().apply {
            put("provider", provider)
            put("error", message)
            put("errorCode", errorCode)
        }

        Handler(Looper.getMainLooper()).post {
            NativeActionCoordinator.dispatchEvent(
                activity,
                "Ikromjon\\NativePHP\\SocialAuth\\Events\\SignInFailed",
                payload.toString()
            )
        }

        return BridgeResponse.error(errorCode, message)
    }
}
