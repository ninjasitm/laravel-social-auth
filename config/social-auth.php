<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Package Routes
    |--------------------------------------------------------------------------
    |
    | Disable this when the application defines its own social authentication
    | routes. It is enabled by default for backwards compatibility.
    |
    */
    'routes' => true,

    // Nullable class-string implementing VerifiedEmailVerifier.
    'verified_email_verifier' => null,

    /*
     * Stateless web OAuth uses encrypted, single-use state, a shared
     * lock-capable cache, and a Secure host-only cookie. The default lifetime
     * is ten minutes; cookie_secure=false is for local HTTP development only.
     */
    'stateless_state' => [
        'ttl' => 600,
        'store' => null,
        'cookie_secure' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Additional service providers
    |--------------------------------------------------------------------------
    |
    | The social providers listed here will enable support for additional social
    | providers which provided by https://socialiteproviders.netlify.com just
    | add new event listener from the installation guide
    |
    */
    'providers' => [
        //
    ],

    'models' => [
        /*
         * When using the "UserSocialite" trait from this package, we need to know which
         * Eloquent model should be used to retrieve your available social providers. Of course, it
         * is often just the "SocialProvider" model but you may use whatever you like.
         */
        'social' => \MadWeb\SocialAuth\Models\SocialProvider::class,

        /*
         * User model which you will use as "SocialAuthenticatable"
         */
        'user' => \App\User::class,
    ],

    'table_names' => [

        /*
        |--------------------------------------------------------------------------
        | Users Table
        |--------------------------------------------------------------------------
        |
        | The table for storing relation between users and social providers. Also there is
        | a place for saving "user social network id", "token", "expiresIn" if it exist
        |
        */
        'user_has_social_provider' => 'user_has_social_provider',

        /*
        |--------------------------------------------------------------------------
        | Social Providers Table
        |--------------------------------------------------------------------------
        |
        | The table that contains all social network providers which your application use.
        |
        */
        'social_providers' => 'social_providers',
    ],

    'foreign_keys' => [

        /*
         * The name of the foreign key to the users table.
         */
        'users' => 'user_id',

        /*
         * The name of the foreign key to the socials table
         */
        'socials' => 'social_id',

        /* The social account subject column on the pivot. */
        'social_subject' => 'social_id',

    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication redirection
    |--------------------------------------------------------------------------
    |
    | Redirect path after success/error login via social network
    |
    */
    'redirect' => '/home',
];
