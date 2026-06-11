<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_devices', function (Blueprint $table): void {
            $table->string('device_model', 128)->nullable()->after('device_name');
            $table->string('device_manufacturer', 64)->nullable()->after('device_model');
            $table->string('os_version', 128)->nullable()->after('device_manufacturer');
            $table->string('locale', 16)->nullable()->after('os_version');
            $table->boolean('is_physical_device')->nullable()->after('locale');
            $table->string('last_seen_ip', 45)->nullable()->after('is_physical_device');
        });

        Schema::table('user_feedback', function (Blueprint $table): void {
            $table->string('device_manufacturer', 64)->nullable()->after('device_model');
            $table->string('os_version', 128)->nullable()->after('device_manufacturer');
            $table->string('locale', 16)->nullable()->after('os_version');
            $table->string('client_ip', 45)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('user_feedback', function (Blueprint $table): void {
            $table->dropColumn(['device_manufacturer', 'os_version', 'locale', 'client_ip']);
        });

        Schema::table('user_devices', function (Blueprint $table): void {
            $table->dropColumn([
                'device_model',
                'device_manufacturer',
                'os_version',
                'locale',
                'is_physical_device',
                'last_seen_ip',
            ]);
        });
    }
};
