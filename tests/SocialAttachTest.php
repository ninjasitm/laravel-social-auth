<?php

namespace MadWeb\SocialAuth\Test;

use MadWeb\SocialAuth\Models\SocialProvider;

class SocialAttachTest extends TestCase
{
    public function test_attach_social()
    {
        $User = $this->getTestUser();

        $this->actingAs($User)->get(route('social.callback', $this->social));

        $this->assertTrue($User->socials()->where('slug', $this->social['social'])->exists());
    }

    public function test_detach_social()
    {
        $this->socialiteMock->create('token', 'social-id');

        $User = $this->getTestUser();

        $User->socials()->attach(
            SocialProvider::whereSlug($this->social['social'])->first(),
            [
                'social_id' => 'social-id',
                'token' => 'token',
            ]
        );

        $this->actingAs($User)->get(route('social.detach', $this->social));

        $this->assertTrue(! $User->socials()->whereSlug($this->social['social'])->exists());
    }

    public function test_detach_error()
    {
        $this->socialiteMock->create('token', 'social-id');

        $User = $this->getTestUser();

        $this->actingAs($User)->get(route('social.detach', $this->social));

        $Errors = $this->app['session.store']->get('errors');

        $Social = SocialProvider::whereSlug($this->social['social'])->first();
        $this->assertSame(
            $Errors->first(),
            trans(
                'social-auth::messages.detach_error',
                ['social' => $Social->label]
            )
        );
    }

    public function test_detach_last_social_error()
    {
        $this->socialiteMock->create('token', 'social-id');

        $User = $this->getTestUser();

        $User->{$User->getEmailField()} = '';
        $User->save();

        $Social = SocialProvider::whereSlug($this->social['social'])->first();
        $User->socials()->attach(
            $Social,
            [
                'social_id' => 'social-id',
                'token' => 'token',
            ]
        );

        $this->actingAs($User)->get(route('social.detach', $this->social));

        $Errors = $this->app['session.store']->get('errors');

        $this->assertSame(
            $Errors->first(),
            trans('social-auth::messages.detach_error_last')
        );
    }

    public function test_already_attached_social()
    {
        $this->disableExceptionHandling();

        $User = $this->getTestUser();

        $social_model = config('social-auth.models.social');

        $Social = $social_model::whereSlug($this->social['social'])->first();
        $User->attachSocial(
            $Social,
            'social-id',
            'token'
        );

        $this->actingAs($User)->get(route('social.callback', $this->social));

        $Errors = $this->app['session.store']->get('errors');

        $this->assertSame(
            $Errors->first(),
            trans('social-auth::messages.user_already_attach', ['social' => $Social->label])
        );
    }

    public function test_someone_already_attached_social()
    {
        $this->disableExceptionHandling();
        $this->socialiteMock->create('token', 'social-id');

        $User = $this->getTestUser();
        $social_model = config('social-auth.models.social');
        $NewUser = User::create(['email' => 'user@example.com', 'avatar' => '']);

        $Social = $social_model::whereSlug($this->social['social'])->first();
        $NewUser->attachSocial(
            $Social,
            'social-id',
            'token'
        );

        $this->actingAs($User)->get(route('social.callback', $this->social));

        $Errors = $this->app['session.store']->get('errors');

        $this->assertSame(
            $Errors->first(),
            trans('social-auth::messages.someone_already_attach')
        );
    }
}
