<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_tone_profiles', function (Blueprint $table): void {
            $table->boolean('use_manual_override')->default(false)->after('phrases');
            $table->text('manual_summary')->nullable()->after('use_manual_override');
            $table->json('manual_phrases')->nullable()->after('manual_summary');
        });
    }

    public function down(): void
    {
        Schema::table('company_tone_profiles', function (Blueprint $table): void {
            $table->dropColumn(['use_manual_override', 'manual_summary', 'manual_phrases']);
        });
    }
};
