<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const EMAIL = 'system@chatswitch.internal';

    public function up(): void
    {
        User::query()->updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Система',
                'password' => Hash::make(Str::random(64)),
                'is_active' => false,
            ],
        );
    }

    public function down(): void
    {
        User::query()->where('email', self::EMAIL)->delete();
    }
};
