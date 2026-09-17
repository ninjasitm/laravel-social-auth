<?php

namespace MadWeb\SocialAuth;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Laravel\Socialite\Contracts\Provider;
use MadWeb\SocialAuth\Exceptions\SocialCallbackException;
use MadWeb\SocialAuth\Models\SocialProvider;
use Symfony\Component\HttpFoundation\Cookie as HttpCookie;
use Throwable;

class StatelessOAuthState
{
    public function __construct(protected CacheManager $cache)
    {
    }

    public function initiate(SocialProvider $social, Provider $provider, ?string $userId, string $redirectPath)
    {
        $repository = null;
        $cacheKey = null;
        try {
            $config = $this->stateConfig();
            $intent = $userId === null ? 'login' : 'attach';
            $attachUserId = $userId;
            if ($intent === 'attach' && ($attachUserId === null || $attachUserId === '')) {
                throw new \RuntimeException('Missing authenticated user identifier.');
            }

            $nonce = $this->base64Url(random_bytes(32));
            $secret = $this->base64Url(random_bytes(32));
            $issuedAt = time();
            $payload = [
                'v' => 1,
                'nonce' => $nonce,
                'provider_id' => (string) $social->getKey(),
                'provider_slug' => (string) $social->slug,
                'intent' => $intent,
                'attach_user_id' => $attachUserId,
                'secret_hash' => hash('sha256', $secret),
                'issued_at' => $issuedAt,
                'expires_at' => $issuedAt + $config['ttl'],
            ];
            $token = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
            $repository = $this->repository($config);
            $cacheKey = $this->cacheKey($nonce);

            $oauth = $provider->stateless();
            if (! empty($social->scopes)) {
                $social->override_scopes ? $oauth->setScopes($social->scopes) : $oauth->scopes($social->scopes);
            }
            $parameters = is_array($social->parameters) ? $social->parameters : [];
            unset($parameters['state']);
            $parameters['state'] = $token;
            $response = $oauth->with($parameters)->redirect();
            $target = $response->getTargetUrl();
            if (! $this->hasExactState($target, $token)) {
                throw new \RuntimeException('Provider redirect did not preserve state.');
            }

            $cookie = $this->stateCookie($nonce, $secret, $config['ttl']);
            $response->withCookie($cookie);
            if ($repository->add($cacheKey, hash('sha256', $token), $config['ttl']) !== true) {
                try {
                    $repository->forget($cacheKey);
                } catch (Throwable) {
                }
                throw new \RuntimeException('State cache was not written.');
            }

            return $response;
        } catch (SocialCallbackException $exception) {
            throw $exception;
        } catch (Throwable) {
            if ($repository !== null && $cacheKey !== null) {
                try {
                    $repository->forget($cacheKey);
                } catch (Throwable) {
                }
            }
            throw $this->failure($social, $redirectPath);
        }
    }

    public function consume(Request $request, SocialProvider $social, ?string $userId, string $redirectPath): HttpCookie
    {
        $clearCookie = null;

        try {
            $token = $this->callbackState($request);

            $payload = $this->decodePayload($token);
            $this->validatePayloadShape($payload);
            $nonce = $payload['nonce'];
            $config = $this->stateConfig();
            $clearCookie = $this->clearedCookie($nonce, $config['cookie_secure']);
            $this->validatePayloadContext($payload, $social, $config['ttl']);

            $secret = $request->cookies->get($this->cookieName($nonce));
            if (! is_string($secret) || $secret === '' || ! hash_equals($payload['secret_hash'], hash('sha256', $secret))) {
                throw new \RuntimeException('Invalid browser binding.');
            }
            if ($payload['intent'] === 'login' && $userId !== null) {
                throw new \RuntimeException('Login intent changed.');
            }
            if ($payload['intent'] === 'attach' && ($userId === null || ! hash_equals($payload['attach_user_id'], $userId))) {
                throw new \RuntimeException('Attach intent changed.');
            }

            $repository = $this->repository($config);
            $store = $repository->getStore();
            $lock = $store->lock($this->lockKey($nonce), 10);
            if (! $lock->get()) {
                throw new \RuntimeException('State is already being consumed.');
            }

            $failure = null;
            try {
                $digest = $repository->get($this->cacheKey($nonce));
                if (! is_string($digest) || ! hash_equals($digest, hash('sha256', $token))) {
                    $failure = new \RuntimeException('State cache did not match.');
                } elseif ($repository->forget($this->cacheKey($nonce)) !== true) {
                    $failure = new \RuntimeException('State cache was not consumed.');
                }
            } finally {
                try {
                    if ($lock->release() !== true) {
                        $failure ??= new \RuntimeException('State lock was not released.');
                    }
                } catch (Throwable) {
                    $failure ??= new \RuntimeException('State lock was not released.');
                }
            }
            if ($failure !== null) {
                throw $failure;
            }

            return $clearCookie;
        } catch (SocialCallbackException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->failure($social, $redirectPath, $clearCookie);
        }
    }

    protected function stateConfig(): array
    {
        $config = config('social-auth.stateless_state');
        if (! is_array($config)
            || ! array_key_exists('ttl', $config)
            || ! array_key_exists('store', $config)
            || ! array_key_exists('cookie_secure', $config)
            || ! is_int($config['ttl'] ?? null)
            || $config['ttl'] <= 0
            || $config['ttl'] > 3600
            || (($config['store'] ?? null) !== null && (! is_string($config['store']) || $config['store'] === ''))
            || ! is_bool($config['cookie_secure'] ?? null)
        ) {
            throw new \InvalidArgumentException('Invalid stateless state configuration.');
        }

        return $config;
    }

    protected function repository(array $config)
    {
        $repository = $this->cache->store($config['store']);
        if (! $repository->getStore() instanceof LockProvider) {
            throw new \RuntimeException('Stateless state store must support locks.');
        }

        return $repository;
    }

    protected function decodePayload(string $token): array
    {
        $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new \RuntimeException('Invalid state payload.');
        }

        return $payload;
    }

    protected function callbackState(Request $request): string
    {
        $rawQuery = $request->server->get('QUERY_STRING');
        if (! is_string($rawQuery)) {
            throw new \RuntimeException('Missing raw state query.');
        }

        $states = [];
        foreach (explode('&', $rawQuery) as $pair) {
            [$rawKey, $rawValue] = array_pad(explode('=', $pair, 2), 2, null);
            $key = $this->decodeQueryComponent($rawKey);
            if ($key === 'state[]' || str_starts_with($key, 'state[')) {
                throw new \RuntimeException('Array state is not supported.');
            }
            if ($key !== 'state') {
                continue;
            }
            if ($rawValue === null) {
                throw new \RuntimeException('State has no value.');
            }
            $value = $this->decodeQueryComponent($rawValue);
            if ($value === '') {
                throw new \RuntimeException('State has no value.');
            }
            $states[] = $value;
        }

        if (count($states) !== 1) {
            throw new \RuntimeException('State must occur exactly once.');
        }

        return $states[0];
    }

    protected function decodeQueryComponent(string $value): string
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $value)) {
            throw new \RuntimeException('Malformed query encoding.');
        }

        return urldecode($value);
    }

    protected function validatePayloadShape(array $payload): void
    {
        $keys = ['v', 'nonce', 'provider_id', 'provider_slug', 'intent', 'attach_user_id', 'secret_hash', 'issued_at', 'expires_at'];
        $payloadKeys = array_keys($payload);
        if (count($payloadKeys) !== count($keys)
            || array_diff($keys, $payloadKeys) !== []
            || array_diff($payloadKeys, $keys) !== []
            || $payload['v'] !== 1
            || ! preg_match('/^[A-Za-z0-9_-]{43}$/', $payload['nonce'])
            || ! is_string($payload['provider_id'])
            || ! is_string($payload['provider_slug'])
            || ! in_array($payload['intent'], ['login', 'attach'], true)
            || ($payload['intent'] === 'login' && $payload['attach_user_id'] !== null)
            || ($payload['intent'] === 'attach' && (! is_string($payload['attach_user_id']) || $payload['attach_user_id'] === ''))
            || ! is_string($payload['secret_hash'])
            || ! preg_match('/^[a-f0-9]{64}$/', $payload['secret_hash'])
            || ! is_int($payload['issued_at'])
            || ! is_int($payload['expires_at'])
        ) {
            throw new \RuntimeException('Invalid state payload.');
        }
    }

    protected function validatePayloadContext(array $payload, SocialProvider $social, int $ttl): void
    {
        if ($payload['provider_id'] !== (string) $social->getKey()
            || $payload['provider_slug'] !== (string) $social->slug
        ) {
            throw new \RuntimeException('Provider changed.');
        }
        $now = time();
        $lifetime = $payload['expires_at'] - $payload['issued_at'];
        if ($payload['issued_at'] > $now || $payload['expires_at'] <= $now || $lifetime <= 0 || $lifetime > $ttl) {
            throw new \RuntimeException('Expired state payload.');
        }
    }

    protected function hasExactState(string $url, string $expected): bool
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($query)) {
            return false;
        }
        $states = [];
        foreach (explode('&', $query) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (rawurldecode(str_replace('+', ' ', $key)) === 'state') {
                $states[] = rawurldecode($value);
            }
        }

        return count($states) === 1 && hash_equals($expected, $states[0]);
    }

    protected function stateCookie(string $nonce, string $secret, int $ttl): HttpCookie
    {
        return new HttpCookie($this->cookieName($nonce), $secret, time() + $ttl, '/', null, config('social-auth.stateless_state.cookie_secure'), true, false, 'lax');
    }

    protected function clearedCookie(string $nonce, bool $secure): HttpCookie
    {
        return new HttpCookie($this->cookieName($nonce), null, time() - 3600, '/', null, $secure, true, false, 'lax');
    }

    protected function failure(SocialProvider $social, string $redirectPath, ?HttpCookie $cookie = null): SocialCallbackException
    {
        $response = redirect($redirectPath)->withErrors(trans('social-auth::messages.generic_error'));
        if ($cookie !== null) {
            $response->withCookie($cookie);
        }

        return new SocialCallbackException($response, $social);
    }

    protected function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected function cookieName(string $nonce): string
    {
        return 'social_auth_state_'.$nonce;
    }

    protected function cacheKey(string $nonce): string
    {
        return 'social-auth:state:'.$nonce;
    }

    protected function lockKey(string $nonce): string
    {
        return 'social-auth:state-lock:'.$nonce;
    }
}
