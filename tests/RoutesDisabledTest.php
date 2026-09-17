<?php

namespace MadWeb\SocialAuth\Test;

class RoutesDisabledTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('social-auth.routes', false);
    }

    public function test_package_routes_are_not_registered_when_disabled()
    {
        $this->assertNull(app('router')->getRoutes()->getByName('social.auth'));
        $this->assertNull(app('router')->getRoutes()->getByName('social.callback'));
        $this->assertNull(app('router')->getRoutes()->getByName('social.detach'));

        $this->get('/social/facebook')->assertNotFound();
        $this->get('/social/facebook/callback')->assertNotFound();
        $this->get('/social/facebook/detach')->assertNotFound();
    }
}
