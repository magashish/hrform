<?php

namespace App\Providers;

use App\Listeners\RecordAuthenticationAudit;
use App\Listeners\SendPortalNotifications;
use App\Models\User;
use App\Policies\HrUserPolicy;
use App\Services\Applicants\CurrentApplicant;
use App\Services\Files\ClamAvScanner;
use App\Services\Files\FileScanner;
use App\Services\Files\NullScanner;
use App\Services\Settings\SettingsService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentApplicant::class);
        $this->app->singleton(SettingsService::class);

        $this->app->bind(FileScanner::class, fn () => match (config('portal.scanner.driver')) {
            'clamav' => new ClamAvScanner,
            default => new NullScanner,
        });
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('portal-code', fn (Request $request) => [
            Limit::perMinute((int) config('portal.applicant_access.code_attempts_per_minute', 10))->by('portal-code:'.$request->ip()),
            Limit::perHour(60)->by('portal-code-h:'.$request->ip()),
        ]);

        RateLimiter::for('portal-otp', fn (Request $request) => [
            Limit::perMinute(10)->by('portal-otp:'.$request->ip()),
            Limit::perMinute(10)->by('portal-otp-s:'.$request->session()->getId()),
        ]);

        RateLimiter::for('hr-code-verify', fn (Request $request) => Limit::perMinute(10)->by('hr-code-verify:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('hr-code-resend', fn (Request $request) => Limit::perMinute(5)->by('hr-code-resend:'.($request->user()?->id ?? $request->ip())));

        RateLimiter::for('portal-upload', fn (Request $request) => Limit::perMinute(30)->by('portal-upload:'.$request->session()->getId()));
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
        Event::subscribe(SendPortalNotifications::class);

        $this->registerRateLimiters();
    }
}
