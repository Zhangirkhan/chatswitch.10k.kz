<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_transcripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32)->default('audio');
            $table->text('text');
            $table->string('model', 64)->nullable();
            $table->string('source_mime', 255)->nullable();
            $table->string('source_filename', 255)->nullable();
            $table->string('text_disk_path', 512)->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_transcripts');
    }
};
