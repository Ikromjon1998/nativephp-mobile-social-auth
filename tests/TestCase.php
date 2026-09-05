<?php

namespace Ikromjon\NativePHP\SocialAuth\Tests;

use Ikromjon\NativePHP\SocialAuth\SocialAuthServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base case for the tests that need a booted application.
 *
 * Most of this suite is structural and needs no framework, so only the tests
 * that actually run the artisan hook opt into this.
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SocialAuthServiceProvider::class];
    }
}
