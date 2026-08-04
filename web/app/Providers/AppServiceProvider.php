<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Public submissions: per form + IP, so one hot form cannot be
        // flooded and one abuser cannot lock out an office NAT elsewhere.
        RateLimiter::for('public-submit', function (Request $request) {
            $formId = $request->route('form')?->id ?? 'none';

            return Limit::perMinute(config('formforge.rate_limits.submissions_per_minute'))
                ->by($formId.'|'.$request->ip());
        });

        RateLimiter::for('public-events', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        // AI generation is the expensive path — limit per user.
        RateLimiter::for('ai-generation', function (Request $request) {
            return Limit::perHour(config('formforge.rate_limits.ai_generations_per_hour'))
                ->by($request->user()?->id ?? $request->ip());
        });
    }
}
