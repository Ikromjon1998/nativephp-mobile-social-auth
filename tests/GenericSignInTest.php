<?php

use Ikromjon\NativePHP\SocialAuth\Exceptions\UnknownProviderException;
use Ikromjon\NativePHP\SocialAuth\Exceptions\UnsupportedDriverException;
use Ikromjon\NativePHP\SocialAuth\SocialAuth;
use Ikromjon\NativePHP\SocialAuth\Tests\TestCase;

uses(TestCase::class);

/**
 * signIn() is what the named methods now run through, so these assert the two
 * things that would break existing apps if they drifted: the bridge function
 * each provider reaches, and the exact parameter payload the native layer
 * receives.
 */

/** A SocialAuth that records the bridge call instead of making one. */
function recordingBridge(?string $response = null): SocialAuth
{
    return new class($response) extends SocialAuth
    {
        public ?string $method = null;

        /** @var array<string, mixed> */
        public array $params = [];

        public bool $called = false;

        public function __construct(private ?string $response) {}

        protected function call(string $method, array $params = []): ?string
        {
            $this->called = true;
            $this->method = $method;
            $this->params = $params;

            return $this->response;
        }
    };
}

it('routes each provider to its own bridge function', function () {
    $google = recordingBridge();
    $google->signIn('google');
    expect($google->method)->toBe('SocialAuth.GoogleSignIn');

    $apple = recordingBridge();
    $apple->signIn('apple');
    expect($apple->method)->toBe('SocialAuth.AppleSignIn');
});

/** The payload the native layer sees must be byte-identical to pre-1.2. */
it('sends the same google payload the named method always sent', function () {
    config()->set('social-auth.providers.google.server_client_id', 'server-id');

    $bridge = recordingBridge();
    $bridge->googleSignIn('a-nonce');

    expect($bridge->params)->toBe(['nonce' => 'a-nonce', 'serverClientId' => 'server-id']);
});

it('omits the google nonce and server client id when unset', function () {
    config()->set('social-auth.providers.google.server_client_id', null);
    config()->set('social-auth.google_server_client_id', null);
    config()->set('services.google.client_id', null);

    $bridge = recordingBridge();
    $bridge->googleSignIn();

    expect($bridge->params)->toBe([]);
});

it('applies apple default scopes and drops null options', function () {
    $bridge = recordingBridge();
    $bridge->appleSignIn();

    expect($bridge->params)->toBe(['scopes' => ['email', 'fullName']]);
});

it('lets caller options override provider defaults', function () {
    $bridge = recordingBridge();
    $bridge->appleSignIn(['email'], 'a-nonce', 'a-state');

    expect($bridge->params)->toBe([
        'scopes' => ['email'],
        'nonce' => 'a-nonce',
        'state' => 'a-state',
    ]);
});

it('decodes a success payload into an AuthResult', function () {
    $result = recordingBridge('{"status":"success","provider":"google","userId":"42"}')
        ->signIn('google');

    expect($result?->provider)->toBe('google');
    expect($result?->userId)->toBe('42');
});

it('rejects an unknown provider before touching the bridge', function () {
    $bridge = recordingBridge();

    expect(fn () => $bridge->signIn('myspace'))->toThrow(UnknownProviderException::class);
    expect($bridge->called)->toBeFalse();
});

/**
 * The oauth driver is configured-but-unimplemented until 1.3.0. Failing loudly
 * beats silently calling a bridge function that does not exist.
 */
it('reports the oauth driver as not yet implemented', function () {
    config()->set('social-auth.providers', [
        'github' => ['driver' => 'oauth', 'client_id' => 'gh'],
    ]);

    expect(fn () => recordingBridge()->signIn('github'))
        ->toThrow(UnsupportedDriverException::class, 'not implemented yet');
});

it('reports an unrecognised driver', function () {
    config()->set('social-auth.providers', [
        'weird' => ['driver' => 'telepathy'],
    ]);

    expect(fn () => recordingBridge()->signIn('weird'))
        ->toThrow(UnsupportedDriverException::class, 'unknown driver [telepathy]');
});

/** Pre-1.2 callers pass nothing, and must keep getting the payload-free call. */
it('signs out with no parameters when no provider is named', function () {
    $bridge = recordingBridge('{"signedOut":true}');

    expect($bridge->signOut())->toBeTrue();
    expect($bridge->params)->toBe([]);
});

it('names the provider when one is given', function () {
    $bridge = recordingBridge('{"signedOut":true}');

    expect($bridge->signOut('google'))->toBeTrue();
    expect($bridge->params)->toBe(['provider' => 'google']);
});

/** Apple has no sign-out API, so there is nothing to ask the bridge for. */
it('reports false for apple sign-out without calling the bridge', function () {
    $bridge = recordingBridge('{"signedOut":true}');

    expect($bridge->signOut('apple'))->toBeFalse();
    expect($bridge->called)->toBeFalse();
});

/** A config-only provider cannot be `native`: there is no compiled bridge for it. */
it('rejects a native provider with no bridge function', function () {
    config()->set('social-auth.providers', [
        'custom' => ['driver' => 'native'],
    ]);

    expect(fn () => recordingBridge()->signIn('custom'))
        ->toThrow(UnsupportedDriverException::class, 'declares no `bridge` function');
});
