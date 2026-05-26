<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_reminder_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->date('due_on');
            $table->unsignedSmallInteger('days_before');
            $table->string('recipient', 160);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['company_id', 'kind', 'due_on', 'days_before'], 'billing_reminder_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_reminder_logs');
    }
};
