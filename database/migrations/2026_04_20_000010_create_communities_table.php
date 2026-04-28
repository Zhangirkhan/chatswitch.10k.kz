<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('avatar_path')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamps();

            $table->index(['whatsapp_session_id', 'is_archived']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communities');
    }
};
