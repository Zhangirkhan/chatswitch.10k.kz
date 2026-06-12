<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_stakeholders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('account_contact_id')->index();
            $table->unsignedBigInteger('stakeholder_contact_id')->index();
            $table->string('role', 32);
            $table->unsignedTinyInteger('influence')->default(50);
            $table->text('notes')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->string('source', 32)->default('manager');
            $table->timestamps();

            $table->unique(['account_contact_id', 'stakeholder_contact_id', 'role'], 'contact_stakeholders_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_stakeholders');
    }
};
