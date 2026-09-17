<?php

namespace MadWeb\SocialAuth\Test;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Laravel\Socialite\Contracts\Provider;
use MadWeb\SocialAuth\Exceptions\SocialCallbackException;
use MadWeb\SocialAuth\Models\SocialProvider;
use MadWeb\SocialAuth\StatelessOAuthState;
use Mockery;
use Symfony\Component\HttpFoundation\RedirectResponse;

class StatelessOAuthStateTest extends TestCase
{
    /**
     * @dataProvider cacheAddFailures
     */
    public function test_initiation_cache_add_failures_never_return_provider_redirect(string $failure): void
    {
        $store = new CacheStoreDouble;
        $store->addFailure = $failure === 'exception' ? new \RuntimeException('cache unavailable') : false;
        $service = $this->serviceFor($store);

        try {
            $service->initiate($this->socialProvider(), $this->redirectProvider(), null, '/home');
            $this->fail('Expected a generic callback failure.');
        } catch (SocialCallbackException $exception) {
            $this->assertSame('http://localhost/home', $exception->getResponse()->headers->get('Location'));
        }
        $this->assertGreaterThanOrEqual(1, $store->forgetCalls);
    }

    public static function cacheAddFailures(): array
    {
        return [['false'], ['exception']];
    }

    public function test_initiation_rejects_store_without_lock_provider(): void
    {
        $store = Mockery::mock(Store::class);
        $service = $this->serviceFor($store);

        $this->expectException(SocialCallbackException::class);
        $service->initiate($this->socialProvider(), Mockery::mock(Provider::class), null, '/home');
    }

    /**
     * @dataProvider lockFailures
     */
    public function test_callback_lock_failures_happen_before_provider_and_keep_state(string $failure): void
    {
        [$social, $request, $store, $key, $digest] = $this->validState();
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturnUsing(function () use ($failure) {
            if ($failure === 'exception') {
                throw new \RuntimeException('lock unavailable');
            }

            return false;
        });
        $store->lockInstance = $lock;
        $service = $this->serviceFor($store);

        $exception = $this->consumeFailure($service, $request, $social);

        $this->assertClearsCookie($exception, $request);
        $this->assertSame($digest, $store->get($key));
    }

    public function test_controller_does_not_retrieve_provider_after_cache_get_failure(): void
    {
        $social = $this->socialProvider();
        $social->stateless = true;
        $social->save();
        $this->socialiteMock->create('token', 'cache-error-subject');
        $start = $this->get(route('social.auth', ['social' => $social->slug]));
        $state = $this->queryValue($start->headers->get('Location'), 'state');
        $cookie = $start->getCookie('social_auth_state_'.$this->nonceFromState($state));

        $store = new CacheStoreDouble;
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturn(true);
        $lock->shouldReceive('release')->once()->andReturn(true);
        $store->lockInstance = $lock;
        $store->getValues = [new \RuntimeException('cache unavailable')];
        $this->app->instance(StatelessOAuthState::class, $this->serviceFor($store));

        $response = $this->withCookie($cookie->getName(), $cookie->getValue())
            ->get(route('social.callback', ['social' => $social->slug, 'state' => $state]));

        $this->assertSame(0, $this->socialiteMock->calls()['user']);
        $this->assertTrue($response->getCookie($cookie->getName())->isCleared());
        $response->assertRedirect(config('social-auth.redirect'));
    }

    public static function lockFailures(): array
    {
        return [['false'], ['exception']];
    }

    /**
     * @dataProvider cacheConsumeFailures
     */
    public function test_callback_cache_failures_never_consume_successfully(string $failure): void
    {
        [$social, $request, $store, $key, $digest] = $this->validState();
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturn(true);
        $lock->shouldReceive('release')->once()->andReturn(true);
        $store->lockInstance = $lock;
        if ($failure === 'get-exception') {
            $store->getValues = [new \RuntimeException('cache unavailable'), $digest];
        } elseif ($failure === 'digest-mismatch') {
            $store->getValues = ['wrong-digest', $digest];
        } elseif ($failure === 'forget-exception') {
            $store->getValues = [$digest];
            $store->forgetFailure = new \RuntimeException('cache unavailable');
        } else {
            $store->getValues = [$digest];
            $store->forgetFailure = false;
        }
        $service = $this->serviceFor($store);

        $exception = $this->consumeFailure($service, $request, $social);

        $this->assertClearsCookie($exception, $request);
        $this->assertSame($digest, $store->get($key));
    }

    public static function cacheConsumeFailures(): array
    {
        return [['get-exception'], ['digest-mismatch'], ['forget-false'], ['forget-exception']];
    }

    /**
     * @dataProvider releaseFailures
     */
    public function test_lock_release_failure_consumes_state_and_makes_replay_fail(string $failure): void
    {
        [$social, $request, $store, $key] = $this->validState();
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->andReturn(true);
        $lock->shouldReceive('release')->andReturnUsing(function () use ($failure) {
            if ($failure === 'exception') {
                throw new \RuntimeException('release unavailable');
            }

            return false;
        });
        $store->lockInstance = $lock;
        $service = $this->serviceFor($store);

        $exception = $this->consumeFailure($service, $request, $social);

        $this->assertClearsCookie($exception, $request);
        $this->assertNull($store->get($key));
        $this->consumeFailure($service, $request, $social);
    }

    public static function releaseFailures(): array
    {
        return [['false'], ['exception']];
    }

    private function validState(): array
    {
        $social = $this->socialProvider();
        $nonce = str_repeat('A', 43);
        $secret = 'browser-secret';
        $now = time();
        $payload = [
            'v' => 1,
            'nonce' => $nonce,
            'provider_id' => (string) $social->getKey(),
            'provider_slug' => $social->slug,
            'intent' => 'login',
            'attach_user_id' => null,
            'secret_hash' => hash('sha256', $secret),
            'issued_at' => $now - 1,
            'expires_at' => $now + 599,
        ];
        $token = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
        $request = Request::create('/callback?state='.rawurlencode($token));
        $request->cookies->set('social_auth_state_'.$nonce, $secret);
        $key = 'social-auth:state:'.$nonce;
        $digest = hash('sha256', $token);
        $store = new CacheStoreDouble;
        $store->put($key, $digest, 600);

        return [$social, $request, $store, $key, $digest];
    }

    private function serviceFor($store): StatelessOAuthState
    {
        $repository = new Repository($store);
        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldReceive('store')->with(null)->andReturn($repository);

        return new StatelessOAuthState($cache);
    }

    private function queryValue(string $url, string $name): string
    {
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return $query[$name];
    }

    private function nonceFromState(string $state): string
    {
        $payload = json_decode(Crypt::decryptString($state), true, 512, JSON_THROW_ON_ERROR);

        return $payload['nonce'];
    }

    private function socialProvider(): SocialProvider
    {
        return SocialProvider::whereSlug('facebook')->first();
    }

    private function redirectProvider(): Provider
    {
        $target = '';
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('stateless')->once()->andReturnSelf();
        $provider->shouldReceive('with')->once()->andReturnUsing(function (array $parameters) use (&$target, $provider) {
            $target = 'https://facebook.com/oauth?'.http_build_query($parameters);

            return $provider;
        });
        $provider->shouldReceive('redirect')->once()->andReturnUsing(fn () => new RedirectResponse($target));

        return $provider;
    }

    private function consumeFailure(StatelessOAuthState $service, Request $request, SocialProvider $social): SocialCallbackException
    {
        try {
            $service->consume($request, $social, null, '/home');
        } catch (SocialCallbackException $exception) {
            return $exception;
        }

        $this->fail('Expected a generic callback failure.');
    }

    private function assertClearsCookie(SocialCallbackException $exception, Request $request): void
    {
        $name = array_key_first($request->cookies->all());
        $cookie = collect($exception->getResponse()->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === $name);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isCleared());
    }
}

class CacheStoreDouble extends ArrayStore
{
    public mixed $addFailure = true;
    public mixed $forgetFailure = true;
    public mixed $lockInstance = null;
    public array $getValues = [];
    public int $forgetCalls = 0;

    public function add($key, $value, $seconds): bool
    {
        if ($this->addFailure instanceof \Throwable) {
            throw $this->addFailure;
        }

        return $this->addFailure;
    }

    public function get($key)
    {
        if ($this->getValues !== []) {
            $value = array_shift($this->getValues);
            if ($value instanceof \Throwable) {
                throw $value;
            }

            return $value;
        }

        return parent::get($key);
    }

    public function forget($key): bool
    {
        $this->forgetCalls++;
        if ($this->forgetFailure instanceof \Throwable) {
            throw $this->forgetFailure;
        }
        if ($this->forgetFailure !== true) {
            return $this->forgetFailure;
        }

        return parent::forget($key);
    }

    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        return $this->lockInstance ?? parent::lock($name, $seconds, $owner);
    }
}
