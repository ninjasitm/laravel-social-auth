<?php

namespace MadWeb\SocialAuth;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Socialite\Contracts\User as SocialUser;
use MadWeb\SocialAuth\Contracts\SocialAuthenticatable;
use MadWeb\SocialAuth\Events\SocialUserAttached;
use MadWeb\SocialAuth\Events\SocialUserCreated;
use MadWeb\SocialAuth\Models\SocialProvider;

class SocialProviderManager
{
    /**
     * @var SocialProvider
     */
    protected $social;

    /**
     * SocialProviderManager constructor.
     * @param SocialProvider $social
     */
    public function __construct(SocialProvider $social)
    {
        $this->social = $social;
    }

    /**
     * @param string $key
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function socialUserQuery(string $key)
    {
        $subjectKey = config('social-auth.foreign_keys.social_subject', 'social_id');
        if ($subjectKey === 'social_id') {
            $subjectKey = config('social-auth.foreign_keys.socials', $subjectKey);
        }

        return $this->social->users()->wherePivot($subjectKey, $key);
    }

    /**
     * Gets user by unique social identifier.
     *
     * @param string $key
     * @return mixed
     */
    public function getUserByKey(string $key)
    {
        $matches = $this->socialUserQuery($key)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * @param SocialAuthenticatable $user
     * @param SocialUser $socialUser
     */
    public function attach(
        SocialAuthenticatable $user,
        SocialUser $socialUser,
        bool $dispatchEvent = true,
        ?string $subject = null
    )
    {
        $user->attachSocial(
            $this->social,
            $subject ?? $socialUser->getId(),
            $socialUser->token,
            $socialUser->expiresIn ?? null
        );

        if ($dispatchEvent) {
            event(new SocialUserAttached($user, $this->social, $socialUser));
        }
    }

    /**
     * Create new system user by social user data.
     *
     * @param Authenticatable $userModel
     * @param SocialProvider $social
     * @param SocialUser $socialUser
     * @return Authenticatable
     */
    public function createNewUser(
        Authenticatable $userModel,
        SocialProvider $social,
        SocialUser $socialUser,
        bool $dispatchEvent = true,
        ?string $email = null,
        ?string $subject = null
    ): Authenticatable {
        $data = $userModel->mapSocialData($socialUser);
        if ($email !== null) {
            $data[$userModel->getEmailField()] = $email;
        }
        $NewUser = $userModel->create($data);

        $NewUser->attachSocial(
            $social,
            $subject ?? $socialUser->getId(),
            $socialUser->token,
            $socialUser->expiresIn ?? null
        );

        if ($dispatchEvent) {
            event(new SocialUserCreated($NewUser));
        }

        return $NewUser;
    }
}
