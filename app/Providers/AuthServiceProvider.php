<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Auth\GuardSessaoOuToken;
use Illuminate\Auth\RequestGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Auth::extend('sessao_ou_token', function ($app, string $name, array $config) {
            return tap(new RequestGuard(
                new GuardSessaoOuToken($app['auth'], $name, $config),
                $app['request'],
                $app['auth']->createUserProvider($config['provider']),
            ), fn (RequestGuard $guard) => $app->refresh('request', $guard, 'setRequest'));
        });

        Gate::before(function ($user, string $ability) {
            if ($user instanceof User && $user->hasPermission($ability)) {
                return true;
            }

            return null;
        });
    }
}
