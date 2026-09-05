<?php

use Ikromjon\NativePHP\SocialAuth\SocialAuth;

/**
 * The native layer builds every response with `BridgeResponse.success(data:)`,
 * which returns the payload flat — there is no `data` envelope. Reading only the
 * enveloped shape is why signOut() always returned false on a real device and
 * checkAppleCredentialState() always reported 'unknown'.
 *
 * These cover both shapes so neither can regress.
 */

/** A SocialAuth whose bridge returns whatever the test hands it. */
function fakeBridge(?string $response): SocialAuth
{
    return new class($response) extends SocialAuth
    {
        public function __construct(private ?string $response) {}

        protected function call(string $method, array $params = []): ?string
        {
            return $this->response;
        }
    };
}

test('signOut reads the flat response the native layer actually sends', function () {
    expect(fakeBridge('{"signedOut":true}')->signOut())->toBeTrue();
});

test('signOut still reads an enveloped response', function () {
    expect(fakeBridge('{"data":{"signedOut":true}}')->signOut())->toBeTrue();
});

test('signOut reports false when the native layer says so', function () {
    expect(fakeBridge('{"signedOut":false}')->signOut())->toBeFalse();
    expect(fakeBridge('{"data":{"signedOut":false}}')->signOut())->toBeFalse();
});

test('signOut reports false for an unusable response', function () {
    expect(fakeBridge(null)->signOut())->toBeFalse();
    expect(fakeBridge('')->signOut())->toBeFalse();
    expect(fakeBridge('not json')->signOut())->toBeFalse();
});

test('credential state reads the flat response', function () {
    expect(fakeBridge('{"state":"authorized"}')->checkAppleCredentialState('001.abc'))->toBe('authorized');
    expect(fakeBridge('{"state":"revoked"}')->checkAppleCredentialState('001.abc'))->toBe('revoked');
});

test('credential state still reads an enveloped response', function () {
    expect(fakeBridge('{"data":{"state":"authorized"}}')->checkAppleCredentialState('001.abc'))->toBe('authorized');
});

test('credential state falls back to unknown', function () {
    expect(fakeBridge(null)->checkAppleCredentialState('001.abc'))->toBe('unknown');
    expect(fakeBridge('{}')->checkAppleCredentialState('001.abc'))->toBe('unknown');
});

test('sign-in reads a flat success payload', function () {
    $result = fakeBridge('{"status":"success","provider":"google","userId":"42","identityToken":"jwt"}')
        ->googleSignIn();

    expect($result)->not->toBeNull();
    expect($result->provider)->toBe('google');
    expect($result->userId)->toBe('42');
    expect($result->identityToken)->toBe('jwt');
});

test('sign-in reads an enveloped success payload', function () {
    $result = fakeBridge('{"data":{"status":"success","provider":"apple","userId":"001.abc"}}')
        ->appleSignIn();

    expect($result)->not->toBeNull();
    expect($result->provider)->toBe('apple');
    expect($result->userId)->toBe('001.abc');
});

test('sign-in returns null when the bridge did not succeed', function () {
    expect(fakeBridge(null)->googleSignIn())->toBeNull();
    expect(fakeBridge('{"status":"error","message":"nope"}')->googleSignIn())->toBeNull();
    expect(fakeBridge('{"data":{"status":"error"}}')->appleSignIn())->toBeNull();
});
