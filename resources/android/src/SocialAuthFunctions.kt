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

            // Read server client ID from Android meta-data (injected via nativephp.json secrets)
            val serverClientId = getServerClientId()
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

                    val eventPayload = JSONObject().apply {
                        put("provider", "google")
                        put("userId", googleIdTokenCredential.id)
                        put("identityToken", googleIdTokenCredential.idToken)
                        put("email", googleIdTokenCredential.id)
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

    // Apple credential state check is not available on Android
    class CheckAppleCredentialState(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return BridgeResponse.success(mapOf("state" to "unsupported_platform"))
        }
    }

    class SignOut(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return BridgeResponse.success(mapOf("signedOut" to true))
        }
    }
}
