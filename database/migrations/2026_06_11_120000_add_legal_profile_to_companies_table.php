<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\TenantSignupRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('bin', 12)->nullable()->after('slug');
            $table->text('legal_address')->nullable()->after('bin');
            $table->string('business_activity', 255)->nullable()->after('legal_address');

            $table->index('bin');
        });

        TenantSignupRequest::query()
            ->where('status', 'processed')
            ->whereNotNull('company_id')
            ->orderBy('id')
            ->each(function (TenantSignupRequest $request): void {
                $digits = preg_replace('/\D+/', '', (string) $request->bin) ?? '';
                if (strlen($digits) !== 12) {
                    return;
                }

                Company::query()
                    ->where('id', $request->company_id)
                    ->whereNull('bin')
                    ->update(['bin' => $digits]);
            });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex(['bin']);
            $table->dropColumn(['bin', 'legal_address', 'business_activity']);
        });
    }
};
