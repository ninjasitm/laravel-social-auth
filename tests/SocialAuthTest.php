<?php

namespace MadWeb\SocialAuth\Test;

use DateInterval;
use Illuminate\Support\Facades\Event;
use MadWeb\SocialAuth\Models\SocialProvider;
use MadWeb\SocialAuth\Exceptions\SocialGetUserInfoException;

class SocialAuthTest extends TestCase
{
    protected $testEmail = 'test.user@test.com';

    public function test_register_via_social_network()
    {
        $this->socialiteMock->setEmail($this->testEmail)->create();

        $this->get(route('social.callback', $this->social));

        $this->assertDatabaseHas('users', ['email' => $this->testEmail]);
    }

    public function test_register_with_expires_in()
    {
        Event::fake();

        $this->socialiteMock->withExpiresIn()->create();

        $this->get(route('social.callback', $this->social));

        $this->assertTrue(
            $this->app['auth']->user()->socials()
            ->wherePivot(
                'expires_in',
                date_create('now')
                    ->add(DateInterval::createFromDateString('5000 seconds'))
                    ->format(app('db')->getQueryGrammar()->getDateFormat())
            )
            ->exists()
        );
    }

    public function test_request_exception()
    {
        $this->disableExceptionHandling();

        $this->socialiteMock->withRequestException()->create();

        $this->expectException(SocialGetUserInfoException::class);

        $this->get(route('social.callback', $this->social));
    }

    public function test_null_user_return()
    {
        $this->disableExceptionHandling();

        $this->socialiteMock->withNullUser()->create();

        $this->expectException(SocialGetUserInfoException::class);

        $this->get(route('social.callback', $this->social));
    }

    public function test_login_via_existing_account()
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

        $this->get(route('social.callback', $this->social));

        $this->assertSame($this->app['auth']->id(), $User->getKey());
    }
}
