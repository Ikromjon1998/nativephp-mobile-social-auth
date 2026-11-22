<?php

namespace Ikromjon\NativePHP\SocialAuth\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

/**
 * Every place the plugin's native code hands an event to PHP, read out of the
 * Swift and Kotlin sources with their structure (strings, comments, brackets)
 * taken into account, so tests can hold each one against the PHP event it names
 * and post exactly the keys the device posts.
 *
 * Files: every *.swift (platform "ios") and *.kt (platform "android") under
 * <root>/resources, recursively, in path order. A location is the file's
 * basename and the line of the call, so two files may not share a basename.
 *
 * Lexing. Comments (// to the end of the line, and block comments, which nest
 * in both languages) are replaced by spaces with their newlines kept, so every
 * offset and line number stays that of the file. Brackets are matched, and
 * lists are split at their top-level commas only, outside string literals.
 * These literals are recognised, and a // or /* inside them is text:
 *  - Swift: "...", """...""", raw #"..."# and #"""..."""# with any number of
 *    #, and \( ) interpolations (\#( ) in raw strings), lexed as code;
 *  - Kotlin: "..." with escapes, """...""", $name and ${...} templates (lexed
 *    as code, so quotes inside them do not end the string), and character
 *    literals such as '"';
 *  - backtick-quoted identifiers in both.
 * Swift regex literals are not: #/.../# throws, and a bare /.../ is unsupported.
 *
 * Swift, with any whitespace (newlines included) between the tokens:
 *
 *     LaravelBridge.shared.send?(
 *         "Vendor\\Package\\Events\\SomethingHappened",
 *         ["key": value, "other": optional ?? ""]
 *     )
 *
 *  - argument 1 is a plain string literal, unescaped ("\\" reads as "\");
 *  - argument 2 is a dictionary literal ([:] when empty) whose keys are plain
 *    string literals; several entries may share a line.
 *
 * Kotlin:
 *
 *     val payload = JSONObject().apply {
 *         put("key", value)
 *         if (condition) putOpt("other", optional ?: "") else put("other", "none")
 *     }
 *     NativeActionCoordinator.dispatchEvent(activity, "Vendor\\Package\\Events\\SomethingHappened", payload.toString())
 *
 *  - argument 2 is a plain string literal; argument 3 is NAME.toString();
 *  - NAME is the nearest `val NAME` or `var NAME` before the call, which must be
 *    initialised with [org.json.]JSONObject().apply { ... } (a type annotation
 *    aside), with nothing chained after the block;
 *  - keys come from put("key", ...) and putOpt("key", ...) calls (optionally on
 *    `this.` or `this@apply.`) that are statements of the block or of if/else
 *    branches inside it;
 *  - from the end of the block to the call, NAME is not referenced at all and no
 *    bracket closes that opened before the block: one that does ends the scope
 *    of the declaration, which then is not the one the call reads;
 *  - after the call, NAME is not referenced again while it is in scope: the
 *    bridges dispatch from Handler(...).post { }, which runs after the rest of
 *    the function, so a later change would still be sent.
 *
 * Anything else throws an UnexpectedValueException naming file:line instead of
 * being skipped, in particular: any other use of LaravelBridge, of
 * NativeActionCoordinator (imports aside) or of dispatchEvent; other argument
 * counts or shapes; an event class or key that is not a plain string literal;
 * duplicate dictionary keys; a builder other than JSONObject().apply { }, or one
 * with a call chained after it; put/putOpt anywhere but as a statement (inside
 * a lambda, a when, a try, or another call); accumulate, append, putOnce,
 * putAll or remove; a put on another receiver; `this` handed out inside the
 * block; and NAME referenced or out of scope before the call.
 *
 * Not modelled, by design (the native code is the plugin's own, not arbitrary
 * input): value types (a non-literal value is sampled as a string), whether a
 * key put only under a condition or as null is always sent, and Kotlin that
 * changes keys through extension functions, extension properties or class
 * properties named like the payload.
 *
 * Each site: ['platform' => 'ios'|'android', 'location' => 'File.swift:84',
 * 'event' => FQCN, 'payload' => [key => sample], 'emptyFallbacks' => [key]].
 * Keys are taken verbatim. A sample is the value itself when that is a plain
 * string literal, else "<key> from <platform>". emptyFallbacks lists the keys
 * whose value ends in Swift's ?? "" or Kotlin's ?: "": when that field is
 * missing, the bridge sends "".
 *
 * @phpstan-type Range array{0: int, 1: int}
 * @phpstan-type Entry array{key: string, sample: ?string, empty: bool}
 * @phpstan-type Site array{platform: string, location: string, event: string, payload: array<string, string>, emptyFallbacks: list<string>}
 */
final class NativeDispatchSites
{
    /** The Swift call as text, for rawCallCounts(). */
    private const SWIFT_CALL = '/LaravelBridge\s*\.\s*shared\s*\.\s*send\b/';

    /** The Kotlin call as text, for rawCallCounts(). */
    private const KOTLIN_CALL = '/NativeActionCoordinator\s*\.\s*dispatchEvent\b/';

    /** JSONObject methods that change its keys some other way than put/putOpt. */
    private const OTHER_MUTATORS = ['accumulate', 'append', 'putOnce', 'putAll', 'remove'];

    private const CLOSERS = ['(' => ')', '[' => ']', '{' => '}'];

    private bool $swift;

    /** The source with its comments blanked: the text the compiler reads. */
    private string $code;

    /**
     * $code with its literals blanked as well, except for the code interpolated
     * into them, which keeps its own brackets. Brackets, commas and identifiers
     * found here are code.
     */
    private string $bare;

    /** @var array<int, array{end: int, value: ?string}> where each literal starts: where it ends, and its value when it is a plain string */
    private array $literals = [];

    /** @var array<int, int> where each literal ends => where it starts */
    private array $literalEnds = [];

    private function __construct(
        private string $source,
        private string $path,
        private string $platform,
    ) {
        $this->swift = $platform === 'ios';
        $this->code = $source;
        $this->bare = $source;

        $this->lexCode(0);
    }

    /**
     * Every dispatch site, in path order and then in source order.
     *
     * @return list<Site>
     */
    public static function all(?string $root = null): array
    {
        $sites = [];

        foreach (self::files($root) as $path => $file) {
            array_push($sites, ...self::read($path, $file)->sites());
        }

        return $sites;
    }

    /**
     * The sites as a Pest dataset, each named "<platform> <location> <event>".
     *
     * @return array<string, array{0: Site}>
     */
    public static function dataset(?string $root = null): array
    {
        $dataset = [];

        foreach (self::all($root) as $site) {
            $name = $site['platform'].' '.$site['location'].' '.class_basename($site['event']);

            if (isset($dataset[$name])) {
                throw new UnexpectedValueException("Two dispatch sites are both named [{$name}]; put the calls on separate lines.");
            }

            $dataset[$name] = [$site];
        }

        return $dataset;
    }

    /**
     * For every file all() reads, keyed by basename: how many times the text
     * LaravelBridge.shared.send or NativeActionCoordinator.dispatchEvent (any
     * whitespace around the dots) appears once comments are stripped, string
     * contents included. When this equals the number of sites per file, the
     * parser has turned every occurrence into a site.
     *
     * @return array<string, int>
     */
    public static function rawCallCounts(?string $root = null): array
    {
        $counts = [];

        foreach (self::files($root) as $path => $file) {
            $code = self::read($path, $file)->code;

            $counts[basename($path)] = preg_match_all(self::SWIFT_CALL, $code) + preg_match_all(self::KOTLIN_CALL, $code);
        }

        return $counts;
    }

    /**
     * The native sources, keyed by path relative to the root.
     *
     * @return array<string, string>
     */
    private static function files(?string $root): array
    {
        $root = rtrim($root ?? dirname(__DIR__, 2), '/\\');
        $resources = $root.DIRECTORY_SEPARATOR.'resources';

        if (! is_dir($resources)) {
            throw new UnexpectedValueException("There is no resources directory in [{$root}].");
        }

        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resources, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['swift', 'kt'], true)) {
                $files[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = $file->getPathname();
            }
        }

        // Directory order differs between filesystems; path order does not.
        ksort($files, SORT_STRING);

        $shared = array_keys(array_filter(
            array_count_values(array_map(basename(...), array_keys($files))),
            fn (int $count) => $count > 1,
        ));

        if ($shared !== []) {
            throw new UnexpectedValueException('Locations name a file by its basename, which several files share: '.implode(', ', $shared).'.');
        }

        return $files;
    }

    private static function read(string $path, string $file): self
    {
        $source = file_get_contents($file);

        if ($source === false) {
            throw new UnexpectedValueException("Cannot read [{$path}].");
        }

        return new self($source, $path, str_ends_with($path, '.swift') ? 'ios' : 'android');
    }

    /** @return list<Site> */
    private function sites(): array
    {
        return $this->swift ? $this->swiftSites() : $this->kotlinSites();
    }

    // Swift ------------------------------------------------------------------

    /** @return list<Site> */
    private function swiftSites(): array
    {
        $sites = [];

        foreach ($this->find('/\bLaravelBridge\b/') as $at) {
            if (! preg_match('/\GLaravelBridge\s*\.\s*shared\s*\.\s*send\s*[?!]?\s*\(/', $this->bare, $call, 0, $at)) {
                $this->fail($at, 'LaravelBridge is used in a way this reader does not understand; the only supported use is LaravelBridge.shared.send?("Event\\\\Class", ["key": value, ...])');
            }

            $arguments = $this->arguments($at + strlen($call[0]) - 1);

            if (count($arguments) !== 2) {
                $this->fail($at, 'LaravelBridge.shared.send takes an event class and a dictionary literal, but this call has '.count($arguments).' arguments');
            }

            $sites[] = $this->site($at, $this->eventClass($arguments[0]), $this->dictionary($arguments[1]));
        }

        return $sites;
    }

    /**
     * The entries of a dictionary literal.
     *
     * @param  Range  $range
     * @return list<Entry>
     */
    private function dictionary(array $range): array
    {
        [$start, $end] = $range;

        if ($this->code[$start] !== '[' || $this->closing($start) !== $end - 1) {
            $this->fail($start, 'the payload must be a dictionary literal ["key": value, ...], not `'.$this->text($range).'`');
        }

        if ($this->text($this->trim($start + 1, $end - 1)) === ':') {
            return [];
        }

        $entries = [];
        $keys = [];

        foreach ($this->split($start + 1, $end - 1) as $element) {
            $colon = $this->topLevel($element, ':');

            if ($colon === null) {
                $this->fail($element[0], 'the payload must be a dictionary literal, but `'.$this->text($element).'` is not a "key": value entry');
            }

            $key = $this->key($this->trim($element[0], $colon));

            if (isset($keys[$key])) {
                $this->fail($element[0], "the payload has the key \"{$key}\" twice, which traps at run time");
            }

            $keys[$key] = true;
            $entries[] = $this->entry($key, $this->trim($colon + 1, $element[1]));
        }

        if ($entries === []) {
            $this->fail($start, '[] is an empty array literal, not a dictionary; an empty payload is [:]');
        }

        return $entries;
    }

    // Kotlin -----------------------------------------------------------------

    /** @return list<Site> */
    private function kotlinSites(): array
    {
        $sites = [];
        $calls = [];

        foreach ($this->find('/\bNativeActionCoordinator\b/') as $at) {
            if ($this->inImport($at)) {
                continue;
            }

            if (! preg_match('/\GNativeActionCoordinator\s*\.\s*(dispatchEvent)\s*\(/', $this->bare, $call, PREG_OFFSET_CAPTURE, $at)) {
                $this->fail($at, 'NativeActionCoordinator is used in a way this reader does not understand; the only supported use is NativeActionCoordinator.dispatchEvent(activity, "Event\\\\Class", payload.toString())');
            }

            $calls[$call[1][1]] = true;
            $sites[] = $this->kotlinSite($at, $at + strlen($call[0][0]) - 1);
        }

        foreach ($this->find('/\bdispatchEvent\b/') as $at) {
            if (! isset($calls[$at])) {
                $this->fail($at, 'dispatchEvent is used in a way this reader does not understand; call it as NativeActionCoordinator.dispatchEvent(activity, "Event\\\\Class", payload.toString())');
            }
        }

        // NativePHP 4.8 can also hand events straight to the element queue. This reader does
        // not read that path, so a site written that way must not silently drop out.
        foreach ($this->find('/\b(?:NativeElementBridge|sendNativeEvent)\b/') as $at) {
            if (! $this->inImport($at)) {
                $this->fail($at, 'events must be dispatched with NativeActionCoordinator.dispatchEvent(...); this reader does not read NativeElementBridge.sendNativeEvent');
            }
        }

        return $sites;
    }

    /** @return Site */
    private function kotlinSite(int $at, int $open): array
    {
        $arguments = $this->arguments($open);

        if (count($arguments) !== 3) {
            $this->fail($at, 'NativeActionCoordinator.dispatchEvent takes an activity, an event class and a payload, but this call has '.count($arguments).' arguments');
        }

        if (! preg_match('/^([A-Za-z_]\w*)\s*\.\s*toString\s*\(\s*\)$/', $this->text($arguments[2], $this->bare), $payload)) {
            $this->fail($arguments[2][0], 'the payload must be NAME.toString() for a val NAME = JSONObject().apply { ... }, not `'.$this->text($arguments[2]).'`');
        }

        $name = $payload[1];

        foreach ([$arguments[0], $arguments[1]] as $argument) {
            if (($reference = $this->firstReference($name, $argument[0], $argument[1])) !== null) {
                $this->fail($reference, "{$name} is used in another argument of the dispatch call as well");
            }
        }

        return $this->site($at, $this->eventClass($arguments[1]), $this->builder($name, $at, $this->closing($open)));
    }

    /**
     * The entries of the JSONObject().apply { } block that NAME holds when the
     * dispatch call at $call reads it.
     *
     * @return list<Entry>
     */
    private function builder(string $name, int $call, int $callEnd): array
    {
        $quoted = preg_quote($name, '/');
        $declarations = $this->find('/\b(?:val|var)\s+'.$quoted.'\b/', 0, $call);

        if ($declarations === []) {
            $this->fail($call, "no val or var {$name} comes before this call");
        }

        $declaration = $declarations[count($declarations) - 1];
        $pattern = '/\G(?:val|var)\s+'.$quoted.'\s*(?::\s*[\w.]+\??\s*)?=\s*(?:org\s*\.\s*json\s*\.\s*)?JSONObject\s*\(\s*\)\s*\.\s*apply\s*\{/';

        if (! preg_match($pattern, $this->bare, $builder, 0, $declaration)) {
            $this->fail($declaration, "{$name}, the payload of the dispatch call on line {$this->line($call)}, must be initialised with JSONObject().apply { put(\"key\", value) ... }");
        }

        $open = $declaration + strlen($builder[0]) - 1;
        $close = $this->closing($open);

        if ($call < $close) {
            $this->fail($call, "this dispatch call is inside the JSONObject().apply { } block that builds {$name}");
        }

        $this->assertNothingChained($close + 1, $name);

        $entries = $this->applyEntries($open + 1, $close);

        // The dispatch call has to read this declaration, with these keys.
        $depth = 0;

        for ($i = $close + 1; $i < $call; $i++) {
            if (isset(self::CLOSERS[$this->bare[$i]])) {
                $depth++;
            } elseif (in_array($this->bare[$i], self::CLOSERS, true) && --$depth < 0) {
                $this->fail($i, "the block declaring {$name} on line {$this->line($declaration)} ends here, before the dispatch call on line {$this->line($call)}, so that declaration is not the one the call reads");
            }
        }

        if (($reference = $this->firstReference($name, $close + 1, $call)) !== null) {
            $this->fail($reference, "{$name} is used between its JSONObject().apply { } block (line {$this->line($declaration)}) and the dispatch call (line {$this->line($call)}), where its keys could change");
        }

        // Nor after the call while it is still in scope: the bridges dispatch from a
        // Handler(...).post { } lambda, which runs only after the rest of the function, so a
        // change written below the call would still reach the payload that is sent.
        if (($reference = $this->firstReference($name, $callEnd + 1, $this->scopeEnd($declaration))) !== null) {
            $this->fail($reference, "{$name} is used after the dispatch call on line {$this->line($call)} while still in scope; a dispatch deferred with Handler(...).post { } would send the changed payload");
        }

        return $entries;
    }

    /** Where the block holding the declaration at $at closes, or the end of the file at top level. */
    private function scopeEnd(int $at): int
    {
        $depth = 0;
        $length = strlen($this->bare);

        for ($i = $at; $i < $length; $i++) {
            if (isset(self::CLOSERS[$this->bare[$i]])) {
                $depth++;
            } elseif (in_array($this->bare[$i], self::CLOSERS, true) && --$depth < 0) {
                return $i;
            }
        }

        return $length;
    }

    /** Fails when the builder expression goes on after its apply block. */
    private function assertNothingChained(int $after, string $name): void
    {
        preg_match('/\G[^\S\r\n]*/', $this->bare, $blank, 0, $after);
        $rest = $after + strlen($blank[0]);

        if (! in_array($this->bare[$rest] ?? '', ['', "\r", "\n", ';', '}'], true)) {
            $this->fail($rest, "the JSONObject().apply { } block of {$name} is followed by more code; nothing may be chained after it");
        }

        // Kotlin lets a line that starts with . ?. or ?: continue the previous one.
        preg_match('/\G\s*/', $this->bare, $blank, 0, $rest);
        $next = $rest + strlen($blank[0]);

        if (in_array($this->bare[$next] ?? '', ['.', '?'], true)) {
            $this->fail($next, "the JSONObject().apply { } block of {$name} is continued on this line; nothing may be chained after it");
        }
    }

    /**
     * The keys that the statements of an apply block put, once nothing else in
     * the block can be changing them.
     *
     * @return list<Entry>
     */
    private function applyEntries(int $start, int $end): array
    {
        $puts = [];
        $this->statements($start, $end, $puts);

        $understood = [];

        foreach ($puts as $put) {
            $understood[$put['name']] = true;

            if ($put['this'] !== null) {
                $understood[$put['this']] = true;
            }
        }

        $words = implode('|', ['put', 'putOpt', ...self::OTHER_MUTATORS, 'this']);

        foreach ($this->find('/\b(?:'.$words.')\b/', $start, $end) as $at) {
            if (! isset($understood[$at])) {
                $this->fail($at, $this->unsupportedInBlock($at));
            }
        }

        return array_map(fn (array $put) => $this->entry($put['key'], $put['value']), $puts);
    }

    /**
     * Walks the statements in [$start, $end), collecting the put and putOpt
     * calls that are statements themselves or the branches of an if/else.
     *
     * @param  list<array{name: int, this: ?int, key: string, value: Range}>  $puts
     */
    private function statements(int $start, int $end, array &$puts): void
    {
        for ($i = $this->skip($start, $end); $i < $end; $i = $this->skip($i, $end)) {
            $i = $this->statement($i, $end, $puts);
        }
    }

    /**
     * @param  list<array{name: int, this: ?int, key: string, value: Range}>  $puts
     * @return int where the statement ends
     */
    private function statement(int $i, int $end, array &$puts): int
    {
        if ($this->keyword($i, 'if')) {
            $condition = $this->skip($i + 2, $end);

            if (($this->bare[$condition] ?? '') !== '(') {
                $this->fail($i, 'expected ( after if');
            }

            $i = $this->branch($this->closing($condition) + 1, $end, $puts);
            $else = $this->skip($i, $end);

            return $this->keyword($else, 'else') ? $this->branch($else + 4, $end, $puts) : $i;
        }

        if (preg_match('/\G(?:(this(?:@apply)?)\s*\.\s*)?(putOpt|put)\s*\(/', $this->bare, $put, PREG_OFFSET_CAPTURE, $i)) {
            $open = $i + strlen($put[0][0]) - 1;
            $arguments = $this->arguments($open);

            if (count($arguments) !== 2) {
                $this->fail($put[2][1], "{$put[2][0]} takes a key and a value, but this call has ".count($arguments).' arguments');
            }

            $puts[] = [
                'name' => $put[2][1],
                'this' => $put[1][1] >= 0 ? $put[1][1] : null,
                'key' => $this->key($arguments[0]),
                'value' => $arguments[1],
            ];

            return $this->closing($open) + 1;
        }

        // Any other statement runs to its newline or semicolon outside brackets.
        while ($i < $end && $this->bare[$i] !== "\n" && $this->bare[$i] !== ';') {
            $i = isset(self::CLOSERS[$this->bare[$i]]) ? $this->closing($i) + 1 : $i + 1;
        }

        return $i;
    }

    /**
     * @param  list<array{name: int, this: ?int, key: string, value: Range}>  $puts
     * @return int where the branch ends
     */
    private function branch(int $i, int $end, array &$puts): int
    {
        $i = $this->skip($i, $end);

        if (($this->bare[$i] ?? '') !== '{') {
            return $this->statement($i, $end, $puts);
        }

        $close = $this->closing($i);
        $this->statements($i + 1, $close, $puts);

        return $close + 1;
    }

    private function unsupportedInBlock(int $at): string
    {
        preg_match('/\G\w+/', $this->bare, $word, 0, $at);
        $word = $word[0];

        return match (true) {
            $word === 'this' => '`this` is used inside the JSONObject().apply { } block other than as the receiver of a put statement, so keys could be added where this reader cannot see them',
            in_array($word, self::OTHER_MUTATORS, true) => "{$word} changes the keys of a JSONObject in a way this reader does not support; use put(\"key\", value)",
            $this->previousChar($at) === '.' => "{$word} is called on another receiver; only put and putOpt statements on the block's own JSONObject are read",
            default => "{$word} is not a statement of the JSONObject().apply { } block or of an if/else branch in it (a lambda, when, try or another call), so this reader cannot tell which keys it adds",
        };
    }

    /** Whether $at lies on an import directive. */
    private function inImport(int $at): bool
    {
        $start = strrpos(substr($this->bare, 0, $at), "\n");
        $start = $start === false ? 0 : $start + 1;
        $end = strpos($this->bare, "\n", $at);
        $line = substr($this->bare, $start, ($end === false ? strlen($this->bare) : $end) - $start);

        return (bool) preg_match('/^\s*import\s+[\w.]+(?:\s+as\s+\w+)?\s*;?\s*$/', $line);
    }

    /** Where NAME is first referenced in [$start, $end), not counting members of the same name. */
    private function firstReference(string $name, int $start, int $end): ?int
    {
        foreach ($this->find('/\b'.preg_quote($name, '/').'\b/', $start, $end) as $at) {
            // x.payload and x?.payload are something else's payload.
            if ($this->previousChar($at) !== '.') {
                return $at;
            }
        }

        return null;
    }

    // Shared -----------------------------------------------------------------

    /**
     * @param  list<Entry>  $entries
     * @return Site
     */
    private function site(int $at, string $event, array $entries): array
    {
        $payload = [];
        $empty = [];

        foreach ($entries as $entry) {
            // A key may be put more than once (one put per if/else branch): the first sample stands.
            $payload[$entry['key']] ??= $entry['sample'] ?? "{$entry['key']} from {$this->platform}";

            if ($entry['empty']) {
                $empty[$entry['key']] = true;
            }
        }

        return [
            'platform' => $this->platform,
            'location' => basename($this->path).':'.$this->line($at),
            'event' => $event,
            'payload' => $payload,
            'emptyFallbacks' => array_keys($empty),
        ];
    }

    /**
     * @param  Range  $value
     * @return Entry
     */
    private function entry(string $key, array $value): array
    {
        return [
            'key' => $key,
            'sample' => $this->literal($value),
            'empty' => $this->endsInEmptyFallback($value),
        ];
    }

    /** @param  Range  $range */
    private function eventClass(array $range): string
    {
        $event = $this->literal($range);

        if ($event === null || $event === '') {
            $this->fail($range[0], 'the event class must be a plain string literal such as "Vendor\\\\Package\\\\Events\\\\SomethingHappened", not `'.$this->text($range).'`');
        }

        return $event;
    }

    /** @param  Range  $range */
    private function key(array $range): string
    {
        return $this->literal($range) ?? $this->fail($range[0], 'a payload key must be a plain string literal, not `'.$this->text($range).'`');
    }

    /**
     * The value of the plain string literal that spans exactly $range, if one does.
     *
     * @param  Range  $range
     */
    private function literal(array $range): ?string
    {
        $literal = $this->literals[$range[0]] ?? null;

        return $literal !== null && $literal['end'] === $range[1] ? $literal['value'] : null;
    }

    /**
     * Whether a value ends in Swift's ?? "" or Kotlin's ?: "".
     *
     * @param  Range  $range
     */
    private function endsInEmptyFallback(array $range): bool
    {
        [$start, $end] = $range;

        // (value ?? "") reads the same as value ?? "".
        while ($start < $end && $this->bare[$start] === '(' && $this->closing($start) === $end - 1) {
            [$start, $end] = $this->trim($start + 1, $end - 1);
        }

        $literal = $this->literalEnds[$end] ?? null;

        if ($literal === null || $literal < $start || $this->literal([$literal, $end]) !== '') {
            return false;
        }

        [, $before] = $this->trim($start, $literal);

        return $before - 2 >= $start && substr($this->code, $before - 2, 2) === ($this->swift ? '??' : '?:');
    }

    /**
     * The arguments of the call whose parenthesis opens at $open.
     *
     * @return list<Range>
     */
    private function arguments(int $open): array
    {
        return $this->split($open + 1, $this->closing($open));
    }

    /**
     * [$start, $end) split at its top-level commas, each part trimmed. Both
     * languages allow a trailing comma; an empty part anywhere else throws.
     *
     * @return list<Range>
     */
    private function split(int $start, int $end): array
    {
        $parts = [];
        $from = $start;

        for ($i = $start; $i < $end; $i++) {
            if (isset(self::CLOSERS[$this->bare[$i]])) {
                $i = $this->closing($i);
            } elseif ($this->bare[$i] === ',') {
                $parts[] = $this->trim($from, $i);
                $from = $i + 1;
            }
        }

        $parts[] = $this->trim($from, $end);

        if ($parts[count($parts) - 1][0] === $parts[count($parts) - 1][1]) {
            array_pop($parts);
        }

        foreach ($parts as [$partStart, $partEnd]) {
            if ($partStart === $partEnd) {
                $this->fail($partStart, 'empty element in a list');
            }
        }

        return $parts;
    }

    /**
     * The first $char in $range outside brackets and literals.
     *
     * @param  Range  $range
     */
    private function topLevel(array $range, string $char): ?int
    {
        for ($i = $range[0]; $i < $range[1]; $i++) {
            if (isset(self::CLOSERS[$this->bare[$i]])) {
                $i = $this->closing($i);
            } elseif ($this->bare[$i] === $char) {
                return $i;
            }
        }

        return null;
    }

    /** Where the bracket that opens at $open closes. */
    private function closing(int $open): int
    {
        $expected = [];

        for ($i = $open, $length = strlen($this->bare); $i < $length; $i++) {
            $char = $this->bare[$i];

            if (isset(self::CLOSERS[$char])) {
                $expected[] = self::CLOSERS[$char];
            } elseif (in_array($char, self::CLOSERS, true)) {
                if (array_pop($expected) !== $char) {
                    $this->fail($i, "unbalanced {$char}");
                }

                if ($expected === []) {
                    return $i;
                }
            }
        }

        $this->fail($open, "unclosed {$this->bare[$open]}");
    }

    /** @return Range $start and $end moved past whitespace (blanked comments included). */
    private function trim(int $start, int $end): array
    {
        while ($start < $end && ctype_space($this->code[$start])) {
            $start++;
        }

        while ($end > $start && ctype_space($this->code[$end - 1])) {
            $end--;
        }

        return [$start, $end];
    }

    /** $i moved past whitespace and semicolons. */
    private function skip(int $i, int $end): int
    {
        while ($i < $end && (ctype_space($this->bare[$i]) || $this->bare[$i] === ';')) {
            $i++;
        }

        return $i;
    }

    private function keyword(int $at, string $word): bool
    {
        return substr($this->bare, $at, strlen($word)) === $word
            && ! preg_match('/\G\w/', $this->bare, $next, 0, $at + strlen($word));
    }

    /** The character before $at, past any whitespace. */
    private function previousChar(int $at): string
    {
        $i = $at - 1;

        while ($i >= 0 && ctype_space($this->bare[$i])) {
            $i--;
        }

        return $i >= 0 ? $this->bare[$i] : '';
    }

    /**
     * Where $pattern matches $bare, from $start up to (not including) $end.
     *
     * @return list<int>
     */
    private function find(string $pattern, int $start = 0, ?int $end = null): array
    {
        preg_match_all($pattern, $this->bare, $matches, PREG_OFFSET_CAPTURE, $start);

        return array_values(array_filter(
            array_column($matches[0], 1),
            fn (int $at) => $end === null || $at < $end,
        ));
    }

    /** @param  Range  $range */
    private function text(array $range, ?string $from = null): string
    {
        return trim(preg_replace('/\s+/', ' ', substr($from ?? $this->code, $range[0], $range[1] - $range[0])));
    }

    private function line(int $offset): int
    {
        return substr_count($this->source, "\n", 0, $offset) + 1;
    }

    private function fail(int $offset, string $message): never
    {
        throw new UnexpectedValueException("{$this->path}:{$this->line($offset)}: {$message}.");
    }

    // Lexing -----------------------------------------------------------------

    /**
     * Lexes code from $i: blanks its comments and records its literals, up to
     * the end of the source or, inside an interpolation, up to the $closer that
     * ends it, whose offset it returns.
     */
    private function lexCode(int $i, ?string $closer = null): int
    {
        $start = $i;
        $length = strlen($this->source);
        $depth = 0;

        while ($i < $length) {
            $char = $this->source[$i];
            $next = $this->source[$i + 1] ?? '';

            if ($char === '/' && $next === '/') {
                $i = $this->lexLineComment($i);
            } elseif ($char === '/' && $next === '*') {
                $i = $this->lexBlockComment($i);
            } elseif ($char === '"' || ($this->swift && $char === '#' && preg_match('/\G#+"/', $this->source, $match, 0, $i))) {
                $i = $this->swift ? $this->lexSwiftString($i) : $this->lexKotlinString($i);
            } elseif ($this->swift && $char === '#' && preg_match('/\G#+\//', $this->source, $match, 0, $i)) {
                $this->fail($i, 'Swift regex literals are not supported');
            } elseif (! $this->swift && $char === "'") {
                $i = $this->lexCharacter($i);
            } elseif ($char === '`') {
                $i = $this->lexBackticks($i);
            } else {
                if ($closer !== null && isset(self::CLOSERS[$char])) {
                    $depth++;
                } elseif ($closer !== null && in_array($char, self::CLOSERS, true)) {
                    if ($depth === 0) {
                        if ($char !== $closer) {
                            $this->fail($i, "unbalanced {$char} in an interpolation");
                        }

                        return $i;
                    }

                    $depth--;
                }

                $i++;
            }
        }

        if ($closer !== null) {
            $this->fail($start, 'unterminated interpolation');
        }

        return $length;
    }

    private function lexLineComment(int $start): int
    {
        $end = $start + strcspn($this->source, "\r\n", $start);
        $this->blank($start, $end);

        return $end;
    }

    private function lexBlockComment(int $start): int
    {
        $depth = 0;

        for ($i = $start, $length = strlen($this->source); $i < $length;) {
            $pair = substr($this->source, $i, 2);

            if ($pair === '/*') {
                $depth++;
                $i += 2;
            } elseif ($pair === '*/') {
                $i += 2;

                if (--$depth === 0) {
                    $this->blank($start, $i);

                    return $i;
                }
            } else {
                $i++;
            }
        }

        $this->fail($start, 'unterminated block comment');
    }

    /** "...", """...""" or a raw #"..."#, with \( ) interpolations. */
    private function lexSwiftString(int $start): int
    {
        $i = $start + strspn($this->source, '#', $start);
        $hashes = str_repeat('#', $i - $start);
        $multiline = substr($this->source, $i, 3) === '"""';
        $i += $multiline ? 3 : 1;

        $contentStart = $i;
        $closer = ($multiline ? '"""' : '"').$hashes;
        $escape = '\\'.$hashes;
        $interpolations = [];

        while (true) {
            $char = $this->source[$i] ?? null;

            if ($char === null || (! $multiline && ($char === "\n" || $char === "\r"))) {
                $this->fail($start, 'unterminated string literal');
            }

            if (substr($this->source, $i, strlen($escape)) === $escape) {
                $i += strlen($escape);

                if (($this->source[$i] ?? '') === '(') {
                    $close = $this->lexCode($i + 1, ')');
                    $interpolations[] = [$i, $close + 1];
                    $i = $close + 1;
                } else {
                    $i++;
                }
            } elseif (substr($this->source, $i, strlen($closer)) === $closer) {
                break;
            } else {
                $i++;
            }
        }

        $end = $i + strlen($closer);
        $plain = ! $multiline && $interpolations === [];

        $this->recordLiteral($start, $end, $interpolations, $plain ? $this->unescapeSwift(substr($this->source, $contentStart, $i - $contentStart), $escape) : null);

        return $end;
    }

    /** "..." with escapes, or a raw """...""", with $name and ${...} templates. */
    private function lexKotlinString(int $start): int
    {
        $raw = substr($this->source, $start, 3) === '"""';
        $i = $start + ($raw ? 3 : 1);
        $contentStart = $i;
        $templates = [];

        while (true) {
            $char = $this->source[$i] ?? null;

            if ($char === null || (! $raw && ($char === "\n" || $char === "\r"))) {
                $this->fail($start, 'unterminated string literal');
            }

            if (! $raw && $char === '\\') {
                $i += 2;
            } elseif ($char === '$' && ($this->source[$i + 1] ?? '') === '{') {
                $close = $this->lexCode($i + 2, '}');
                $templates[] = [$i + 1, $close + 1];
                $i = $close + 1;
            } elseif ($char === '$' && preg_match('/\G[A-Za-z_]\w*/', $this->source, $name, 0, $i + 1)) {
                $templates[] = [$i + 1, $i + 1 + strlen($name[0])];
                $i += 1 + strlen($name[0]);
            } elseif ($raw && substr($this->source, $i, 3) === '"""') {
                // Quotes right before the closing three belong to the string.
                $i += strspn($this->source, '"', $i) - 3;
                break;
            } elseif (! $raw && $char === '"') {
                break;
            } else {
                $i++;
            }
        }

        $end = $i + ($raw ? 3 : 1);
        $content = substr($this->source, $contentStart, $i - $contentStart);
        $value = $templates !== [] ? null : ($raw ? $content : $this->unescapeKotlin($content));

        $this->recordLiteral($start, $end, $templates, $value);

        return $end;
    }

    /** A Kotlin character literal such as '"' or '\''. */
    private function lexCharacter(int $start): int
    {
        for ($i = $start + 1; ($this->source[$i] ?? "'") !== "'"; $i += $this->source[$i] === '\\' ? 2 : 1) {
            if ($this->source[$i] === "\n" || $this->source[$i] === "\r") {
                break;
            }
        }

        if (($this->source[$i] ?? '') !== "'") {
            $this->fail($start, 'unterminated character literal');
        }

        $this->recordLiteral($start, $i + 1, [], null);

        return $i + 1;
    }

    /** A `quoted identifier`: kept as a word in $bare, its other characters blanked. */
    private function lexBackticks(int $start): int
    {
        $length = strcspn($this->source, "`\r\n", $start + 1);

        if (($this->source[$start + 1 + $length] ?? '') !== '`') {
            $this->fail($start, 'unterminated backtick identifier');
        }

        for ($i = $start + 1; $i <= $start + $length; $i++) {
            if (! preg_match('/\w/', $this->bare[$i])) {
                $this->bare[$i] = ' ';
            }
        }

        return $start + $length + 2;
    }

    /**
     * Records a literal and blanks it in $bare, except for its interpolated
     * code, whose own literals are already blanked.
     *
     * @param  list<Range>  $interpolations
     */
    private function recordLiteral(int $start, int $end, array $interpolations, ?string $value): void
    {
        $this->literals[$start] = ['end' => $end, 'value' => $value];
        $this->literalEnds[$end] = $start;

        $from = $start;

        foreach ($interpolations as [$codeStart, $codeEnd]) {
            $this->blank($from, $codeStart, inCode: false);
            $from = $codeEnd;
        }

        $this->blank($from, $end, inCode: false);
    }

    /** Replaces [$start, $end) with spaces, keeping line breaks. */
    private function blank(int $start, int $end, bool $inCode = true): void
    {
        for ($i = $start; $i < $end; $i++) {
            if ($this->source[$i] !== "\n" && $this->source[$i] !== "\r") {
                $this->bare[$i] = ' ';

                if ($inCode) {
                    $this->code[$i] = ' ';
                }
            }
        }
    }

    /** The value of a single-line Swift string, or null if it uses an escape Swift does not have. */
    private function unescapeSwift(string $content, string $escape): ?string
    {
        $simple = ['0' => "\0", '\\' => '\\', 't' => "\t", 'n' => "\n", 'r' => "\r", '"' => '"', "'" => "'"];

        return $this->unescape($content, $escape, $simple, '/\Gu\{([0-9A-Fa-f]{1,8})\}/');
    }

    /** The value of a Kotlin "..." string, or null if it uses an escape Kotlin does not have. */
    private function unescapeKotlin(string $content): ?string
    {
        $simple = ['t' => "\t", 'b' => "\x08", 'n' => "\n", 'r' => "\r", "'" => "'", '"' => '"', '\\' => '\\', '$' => '$'];

        return $this->unescape($content, '\\', $simple, '/\Gu([0-9A-Fa-f]{4})/');
    }

    /** @param  array<string, string>  $simple */
    private function unescape(string $content, string $escape, array $simple, string $unicode): ?string
    {
        $value = '';

        for ($i = 0, $length = strlen($content); $i < $length;) {
            if (substr($content, $i, strlen($escape)) !== $escape) {
                $value .= $content[$i++];

                continue;
            }

            $i += strlen($escape);
            $char = $content[$i] ?? '';

            if (isset($simple[$char])) {
                $value .= $simple[$char];
                $i++;
            } elseif (preg_match($unicode, $content, $code, 0, $i) && ($decoded = mb_chr((int) hexdec($code[1]), 'UTF-8')) !== false) {
                $value .= $decoded;
                $i += strlen($code[0]);
            } else {
                return null;
            }
        }

        return $value;
    }
}
