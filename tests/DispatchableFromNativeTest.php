<?php

use Ikromjon\NativePHP\SocialAuth\Tests\Fixtures\LookalikeEvent;
use Ikromjon\NativePHP\SocialAuth\Tests\Fixtures\MarkedLookalikeEvent;
use Ikromjon\NativePHP\SocialAuth\Tests\NativeEndpointTestCase;
use Ikromjon\NativePHP\SocialAuth\Tests\Support\NativeDispatchSites;
use Illuminate\Support\Facades\Event;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

uses(NativeEndpointTestCase::class);

/**
 * Since nativephp/mobile 3.3.11 and 4.6.1 (GHSA-83jv-8h7x-287p), POST
 * /_native/api/events builds only classes that implement DispatchableFromNative
 * and answers HTTP 200 {"success": false} for any other, so an event without
 * the marker is lost without an error anywhere. 3.3.11 also passes every
 * payload key as a named constructor argument, failing with "Unknown named
 * parameter" on one the constructor does not declare; 4.6.1 and later drop it.
 *
 * Each site where the Swift or Kotlin code dispatches an event is read from the
 * source, held against the event class it names, and then posted to the real
 * endpoint of the installed core with the keys that site sends.
 */
beforeEach(function () {
    LookalikeEvent::$constructed = 0;
    MarkedLookalikeEvent::$constructed = 0;
});

/** @return list<string> */
function eventsInPluginManifest(): array
{
    return json_decode(file_get_contents(dirname(__DIR__).'/nativephp.json'), true)['events'];
}

/**
 * The keys a site may send as "" that its event declares nullable, so they
 * arrive as null. A non-nullable parameter (iOS sends GoogleSignInCompleted's
 * userId ?? "") would make the endpoint fail building the event instead.
 *
 * @param  array{event: string, emptyFallbacks: list<string>}  $site
 * @return list<string>
 */
function emptyFallbacksThatArriveAsNull(array $site): array
{
    $nullable = [];

    foreach ((new ReflectionClass($site['event']))->getConstructor()?->getParameters() ?? [] as $parameter) {
        if ($parameter->allowsNull()) {
            $nullable[] = $parameter->getName();
        }
    }

    return array_values(array_intersect($site['emptyFallbacks'], $nullable));
}

arch('native events are dispatchable')
    ->expect('Ikromjon\NativePHP\SocialAuth\Events')
    ->toImplement(DispatchableFromNative::class);

test('the parser reads every dispatch call in the native sources', function () {
    $raw = NativeDispatchSites::rawCallCounts();
    $sites = NativeDispatchSites::all();
    $parsed = array_fill_keys(array_keys($raw), 0);

    foreach ($sites as $site) {
        $file = substr($site['location'], 0, strrpos($site['location'], ':'));
        $parsed[$file] = ($parsed[$file] ?? 0) + 1;
    }

    expect($parsed)->toBe($raw)
        ->and(array_column($sites, 'platform'))->toContain('ios', 'android');
});

test('each native dispatch site names a dispatchable event and its parameters', function (array $site) {
    $event = $site['event'];

    expect(eventsInPluginManifest())->toContain($event)
        ->and(class_exists($event))->toBeTrue("{$event} does not exist")
        ->and(is_subclass_of($event, DispatchableFromNative::class))->toBeTrue("{$event} does not implement DispatchableFromNative");

    $declared = [];
    $required = [];

    foreach ((new ReflectionClass($event))->getConstructor()?->getParameters() ?? [] as $parameter) {
        $declared[] = $parameter->getName();

        if (! $parameter->isOptional()) {
            $required[] = $parameter->getName();
        }
    }

    expect(array_values(array_diff(array_keys($site['payload']), $declared)))->toBe([], 'Keys the constructor does not declare')
        ->and(array_values(array_diff($required, array_keys($site['payload']))))->toBe([], 'Required parameters the site does not send');
})->with(fn () => NativeDispatchSites::dataset());

test('every event in the manifest is dispatched by native code', function () {
    $dispatched = array_column(NativeDispatchSites::all(), 'event');

    expect(array_values(array_diff(eventsInPluginManifest(), $dispatched)))->toBe([]);
});

test('each platform still dispatches every event it did', function () {
    // The tests above hold each site to its event, and every event to some site,
    // so a whole site could disappear while the other platform still sends that
    // event. Pin how often each platform dispatches each event: deleting or
    // adding a dispatch site has to be deliberate and show up here.
    $counts = [];

    foreach (NativeDispatchSites::all() as $site) {
        $event = class_basename($site['event']);
        $counts[$site['platform']][$event] = ($counts[$site['platform']][$event] ?? 0) + 1;
    }

    ksort($counts['ios']);
    ksort($counts['android']);

    expect($counts)->toBe([
        'android' => ['GoogleSignInCompleted' => 1, 'SignInFailed' => 4],
        'ios' => ['AppleSignInCompleted' => 1, 'GoogleSignInCompleted' => 1, 'SignInFailed' => 3],
    ]);
});

test('events can be dispatched from native', function (array $site) {
    Event::fake([$site['event']]);

    $this->postNativeEvent($site['event'], $site['payload'])
        ->assertOk()
        ->assertJsonPath('success', true);

    Event::assertDispatchedTimes($site['event'], 1);

    $event = $this->dispatchedEvent($site['event']);
    $received = [];

    foreach (array_keys($site['payload']) as $key) {
        expect(property_exists($event, $key))->toBeTrue("{$site['event']} has no \${$key}");

        $received[$key] = $event->{$key};
    }

    expect($received)->toBe($site['payload']);
})->with(fn () => NativeDispatchSites::dataset());

test('optional fields a bridge sends empty reach listeners as null', function (array $site) {
    // Laravel's ConvertEmptyStringsToNull middleware runs on this request.
    $empty = emptyFallbacksThatArriveAsNull($site);

    Event::fake([$site['event']]);

    $this->postNativeEvent($site['event'], array_replace($site['payload'], array_fill_keys($empty, '')))
        ->assertOk()
        ->assertJsonPath('success', true);

    Event::assertDispatchedTimes($site['event'], 1);

    $event = $this->dispatchedEvent($site['event']);

    foreach ($site['payload'] as $key => $sample) {
        expect($event->{$key})->toBe(in_array($key, $empty, true) ? null : $sample);
    }
})->with(fn () => array_filter(
    NativeDispatchSites::dataset(),
    fn (array $arguments) => emptyFallbacksThatArriveAsNull($arguments[0]) !== [],
));

test('an event without the marker is refused before it is constructed', function () {
    Event::fake([LookalikeEvent::class, MarkedLookalikeEvent::class]);

    $payload = ['provider' => 'google', 'error' => 'Forged by a page', 'errorCode' => 'FORGED'];

    $this->postNativeEvent(LookalikeEvent::class, $payload)
        ->assertOk()
        ->assertJsonPath('success', false);

    expect(LookalikeEvent::$constructed)->toBe(0);
    Event::assertNothingDispatched();

    // The same class and payload with the marker goes through, so the marker is what decided.
    $this->postNativeEvent(MarkedLookalikeEvent::class, $payload)
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(MarkedLookalikeEvent::$constructed)->toBe(1);
    Event::assertDispatchedTimes(MarkedLookalikeEvent::class, 1);
});
