<?php

namespace MadWeb\SocialAuth\Test;

use Illuminate\Support\Facades\Event;
use MadWeb\SocialAuth\Events\SocialUserAttached;
use MadWeb\SocialAuth\Events\SocialUserAuthenticated;
use MadWeb\SocialAuth\Events\SocialUserCreated;
use MadWeb\SocialAuth\Events\SocialUserDetached;
use MadWeb\SocialAuth\Models\SocialProvider;
use MadWeb\SocialAuth\SocialProviderManager;
use Mockery;

class EventsTest extends TestCase
{
    protected $testEmail = 'some.mail@mail.com';

    public function setUp(): void
    {
        parent::setUp();

        Event::fake();
    }

    public function test_social_user_created()
    {
        $this->socialiteMock->setEmail($this->testEmail)->create();

        $this->get(route('social.callback', $this->social));

        Event::assertDispatched(SocialUserCreated::class);
    }

    public function test_social_user_attach()
    {
        $Manager = new SocialProviderManager(SocialProvider::first());

        $SocialUser = Mockery::mock(\Laravel\Socialite\Two\User::class);
        $SocialUser->token = 'random-token';
        $SocialUser->expiresIn = 5000;
        $SocialUser->shouldReceive('getId')->andReturn('random-id');

        $Manager->attach($this->getTestUser(), $SocialUser);

        Event::assertDispatched(SocialUserAttached::class);
    }

    public function test_social_user_authenticated()
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

        Event::assertDispatched(SocialUserAuthenticated::class);
    }

    public function test_social_user_detach()
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

        $this->actingAs($User)->delete(route('social.detach', $this->social));

        Event::assertDispatched(SocialUserDetached::class);
    }
}
