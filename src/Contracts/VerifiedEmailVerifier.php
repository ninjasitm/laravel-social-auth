<?php

namespace MadWeb\SocialAuth\Contracts;

use Laravel\Socialite\Contracts\User as SocialUser;
use MadWeb\SocialAuth\Models\SocialProvider;

interface VerifiedEmailVerifier
{
    /**
     * The email is the package-normalized value used for lookup and persistence.
     * Only the exact boolean true permits the social authentication flow.
     *
     * @return mixed
     */
    public function isVerified(SocialUser $user, SocialProvider $provider, string $email): mixed;
}
