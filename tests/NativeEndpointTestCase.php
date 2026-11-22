<?php

namespace Ikromjon\NativePHP\SocialAuth\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Native\Mobile\Http\Controllers\DispatchEventFromAppController;
use ReflectionClass;

/**
 * Serves the installed NativePHP core's own POST /_native/api/events route, the
 * endpoint the native bridges post device events to.
 *
 * The route file is the one shipped next to the controller it names, so the
 * tests run against whatever the resolved nativephp/mobile does with an event:
 * 3.3.11 builds it from the whole payload, 4.6.1 and later drop the keys its
 * constructor does not declare first, and both refuse a class that does not
 * implement DispatchableFromNative (GHSA-83jv-8h7x-287p).
 *
 * Native\Mobile\NativeServiceProvider is deliberately not booted. Registering it
 * defines NativePHP's global nativephp_* polyfills (nativephp_call() and the
 * rest, meant for Jump) for the remainder of the PHP process, and functions
 * cannot be undefined. SocialAuth::call() reads an existing nativephp_call() as
 * "running on a device", so every test that runs after this one would reach the
 * polyfill instead of getting null, and what those tests see would depend on
 * the order they run in. The provider also swaps the event dispatcher and
 * registers this route itself. The endpoint needs none of that: its route file
 * and a controller the container builds are all there is to it.
 */
abstract class NativeEndpointTestCase extends TestCase
{
    private const CALLBACKS = 'Native\\Mobile\\Support\\NativeCallbacks';

    /** NATIVEPHP_EVENTS_SIGNED as it was before the test, false when unset. */
    private string|false $signedEvents = false;

    /**
     * On a device the native shell sets NATIVEPHP_EVENTS_SIGNED and signs every
     * event; unsigned posts then get a 403. These tests are about the marker
     * gate and the payloads, so they run unsigned wherever the suite runs, and
     * the variable is put back afterwards.
     */
    protected function setUp(): void
    {
        $this->signedEvents = getenv('NATIVEPHP_EVENTS_SIGNED');
        putenv('NATIVEPHP_EVENTS_SIGNED');

        parent::setUp();

        // From 4.6.1 the endpoint also fires any static callback NativeCallbacks holds
        // for the event's class. Callbacks are process-wide, so drop any an earlier
        // test left behind, as NativePHP's own FakeBridge does. 3.3.x has none.
        if (class_exists(self::CALLBACKS) && method_exists(self::CALLBACKS, 'flush')) {
            (self::CALLBACKS)::flush();
        }
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->signedEvents !== false) {
                putenv('NATIVEPHP_EVENTS_SIGNED='.$this->signedEvents);
            }
        }
    }

    protected function defineRoutes($router): void
    {
        // require, not require_once: every test builds a fresh router.
        require dirname((string) (new ReflectionClass(DispatchEventFromAppController::class))->getFileName(), 4).'/routes/api.php';
    }

    /**
     * Posts an event the way both bridges' injected fetch() does: a JSON body
     * holding the event class and its payload.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function postNativeEvent(string $event, array $payload): TestResponse
    {
        return $this->postJson('/_native/api/events', ['event' => $event, 'payload' => $payload], [
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
    }

    /** The one instance of $event that Event::fake() recorded. */
    protected function dispatchedEvent(string $event): object
    {
        $dispatched = Event::dispatched($event);

        $this->assertCount(1, $dispatched, "Expected exactly one {$event}.");

        return $dispatched->first()[0];
    }
}
