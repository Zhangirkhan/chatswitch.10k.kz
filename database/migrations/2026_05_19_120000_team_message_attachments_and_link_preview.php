<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_messages', function (Blueprint $table): void {
            $table->json('link_preview')->nullable()->after('forward_quote_body');
        });

        Schema::create('team_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_message_id')->constrained('team_messages')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();

            $table->index('team_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_message_attachments');

        Schema::table('team_messages', function (Blueprint $table): void {
            $table->dropColumn('link_preview');
        });
    }
};
