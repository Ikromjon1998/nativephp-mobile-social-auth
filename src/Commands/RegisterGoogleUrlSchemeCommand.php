<?php

namespace Ikromjon\NativePHP\SocialAuth\Commands;

use DOMDocument;
use DOMElement;
use Illuminate\Console\Command;

/**
 * Registers the Google callback URL scheme in the generated iOS Info.plist.
 *
 * GoogleSignIn-iOS refuses to start unless the reversed client ID is listed in
 * CFBundleURLTypes, and it reports that by raising an uncaught NSException that
 * terminates the app.
 *
 * The manifest cannot express this on its own. `url_schemes` is not a key
 * NativePHP Mobile reads (3.3.x or 4.x), and the supported `info_plist` route
 * only handles flat strings and flat arrays of strings — not the array-of-dicts
 * that CFBundleURLTypes requires. So the entry is written here instead, from a
 * post_compile hook, which runs after NativePHP has finished rewriting the
 * Info.plist and before Xcode builds it.
 *
 * The entry is added as its own dict, identified by CFBundleURLName. NativePHP's
 * own updateUrlTypes() only ever touches the first dict in the array (filling it
 * from NATIVEPHP_DEEPLINK_SCHEME), so keeping ours separate means the two do not
 * overwrite each other on rebuilds.
 */
class RegisterGoogleUrlSchemeCommand extends Command
{
    /** Identifies our entry so repeated builds update it instead of duplicating it. */
    private const URL_NAME = 'ikromjon.social-auth.google';

    protected $signature = 'social-auth:register-url-scheme
        {--platform= : The platform being built (ios or android)}
        {--build-path= : Path to the generated native project}
        {--plugin-path= : Path to this plugin}
        {--app-id= : The application identifier}
        {--config= : JSON-encoded NativePHP config}
        {--plugins= : JSON-encoded list of registered plugins}';

    protected $description = 'Register the Google Sign-In callback URL scheme in the iOS Info.plist';

    public function handle(): int
    {
        if ($this->option('platform') !== 'ios') {
            return self::SUCCESS;
        }

        $reversed = $this->reversedClientId();

        if ($reversed === null) {
            $this->warn('social-auth: neither GOOGLE_IOS_REVERSED_CLIENT_ID nor GOOGLE_IOS_CLIENT_ID is set; '
                .'skipping URL scheme registration. Google Sign-In will not start on iOS.');

            return self::SUCCESS;
        }

        $plistPaths = $this->infoPlistPaths((string) $this->option('build-path'));

        if ($plistPaths === []) {
            $this->warn('social-auth: found no Info.plist under '.$this->option('build-path')
                .'; skipping URL scheme registration.');

            return self::SUCCESS;
        }

        $patched = 0;

        foreach ($plistPaths as $plistPath) {
            if ($this->patch($plistPath, $reversed)) {
                $patched++;
            }
        }

        if ($patched > 0) {
            $this->line('  <fg=green>social-auth</>: registered URL scheme '.$reversed
                .' in '.$patched.' Info.plist file(s)');
        }

        return self::SUCCESS;
    }

    /**
     * Every Info.plist the Xcode project might build against.
     *
     * The generated project has one per destination — `NativePHP/Info.plist`
     * for device builds and `NativePHP-simulator-Info.plist` for the simulator
     * — so patching only the first leaves simulator builds still crashing.
     *
     * @return array<int, string>
     */
    private function infoPlistPaths(string $buildPath): array
    {
        $buildPath = rtrim($buildPath, '/');

        $candidates = array_merge(
            glob($buildPath.'/*Info.plist') ?: [],
            glob($buildPath.'/NativePHP/*Info.plist') ?: [],
        );

        // `build/` holds Xcode's own processed copies; patching those achieves
        // nothing because they are regenerated from the sources above.
        return array_values(array_filter(
            $candidates,
            fn (string $path): bool => ! str_contains($path, '/build/')
        ));
    }

    private function patch(string $plistPath, string $scheme): bool
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;

        if (! @$document->load($plistPath)) {
            $this->warn('social-auth: could not parse '.$plistPath.'; skipping it.');

            return false;
        }

        $root = $this->rootDict($document);

        if ($root === null) {
            $this->warn('social-auth: unexpected structure in '.$plistPath.'; skipping it.');

            return false;
        }

        $this->writeUrlScheme($document, $root, $scheme);

        file_put_contents($plistPath, $document->saveXML());

        return true;
    }

    /**
     * The reversed client ID, preferring the explicit secret.
     *
     * Google shows it as "iOS URL scheme", but it is a pure transformation of
     * the client ID, so it can be derived when only that is configured.
     */
    private function reversedClientId(): ?string
    {
        $reversed = env('GOOGLE_IOS_REVERSED_CLIENT_ID');

        if (is_string($reversed) && $reversed !== '') {
            return $reversed;
        }

        $clientId = env('GOOGLE_IOS_CLIENT_ID');

        if (! is_string($clientId) || $clientId === '') {
            return null;
        }

        return 'com.googleusercontent.apps.'.str_replace('.apps.googleusercontent.com', '', $clientId);
    }

    /** The top-level <dict> of the plist. */
    private function rootDict(DOMDocument $document): ?DOMElement
    {
        $plist = $document->documentElement;

        if ($plist === null) {
            return null;
        }

        foreach ($plist->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === 'dict') {
                return $child;
            }
        }

        return null;
    }

    /** Adds — or refreshes — our own dict inside CFBundleURLTypes. */
    private function writeUrlScheme(DOMDocument $document, DOMElement $root, string $scheme): void
    {
        $urlTypes = $this->valueFor($root, 'CFBundleURLTypes');

        if ($urlTypes === null) {
            $key = $document->createElement('key', 'CFBundleURLTypes');
            $urlTypes = $document->createElement('array');
            $root->appendChild($key);
            $root->appendChild($urlTypes);
        }

        foreach ($urlTypes->childNodes as $entry) {
            if (! $entry instanceof DOMElement || $entry->tagName !== 'dict') {
                continue;
            }

            $name = $this->valueFor($entry, 'CFBundleURLName');

            if ($name !== null && $name->textContent === self::URL_NAME) {
                // Ours already: replace it so a changed client ID takes effect.
                $urlTypes->replaceChild($this->buildEntry($document, $scheme), $entry);

                return;
            }
        }

        $urlTypes->appendChild($this->buildEntry($document, $scheme));
    }

    private function buildEntry(DOMDocument $document, string $scheme): DOMElement
    {
        $dict = $document->createElement('dict');

        $dict->appendChild($document->createElement('key', 'CFBundleTypeRole'));
        $dict->appendChild($document->createElement('string', 'Editor'));
        $dict->appendChild($document->createElement('key', 'CFBundleURLName'));
        $dict->appendChild($document->createElement('string', self::URL_NAME));
        $dict->appendChild($document->createElement('key', 'CFBundleURLSchemes'));

        $schemes = $document->createElement('array');
        $schemes->appendChild($document->createElement('string', $scheme));
        $dict->appendChild($schemes);

        return $dict;
    }

    /**
     * The value node following a <key> in a plist dict.
     *
     * Plists express pairs as adjacent siblings rather than nesting, so the
     * value is simply the next element after the matching key.
     */
    private function valueFor(DOMElement $dict, string $key): ?DOMElement
    {
        $keyMatched = false;

        foreach ($dict->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if ($keyMatched) {
                return $node;
            }

            if ($node->tagName === 'key' && $node->textContent === $key) {
                $keyMatched = true;
            }
        }

        return null;
    }
}
