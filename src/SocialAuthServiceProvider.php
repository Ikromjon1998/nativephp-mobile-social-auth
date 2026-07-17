<?php

namespace Ikromjon\NativePHP\SocialAuth;

use Illuminate\Support\ServiceProvider;

class SocialAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/social-auth.php', 'social-auth');

        $this->app->singleton(SocialAuth::class, function () {
            return new SocialAuth;
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/social-auth.php' => config_path('social-auth.php'),
        ], 'social-auth-config');
    }
}
