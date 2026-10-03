<?php

use Ikromjon\NativePHP\SocialAuth\Tests\TestCase;

uses(TestCase::class);

/**
 * The hook exists because a missing URL scheme does not merely break Google
 * Sign-In on iOS — it terminates the app. These tests pin the behaviour that
 * keeps that from regressing silently.
 */
const IOS_CLIENT_ID = '123456789-abcdef.apps.googleusercontent.com';
const IOS_REVERSED = 'com.googleusercontent.apps.123456789-abcdef';

/** A plist shaped like the one NativePHP generates, with schemes left empty. */
function makeInfoPlist(string $dir, string $urlTypes = ''): string
{
    @mkdir($dir.'/NativePHP', 0777, true);
    $path = $dir.'/NativePHP/Info.plist';

    file_put_contents($path, <<<PLIST
    <?xml version="1.0" encoding="UTF-8"?>
    <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
    <plist version="1.0">
    <dict>
        <key>CFBundleName</key>
        <string>NativePHP</string>
        <key>GIDClientID</key>
        <string>@{IOS_CLIENT_ID}</string>
    $urlTypes
    </dict>
    </plist>
    PLIST);

    return $path;
}

function schemesIn(string $path): array
{
    $plist = simplexml_load_file($path);
    $found = [];

    foreach ($plist->dict->children() as $node) {
        // Plists pair a <key> with the element that follows it.
        if ($node->getName() === 'key' && (string) $node === 'CFBundleURLTypes') {
            $array = $node->xpath('following-sibling::array[1]')[0] ?? null;

            foreach ($array?->dict ?? [] as $dict) {
                $schemes = $dict->xpath('key[text()="CFBundleURLSchemes"]/following-sibling::array[1]/string') ?: [];
                foreach ($schemes as $scheme) {
                    $found[] = (string) $scheme;
                }
            }
        }
    }

    return $found;
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/social-auth-hook-'.bin2hex(random_bytes(4));
    @mkdir($this->dir, 0777, true);
    putenv('GOOGLE_IOS_CLIENT_ID='.IOS_CLIENT_ID);
    putenv('GOOGLE_IOS_REVERSED_CLIENT_ID='.IOS_REVERSED);
});

afterEach(function () {
    putenv('GOOGLE_IOS_CLIENT_ID');
    putenv('GOOGLE_IOS_REVERSED_CLIENT_ID');
});

it('adds the reversed client id to an empty CFBundleURLSchemes', function () {
    $path = makeInfoPlist($this->dir, <<<'XML'
        <key>CFBundleURLTypes</key>
        <array>
            <dict>
                <key>CFBundleURLName</key>
                <string>com.example.app</string>
                <key>CFBundleURLSchemes</key>
                <array/>
            </dict>
        </array>
    XML);

    $this->artisan('social-auth:register-url-scheme', [
        '--platform' => 'ios',
        '--build-path' => $this->dir,
    ])->assertSuccessful();

    expect(schemesIn($path))->toContain(IOS_REVERSED);
});

it('creates CFBundleURLTypes when the plist has none', function () {
    $path = makeInfoPlist($this->dir);

    $this->artisan('social-auth:register-url-scheme', [
        '--platform' => 'ios',
        '--build-path' => $this->dir,
    ])->assertSuccessful();

    expect(schemesIn($path))->toContain(IOS_REVERSED);
});

/** NativePHP owns the first dict and refills it each build; ours must not collide. */
it('leaves the deeplink entry alone', function () {
    $path = makeInfoPlist($this->dir, <<<'XML'
        <key>CFBundleURLTypes</key>
        <array>
            <dict>
                <key>CFBundleURLName</key>
                <string>com.example.app</string>
                <key>CFBundleURLSchemes</key>
                <array><string>nativephp</string></array>
            </dict>
        </array>
    XML);

    $this->artisan('social-auth:register-url-scheme', [
        '--platform' => 'ios',
        '--build-path' => $this->dir,
    ])->assertSuccessful();

    expect(schemesIn($path))->toContain('nativephp')->toContain(IOS_REVERSED);
});

it('does not duplicate the entry when the build runs again', function () {
    $path = makeInfoPlist($this->dir);

    foreach (range(1, 3) as $ignored) {
        $this->artisan('social-auth:register-url-scheme', [
            '--platform' => 'ios',
            '--build-path' => $this->dir,
        ])->assertSuccessful();
    }

    expect(array_count_values(schemesIn($path))[IOS_REVERSED])->toBe(1);
});

it('replaces a stale scheme when the client id changes', function () {
    $path = makeInfoPlist($this->dir);

    $this->artisan('social-auth:register-url-scheme', [
        '--platform' => 'ios', '--build-path' => $this->dir,
    ])->assertSuccessful();

    putenv('GOOGLE_IOS_REVERSED_CLIENT_ID=com.googleusercontent.apps.999-new');

    $this->artisan('social-auth:register-url-scheme', [
        '--platform' => 'ios', '--build-path' => $this->dir,
    ])->assertSuccessful();

    expect(schemesIn($path))
        ->toContain('com.googleusercontent.apps.999-new')
        ->not->toContain(IOS_REVERSED);
});

/** Google shows it as "iOS URL scheme", but it is derivable from the client ID. */
it('derives the reversed id when only the client id is set', function () {
    putenv('GOOGLE_IOS_REVERSED_CLIENT_ID');
    $path = makeInfoPlist($this->dir);

    $this->artisan('social-auth:register-url-scheme', [
        '--platform' => 'ios', '--build-path' => $this->dir,
    ])->assertSuccessful();

    expect(schemesIn($path))->toContain(IOS_REVERSED);
});

it('does nothing on android', function () {
    $path = makeInfoPlist($this->dir);
    $before = file_get_contents($path);

    $this->artisan('social-auth:register-url-scheme', [
        '--platform' => 'android', '--build-path' => $this->dir,
    ])->assertSuccessful();

    expect(file_get_contents($path))->toBe($before);
});

it('warns instead of failing the build when nothing is configured', function () {
    putenv('GOOGLE_IOS_CLIENT_ID');
    putenv('GOOGLE_IOS_REVERSED_CLIENT_ID');
    makeInfoPlist($this->dir);

    $this->artisan('social-auth:register-url-scheme', [
        '--platform' => 'ios', '--build-path' => $this->dir,
    ])->assertSuccessful();
});

it('warns instead of failing the build when the plist is missing', function () {
    $this->artisan('social-auth:register-url-scheme', [
        '--platform' => 'ios', '--build-path' => $this->dir.'/nope',
    ])->assertSuccessful();
});

/**
 * The generated project carries one Info.plist per destination. Patching only
 * NativePHP/Info.plist left simulator builds still crashing, because the
 * simulator target builds against NativePHP-simulator-Info.plist.
 */
it('patches every Info.plist the project builds against', function () {
    makeInfoPlist($this->dir);

    $simulator = $this->dir.'/NativePHP-simulator-Info.plist';
    copy($this->dir.'/NativePHP/Info.plist', $simulator);

    $this->artisan('social-auth:register-url-scheme', [
        '--platform' => 'ios', '--build-path' => $this->dir,
    ])->assertSuccessful();

    expect(schemesIn($this->dir.'/NativePHP/Info.plist'))->toContain(IOS_REVERSED);
    expect(schemesIn($simulator))->toContain(IOS_REVERSED);
});

/** Xcode regenerates these from the sources, so writing them is wasted work. */
it('ignores Xcode build output copies', function () {
    makeInfoPlist($this->dir);
    @mkdir($this->dir.'/build', 0777, true);
    $stale = $this->dir.'/build/Info.plist';
    copy($this->dir.'/NativePHP/Info.plist', $stale);

    $this->artisan('social-auth:register-url-scheme', [
        '--platform' => 'ios', '--build-path' => $this->dir,
    ])->assertSuccessful();

    expect(schemesIn($stale))->not->toContain(IOS_REVERSED);
});

/** Reads the CFBundleURLName of every entry, so ownership can be asserted. */
function urlNamesIn(string $path): array
{
    $plist = simplexml_load_file($path);
    $found = [];

    foreach ($plist->dict->children() as $node) {
        if ($node->getName() === 'key' && (string) $node === 'CFBundleURLTypes') {
            $array = $node->xpath('following-sibling::array[1]')[0] ?? null;

            foreach ($array?->dict ?? [] as $dict) {
                $names = $dict->xpath('key[text()="CFBundleURLName"]/following-sibling::string[1]') ?: [];
                foreach ($names as $name) {
                    $found[] = (string) $name;
                }
            }
        }
    }

    return $found;
}

/**
 * Every plist in the field already carries this exact name. Changing it would
 * make the next build append a second Google entry instead of refreshing the
 * existing one, which is how duplicate URL schemes get shipped.
 */
it('keeps the historical CFBundleURLName for google', function () {
    $path = makeInfoPlist($this->dir);

    $this->artisan('social-auth:register-url-scheme', [
        '--platform' => 'ios', '--build-path' => $this->dir,
    ])->assertSuccessful();

    expect(urlNamesIn($path))->toContain('ikromjon.social-auth.google');
});

it('registers a scheme for a configured custom provider', function () {
    config()->set('social-auth.providers.github', [
        'driver' => 'oauth',
        'url_scheme' => 'redirect_scheme',
        'redirect_scheme' => 'myapp-github',
    ]);

    $path = makeInfoPlist($this->dir);

    $this->artisan('social-auth:register-url-scheme', [
        '--platform' => 'ios', '--build-path' => $this->dir,
    ])->assertSuccessful();

    expect(schemesIn($path))->toContain('myapp-github')->toContain(IOS_REVERSED);
    expect(urlNamesIn($path))->toContain('ikromjon.social-auth.github');
});

it('gives each provider its own entry rather than merging them', function () {
    config()->set('social-auth.providers.github', [
        'driver' => 'oauth', 'url_scheme' => 'redirect_scheme', 'redirect_scheme' => 'myapp-github',
    ]);

    $path = makeInfoPlist($this->dir);

    foreach (range(1, 2) as $ignored) {
        $this->artisan('social-auth:register-url-scheme', [
            '--platform' => 'ios', '--build-path' => $this->dir,
        ])->assertSuccessful();
    }

    $names = array_count_values(urlNamesIn($path));

    expect($names['ikromjon.social-auth.google'])->toBe(1);
    expect($names['ikromjon.social-auth.github'])->toBe(1);
});

/** Sign in with Apple needs no callback scheme on iOS; it must not invent one. */
it('registers nothing for apple', function () {
    $path = makeInfoPlist($this->dir);

    $this->artisan('social-auth:register-url-scheme', [
        '--platform' => 'ios', '--build-path' => $this->dir,
    ])->assertSuccessful();

    expect(urlNamesIn($path))->not->toContain('ikromjon.social-auth.apple');
});

/** A provider configured without a scheme must not block the ones that have one. */
it('skips providers with nothing to register', function () {
    config()->set('social-auth.providers.github', ['driver' => 'oauth']);

    $path = makeInfoPlist($this->dir);

    $this->artisan('social-auth:register-url-scheme', [
        '--platform' => 'ios', '--build-path' => $this->dir,
    ])->assertSuccessful();

    expect(schemesIn($path))->toContain(IOS_REVERSED);
    expect(urlNamesIn($path))->not->toContain('ikromjon.social-auth.github');
});
