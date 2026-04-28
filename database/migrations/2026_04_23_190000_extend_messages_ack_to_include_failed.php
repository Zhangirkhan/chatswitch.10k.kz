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
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE messages MODIFY ack VARCHAR(20) NOT NULL DEFAULT 'pending'");

            return;
        }

        Schema::table('messages', function (Blueprint $table): void {
            $table->string('ack', 20)->default('pending')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE messages MODIFY ack ENUM('pending','sent','delivered','read') NOT NULL DEFAULT 'pending'");
        }
    }
};
