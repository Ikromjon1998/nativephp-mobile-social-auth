<?php

namespace Ikromjon\NativePHP\SocialAuth\Tests\Support;

use RuntimeException;

/**
 * Code samples cut out of the texts that ship with the plugin, so the tests
 * run what a reader copies rather than a hand-kept copy that can drift.
 */
final class DocumentedExamples
{
    /**
     * The fenced code blocks of a README section, in order, as [language, code].
     *
     * The section starts at the given heading line and ends at the next
     * heading of the same or a higher level outside a code block.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function readmeBlocks(string $heading): array
    {
        $level = strspn($heading, '#');
        $found = false;
        $language = null;
        $lines = [];
        $blocks = [];

        foreach (explode("\n", self::read('README.md')) as $line) {
            if (! $found) {
                $found = $line === $heading;

                continue;
            }

            if ($language === null) {
                if (preg_match('/^#{1,'.$level.'} /', $line)) {
                    break;
                }

                if (preg_match('/^```(\w+)$/', $line, $fence)) {
                    $language = $fence[1];
                    $lines = [];
                }

                continue;
            }

            if ($line === '```') {
                $blocks[] = [$language, implode("\n", $lines)."\n"];
                $language = null;

                continue;
            }

            $lines[] = $line;
        }

        if (! $found) {
            throw new RuntimeException("README.md has no heading [{$heading}].");
        }

        return $blocks;
    }

    /** The code of a named <code-snippet> in the Boost guidelines. */
    public static function guidelineSnippet(string $name): string
    {
        $pattern = '/<code-snippet name="'.preg_quote($name, '/').'" lang="\w+">\n(.*?)<\/code-snippet>/s';

        if (! preg_match($pattern, self::read('resources/boost/guidelines/core.blade.php'), $snippet)) {
            throw new RuntimeException("The Boost guidelines have no snippet named [{$name}].");
        }

        return $snippet[1];
    }

    /** Declare the class a PHP sample defines, once per process. */
    public static function declare(string $class, string $code): void
    {
        if (! class_exists($class, false)) {
            eval((string) preg_replace('/^<\?php\s*/', '', $code));
        }

        if (! class_exists($class, false)) {
            throw new RuntimeException("The sample did not declare [{$class}].");
        }
    }

    private static function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }
}
