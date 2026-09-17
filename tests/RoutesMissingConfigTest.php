<?php

namespace MadWeb\SocialAuth\Test;

use MadWeb\SocialAuth\SocialAuthServiceProvider;

class RoutesMissingConfigTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [StaleConfigSocialAuthServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $config = require __DIR__.'/../config/social-auth.php';
        unset($config['routes']);
        $app['config']->set('social-auth', $config);
    }

    public function test_package_routes_remain_registered_when_cached_config_lacks_routes_key()
    {
        $this->assertNotNull(app('router')->getRoutes()->getByName('social.auth'));
        $this->assertNotNull(app('router')->getRoutes()->getByName('social.callback'));
        $this->assertNotNull(app('router')->getRoutes()->getByName('social.detach'));
    }
}

class StaleConfigSocialAuthServiceProvider extends SocialAuthServiceProvider
{
    public function register()
    {
    }
}
