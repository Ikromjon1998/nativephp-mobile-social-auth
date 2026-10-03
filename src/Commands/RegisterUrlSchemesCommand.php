<?php

namespace Ikromjon\NativePHP\SocialAuth\Commands;

use DOMDocument;
use DOMElement;
use Ikromjon\NativePHP\SocialAuth\Providers\ProviderRegistry;
use Illuminate\Console\Command;

/**
 * Registers each provider's callback URL scheme in the generated iOS Info.plist.
 *
 * GoogleSignIn-iOS refuses to start unless the reversed client ID is listed in
 * CFBundleURLTypes, and it reports that by raising an uncaught NSException that
 * terminates the app. Other providers that hand control to a browser have the
 * same requirement, which is why this walks the provider registry rather than
 * hardcoding Google.
 *
 * The manifest cannot express this on its own. `url_schemes` is not a key
 * NativePHP Mobile reads (3.3.x or 4.x), and the supported `info_plist` route
 * only handles flat strings and flat arrays of strings — not the array-of-dicts
 * that CFBundleURLTypes requires. So the entries are written here instead, from
 * a post_compile hook, which runs after NativePHP has finished rewriting the
 * Info.plist and before Xcode builds it.
 *
 * Each provider gets its own dict, identified by CFBundleURLName. NativePHP's
 * own updateUrlTypes() only ever touches the first dict in the array (filling it
 * from NATIVEPHP_DEEPLINK_SCHEME), so keeping ours separate means the two do not
 * overwrite each other on rebuilds.
 */
class RegisterUrlSchemesCommand extends Command
{
    /**
     * The artisan signature is referenced from nativephp.json's post_compile
     * hook, so it stays as-is even though the command now covers every
     * provider rather than Google alone.
     */
    protected $signature = 'social-auth:register-url-scheme
        {--platform= : The platform being built (ios or android)}
        {--build-path= : Path to the generated native project}
        {--plugin-path= : Path to this plugin}
        {--app-id= : The application identifier}
        {--config= : JSON-encoded NativePHP config}
        {--plugins= : JSON-encoded list of registered plugins}';

    protected $description = 'Register provider callback URL schemes in the iOS Info.plist';

    public function handle(ProviderRegistry $providers): int
    {
        if ($this->option('platform') !== 'ios') {
            return self::SUCCESS;
        }

        $schemes = $this->schemes($providers);

        if ($schemes === []) {
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
            if ($this->patch($plistPath, $schemes)) {
                $patched++;
            }
        }

        if ($patched > 0) {
            $this->line('  <fg=green>social-auth</>: registered URL scheme(s) '.implode(', ', $schemes)
                .' in '.$patched.' Info.plist file(s)');
        }

        return self::SUCCESS;
    }

    /**
     * The scheme each configured provider needs registered, keyed by provider.
     *
     * A provider with nothing to register is skipped rather than failing the
     * build — Apple Sign-In, for instance, never needs a URL scheme on iOS.
     *
     * @return array<string, string>
     */
    private function schemes(ProviderRegistry $providers): array
    {
        $schemes = [];

        foreach ($providers->all() as $provider => $definition) {
            $scheme = $this->schemeFor($provider, $definition, $providers);

            if ($scheme !== null) {
                $schemes[$provider] = $scheme;
            }
        }

        return $schemes;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function schemeFor(string $provider, array $definition, ProviderRegistry $providers): ?string
    {
        return match ($definition['url_scheme'] ?? null) {
            'google_reversed' => $this->reversedClientId($provider, $providers),
            'redirect_scheme' => $this->stringValue($providers->value($provider, 'redirect_scheme')),
            default => null,
        };
    }

    /**
     * The reversed client ID, preferring the explicit secret.
     *
     * Google shows it as "iOS URL scheme", but it is a pure transformation of
     * the client ID, so it can be derived when only that is configured.
     *
     * Config is consulted first and env second: this runs during a build, where
     * the app's own .env is loaded, and the env fallback keeps setups that never
     * published the config file working.
     */
    private function reversedClientId(string $provider, ProviderRegistry $providers): ?string
    {
        $reversed = $this->stringValue($providers->value($provider, 'ios_reversed_client_id'))
            ?? $this->stringValue(env('GOOGLE_IOS_REVERSED_CLIENT_ID'));

        if ($reversed !== null) {
            return $reversed;
        }

        $clientId = $this->stringValue($providers->value($provider, 'ios_client_id'))
            ?? $this->stringValue(env('GOOGLE_IOS_CLIENT_ID'));

        if ($clientId === null) {
            return null;
        }

        return 'com.googleusercontent.apps.'.str_replace('.apps.googleusercontent.com', '', $clientId);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Identifies a provider's entry so repeated builds update it in place.
     *
     * Google's value must stay exactly `ikromjon.social-auth.google`: it is
     * already written into every plist in the field, and a different name would
     * append a second entry instead of refreshing the existing one.
     */
    private function urlName(string $provider): string
    {
        return 'ikromjon.social-auth.'.$provider;
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

    /**
     * @param  array<string, string>  $schemes
     */
    private function patch(string $plistPath, array $schemes): bool
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

        foreach ($schemes as $provider => $scheme) {
            $this->writeUrlScheme($document, $root, $this->urlName($provider), $scheme);
        }

        file_put_contents($plistPath, $document->saveXML());

        return true;
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

    /** Adds — or refreshes — one provider's dict inside CFBundleURLTypes. */
    private function writeUrlScheme(DOMDocument $document, DOMElement $root, string $urlName, string $scheme): void
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

            if ($name !== null && $name->textContent === $urlName) {
                // Ours already: replace it so a changed client ID takes effect.
                $urlTypes->replaceChild($this->buildEntry($document, $urlName, $scheme), $entry);

                return;
            }
        }

        $urlTypes->appendChild($this->buildEntry($document, $urlName, $scheme));
    }

    private function buildEntry(DOMDocument $document, string $urlName, string $scheme): DOMElement
    {
        $dict = $document->createElement('dict');

        $dict->appendChild($document->createElement('key', 'CFBundleTypeRole'));
        $dict->appendChild($document->createElement('string', 'Editor'));
        $dict->appendChild($document->createElement('key', 'CFBundleURLName'));
        $dict->appendChild($document->createElement('string', $urlName));
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
