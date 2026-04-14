package com.ikromjon.plugins.socialauth

import android.os.Handler
import android.os.Looper
import androidx.credentials.CredentialManager
import androidx.credentials.GetCredentialRequest
import androidx.credentials.GetCredentialResponse
import androidx.credentials.exceptions.GetCredentialCancellationException
import androidx.credentials.exceptions.GetCredentialException
import androidx.credentials.exceptions.NoCredentialException
import androidx.fragment.app.FragmentActivity
import com.google.android.libraries.identity.googleid.GetGoogleIdOption
import com.google.android.libraries.identity.googleid.GoogleIdTokenCredential
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.utils.NativeActionCoordinator
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.suspendCancellableCoroutine
import java.util.concurrent.CountDownLatch
import kotlin.coroutines.resume

object SocialAuthFunctions {

    // Apple Sign-In is not supported natively on Android
    class AppleSignIn(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            NativeActionCoordinator.dispatchEvent(
                "Ikromjon\\NativePHP\\SocialAuth\\Events\\SignInFailed",
                mapOf(
                    "provider" to "apple",
                    "error" to "Apple Sign-In is not available on Android",
                    "errorCode" to "UNSUPPORTED_PLATFORM"
                )
            )

            return BridgeResponse.error(
                "UNSUPPORTED_PLATFORM",
                "Apple Sign-In is not available on Android. Use Google Sign-In instead."
            )
        }
    }

    class GoogleSignIn(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val nonce = parameters["nonce"] as? String

            // Read server client ID from NativePHP secrets/config
            val serverClientId = getServerClientId()
            if (serverClientId.isNullOrEmpty()) {
                NativeActionCoordinator.dispatchEvent(
                    "Ikromjon\\NativePHP\\SocialAuth\\Events\\SignInFailed",
                    mapOf(
                        "provider" to "google",
                        "error" to "GOOGLE_SERVER_CLIENT_ID is not configured",
                        "errorCode" to "MISSING_CONFIG"
                    )
                )
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

            val latch = CountDownLatch(1)
            var credentialResponse: GetCredentialResponse? = null
            var credentialError: GetCredentialException? = null

            Handler(Looper.getMainLooper()).post {
                runBlocking {
                    try {
                        credentialResponse = credentialManager.getCredential(
                            context = activity,
                            request = request
                        )
                    } catch (e: GetCredentialException) {
                        credentialError = e
                    } finally {
                        latch.countDown()
                    }
                }
            }

            latch.await()

            if (credentialError != null) {
                val error = credentialError!!
                val errorCode = when (error) {
                    is GetCredentialCancellationException -> "CANCELED"
                    is NoCredentialException -> "NO_CREDENTIAL"
                    else -> "UNKNOWN"
                }

                NativeActionCoordinator.dispatchEvent(
                    "Ikromjon\\NativePHP\\SocialAuth\\Events\\SignInFailed",
                    mapOf(
                        "provider" to "google",
                        "error" to (error.message ?: "Google Sign-In failed"),
                        "errorCode" to errorCode
                    )
                )

                return BridgeResponse.error(
                    "GOOGLE_SIGN_IN_FAILED",
                    error.message ?: "Google Sign-In failed"
                )
            }

            val response = credentialResponse
                ?: return BridgeResponse.error("GOOGLE_SIGN_IN_FAILED", "No credential response received")

            val credential = response.credential

            val googleIdTokenCredential: GoogleIdTokenCredential
            try {
                googleIdTokenCredential = GoogleIdTokenCredential.createFrom(credential.data)
            } catch (e: Exception) {
                NativeActionCoordinator.dispatchEvent(
                    "Ikromjon\\NativePHP\\SocialAuth\\Events\\SignInFailed",
                    mapOf(
                        "provider" to "google",
                        "error" to "Failed to parse Google credential: ${e.message}",
                        "errorCode" to "PARSE_ERROR"
                    )
                )
                return BridgeResponse.error("PARSE_ERROR", "Failed to parse Google credential: ${e.message}")
            }

            val resultData = mutableMapOf<String, Any>(
                "status" to "success",
                "provider" to "google",
                "identityToken" to googleIdTokenCredential.idToken
            )

            googleIdTokenCredential.id.let { resultData["userId"] = it }
            googleIdTokenCredential.displayName?.let { resultData["displayName"] = it }
            googleIdTokenCredential.givenName?.let { resultData["givenName"] = it }
            googleIdTokenCredential.familyName?.let { resultData["familyName"] = it }
            googleIdTokenCredential.profilePictureUri?.toString()?.let { resultData["photoUrl"] = it }

            // Email is typically the ID for Google credentials
            resultData["email"] = googleIdTokenCredential.id

            NativeActionCoordinator.dispatchEvent(
                "Ikromjon\\NativePHP\\SocialAuth\\Events\\GoogleSignInCompleted",
                mapOf(
                    "userId" to (resultData["userId"] as? String ?: ""),
                    "identityToken" to (resultData["identityToken"] as? String ?: ""),
                    "email" to (resultData["email"] as? String ?: ""),
                    "displayName" to (resultData["displayName"] as? String ?: ""),
                    "photoUrl" to (resultData["photoUrl"] as? String ?: "")
                )
            )

            return BridgeResponse.success(resultData)
        }

        private fun getServerClientId(): String? {
            // Try reading from app metadata (set via NativePHP secrets)
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

            // Try reading from resources (string resource)
            try {
                val resId = activity.resources.getIdentifier(
                    "google_server_client_id", "string", activity.packageName
                )
                if (resId != 0) {
                    return activity.getString(resId)
                }
            } catch (_: Exception) {}

            return null
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
            // Credential Manager doesn't have a built-in sign-out,
            // but we can clear any cached credentials
            try {
                val credentialManager = CredentialManager.create(activity)
                // Clear credential state is handled by the app's own session management
                // The Credential Manager does not maintain sign-in state
            } catch (_: Exception) {}

            return BridgeResponse.success(mapOf("signedOut" to true))
        }
    }
}
