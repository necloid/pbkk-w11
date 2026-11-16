<?php

namespace App\Providers;

use App\Services\Llm\LlmManager;
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
        $this->app->bind(LlmManager::class, fn () => new LlmManager(config('llm')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Membatasi percobaan login/registrasi per IP untuk menahan brute force.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Membatasi kueri ke LLM per user agar GPU tidak dibanjiri request.
        RateLimiter::for('chat-ai', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));
    }
}
