<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_whatsapp_session', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_session_id')
                ->constrained('whatsapp_sessions')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'whatsapp_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_whatsapp_session');
    }
};
