<?php

namespace Ikromjon\NativePHP\SocialAuth\Tests;

use Native\Mobile\NativeServiceProvider;

/**
 * Boots NativePHP's own service provider, which Native::test() needs.
 *
 * Booting it defines NativePHP's global nativephp_* polyfills, including
 * nativephp_call(), for the rest of the PHP process. Tests that need that
 * function to be absent live in the "Plugin" suite, which phpunit.xml runs
 * before the "SuperNative" suite that uses this case.
 */
abstract class NativeAppTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [NativeServiceProvider::class, ...parent::getPackageProviders($app)];
    }
}
