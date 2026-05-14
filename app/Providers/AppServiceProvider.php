<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Chat;
use App\Models\Department;
use App\Models\TeamConversation;
use App\Models\WhatsappSession;
use App\Observers\DepartmentObserver;
use App\Policies\ChatPolicy;
use App\Policies\TeamConversationPolicy;
use App\Policies\WhatsappSessionPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        Gate::policy(Chat::class, ChatPolicy::class);
        Gate::policy(TeamConversation::class, TeamConversationPolicy::class);
        Gate::policy(WhatsappSession::class, WhatsappSessionPolicy::class);

        Department::observe(DepartmentObserver::class);
    }
}
