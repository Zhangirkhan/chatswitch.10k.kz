<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Services\Company\DemoChatsFactory;
use Illuminate\Database\Seeder;

final class DemoChatsSeeder extends Seeder
{
    public function run(DemoChatsFactory $demoChats): void
    {
        $slug = (string) config('tenancy.fallback_slug', 'demo');
        $company = Company::query()->withoutGlobalScope('tenant')->where('slug', $slug)->first();

        if ($company === null) {
            if ($this->command !== null) {
                $this->command->warn("DemoChatsSeeder: компания «{$slug}» не найдена.");
            }

            return;
        }

        $stats = $demoChats->seedForCompany($company);

        if ($this->command !== null) {
            $this->command->info("DemoChatsSeeder: чатов {$stats['chats']}, сообщений {$stats['messages']}.");
        }
    }
}
