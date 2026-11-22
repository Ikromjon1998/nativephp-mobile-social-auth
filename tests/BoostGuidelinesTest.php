<?php

use Ikromjon\NativePHP\SocialAuth\SocialAuth;
use Ikromjon\NativePHP\SocialAuth\Tests\TestCase;
use Illuminate\Support\Facades\Blade;

uses(TestCase::class);

/**
 * Laravel Boost loads resources/boost/guidelines/core.blade.php from every
 * installed package into the app's AI guidelines, and it is where NativePHP's
 * native:plugin:boost puts a plugin's. These keep the file renderable and in
 * step with the code, so an assistant is never taught an API that moved.
 */
function boostGuidelines(): string
{
    return (string) file_get_contents(dirname(__DIR__).'/resources/boost/guidelines/core.blade.php');
}

test('the boost guidelines render as blade without compiling anything by accident', function () {
    // Outside @verbatim, Blade in the app would compile {{ }} and any registered @directive.
    $prose = preg_replace('/@verbatim.*?@endverbatim/s', '', boostGuidelines());

    expect($prose)->not->toMatch('/\{\{|\{!!|(?<!\w)@\w+/')
        ->and(Blade::render(boostGuidelines()))->toContain('<code-snippet name="Sign-in on a native screen" lang="php">');
});

test('every php example in the boost guidelines parses', function () {
    preg_match_all('/<code-snippet name="([^"]+)" lang="php">(.*?)<\/code-snippet>/s', boostGuidelines(), $snippets, PREG_SET_ORDER);

    $errors = [];

    foreach ($snippets as [, $name, $code]) {
        try {
            token_get_all('<?php '.$code, TOKEN_PARSE);
        } catch (ParseError $e) {
            $errors[$name] = $e->getMessage().' on line '.$e->getLine();
        }
    }

    expect($snippets)->toHaveCount(3)
        ->and($errors)->toBe([]);
});

test('the boost guidelines cover every event, method, error code, secret and core version', function () {
    $root = dirname(__DIR__);
    $manifest = json_decode(file_get_contents($root.'/nativephp.json'), true);
    $composer = json_decode(file_get_contents($root.'/composer.json'), true);

    $methods = array_filter(
        array_map(fn (ReflectionMethod $m) => $m->getName(), (new ReflectionClass(SocialAuth::class))->getMethods(ReflectionMethod::IS_PUBLIC)),
        fn (string $name) => ! str_starts_with($name, '__'),
    );

    // The README's list, which ErrorCodeCoverageTest keeps equal to what the native layers emit.
    preg_match('/^\*\*Error codes:\*\*(.*)$/m', file_get_contents($root.'/README.md'), $line);
    preg_match_all('/`([A-Z_]+)`/', $line[1], $codes);

    $expected = [
        ...array_map(fn (string $event) => class_basename($event), $manifest['events']),
        ...$methods,
        ...$codes[1],
        ...array_keys($manifest['secrets']),
        ...explode('|', $composer['require']['nativephp/mobile']),
    ];

    $missing = array_values(array_filter($expected, fn (string $needle) => ! str_contains(boostGuidelines(), $needle)));

    expect($missing)->toBe([]);
});
