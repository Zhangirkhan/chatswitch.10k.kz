<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_department', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['chat_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_department');
    }
};
