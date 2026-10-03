<?php

namespace Ikromjon\NativePHP\SocialAuth\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Ikromjon\NativePHP\SocialAuth\Data\AuthResult|null signIn(string $provider, array $options = [])
 * @method static \Ikromjon\NativePHP\SocialAuth\Data\AuthResult|null appleSignIn(array $scopes = ['email', 'fullName'], ?string $nonce = null, ?string $state = null)
 * @method static \Ikromjon\NativePHP\SocialAuth\Data\AuthResult|null googleSignIn(?string $nonce = null)
 * @method static string checkAppleCredentialState(string $userId)
 * @method static bool signOut(?string $provider = null)
 *
 * @see \Ikromjon\NativePHP\SocialAuth\SocialAuth
 */
class SocialAuth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Ikromjon\NativePHP\SocialAuth\SocialAuth::class;
    }
}
