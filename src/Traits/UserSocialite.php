<?php

namespace MadWeb\SocialAuth\Traits;

use DateInterval;
use Laravel\Socialite\Contracts\User;
use MadWeb\SocialAuth\Models\SocialProvider;

trait UserSocialite
{
    /**
     * User socials relationship.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function socials()
    {
        $social_pivot_table_name = config('social-auth.table_names.user_has_social_provider');

        $subjectKey = config('social-auth.foreign_keys.social_subject', 'social_id');
        if ($subjectKey === 'social_id') {
            $subjectKey = config('social-auth.foreign_keys.socials', $subjectKey);
        }

        return $this->belongsToMany(
            SocialProvider::class,
            $social_pivot_table_name,
            config('social-auth.foreign_keys.users'),
            'social_provider_id'
        )->withPivot($subjectKey, 'token', 'expires_in');
    }

    /**
     * Check social network is attached to user.
     *
     * @param $slug
     * @return mixed
     */
    public function isAttached(string $slug): bool
    {
        return $this->socials()->where(['slug' => $slug])->exists();
    }

    /**
     * Attach social network provider to the user.
     *
     * @param SocialProvider $social
     * @param string $socialId
     * @param string $token
     * @param int $expiresIn
     */
    public function attachSocial($social, string $socialId, string $token, int $expiresIn = null)
    {
        $subjectKey = config('social-auth.foreign_keys.social_subject', 'social_id');
        if ($subjectKey === 'social_id') {
            $subjectKey = config('social-auth.foreign_keys.socials', $subjectKey);
        }
        $data = [$subjectKey => $socialId, 'token' => $token];

        $expiresIn = $expiresIn
            ? date_create('now')
                ->add(DateInterval::createFromDateString($expiresIn.' seconds'))
                ->format($this->getDateFormat())
            : false;

        if ($expiresIn) {
            $data['expires_in'] = $expiresIn;
        }

        $this->socials()->attach($social, $data);
    }

    /**
     * @param User $socialUser
     * @return array
     */
    public function mapSocialData(User $socialUser)
    {
        $raw = $socialUser->getRaw();
        $name = $socialUser->getName() ?? $socialUser->getNickname();
        $name = $name ?? $socialUser->getEmail();

        $result = [
            $this->getEmailField() => $socialUser->getEmail(),
            'name' => $name,
            'verified' => $raw['verified'] ?? true,
            'avatar' => $socialUser->getAvatar(),
        ];

        return $result;
    }

    /**
     * Get model email field name.
     *
     * @return string
     */
    public function getEmailField(): string
    {
        return 'email';
    }
}
