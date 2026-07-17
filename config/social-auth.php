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
    */

    'google_server_client_id' => env('GOOGLE_SERVER_CLIENT_ID'),

];
