<?php

namespace Ikromjon\NativePHP\SocialAuth\Tests\Fixtures;

use Ikromjon\NativePHP\SocialAuth\Events\AppleSignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Events\GoogleSignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Events\SignInFailed;
use Ikromjon\NativePHP\SocialAuth\Facades\SocialAuth;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * A NativePHP 4 sign-in screen built the way the README and the Boost
 * guidelines tell apps to: start sign-in through the facade, then handle
 * the result in #[On] methods whose parameters are named after the payload
 * keys. Each handler records what it received, by event class.
 */
class SignInScreen extends NativeComponent
{
    /** @var array<string, array<string, mixed>> */
    public array $received = [];

    public function signInWithApple(): void
    {
        SocialAuth::appleSignIn();
    }

    public function signInWithGoogle(): void
    {
        SocialAuth::googleSignIn();
    }

    #[On(AppleSignInCompleted::class)]
    public function appleSignedIn(
        string $userId,
        ?string $identityToken = null,
        ?string $authorizationCode = null,
        ?string $email = null,
        ?string $givenName = null,
        ?string $familyName = null,
        ?string $displayName = null,
        ?string $state = null,
        ?string $realUserStatus = null,
    ): void {
        $this->received[AppleSignInCompleted::class] = get_defined_vars();
    }

    #[On(GoogleSignInCompleted::class)]
    public function googleSignedIn(
        string $userId,
        ?string $identityToken = null,
        ?string $email = null,
        ?string $displayName = null,
        ?string $givenName = null,
        ?string $familyName = null,
        ?string $photoUrl = null,
        ?string $accessToken = null,
        ?string $authorizationCode = null,
    ): void {
        $this->received[GoogleSignInCompleted::class] = get_defined_vars();
    }

    #[On(SignInFailed::class)]
    public function signInFailed(string $provider, string $error, ?string $errorCode = null): void
    {
        $this->received[SignInFailed::class] = get_defined_vars();
    }

    public function render(): Element
    {
        return Column::make(Text::make('Sign in'));
    }
}
