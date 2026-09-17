<?php

$this->app['router']->group(
    [
        'namespace' => 'MadWeb\SocialAuth\Controllers',
        'middleware' => ['web'],
        'as' => 'social.',
    ],
    function ($router) {
        $router->get('social/{social}', 'SocialAuthController@getAccount')->name('auth');
        $router->get('social/{social}/callback', 'SocialAuthController@callback')->name('callback');
        $router->delete('social/{social}/detach', 'SocialAuthController@detachAccount')->middleware('auth')->name('detach');
    }
);
