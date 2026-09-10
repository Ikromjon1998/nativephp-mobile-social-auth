<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Server Client ID
    |--------------------------------------------------------------------------
    |
    | The Web/Server OAuth client ID from Google Cloud Console. It is passed
    | to the native layer at runtime and becomes the `aud` claim of Google
    | ID tokens, so your backend can verify them.
    |
    | Deprecated since 1.2.0: prefer `providers.google.server_client_id` below.
    | This key is still read as a fallback and will be removed in 2.0.
    |
    */

    'google_server_client_id' => env('GOOGLE_SERVER_CLIENT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Credentials and options per provider. Entries here are merged over the
    | plugin's built-in definitions, so you only list what you are changing —
    | the bridge wiring for Apple and Google stays in place whether or not you
    | mention them.
    |
    | NEVER put a client secret in this file. It is compiled into the app
    | binary and can be extracted from it. Providers that need a secret to
    | exchange an authorization code must do that exchange on your server.
    |
    */

    'providers' => [

        'google' => [
            'server_client_id' => env('GOOGLE_SERVER_CLIENT_ID'),
            'ios_client_id' => env('GOOGLE_IOS_CLIENT_ID'),
            'ios_reversed_client_id' => env('GOOGLE_IOS_REVERSED_CLIENT_ID'),
        ],

        'apple' => [
            // Sign in with Apple has no configurable credentials on iOS; the
            // entitlement carries the identity.
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Generic Sign-In Event
    |--------------------------------------------------------------------------
    |
    | When true, every provider-specific completion event is mirrored as a
    | SignInCompleted event carrying the provider name. Listen to one or the
    | other, never both, or your handler runs twice.
    |
    */

    'dispatch_generic_event' => true,

];
