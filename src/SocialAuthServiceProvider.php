<?php

namespace Ikromjon\NativePHP\SocialAuth;

use Illuminate\Support\ServiceProvider;

class SocialAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SocialAuth::class, function () {
            return new SocialAuth;
        });
    }

    public function boot(): void
    {
        //
    }
}
