<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')->where('code', 'boxed')->update([
            'name' => 'Коробочная установка',
            'features' => json_encode([
                'platform' => 'unlimited',
                'whatsapp' => 'unlimited',
                'users' => 'unlimited',
                'modules' => 'all',
                'ai' => 'usage_billed',
                'description' => '1 000 000 ₸ разово за установку платформы; AI-токены отдельно',
            ]),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('plans')->where('code', 'boxed')->update([
            'name' => 'Коробочный',
            'features' => json_encode([
                'whatsapp' => 'unlimited',
                'users' => 'unlimited',
                'ai' => 'unlimited',
                'modules' => 'all',
                'description' => '1 000 000 ₸ разово, всё безлимит',
            ]),
            'updated_at' => now(),
        ]);
    }
};
