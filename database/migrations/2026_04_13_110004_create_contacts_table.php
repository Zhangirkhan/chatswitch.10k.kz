<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('whatsapp_id')->unique();
            $table->string('phone_number');
            $table->string('name')->nullable();
            $table->string('push_name')->nullable();
            $table->string('profile_picture_url')->nullable();
            $table->boolean('is_business')->default(false);
            $table->timestamps();

            $table->index('phone_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
