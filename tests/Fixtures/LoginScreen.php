<?php

namespace Ikromjon\NativePHP\SocialAuth\Tests\Fixtures;

use Ikromjon\NativePHP\SocialAuth\Events\GoogleSignInCompleted;
use Ikromjon\NativePHP\SocialAuth\Events\SignInFailed;
use Ikromjon\NativePHP\SocialAuth\Facades\SocialAuth;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * The Login screen from the Boost guidelines with its Google handler
 * recording what it received, to show that a handler taking only some
 * fields gets them from a full payload. BoostGuidelineExamplesTest keeps
 * its handlers equal to the guidelines' own. It renders an element tree
 * because a test has no Blade view to infer.
 */
class LoginScreen extends NativeComponent
{
    public ?string $error = null;

    /** @var array<string, mixed> */
    public array $received = [];

    public function signInWithGoogle(): void
    {
        SocialAuth::googleSignIn();
    }

    #[On(GoogleSignInCompleted::class)]
    public function googleSignedIn(string $userId, ?string $identityToken = null, ?string $email = null): void
    {
        $this->received = get_defined_vars();
    }

    #[On(SignInFailed::class)]
    public function signInFailed(string $provider, string $error, ?string $errorCode = null): void
    {
        if ($errorCode !== 'CANCELED') {
            $this->error = $error;
        }
    }

    public function render(): Element
    {
        return Column::make(Text::make($this->error ?? 'Sign in'));
    }
}
