<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
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
        // Seul un réviseur peut trancher un écart de rapprochement (F10).
        Gate::define('review-decisions', static fn (User $user): bool => $user->isReviewer());
    }
}
