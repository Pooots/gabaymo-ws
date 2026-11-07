<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $appUrl = config('app.url');
        if (is_string($appUrl) && $appUrl !== '') {
            URL::forceRootUrl(rtrim($appUrl, '/'));
        }

        $this->rateLimits();

        // Reverb channel authorization callbacks. The auth endpoint itself is
        // POST /api/v1/broadcasting/auth (routes/api.php), not Broadcast::routes().
        require base_path('routes/channels.php');
    }

    /** Named limiters used in routes/api.php (`throttle:<name>`). */
    private function rateLimits(): void
    {
        $perMinute = fn (string $key, int $fallback) => max(1, (int) config("services.rate_limits.{$key}", $fallback));
        $emailIp = fn (Request $request) => mb_strtolower((string) $request->input('email', $request->user()?->email ?? '')).'|'.$request->ip();
        $actor = fn (Request $request) => ($request->user() ? get_class($request->user()).':'.$request->user()->getAuthIdentifier() : 'ip:'.$request->ip());

        // Sign-in: per email + IP (credential stuffing) and per IP (spraying many emails).
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute($perMinute('login', 5))->by('login:'.$emailIp($request)),
            Limit::perMinute($perMinute('login', 5) * 6)->by('login-ip:'.$request->ip()),
        ]);
        RateLimiter::for('admin-login', fn (Request $request) => [
            Limit::perMinute($perMinute('admin_login', 5))->by('admin:'.$emailIp($request)),
            Limit::perMinute($perMinute('admin_login', 5) * 4)->by('admin-ip:'.$request->ip()),
        ]);
        RateLimiter::for('register', fn (Request $request) => [
            Limit::perMinute($perMinute('register', 5))->by('register:'.$emailIp($request)),
            Limit::perHour($perMinute('register', 5) * 6)->by('register-ip:'.$request->ip()),
        ]);
        // Forgot / reset / change password and other password-confirmed actions.
        RateLimiter::for('password', fn (Request $request) => [
            Limit::perMinute($perMinute('password', 5))->by('password:'.$emailIp($request)),
            Limit::perMinute($perMinute('password', 5) * 4)->by('password-ip:'.$request->ip()),
        ]);
        // Public invitation links: lookups per IP; accept per email + IP and per IP.
        RateLimiter::for('invitation', fn (Request $request) => Limit::perMinute($perMinute('invitation', 30))->by('invitation-ip:'.$request->ip()));
        RateLimiter::for('invitation-accept', fn (Request $request) => [
            Limit::perMinute($perMinute('invitation_accept', 5))->by('invitation-accept:'.$emailIp($request)),
            Limit::perMinute($perMinute('invitation_accept', 5) * 4)->by('invitation-accept-ip:'.$request->ip()),
        ]);
        RateLimiter::for('redeem', fn (Request $request) => Limit::perMinute($perMinute('redeem', 5))->by('redeem:'.$actor($request)));
        RateLimiter::for('partner', fn (Request $request) => Limit::perMinute($perMinute('partner', 120))->by('partner:'.$actor($request)));
        RateLimiter::for('ai', fn (Request $request) => Limit::perMinute($perMinute('ai', 60))->by('ai:'.$actor($request)));
    }
}
