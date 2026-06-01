<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_transcripts', function (Blueprint $table) {
            $table->string('status', 32)->default('pending')->after('kind');
            $table->text('error_message')->nullable()->after('text_disk_path');
            $table->timestamp('started_at')->nullable()->after('error_message');
            $table->timestamp('completed_at')->nullable()->after('started_at');
        });

        DB::table('message_transcripts')
            ->whereNotNull('text')
            ->where('text', '!=', '')
            ->update(['status' => 'completed', 'completed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('message_transcripts', function (Blueprint $table) {
            $table->dropColumn(['status', 'error_message', 'started_at', 'completed_at']);
        });
    }
};
