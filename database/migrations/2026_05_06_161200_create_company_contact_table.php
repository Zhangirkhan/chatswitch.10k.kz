<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_contact', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('position')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'contact_id']);
            $table->index(['contact_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_contact');
    }
};
