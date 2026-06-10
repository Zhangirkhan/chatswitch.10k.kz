<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_app_releases', function (Blueprint $table): void {
            $table->id();
            $table->string('platform', 16);
            $table->string('version_name', 32);
            $table->unsignedInteger('version_code');
            $table->unsignedInteger('min_version_code')->default(0);
            $table->string('download_url', 512);
            $table->text('release_notes')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'version_code']);
            $table->index(['platform', 'is_published']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_releases');
    }
};
