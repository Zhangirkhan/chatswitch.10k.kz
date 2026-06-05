<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\ApplyTenantToQueue;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Department;
use App\Models\TeamConversation;
use App\Models\WhatsappSession;
use App\Observers\DepartmentObserver;
use App\Policies\ChatPolicy;
use App\Policies\ContactPolicy;
use App\Policies\TeamConversationPolicy;
use App\Policies\WhatsappSessionPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('whatsapp-service', function (Request $request): Limit {
            return Limit::perMinute(300)->by($request->ip());
        });

        RateLimiter::for('chat-send', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('chat-translate', function (Request $request): Limit {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('chat-ai', function (Request $request): Limit {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('workspace-lookup', function (Request $request): Limit {
            return Limit::perMinute(30)->by($request->ip());
        });

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        Gate::policy(Chat::class, ChatPolicy::class);
        Gate::policy(Contact::class, ContactPolicy::class);
        Gate::policy(TeamConversation::class, TeamConversationPolicy::class);
        Gate::policy(WhatsappSession::class, WhatsappSessionPolicy::class);

        Department::observe(DepartmentObserver::class);

        Queue::before(function (JobProcessing $event): void {
            app(ApplyTenantToQueue::class)->handle($event);
        });

        Queue::after(function (JobProcessed $event): void {
            app(TenantContext::class)->clear();
        });
    }
}
