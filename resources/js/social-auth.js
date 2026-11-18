const baseUrl = '/_native/api/call';

async function bridgeCall(method, params = {}) {
    const response = await fetch(baseUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({ method, params }),
    });
    const result = await response.json();
    if (result.status === 'error') {
        throw new Error(result.message || 'Native call failed');
    }
    return result.data?.data ?? result.data;
}

export async function appleSignIn(scopes = ['email', 'fullName'], nonce = null, state = null) {
    const params = { scopes };
    if (nonce) params.nonce = nonce;
    if (state) params.state = state;
    return bridgeCall('SocialAuth.AppleSignIn', params);
}

// Android's Credential Manager needs the Web (server) client ID. The PHP facade adds it from
// config('social-auth.google_server_client_id'); a JavaScript caller passes it here, for example
// import.meta.env.VITE_GOOGLE_SERVER_CLIENT_ID. iOS reads GIDServerClientID from Info.plist instead.
export async function googleSignIn(nonce = null, serverClientId = null) {
    const params = {};
    if (nonce) params.nonce = nonce;
    if (serverClientId) params.serverClientId = serverClientId;
    return bridgeCall('SocialAuth.GoogleSignIn', params);
}

export async function checkAppleCredentialState(userId) {
    return bridgeCall('SocialAuth.CheckAppleCredentialState', { userId });
}

export async function signOut() {
    return bridgeCall('SocialAuth.SignOut');
}

export const socialAuth = {
    appleSignIn,
    googleSignIn,
    checkAppleCredentialState,
    signOut,
};

export default socialAuth;
