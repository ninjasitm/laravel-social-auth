<?php

namespace MadWeb\SocialAuth\Test;

class EnvironmentTest extends TestCase
{
    public function test_facebook_provider_exists()
    {
        $social_model = config('social-auth.models.social');

        $this->assertTrue($social_model::whereSlug('facebook')->exists());
    }

    public function test_google_provider_exists()
    {
        $social_model = config('social-auth.models.social');

        $this->assertTrue($social_model::whereSlug('google')->exists());
    }
}
