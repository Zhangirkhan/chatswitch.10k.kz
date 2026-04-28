<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Chat;
use App\Models\WhatsappSession;
use App\Policies\ChatPolicy;
use App\Policies\WhatsappSessionPolicy;
use Illuminate\Support\Facades\Gate;
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

        Gate::policy(Chat::class, ChatPolicy::class);
        Gate::policy(WhatsappSession::class, WhatsappSessionPolicy::class);
    }
}
