<?php

declare(strict_types=1);

use App\Support\FunnelStageType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funnel_stages', function (Blueprint $table): void {
            $table->string('stage_type', 32)->default(FunnelStageType::OTHER)->after('color');
        });

        foreach (DB::table('funnel_stages')->select(['id', 'name'])->orderBy('id')->get() as $row) {
            DB::table('funnel_stages')
                ->where('id', $row->id)
                ->update([
                    'stage_type' => FunnelStageType::guessFromName((string) $row->name),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('funnel_stages', function (Blueprint $table): void {
            $table->dropColumn('stage_type');
        });
    }
};
