<?php

/**
 * Every errorCode the native layers dispatch has to appear in the README.
 *
 * An undocumented code is not cosmetic: integrators branch on errorCode, and a
 * value they have never seen falls through to whatever their default arm does.
 * `SCOPES_ALREADY_GRANTED` was missing this way.
 */

/** @return array<int, string> */
function emittedErrorCodes(): array
{
    $root = dirname(__DIR__);

    $ios = file_get_contents($root.'/resources/ios/Sources/SocialAuthFunctions.swift');

    // The browser flow lives partly in the redirect activity, so both Kotlin
    // sources are scanned or its codes would look undocumented-by-omission.
    $android = file_get_contents($root.'/resources/android/src/SocialAuthFunctions.kt')
        .file_get_contents($root.'/resources/android/src/SocialAuthRedirectActivity.kt');

    $codes = [];

    // iOS assigns to a local before dispatching: errorCode = "CANCELED"
    preg_match_all('/errorCode = "([A-Z_]+)"/', $ios, $m);
    $codes = array_merge($codes, $m[1]);

    // iOS also passes some directly into the event payload.
    preg_match_all('/"errorCode": "([A-Z_]+)"/', $ios, $m);
    $codes = array_merge($codes, $m[1]);

    // Android writes them into the JSON payload, or maps exceptions in a when.
    preg_match_all('/put\("errorCode", "([A-Z_]+)"\)/', $android, $m);
    $codes = array_merge($codes, $m[1]);

    preg_match_all('/->\s*"([A-Z_]+)"/', $android, $m);
    $codes = array_merge($codes, $m[1]);

    // The browser flow reports through shared helpers that take the code as
    // their first string argument rather than assigning it to a local.
    preg_match_all('/Self\.fail\(provider,\s*"([A-Z_]+)"/', $ios, $m);
    $codes = array_merge($codes, $m[1]);

    preg_match_all('/(?:fail|dispatchFailure)\(\s*activity,\s*provider,\s*"([A-Z_]+)"/', $android, $m);
    $codes = array_merge($codes, $m[1]);

    return array_values(array_unique($codes));
}

/** @return array<int, string> */
function documentedErrorCodes(): array
{
    $readme = file_get_contents(dirname(__DIR__).'/README.md');

    expect($readme)->toContain('**Error codes:**');

    $line = collect(explode("\n", $readme))
        ->first(fn (string $l): bool => str_starts_with($l, '**Error codes:**'));

    preg_match_all('/`([A-Z_]+)`/', $line, $m);

    return $m[1];
}

test('every error code the native layers emit is documented', function () {
    $undocumented = array_diff(emittedErrorCodes(), documentedErrorCodes());

    expect($undocumented)->toBe([], 'Undocumented error codes: '.implode(', ', $undocumented));
});

test('the documented list has no codes the native layers never emit', function () {
    $unused = array_diff(documentedErrorCodes(), emittedErrorCodes());

    expect($unused)->toBe([], 'Documented but never emitted: '.implode(', ', $unused));
});
