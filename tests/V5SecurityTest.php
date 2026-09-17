<?php

namespace MadWeb\SocialAuth\Test;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Contracts\User as SocialUser;
use MadWeb\SocialAuth\Contracts\VerifiedEmailVerifier;
use MadWeb\SocialAuth\Events\SocialUserAuthenticated;
use MadWeb\SocialAuth\Events\SocialUserAttached;
use MadWeb\SocialAuth\Models\SocialProvider;
use MadWeb\SocialAuth\StatelessOAuthState;
use Mockery;
use RuntimeException;

class V5SecurityTest extends TestCase
{
    public function test_stateless_redirect_initiates_bound_flow(): void
    {
        $social = $this->statelessProvider();

        $response = $this->get(route('social.auth', ['social' => $social->slug]));

        $response->assertRedirect();
        $this->assertSame(1, $this->socialiteMock->calls()['driver']);
        $this->assertSame(1, $this->socialiteMock->calls()['stateless']);
        $this->assertSame(1, $this->socialiteMock->calls()['redirect']);
        $this->assertSame(0, $this->socialiteMock->calls()['user']);
    }

    public function test_stateless_guest_callback_is_rejected_before_provider_retrieval(): void
    {
        Event::fake([
            SocialUserAuthenticated::class,
            SocialUserAttached::class,
            \MadWeb\SocialAuth\Events\SocialUserCreated::class,
        ]);
        $this->statelessProvider();
        $this->socialiteMock->setEmail('attacker@example.com')->create('token', 'attacker-subject');

        $response = $this->get(route('social.callback', $this->social));

        $this->assertGenericFailure($response);
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'attacker@example.com']);
        $this->assertSame(['driver' => 0, 'user' => 0, 'stateless' => 0, 'redirect' => 0], $this->socialiteMock->calls());
        Event::assertNotDispatched(SocialUserAuthenticated::class);
        Event::assertNotDispatched(SocialUserAttached::class);
        Event::assertNotDispatched(\MadWeb\SocialAuth\Events\SocialUserCreated::class);
    }

    public function test_stateless_authenticated_callback_cannot_attach_before_provider_retrieval(): void
    {
        Event::fake([
            SocialUserAuthenticated::class,
            SocialUserAttached::class,
            \MadWeb\SocialAuth\Events\SocialUserCreated::class,
        ]);
        $user = $this->getTestUser();
        $this->statelessProvider();
        $this->socialiteMock->create('token', 'attacker-subject');

        $response = $this->actingAs($user)->get(route('social.callback', $this->social));

        $this->assertGenericFailure($response);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseMissing('user_has_social_provider', ['social_id' => 'attacker-subject']);
        $this->assertSame(['driver' => 0, 'user' => 0, 'stateless' => 0, 'redirect' => 0], $this->socialiteMock->calls());
        Event::assertNotDispatched(SocialUserAuthenticated::class);
        Event::assertNotDispatched(SocialUserAttached::class);
        Event::assertNotDispatched(\MadWeb\SocialAuth\Events\SocialUserCreated::class);
    }

    public function test_stateful_callback_still_uses_socialite_user_path(): void
    {
        config(['social-auth.stateless_state.ttl' => 0]);
        $this->socialiteMock->setEmail('stateful@example.com')->create('token', 'stateful-subject');

        $response = $this->get(route('social.callback', $this->social));

        $response->assertRedirect(config('social-auth.redirect'));
        $this->assertSame(1, $this->socialiteMock->calls()['user']);
        $this->assertSame(0, $this->socialiteMock->calls()['stateless']);
    }

    public function test_stateless_callback_rejects_array_and_tampered_state_before_provider(): void
    {
        $social = $this->statelessProvider();
        $this->socialiteMock->create('token', 'tampered-subject');

        $arrayResponse = $this->get(route('social.callback', ['social' => $social->slug]).'?state[]=tampered');
        $this->assertGenericFailure($arrayResponse);
        $this->assertSame(0, $this->socialiteMock->calls()['user']);

        $this->socialiteMock->create('token', 'tampered-subject');
        $start = $this->get(route('social.auth', ['social' => $social->slug]));
        $state = $this->queryValue($start->headers->get('Location'), 'state');
        $cookie = $start->getCookie('social_auth_state_'.$this->nonceFromState($state));
        $tampered = substr($state, 0, -1).($state[-1] === 'A' ? 'B' : 'A');

        $response = $this->withCookie($cookie->getName(), $cookie->getValue())
            ->get(route('social.callback', ['social' => $social->slug, 'state' => $tampered]));

        $this->assertGenericFailure($response);
        $this->assertSame(0, $this->socialiteMock->calls()['user']);
    }

    public function test_stateless_callback_rejects_duplicate_raw_state_parameters_without_consuming(): void
    {
        Event::fake([
            SocialUserAuthenticated::class,
            SocialUserAttached::class,
            \MadWeb\SocialAuth\Events\SocialUserCreated::class,
        ]);
        $social = $this->statelessProvider();
        $this->socialiteMock->create('token', 'duplicate-subject');
        $start = $this->get(route('social.auth', ['social' => $social->slug]));
        $state = $this->queryValue($start->headers->get('Location'), 'state');
        $payload = json_decode(Crypt::decryptString($state), true, 512, JSON_THROW_ON_ERROR);
        $cookie = $start->getCookie('social_auth_state_'.$payload['nonce']);
        $encoded = rawurlencode($state);

        foreach (['state=attacker&state='.$encoded, 'state='.$encoded.'&state=attacker', 'st%61te=attacker&state='.$encoded] as $query) {
            $response = $this->withCookie($cookie->getName(), $cookie->getValue())
                ->get(route('social.callback', ['social' => $social->slug]).'?'.$query);

            $this->assertGenericFailure($response);
            $this->assertSame(0, $this->socialiteMock->calls()['user']);
            $this->assertTrue(Cache::has('social-auth:state:'.$payload['nonce']));
        }

        Event::assertNotDispatched(SocialUserAuthenticated::class);
        Event::assertNotDispatched(SocialUserAttached::class);
        Event::assertNotDispatched(\MadWeb\SocialAuth\Events\SocialUserCreated::class);
    }

    public function test_generic_callback_failures_redirect_with_safe_error(): void
    {
        Event::fake();
        config(['social-auth.verified_email_verifier' => null]);
        $this->socialiteMock->setEmail('generic@example.com')->create('token', 'generic-subject');

        $response = $this->get(route('social.callback', $this->social));

        $response->assertRedirect(config('social-auth.redirect'));
        $response->assertSessionHas('errors');
        $this->assertSame(trans('social-auth::messages.generic_error'), session('errors')->first());
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'generic@example.com']);
        Event::assertNotDispatched(SocialUserAuthenticated::class);
        Event::assertNotDispatched(SocialUserAttached::class);
        Event::assertNotDispatched(\MadWeb\SocialAuth\Events\SocialUserCreated::class);
    }

    public function test_provider_exception_message_is_not_exposed(): void
    {
        $this->disableExceptionHandling();
        $this->socialiteMock->withRequestException('secret-token-value')->create();

        try {
            $this->get(route('social.callback', $this->social));
            $this->fail('Expected SocialGetUserInfoException.');
        } catch (\MadWeb\SocialAuth\Exceptions\SocialGetUserInfoException $exception) {
            $this->assertStringNotContainsString('secret-token-value', $exception->getMessage());
        }
    }

    public function test_ambiguous_subject_redirects_generically_without_auth_or_events(): void
    {
        Event::fake();
        $provider = SocialProvider::whereSlug('facebook')->first();
        Schema::table('user_has_social_provider', function ($table): void {
            $table->dropUnique('social_auth_provider_subject_unique');
        });
        $second = User::create(['email' => 'second-owner@example.com', 'avatar' => '']);
        app('db')->table('user_has_social_provider')->insert([
            ['user_id' => $this->getTestUser()->getKey(), 'social_provider_id' => $provider->getKey(), 'social_id' => 'ambiguous-subject', 'token' => 'one'],
            ['user_id' => $second->getKey(), 'social_provider_id' => $provider->getKey(), 'social_id' => 'ambiguous-subject', 'token' => 'two'],
        ]);
        $this->socialiteMock->setEmail('not-an-email')->create('token', 'ambiguous-subject');

        $response = $this->get(route('social.callback', $this->social));

        $response->assertRedirect(config('social-auth.redirect'));
        $response->assertSessionHas('errors');
        $this->assertSame(trans('social-auth::messages.generic_error'), session('errors')->first());
        $this->assertGuest();
        Event::assertNotDispatched(SocialUserAuthenticated::class);
        Event::assertNotDispatched(SocialUserAttached::class);
        Event::assertNotDispatched(\MadWeb\SocialAuth\Events\SocialUserCreated::class);
    }

    public function test_email_is_trimmed_before_verification_lookup_and_persistence(): void
    {
        TrimCapturingVerifier::$email = null;
        config(['social-auth.verified_email_verifier' => TrimCapturingVerifier::class]);
        $this->socialiteMock->setEmail('  trimmed@example.com  ')->create('token', 'trimmed-subject');

        $this->get(route('social.callback', $this->social));

        $this->assertSame('trimmed@example.com', TrimCapturingVerifier::$email);
        $this->assertDatabaseHas('users', ['email' => 'trimmed@example.com']);
        $this->assertDatabaseMissing('users', ['email' => '  trimmed@example.com  ']);
    }

    /**
     * @dataProvider malformedSubjects
     */
    public function test_malformed_provider_subjects_fail_closed($subject): void
    {
        Event::fake();
        config(['social-auth.verified_email_verifier' => ApprovedVerifier::class]);
        $this->socialiteMock->setEmail('malformed@example.com')->create('token', $subject);

        $response = $this->get(route('social.callback', $this->social));

        $response->assertRedirect(config('social-auth.redirect'));
        $response->assertSessionHas('errors');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'malformed@example.com']);
        Event::assertNotDispatched(SocialUserAuthenticated::class);
        Event::assertNotDispatched(SocialUserAttached::class);
        Event::assertNotDispatched(\MadWeb\SocialAuth\Events\SocialUserCreated::class);
    }

    public static function malformedSubjects(): array
    {
        return [[''], [null], [false], [true], [1.5], [[]], [(object) ['id' => 1]]];
    }

    public function test_authenticated_malformed_subject_fails_closed_without_attachment(): void
    {
        Event::fake();
        $user = $this->getTestUser();
        $this->socialiteMock->setEmail('ignored@example.com')->create('token', []);

        $response = $this->actingAs($user)->get(route('social.callback', $this->social));

        $response->assertRedirect(config('social-auth.redirect'));
        $response->assertSessionHas('errors');
        $this->assertDatabaseMissing('user_has_social_provider', ['user_id' => $user->getKey()]);
        Event::assertNotDispatched(SocialUserAuthenticated::class);
        Event::assertNotDispatched(SocialUserAttached::class);
    }

    public function test_integer_subject_is_normalized_for_existing_link(): void
    {
        $owner = $this->getTestUser();
        $provider = SocialProvider::whereSlug('facebook')->first();
        $owner->attachSocial($provider, '123', 'token');
        config(['social-auth.verified_email_verifier' => ThrowingVerifier::class]);
        $this->socialiteMock->setEmail('not-an-email')->create('token', 123);

        $this->get(route('social.callback', $this->social));

        $this->assertAuthenticatedAs($owner);
    }

    public function test_whitespace_subjects_are_opaque_and_distinct(): void
    {
        $owner = $this->getTestUser();
        $provider = SocialProvider::whereSlug('facebook')->first();
        $owner->attachSocial($provider, 'abc', 'token');
        config(['social-auth.verified_email_verifier' => ThrowingVerifier::class]);
        $this->socialiteMock->setEmail('not-an-email')->create('token', ' abc ');

        $this->get(route('social.callback', $this->social));

        $this->assertGuest();
        $this->assertDatabaseHas('user_has_social_provider', ['social_id' => 'abc']);
        $this->assertDatabaseMissing('user_has_social_provider', ['social_id' => ' abc ']);
    }

    public function test_exact_whitespace_subject_is_persisted_and_matched(): void
    {
        $owner = $this->getTestUser();
        $provider = SocialProvider::whereSlug('facebook')->first();
        $owner->attachSocial($provider, '  exact subject  ', 'token');
        config(['social-auth.verified_email_verifier' => ThrowingVerifier::class]);
        $this->socialiteMock->setEmail('not-an-email')->create('token', '  exact subject  ');

        $this->get(route('social.callback', $this->social));

        $this->assertAuthenticatedAs($owner);
        $this->assertDatabaseHas('user_has_social_provider', ['social_id' => '  exact subject  ']);
    }

    public function test_whitespace_only_subject_is_accepted_and_persisted_exactly(): void
    {
        config(['social-auth.verified_email_verifier' => ApprovedVerifier::class]);
        $this->socialiteMock->setEmail('whitespace@example.com')->create('token', '   ');

        $this->get(route('social.callback', $this->social));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('user_has_social_provider', ['social_id' => '   ']);
    }

    public function test_user_email_is_database_unique(): void
    {
        User::create(['email' => 'unique@example.com', 'avatar' => '']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        User::create(['email' => 'unique@example.com', 'avatar' => '']);
    }

    public function test_mutation_failure_after_create_rolls_back_and_redirects_generically(): void
    {
        Event::fake();
        config(['social-auth.verified_email_verifier' => ApprovedVerifier::class]);
        app('db')->statement("CREATE TRIGGER fail_social_link AFTER INSERT ON user_has_social_provider BEGIN SELECT RAISE(ABORT, 'forced mutation failure'); END");
        $this->socialiteMock->setEmail('rollback@example.com')->create('token', 'rollback-subject');

        $response = $this->get(route('social.callback', $this->social));

        $response->assertRedirect(config('social-auth.redirect'));
        $response->assertSessionHas('errors');
        $this->assertSame(trans('social-auth::messages.generic_error'), session('errors')->first());
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.com']);
        $this->assertDatabaseMissing('user_has_social_provider', ['social_id' => 'rollback-subject']);
        Event::assertNotDispatched(SocialUserAuthenticated::class);
        Event::assertNotDispatched(SocialUserAttached::class);
        Event::assertNotDispatched(\MadWeb\SocialAuth\Events\SocialUserCreated::class);
    }

    public function test_mutation_failure_after_existing_user_attach_fails_closed(): void
    {
        Event::fake();
        config(['social-auth.verified_email_verifier' => ApprovedVerifier::class]);
        $owner = User::create(['email' => 'attach-rollback@example.com', 'avatar' => '']);
        app('db')->statement("CREATE TRIGGER fail_existing_social_link AFTER INSERT ON user_has_social_provider BEGIN SELECT RAISE(ABORT, 'forced attach failure'); END");
        $this->socialiteMock->setEmail('attach-rollback@example.com')->create('token', 'attach-rollback-subject');

        $response = $this->get(route('social.callback', $this->social));

        $response->assertRedirect(config('social-auth.redirect'));
        $this->assertGuest();
        $this->assertDatabaseMissing('user_has_social_provider', ['social_id' => 'attach-rollback-subject']);
        $this->assertDatabaseHas('users', ['id' => $owner->getKey()]);
        Event::assertNotDispatched(SocialUserAuthenticated::class);
        Event::assertNotDispatched(SocialUserAttached::class);
        Event::assertNotDispatched(\MadWeb\SocialAuth\Events\SocialUserCreated::class);
    }

    public function test_new_user_events_are_created_before_authenticated(): void
    {
        $events = [];
        Event::listen(SocialUserAuthenticated::class, function () use (&$events): void {
            $events[] = 'authenticated';
        });
        Event::listen(\MadWeb\SocialAuth\Events\SocialUserCreated::class, function () use (&$events): void {
            $events[] = 'created';
        });
        config(['social-auth.verified_email_verifier' => ApprovedVerifier::class]);
        $this->socialiteMock->setEmail('ordered@example.com')->create('token', 'ordered-subject');

        $this->get(route('social.callback', $this->social));

        $this->assertSame(['created', 'authenticated'], $events);
    }

    public function test_existing_email_attach_keeps_authentication_before_attach_event(): void
    {
        Event::fake();
        config(['social-auth.verified_email_verifier' => ApprovedVerifier::class]);
        User::create(['email' => 'ordered-attach@example.com', 'avatar' => '']);
        $this->assertSame(1, User::whereEmail('ordered-attach@example.com')->count());
        $this->socialiteMock->setEmail('ordered-attach@example.com')->create('token', 'ordered-attach-subject');

        $response = $this->get(route('social.callback', $this->social));

        $this->assertDatabaseHas('user_has_social_provider', ['social_id' => 'ordered-attach-subject']);
        $response->assertRedirect(config('social-auth.redirect'));
        Event::assertDispatched(SocialUserAuthenticated::class);
        Event::assertDispatched(SocialUserAttached::class);
    }
    public function test_linked_subject_bypasses_invalid_email_and_throwing_verifier(): void
    {
        $owner = $this->getTestUser();
        $provider = SocialProvider::whereSlug('facebook')->first();
        $owner->attachSocial($provider, 'linked-subject', 'token');
        config(['social-auth.verified_email_verifier' => ThrowingVerifier::class]);
        $this->socialiteMock->setEmail('not-an-email')->create('token', 'linked-subject');

        $this->get(route('social.callback', $this->social));

        $this->assertAuthenticatedAs($owner);
    }

    public function test_unmatched_callback_without_verifier_has_no_side_effects(): void
    {
        Event::fake();
        config(['social-auth.verified_email_verifier' => null]);
        $this->socialiteMock->setEmail('new@example.com')->create('token', 'new-subject');

        $this->get(route('social.callback', $this->social));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
        Event::assertNotDispatched(SocialUserAuthenticated::class);
        Event::assertNotDispatched(SocialUserAttached::class);
    }

    public function test_invalid_email_is_rejected_before_verifier(): void
    {
        CountingVerifier::$calls = 0;
        config(['social-auth.verified_email_verifier' => CountingVerifier::class]);
        $this->socialiteMock->setEmail('not-an-email')->create('token', 'invalid-email-subject');

        $response = $this->get(route('social.callback', $this->social));

        $response->assertRedirect(config('social-auth.redirect'));
        $response->assertSessionHas('errors');
        $this->assertGuest();
        $this->assertSame(0, CountingVerifier::$calls);
    }

    /**
     * @dataProvider invalidVerifierConfigurations
     */
    public function test_invalid_verifier_configuration_fails_closed(?string $verifier): void
    {
        config(['social-auth.verified_email_verifier' => $verifier]);
        $this->socialiteMock->setEmail('resolver-failure@example.com')->create('token', 'resolver-failure');

        $response = $this->get(route('social.callback', $this->social));

        $response->assertRedirect(config('social-auth.redirect'));
        $response->assertSessionHas('errors');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'resolver-failure@example.com']);
    }

    public static function invalidVerifierConfigurations(): array
    {
        return [[null], ['MadWeb\\SocialAuth\\Test\\MissingVerifier'], [\stdClass::class], [ThrowingResolver::class]];
    }

    /**
     * @dataProvider nonStrictVerifierResults
     */
    public function test_only_boolean_true_verifier_result_is_accepted(mixed $result): void
    {
        StrictValueVerifier::$value = $result;
        config(['social-auth.verified_email_verifier' => StrictValueVerifier::class]);
        $email = 'strict-'.md5(serialize($result)).'@example.com';
        $this->socialiteMock->setEmail($email)->create('token', $email);

        $this->get(route('social.callback', $this->social));

        if ($result === true) {
            $this->assertAuthenticated();
            $this->assertDatabaseHas('users', ['email' => $email]);
        } else {
            $this->assertGuest();
            $this->assertDatabaseMissing('users', ['email' => $email]);
        }
    }

    public static function nonStrictVerifierResults(): array
    {
        return [['true'], ['false'], [1], [null], [false], [true]];
    }

    public function test_approving_verifier_attaches_existing_email_user(): void
    {
        config(['social-auth.verified_email_verifier' => ApprovingVerifier::class]);
        $this->socialiteMock->setEmail('existing@example.com')->create('token', '  existing-subject  ');
        $owner = User::create(['email' => 'existing@example.com', 'avatar' => '']);

        $this->get(route('social.callback', $this->social));

        $this->assertAuthenticatedAs($owner);
        $this->assertDatabaseHas('user_has_social_provider', [
            'user_id' => $owner->getKey(),
            'social_id' => '  existing-subject  ',
        ]);
    }

    public function test_approving_verifier_creates_and_logs_in_new_user(): void
    {
        Event::fake();
        config(['social-auth.verified_email_verifier' => ApprovingVerifier::class]);
        $this->socialiteMock->setEmail('created@example.com')->create('token', 98765);

        $this->get(route('social.callback', $this->social));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'created@example.com']);
        $this->assertDatabaseHas('user_has_social_provider', ['social_id' => '98765']);
        Event::assertDispatched(SocialUserAuthenticated::class);
    }

    public function test_stateless_guest_flow_uses_bound_state_and_clears_cookie(): void
    {
        $social = $this->statelessProvider();
        $this->socialiteMock->setEmail('stateless@example.com')->create('token', 'stateless-subject');

        $start = $this->get(route('social.auth', ['social' => $social->slug]));
        $state = $this->queryValue($start->headers->get('Location'), 'state');
        $cookie = $start->getCookie('social_auth_state_'.$this->nonceFromState($state));

        $callback = $this->withCookie($cookie->getName(), $cookie->getValue())
            ->get(route('social.callback', ['social' => $social->slug, 'state' => $state]));

        $callback->assertRedirect(config('social-auth.redirect'));
        $this->assertAuthenticated();
        $this->assertSame(2, $this->socialiteMock->calls()['stateless']);
        $this->assertSame(1, $this->socialiteMock->calls()['user']);
        $this->assertTrue($callback->getCookie($cookie->getName())->isCleared());
    }

    public function test_stateless_authenticated_attach_binds_exact_user(): void
    {
        $social = $this->statelessProvider();
        $user = $this->getTestUser();
        $this->actingAs($user);
        $this->socialiteMock->create('token', 'attached-subject');

        $start = $this->get(route('social.auth', ['social' => $social->slug]));
        $state = $this->queryValue($start->headers->get('Location'), 'state');
        $cookie = $start->getCookie('social_auth_state_'.$this->nonceFromState($state));
        $callback = $this->withCookie($cookie->getName(), $cookie->getValue())
            ->get(route('social.callback', ['social' => $social->slug, 'state' => $state]));

        $callback->assertRedirect(config('social-auth.redirect'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('user_has_social_provider', [
            'user_id' => $user->getKey(),
            'social_id' => 'attached-subject',
        ]);
    }

    public function test_stateless_state_payload_is_encrypted_and_exactly_bound(): void
    {
        $social = $this->statelessProvider();
        $social->parameters = ['state' => 'attacker-state', 'scope' => 'profile'];
        $social->save();
        $this->socialiteMock->create('token', 'payload-subject');

        $response = $this->get(route('social.auth', ['social' => $social->slug]));
        $state = $this->queryValue($response->headers->get('Location'), 'state');
        $plaintext = Crypt::decryptString($state);
        $payload = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['v', 'nonce', 'provider_id', 'provider_slug', 'intent', 'attach_user_id', 'secret_hash', 'issued_at', 'expires_at'], array_keys($payload));
        $this->assertSame('1', $payload['provider_id']);
        $this->assertSame('facebook', $payload['provider_slug']);
        $this->assertSame('login', $payload['intent']);
        $this->assertNull($payload['attach_user_id']);
        $this->assertArrayNotHasKey('subject', $payload);
        $this->assertStringNotContainsString('payload-subject', $plaintext);
        $this->assertSame($state, $this->queryValue($response->headers->get('Location'), 'state'));
        $this->assertSame(1, $this->socialiteMock->calls()['stateless']);
        $this->assertSame(1, $this->socialiteMock->calls()['redirect']);
        $this->assertSame(hash('sha256', $state), Cache::get('social-auth:state:'.$payload['nonce']));
        $cookie = $response->getCookie('social_auth_state_'.$payload['nonce']);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertGreaterThanOrEqual(590, $cookie->getMaxAge());
        $this->assertLessThanOrEqual(600, $cookie->getMaxAge());
    }

    public function test_stateless_tabs_have_independent_nonce_and_browser_secret(): void
    {
        $social = $this->statelessProvider();

        $first = $this->get(route('social.auth', ['social' => $social->slug]));
        $second = $this->get(route('social.auth', ['social' => $social->slug]));
        $firstState = $this->queryValue($first->headers->get('Location'), 'state');
        $secondState = $this->queryValue($second->headers->get('Location'), 'state');
        $firstPayload = json_decode(Crypt::decryptString($firstState), true, 512, JSON_THROW_ON_ERROR);
        $secondPayload = json_decode(Crypt::decryptString($secondState), true, 512, JSON_THROW_ON_ERROR);

        $this->assertNotSame($firstPayload['nonce'], $secondPayload['nonce']);
        $this->assertNotSame($firstPayload['secret_hash'], $secondPayload['secret_hash']);
        $this->assertNotSame(
            'social_auth_state_'.$firstPayload['nonce'],
            'social_auth_state_'.$secondPayload['nonce']
        );
        $this->assertSame(hash('sha256', $firstState), Cache::get('social-auth:state:'.$firstPayload['nonce']));
        $this->assertSame(hash('sha256', $secondState), Cache::get('social-auth:state:'.$secondPayload['nonce']));
    }

    public function test_stateless_wrong_binder_fails_before_provider_and_clears_flow_cookie(): void
    {
        $social = $this->statelessProvider();
        $this->socialiteMock->create('token', 'bound-subject');
        $start = $this->get(route('social.auth', ['social' => $social->slug]));
        $state = $this->queryValue($start->headers->get('Location'), 'state');
        $cookieName = 'social_auth_state_'.$this->nonceFromState($state);

        $response = $this->withCookie($cookieName, 'wrong-browser-secret')
            ->get(route('social.callback', ['social' => $social->slug, 'state' => $state]));

        $this->assertGenericFailure($response);
        $this->assertGuest();
        $this->assertSame(0, $this->socialiteMock->calls()['user']);
        $this->assertTrue($response->getCookie($cookieName)->isCleared());
    }

    public function test_stateless_provider_mismatch_fails_before_provider_and_clears_flow_cookie(): void
    {
        $social = $this->statelessProvider();
        $google = SocialProvider::whereSlug('google')->first();
        $google->stateless = true;
        $google->save();
        $start = $this->get(route('social.auth', ['social' => $social->slug]));
        $state = $this->queryValue($start->headers->get('Location'), 'state');
        $cookie = $start->getCookie('social_auth_state_'.$this->nonceFromState($state));

        $response = $this->withCookie($cookie->getName(), $cookie->getValue())
            ->get(route('social.callback', ['social' => $google->slug, 'state' => $state]));

        $this->assertGenericFailure($response);
        $this->assertSame(0, $this->socialiteMock->calls()['user']);
        $this->assertTrue($response->getCookie($cookie->getName())->isCleared());
    }

    public function test_stateless_callback_is_single_use_and_provider_failure_consumes_state(): void
    {
        $social = $this->statelessProvider();
        $this->socialiteMock->setEmail('provider-error@example.com')->create('token', 'provider-error');
        $start = $this->get(route('social.auth', ['social' => $social->slug]));
        $state = $this->queryValue($start->headers->get('Location'), 'state');
        $cookie = $start->getCookie('social_auth_state_'.$this->nonceFromState($state));
        $this->socialiteMock->withRequestException('provider-secret')->create('token', 'provider-error');

        $response = $this->withCookie($cookie->getName(), $cookie->getValue())
            ->get(route('social.callback', ['social' => $social->slug, 'state' => $state]));

        $this->assertGenericFailure($response);
        $this->assertGuest();
        $this->assertTrue($response->getCookie($cookie->getName())->isCleared());
        $this->assertSame(1, $this->socialiteMock->calls()['user']);

        $replay = $this->withCookie($cookie->getName(), $cookie->getValue())
            ->get(route('social.callback', ['social' => $social->slug, 'state' => $state]));
        $this->assertGenericFailure($replay);
        $this->assertSame(1, $this->socialiteMock->calls()['user']);
    }

    public function test_stateless_attach_state_cannot_transfer_to_another_authenticated_user(): void
    {
        $social = $this->statelessProvider();
        $attacker = $this->getTestUser();
        $victim = User::create(['email' => 'victim@example.com', 'avatar' => '']);
        $this->actingAs($attacker);
        $this->socialiteMock->create('token', 'transfer-subject');
        $start = $this->get(route('social.auth', ['social' => $social->slug]));
        $state = $this->queryValue($start->headers->get('Location'), 'state');
        $cookie = $start->getCookie('social_auth_state_'.$this->nonceFromState($state));

        $response = $this->actingAs($victim)
            ->withCookie($cookie->getName(), $cookie->getValue())
            ->get(route('social.callback', ['social' => $social->slug, 'state' => $state]));

        $this->assertGenericFailure($response);
        $this->assertAuthenticatedAs($victim);
        $this->assertDatabaseMissing('user_has_social_provider', ['social_id' => 'transfer-subject']);
        $this->assertSame(0, $this->socialiteMock->calls()['user']);
    }

    /**
     * @dataProvider invalidProviderRedirects
     */
    public function test_invalid_provider_state_target_fails_before_cache_write(string $target): void
    {
        $social = $this->statelessProvider();
        $store = new RedirectValidationCacheStore;
        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldReceive('store')->with(null)->once()->andReturn(new Repository($store));
        $this->app->instance(StatelessOAuthState::class, new StatelessOAuthState($cache));
        $this->socialiteMock->withRedirectUrl($target);

        $response = $this->get(route('social.auth', ['social' => $social->slug]));

        $this->assertGenericFailure($response);
        $this->assertSame($target, $this->socialiteMock->redirectUrl());
        $state = $this->socialiteMock->parameters()['state'];
        $payload = json_decode(Crypt::decryptString($state), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $store->addCalls);
        $this->assertFalse(Cache::has('social-auth:state:'.$payload['nonce']));
    }

    public static function invalidProviderRedirects(): array
    {
        return [
            ['https://facebook.com/oauth'],
            ['https://facebook.com/oauth?state=wrong'],
            ['https://facebook.com/oauth?state=one&state=two'],
            ['https://facebook.com/oauth#state=fragment'],
        ];
    }

    public function test_redirect_validation_cache_store_add_is_atomic(): void
    {
        $store = new RedirectValidationCacheStore;

        $this->assertTrue($store->add('state', 'first', 60));
        $this->assertFalse($store->add('state', 'second', 60));
        $this->assertSame('first', $store->get('state'));
    }

    /**
     * @dataProvider invalidStateConfigurations
     */
    public function test_invalid_stateless_state_configuration_fails_closed(array $override): void
    {
        $social = $this->statelessProvider();
        config(['social-auth.stateless_state' => array_merge(config('social-auth.stateless_state'), $override)]);

        $response = $this->get(route('social.auth', ['social' => $social->slug]));

        $this->assertGenericFailure($response);
        $this->assertSame(0, $this->socialiteMock->calls()['stateless']);
        $this->assertSame(0, $this->socialiteMock->calls()['redirect']);
    }

    public static function invalidStateConfigurations(): array
    {
        return [
            [['ttl' => 0]],
            [['ttl' => 3601]],
            [['store' => '']],
            [['store' => 42]],
            [['cookie_secure' => 'true']],
        ];
    }

    private function statelessProvider(): SocialProvider
    {
        $social = SocialProvider::whereSlug('facebook')->first();
        $social->stateless = true;
        $social->save();

        return $social->fresh();
    }

    private function assertGenericFailure($response): void
    {
        $response->assertRedirect(config('social-auth.redirect'));
        $response->assertSessionHas('errors');
        $this->assertSame(trans('social-auth::messages.generic_error'), session('errors')->first());
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

    public function test_ambiguous_email_fails_closed_without_attachment(): void
    {
        config(['social-auth.verified_email_verifier' => ApprovingVerifier::class]);
        Schema::create('legacy_users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email');
            $table->string('avatar');
        });
        config(['social-auth.models.user' => LegacyUser::class]);
        LegacyUser::create(['email' => 'ambiguous@example.com', 'avatar' => '']);
        LegacyUser::create(['email' => 'ambiguous@example.com', 'avatar' => '']);
        $this->socialiteMock->setEmail('ambiguous@example.com')->create('token', 'ambiguous-subject');

        $response = $this->get(route('social.callback', $this->social));

        $response->assertRedirect(config('social-auth.redirect'));
        $response->assertSessionHas('errors');
        $this->assertGuest();
        $this->assertDatabaseMissing('user_has_social_provider', ['social_id' => 'ambiguous-subject']);
    }

    public function test_authenticated_attach_bypasses_verifier_but_conflict_does_not_change_owner(): void
    {
        config(['social-auth.verified_email_verifier' => null]);
        $current = $this->getTestUser();
        $owner = User::create(['email' => 'owner@example.com', 'avatar' => '']);
        $provider = SocialProvider::whereSlug('facebook')->first();
        $owner->attachSocial($provider, 'claimed-subject', 'owner-token');
        $this->socialiteMock->setEmail('not-an-email')->create('token', 'unclaimed-subject');

        $this->actingAs($current)->get(route('social.callback', $this->social));
        $this->assertDatabaseHas('user_has_social_provider', [
            'user_id' => $current->getKey(),
            'social_id' => 'unclaimed-subject',
        ]);

        $this->socialiteMock->setEmail('not-an-email')->create('token', 'claimed-subject');
        $this->actingAs($current)->get(route('social.callback', $this->social));
        $this->assertDatabaseHas('user_has_social_provider', [
            'user_id' => $owner->getKey(),
            'social_id' => 'claimed-subject',
        ]);
        $this->assertDatabaseMissing('user_has_social_provider', [
            'user_id' => $current->getKey(),
            'social_id' => 'claimed-subject',
        ]);
    }

    public function test_configured_pivot_keys_are_used_by_both_relationship_sides(): void
    {
        Schema::create('custom_user_socials', function (Blueprint $table): void {
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('social_provider_id');
            $table->string('external_id');
            $table->string('token');
        });
        config([
            'social-auth.table_names.user_has_social_provider' => 'custom_user_socials',
            'social-auth.foreign_keys.users' => 'account_id',
            'social-auth.foreign_keys.social_subject' => 'external_id',
        ]);
        $user = $this->getTestUser();
        $provider = SocialProvider::whereSlug('facebook')->first();

        $user->attachSocial($provider, 'configured-subject', 'token');

        $this->assertTrue($user->socials()->whereKey($provider->getKey())->exists());
        $this->assertTrue($provider->users()->whereKey($user->getKey())->exists());
        $this->assertDatabaseHas('custom_user_socials', [
            'account_id' => $user->getKey(),
            'social_provider_id' => $provider->getKey(),
            'external_id' => 'configured-subject',
        ]);
    }

    public function test_get_detach_cannot_mutate_and_guest_delete_is_protected(): void
    {
        $user = $this->getTestUser();
        $provider = SocialProvider::whereSlug('facebook')->first();
        $user->attachSocial($provider, 'detach-subject', 'token');

        $this->actingAs($user)->get(route('social.detach', $this->social))->assertStatus(405);
        $this->assertDatabaseHas('user_has_social_provider', ['social_id' => 'detach-subject']);
        $this->app['auth']->logout();
        $this->app['router']->get('/login', fn () => 'login')->name('login');
        $this->delete(route('social.detach', $this->social))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseHas('user_has_social_provider', ['social_id' => 'detach-subject']);
    }

    public function test_attach_view_renders_csrf_protected_delete_form(): void
    {
        $user = $this->getTestUser();
        $provider = SocialProvider::whereSlug('facebook')->first();
        $user->attachSocial($provider, 'view-subject', 'token');
        $this->actingAs($user);

        $html = view('social-auth::attach', ['socialProviders' => [$provider]])->render();

        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('name="_method"', $html);
        $this->assertStringContainsString('value="DELETE"', $html);
        $this->assertStringContainsString('name="_token"', $html);
    }
}

class ApprovingVerifier implements VerifiedEmailVerifier
{
    public function isVerified(SocialUser $user, SocialProvider $provider, string $email): mixed
    {
        return true;
    }
}

class CountingVerifier implements VerifiedEmailVerifier
{
    public static int $calls = 0;

    public function isVerified(SocialUser $user, SocialProvider $provider, string $email): mixed
    {
        self::$calls++;

        return true;
    }
}

class StrictValueVerifier implements VerifiedEmailVerifier
{
    public static mixed $value;

    public function isVerified(SocialUser $user, SocialProvider $provider, string $email): mixed
    {
        return self::$value;
    }
}

class ThrowingVerifier implements VerifiedEmailVerifier
{
    public function isVerified(SocialUser $user, SocialProvider $provider, string $email): mixed
    {
        throw new RuntimeException('must not run for a linked subject');
    }
}

class TrimCapturingVerifier implements VerifiedEmailVerifier
{
    public static ?string $email = null;

    public function isVerified(SocialUser $user, SocialProvider $provider, string $email): mixed
    {
        self::$email = $email;

        return true;
    }
}

class LegacyUser extends User
{
    protected $table = 'legacy_users';
}

class ThrowingResolver
{
    public function __construct()
    {
        throw new RuntimeException('must not leak resolver details');
    }
}

class RedirectValidationCacheStore extends ArrayStore
{
    public int $addCalls = 0;

    public function add($key, $value, $seconds): bool
    {
        $this->addCalls++;

        if ($this->get($key) !== null) {
            return false;
        }

        return $this->put($key, $value, $seconds);
    }
}
