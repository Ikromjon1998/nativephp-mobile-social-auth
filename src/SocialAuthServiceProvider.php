<?php

namespace Ikromjon\NativePHP\SocialAuth;

use Ikromjon\NativePHP\SocialAuth\Events\AppleSignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Events\GoogleSignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Events\SignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Providers\ProviderRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class SocialAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/social-auth.php', 'social-auth');

        $this->app->singleton(ProviderRegistry::class, fn () => new ProviderRegistry);

        $this->app->singleton(SocialAuth::class, function ($app) {
            return new SocialAuth($app->make(ProviderRegistry::class));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/social-auth.php' => config_path('social-auth.php'),
        ], 'social-auth-config');

        $this->mirrorProviderEvents();

        if ($this->app->runningInConsole()) {
            // Invoked by NativePHP as this plugin's post_compile hook.
            $this->commands([
                Commands\RegisterUrlSchemesCommand::class,
            ]);
        }
    }

    /**
     * Re-dispatch each provider-specific completion event as SignInCompleted.
     *
     * Fanning out in PHP rather than dispatching both events natively is what
     * keeps this backwards compatible. The native layer is unchanged, so every
     * existing #[OnNative] handler keeps firing exactly once, and listeners
     * written against the generic event work for providers added later.
     */
    protected function mirrorProviderEvents(): void
    {
        Event::listen(AppleSignInCompleted::class, function (AppleSignInCompleted $event) {
            if (! $this->mirroringEnabled()) {
                return;
            }

            SignInCompleted::dispatch(
                provider: 'apple',
                userId: $event->userId,
                identityToken: $event->identityToken,
                authorizationCode: $event->authorizationCode,
                email: $event->email,
                givenName: $event->givenName,
                familyName: $event->familyName,
                displayName: $event->displayName,
                state: $event->state,
                realUserStatus: $event->realUserStatus,
            );
        });

        Event::listen(GoogleSignInCompleted::class, function (GoogleSignInCompleted $event) {
            if (! $this->mirroringEnabled()) {
                return;
            }

            SignInCompleted::dispatch(
                provider: 'google',
                userId: $event->userId,
                identityToken: $event->identityToken,
                authorizationCode: $event->authorizationCode,
                accessToken: $event->accessToken,
                email: $event->email,
                givenName: $event->givenName,
                familyName: $event->familyName,
                displayName: $event->displayName,
                photoUrl: $event->photoUrl,
            );
        });
    }

    /**
     * Checked inside the listener rather than around its registration so the
     * flag responds to a runtime config change, and so boot order cannot decide
     * whether mirroring is available.
     */
    protected function mirroringEnabled(): bool
    {
        return config('social-auth.dispatch_generic_event', true) !== false;
    }
}
