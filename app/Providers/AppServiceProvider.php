<?php

namespace App\Providers;

use App\Listeners\RecordAuthenticationAudit;
use App\Models\User;
use App\Policies\HrUserPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $production = $this->app->environment('production');

        // Catch lazy-loading, silently discarded attributes and missing
        // attributes during development and testing.
        Model::shouldBeStrict(! $production);

        if ($production) {
            URL::forceScheme('https');

            // Safety net: never render debug pages (stack traces, env) in production.
            config(['app.debug' => false]);
        }

        // Comma-separated IPs/CIDRs of a TLS-terminating proxy, or "*".
        $proxies = config('portal.trusted_proxies');
        if (is_string($proxies) && $proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        Password::defaults(function () {
            $rule = Password::min((int) config('portal.hr.password_min_length', 12))
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols();

            return config('portal.hr.password_check_uncompromised') ? $rule->uncompromised() : $rule;
        });

        // Keep the HR email address out of the reset URL's query string.
        ResetPassword::createUrlUsing(
            fn (User $user, string $token) => route('password.reset', ['token' => $token])
        );

        Gate::policy(User::class, HrUserPolicy::class);

        Event::subscribe(RecordAuthenticationAudit::class);
    }
}
