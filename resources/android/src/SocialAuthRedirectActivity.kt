package com.ikromjon.plugins.socialauth

import android.app.Activity
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONObject

/**
 * Holds the in-flight browser sign-in while control is with the browser.
 *
 * The Custom Tab runs in another task, so there is no return value to wait on —
 * the redirect arrives as a fresh Intent and has to be matched back to the
 * request that started it. State lives here for that hop only and is cleared as
 * soon as the flow ends, either way.
 *
 * The activity reference is held so the event is dispatched from the same
 * context that started the request, rather than from the transparent redirect
 * activity, which is finishing as it delivers.
 */
internal object PendingOAuth {

    private var activity: FragmentActivity? = null
    private var provider: String? = null
    private var expectedState: String? = null
    private var codeVerifier: String? = null
    private var redirectUri: String? = null

    fun start(
        activity: FragmentActivity,
        provider: String,
        state: String?,
        codeVerifier: String?,
        redirectUri: String
    ) {
        this.activity = activity
        this.provider = provider
        this.expectedState = state
        this.codeVerifier = codeVerifier
        this.redirectUri = redirectUri
    }

    fun clear() {
        activity = null
        provider = null
        expectedState = null
        codeVerifier = null
        redirectUri = null
    }

    /**
     * Turn the redirect into an event.
     *
     * Errors are reported the same way a native provider reports them, so an
     * app handling SignInFailed needs no separate path for browser providers.
     */
    fun complete(uri: Uri?) {
        val activity = this.activity
        val provider = this.provider ?: "oauth"

        // No pending request means the app was killed while the browser was
        // open and the static state went with it. There is nothing to dispatch
        // to, and re-running the flow is the only recovery.
        if (activity == null) {
            clear()

            return
        }

        if (uri == null) {
            dispatchFailure(activity, provider, "INVALID_RESPONSE", "The browser returned no callback URL.")

            return
        }

        val providerError = uri.getQueryParameter("error")
        if (providerError != null) {
            val description = uri.getQueryParameter("error_description") ?: providerError

            if (providerError == "access_denied") {
                dispatchFailure(activity, provider, "CANCELED", description)
            } else {
                dispatchFailure(activity, provider, "OAUTH_FAILED", description)
            }

            return
        }

        val code = uri.getQueryParameter("code")
        if (code.isNullOrEmpty()) {
            dispatchFailure(activity, provider, "INVALID_RESPONSE", "The callback carried no authorization code.")

            return
        }

        // Compared here because this is the only place holding both values.
        // A mismatch means the response is not the one this app asked for.
        val returnedState = uri.getQueryParameter("state")
        val expected = expectedState
        if (!expected.isNullOrEmpty() && returnedState != expected) {
            dispatchFailure(
                activity,
                provider,
                "STATE_MISMATCH",
                "The state returned by the provider did not match the one sent."
            )

            return
        }

        val payload = JSONObject().apply {
            put("provider", provider)
            put("authorizationCode", code)
            put("codeVerifier", codeVerifier ?: "")
            put("state", returnedState ?: "")
            put("redirectUri", redirectUri ?: "")
        }

        NativeActionCoordinator.dispatchEvent(
            activity,
            "Ikromjon\\NativePHP\\SocialAuth\\Events\\AuthorizationCodeReceived",
            payload.toString()
        )

        clear()
    }

    private fun dispatchFailure(
        activity: FragmentActivity,
        provider: String,
        errorCode: String,
        message: String
    ) {
        val payload = JSONObject().apply {
            put("provider", provider)
            put("error", message)
            put("errorCode", errorCode)
        }

        NativeActionCoordinator.dispatchEvent(
            activity,
            "Ikromjon\\NativePHP\\SocialAuth\\Events\\SignInFailed",
            payload.toString()
        )

        clear()
    }
}

/**
 * Catches the OAuth redirect and hands it straight back to the plugin.
 *
 * Declared with `android:launchMode="singleTask"` so returning from the browser
 * reuses this instance rather than stacking a second copy, and with no UI of
 * its own — it exists only to receive the Intent and finish.
 *
 * The manifest binds it to `${applicationId}` as the URL scheme, so the redirect
 * URI a provider is configured with must be `<your.application.id>://callback`.
 */
class SocialAuthRedirectActivity : Activity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        handle(intent)
    }

    /** singleTask delivers a second redirect here rather than through onCreate. */
    override fun onNewIntent(intent: Intent?) {
        super.onNewIntent(intent)

        handle(intent)
    }

    private fun handle(intent: Intent?) {
        PendingOAuth.complete(intent?.data)

        finish()
    }
}
