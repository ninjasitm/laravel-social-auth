<?php

namespace MadWeb\SocialAuth\Test;

class RoutesTest extends TestCase
{
    public function test_routes_are_enabled_by_default()
    {
        $this->assertTrue(config('social-auth.routes'));
        $this->assertSame('social/{social}', app('router')->getRoutes()->getByName('social.auth')->uri());
        $this->assertSame('social/{social}/callback', app('router')->getRoutes()->getByName('social.callback')->uri());
        $this->assertSame('social/{social}/detach', app('router')->getRoutes()->getByName('social.detach')->uri());
    }
}
