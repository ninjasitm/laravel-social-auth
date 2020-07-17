<?php

namespace MadWeb\SocialAuth;

use Illuminate\Support\ServiceProvider;
use MadWeb\SocialAuth\Console\AddSocialProviderCommand;
use MadWeb\SocialAuth\Console\CacheRefreshCommand;

class SocialAuthServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @param SocialProvidersLoader $loader
     * @return void
     */
    public function boot(SocialProvidersLoader $loader)
    {
        $this->registerResources();

        // Share social Providers for views
        view()->composer(['social-auth::buttons', 'social-auth::attach'], function ($view) use ($loader) {
            /* @var \Illuminate\View\View $view */
            $view->with('socialProviders', $loader->getSocialProviders());
        });

        $loader->registerSocialProviders();

        $this->app->register(\SocialiteProviders\Manager\ServiceProvider::class);
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/social-auth.php',
            'social-auth'
        );

        $this->app->singleton('command.social-auth.refresh', CacheRefreshCommand::class);
        $this->app->singleton('command.social-auth.add', AddSocialProviderCommand::class);

        $this->commands(['command.social-auth.refresh', 'command.social-auth.add']);
    }

    /**
     * Register package resources.
     */
    protected function registerResources()
    {
        $resource_folder = __DIR__.'/../resources';

        $this->publishes([
            __DIR__.'/../config/social-auth.php' => $this->app->configPath().'/social-auth.php',
        ], 'config');

        if (! class_exists('CreateSocialProvidersTable')) {
            // Publish the migration
            $timestamp = date('Y_m_d_His', time());
            $this->publishes([
                __DIR__.'/../database/migrations/create_social_providers_table.php.stub' => $this->app->databasePath().'/migrations/'.$timestamp.'_create_social_providers_table.php',
            ], 'migrations');
        }

        // Views
        $this->loadViewsFrom($resource_folder.'/views', 'social-auth');

        $this->publishes([
            $resource_folder.'/views' => resource_path('views/vendor/social-auth'),
        ], 'views');

        // Translations
        $this->loadTranslationsFrom($resource_folder.'/lang', 'social-auth');

        $this->publishes([
            $resource_folder.'/lang' => resource_path('lang/vendor/social-auth'),
        ], 'lang');

        // Routes
        $this->loadRoutesFrom(__DIR__.'/../routes/routes.php');
    }
}
