<?php

namespace MadWeb\SocialAuth\Controllers;

use Exception;
use Throwable;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Auth\RedirectsUsers;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Laravel\Socialite\Contracts\Factory as Socialite;
use Laravel\Socialite\Contracts\User as SocialUser;
use MadWeb\SocialAuth\Events\SocialUserAuthenticated;
use MadWeb\SocialAuth\Events\SocialUserAttached;
use MadWeb\SocialAuth\Events\SocialUserCreated;
use MadWeb\SocialAuth\Contracts\VerifiedEmailVerifier;
use MadWeb\SocialAuth\Events\SocialUserDetached;
use MadWeb\SocialAuth\Exceptions\SocialGetUserInfoException;
use MadWeb\SocialAuth\Exceptions\SocialCallbackException;
use MadWeb\SocialAuth\Exceptions\SocialAuthHttpException;
use MadWeb\SocialAuth\Exceptions\SocialUserAttachException;
use MadWeb\SocialAuth\Models\SocialProvider;
use MadWeb\SocialAuth\SocialProviderManager;
use MadWeb\SocialAuth\StatelessOAuthState;

/**
 * Class SocialAuthController.
 */
class SocialAuthController extends BaseController
{
    use AuthorizesRequests,
        DispatchesJobs,
        ValidatesRequests,
        RedirectsUsers;

    /**
     * Redirect path.
     *
     * @var string
     */
    protected $redirectTo = '/';

    /**
     * @var Guard auth provider instance
     */
    protected $auth;

    /**
     * @var Socialite
     */
    protected $socialite;

    /**
     * @var \MadWeb\SocialAuth\Contracts\SocialAuthenticatable|\Illuminate\Contracts\Auth\Authenticatable
     */
    protected $userModel;

    /**
     * @var SocialProviderManager
     */
    protected $manager;

    /**
     * SocialAuthController constructor. Register Guard contract dependency.
     *
     * @param Guard $auth
     * @param Socialite $socialite
     */
    public function __construct(Guard $auth, Socialite $socialite)
    {
        $this->auth = $auth;
        $this->socialite = $socialite;
        $this->redirectTo = config('social-auth.redirect');

        $className = config('social-auth.models.user');
        $this->userModel = new $className;

        $this->middleware(function ($request, $next) {
            $this->manager = new SocialProviderManager($request->route('social'));

            return $next($request);
        });
    }

    /**
     * If there is no response from the social network, redirect the user to the social auth page
     * else make create with information from social network.
     *
     * @param SocialProvider $social bound by "Route model binding" feature
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function getAccount(SocialProvider $social)
    {
        if ($social->stateless) {
            $provider = $this->socialite->driver($social->slug);
            $userId = $this->auth->check() ? $this->authenticatedUserId($social) : null;

            return app(StatelessOAuthState::class)->initiate($social, $provider, $userId, $this->redirectPath());
        }

        $provider = $this->socialite->driver($social->slug);

        if (! empty($social->scopes)) {
            $social->override_scopes ? $provider->setScopes($social->scopes) : $provider->scopes($social->scopes);
        }

        return empty($social->parameters) ? $provider->redirect() : $provider->with($social->parameters)->redirect();
    }

    /**
     * Redirect callback for social network.
     *
     * @param Request $request
     * @param SocialProvider $social
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     * @throws SocialGetUserInfoException
     * @throws SocialUserAttachException
     */
    public function callback(Request $request, SocialProvider $social)
    {
        $SocialUser = null;
        $clearCookie = null;

        if ($social->stateless) {
            $userId = $this->auth->check() ? $this->authenticatedUserId($social) : null;
            $clearCookie = app(StatelessOAuthState::class)->consume($request, $social, $userId, $this->redirectPath());
            try {
                $SocialUser = $this->socialite->driver($social->slug)->stateless()->user();
            } catch (Throwable) {
                throw $this->genericFailure($social, $clearCookie);
            }
        } else {
            $provider = $this->socialite->driver($social->slug);
            try {
                $SocialUser = $provider->user();
            } catch (Exception $e) {
                throw new SocialGetUserInfoException(
                    $social,
                    trans('social-auth::messages.no_user_data', ['social' => $social->label])
                );
            }
        }

        // if we have no social info for some reason
        if (! $SocialUser) {
            if ($clearCookie !== null) {
                throw $this->genericFailure($social, $clearCookie);
            }
            throw new SocialGetUserInfoException(
                $social,
                trans('social-auth::messages.no_user_data', ['social' => $social->label])
            );
        }

        try {
            $subject = $this->normalizeSubject($SocialUser->getId(), $social);

            // if user is guest
            if (! $this->auth->check()) {
                $response = $this->processData($request, $social, $SocialUser, $subject);

                return $clearCookie === null ? $response : $response->withCookie($clearCookie);
            }

        $redirect_path = $this->redirectPath();
        $User = $request->user();

        // if user already attached
        if ($User->isAttached($social->slug)) {
            throw new SocialUserAttachException(
                redirect($redirect_path)
                    ->withErrors(trans('social-auth::messages.user_already_attach', ['social' => $social->label])),
                $social
            );
        }

        //If someone already attached current socialProvider account
        $subjectMatches = $this->manager->socialUserQuery($subject)->get();
        $matches = $subjectMatches->count();
        if ($matches > 1) {
            throw $this->genericFailure($social);
        }
        if ($matches === 1) {
            if (! $this->subjectsMatchExactly($subjectMatches, $subject)) {
                throw $this->genericFailure($social);
            }
            throw new SocialUserAttachException(
                redirect($redirect_path)
                    ->withErrors(trans('social-auth::messages.someone_already_attach')),
                $social
            );
        }

        try {
            DB::transaction(fn () => $this->manager->attach($User, $SocialUser, false, $subject));
        } catch (Throwable $e) {
            throw $this->genericFailure($social);
        }

        event(new SocialUserAttached($User, $social, $SocialUser));

        $response = redirect($redirect_path);

        return $clearCookie === null ? $response : $response->withCookie($clearCookie);
        } catch (Throwable $exception) {
            if ($clearCookie !== null) {
                if ($exception instanceof SocialAuthHttpException) {
                    $exception->getResponse()->headers->setCookie($clearCookie);
                    throw $exception;
                }

                throw $this->genericFailure($social, $clearCookie);
            }

            throw $exception;
        }
    }

    /**
     * Detaches social account for user.
     *
     * @param Request $request
     * @param SocialProvider $social
     * @return array
     * @throws SocialUserAttachException
     */
    public function detachAccount(Request $request, SocialProvider $social)
    {
        /** @var \MadWeb\SocialAuth\Contracts\SocialAuthenticatable $User */
        $User = $request->user();
        $UserSocials = $User->socials();

        if ($UserSocials->count() === 1 and empty($User->{$User->getEmailField()})) {
            throw new SocialUserAttachException(
                back()->withErrors(trans('social-auth::messages.detach_error_last')),
                $social
            );
        }

        $result = $UserSocials->detach($social->id);

        if (! $result) {
            throw new SocialUserAttachException(
                back()->withErrors(trans('social-auth::messages.detach_error', ['social' => $social->label])),
                $social
            );
        }

        event(new SocialUserDetached($User, $social, $result));

        return redirect($this->redirectPath());
    }

    /**
     * Process user using data from social network.
     *
     * @param Request $request
     * @param SocialProvider $social
     * @param SocialUser $socialUser
     * @param string $subject
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    protected function processData(Request $request, SocialProvider $social, SocialUser $socialUser, string $subject)
    {
        // A claimed subject is the sole credential required for sign-in.
        $subjectMatches = $this->manager->socialUserQuery($subject)->get();
        $redirect_path = $this->redirectPath();

        if ($subjectMatches->count() > 1) {
            throw $this->genericFailure($social);
        }
        if ($subjectMatches->count() === 1) {
            if (! $this->subjectsMatchExactly($subjectMatches, $subject)) {
                throw $this->genericFailure($social);
            }
            $this->login($subjectMatches->first());

            return redirect($redirect_path);
        }

        $email = $socialUser->getEmail();
        if (! is_string($email)) {
            throw $this->genericFailure($social);
        }
        $email = trim($email);
        if ($email === ''
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw $this->genericFailure($social);
        }

        $this->verifyEmail($socialUser, $social, $email);

        try {
            [$NewUser, $eventType] = DB::transaction(function () use ($email, $social, $socialUser, $subject) {
                $emailMatches = $this->userModel
                    ->where($this->userModel->getEmailField(), $email)
                    ->lockForUpdate()
                    ->get();
                if ($emailMatches->count() > 1) {
                    throw $this->genericFailure($social);
                }

                if ($emailMatches->count() === 1) {
                    $user = $emailMatches->first();
                    $this->manager->attach($user, $socialUser, false, $subject);

                    return [$user, 'attached'];
                }

                return [$this->manager->createNewUser($this->userModel, $social, $socialUser, false, $email, $subject), 'created'];
            });
        } catch (Throwable $e) {
            throw $this->genericFailure($social);
        }

        if ($eventType === 'created') {
            event(new SocialUserCreated($NewUser));
        }
        $this->login($NewUser);
        if ($eventType === 'attached') {
            event(new SocialUserAttached($NewUser, $social, $socialUser));
        }

        return redirect($redirect_path);
    }

    /**
     * Login user.
     *
     * @param Authenticatable $user
     */
    protected function login(Authenticatable $user)
    {
        $this->auth->login($user);
        event(new SocialUserAuthenticated($user));
    }

    protected function verifyEmail(SocialUser $socialUser, SocialProvider $social, string $email): void
    {
        try {
            $verifier = config('social-auth.verified_email_verifier');
            if (! is_string($verifier) || $verifier === '') {
                throw $this->genericFailure($social);
            }

            $instance = app()->make($verifier);
            if (! $instance instanceof VerifiedEmailVerifier || $instance->isVerified($socialUser, $social, $email) !== true) {
                throw $this->genericFailure($social);
            }
        } catch (Throwable $e) {
            throw $this->genericFailure($social);
        }
    }

    protected function normalizeSubject($subject, SocialProvider $social): string
    {
        if (is_int($subject)) {
            $subject = (string) $subject;
        }

        if (! is_string($subject)) {
            throw $this->genericFailure($social);
        }

        if ($subject === '') {
            throw $this->genericFailure($social);
        }

        return $subject;
    }

    protected function subjectsMatchExactly($matches, string $subject): bool
    {
        $subjectKey = config('social-auth.foreign_keys.social_subject', 'social_id');
        if ($subjectKey === 'social_id') {
            $subjectKey = config('social-auth.foreign_keys.socials', $subjectKey);
        }

        foreach ($matches as $match) {
            $stored = $match->pivot->{$subjectKey} ?? null;
            if (! is_string($stored) || strlen($stored) !== strlen($subject) || ! hash_equals($stored, $subject)) {
                return false;
            }
        }

        return true;
    }

    protected function authenticatedUserId(SocialProvider $social): string
    {
        $user = $this->auth->user();
        $identifier = $user?->getAuthIdentifier();
        if ($identifier === null || (string) $identifier === '') {
            throw $this->genericFailure($social);
        }

        return (string) $identifier;
    }

    protected function genericFailure(SocialProvider $social, $cookie = null): SocialCallbackException
    {
        $response = redirect($this->redirectPath())
            ->withErrors(trans('social-auth::messages.generic_error'));
        if ($cookie !== null) {
            $response->withCookie($cookie);
        }

        return new SocialCallbackException($response, $social);
    }
}
