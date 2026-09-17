<?php

namespace MadWeb\SocialAuth\Test\Utils;

use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Contracts\Factory as Socialite;
use Mockery;

class SocialiteMock
{
    /**
     * @var bool
     */
    protected $request_exception = false;

    protected $request_exception_message = '';

    /**
     * @var bool
     */
    protected $null_user = false;

    /**
     * @var bool
     */
    protected $with_expires_in = false;

    /**
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

    /**
     * @var string
     */
    protected $email;

    protected $calls = ['driver' => 0, 'user' => 0, 'stateless' => 0, 'redirect' => 0];

    protected $redirect_url = 'https://facebook.com/oauth';

    protected $forced_redirect_url = null;

    protected $parameters = [];

    /**
     * SocialiteMock constructor.
     * @param $app
     * @param $email
     */
    public function __construct($app, $email)
    {
        $this->app = $app;
        $this->email = $email;
    }

    /**
     * Mock the Socialite Factory, so we can hijack the OAuth Request.
     * @param  string $email
     * @param  string $token
     * @param  string $id
     * @return Mockery\MockInterface
     */
    public function __invoke($email, $token = 'random-token', $id = 'random-id')
    {
        return $this->create($email, $token, $id);
    }

    /**
     * Mock the Socialite Factory, so we can hijack the OAuth Request.
     * @param  string $token
     * @param  string $id
     * @return Mockery\MockInterface
     */
    public function create($token = 'random-token', $id = 'random-id')
    {
        $this->calls = ['driver' => 0, 'user' => 0, 'stateless' => 0, 'redirect' => 0];
        $this->parameters = [];
        $user = Mockery::mock(\Laravel\Socialite\Two\User::class);

        $user->token = $token;
        if ($this->with_expires_in) {
            $user->expiresIn = 5000;
        }
        $user->shouldReceive('getId')->andReturn($id);
        $user->shouldReceive('getEmail')->andReturn($this->email);
        $user->shouldReceive('getName')->andReturn('John Doe');
        $user->shouldReceive('getNickname')->andReturn('John Doe');
        $user->shouldReceive('getAvatar')->andReturn('http://example.com');
        $user->shouldReceive('getRaw')->andReturn(['verified' => true]);

        $provider = Mockery::mock(\Laravel\Socialite\Two\FacebookProvider::class);

        $expectation = $provider->shouldReceive('user')->andReturnUsing(function () use ($user) {
            $this->calls['user']++;

            if ($this->request_exception) {
                throw new \Exception($this->request_exception_message);
            }
            if ($this->null_user) {
                return null;
            }

            return $user;
        });

        $provider
            ->shouldReceive('redirect')
            ->andReturnUsing(function () {
                $this->calls['redirect']++;

                return new RedirectResponse($this->redirect_url);
            });
        $provider
            ->shouldReceive('with')
            ->andReturnUsing(function (array $parameters) use ($provider) {
                $this->parameters = $parameters;
                if ($this->forced_redirect_url === null) {
                    $this->redirect_url = 'https://facebook.com/oauth?'.http_build_query($parameters);
                }

                return $provider;
            });
        $provider
            ->shouldReceive('stateless')
            ->andReturnUsing(function () use ($provider) {
                $this->calls['stateless']++;

                return $provider;
            });

        $service = Mockery::mock(\Laravel\Socialite\SocialiteManager::class);
        $service
            ->shouldReceive('driver')
            ->andReturnUsing(function () use ($provider) {
                $this->calls['driver']++;

                return $provider;
            });

        $this->app->instance(Socialite::class, $service);

        return $service;
    }

    /**
     * @return $this
     */
    public function withRequestException(string $message = '')
    {
        $this->request_exception = true;
        $this->request_exception_message = $message;

        return $this;
    }

    /**
     * @return $this
     */
    public function withNullUser()
    {
        $this->null_user = true;

        return $this;
    }

    /**
     * @return $this
     */
    public function withExpiresIn()
    {
        $this->with_expires_in = true;

        return $this;
    }

    /**
     * @param string $email
     * @return $this
     */
    public function setEmail(string $email)
    {
        $this->email = $email;

        return $this;
    }

    public function withRedirectUrl(string $url)
    {
        $this->forced_redirect_url = $url;

        return $this;
    }

    public function calls(): array
    {
        return $this->calls;
    }

    public function parameters(): array
    {
        return $this->parameters;
    }
}
