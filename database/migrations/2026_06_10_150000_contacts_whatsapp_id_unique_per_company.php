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
        if (! Schema::hasColumn('contacts', 'company_id') || ! Schema::hasColumn('contacts', 'whatsapp_id')) {
            return;
        }

        if ($this->indexExists('contacts', 'contacts_company_whatsapp_unique')) {
            return;
        }

        $this->dropIndexIfExists('contacts', 'contacts_whatsapp_id_unique');

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            try {
                Schema::table('contacts', function (Blueprint $table): void {
                    $table->dropUnique(['whatsapp_id']);
                });
            } catch (Throwable) {
                // Multitenancy migration may have already replaced the legacy unique index.
            }
        }

        Schema::table('contacts', function (Blueprint $table): void {
            $table->unique(['company_id', 'whatsapp_id'], 'contacts_company_whatsapp_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('contacts', 'company_id')) {
            return;
        }

        $this->dropIndexIfExists('contacts', 'contacts_company_whatsapp_unique');

        if (! $this->indexExists('contacts', 'contacts_whatsapp_id_unique')) {
            Schema::table('contacts', function (Blueprint $table): void {
                $table->unique('whatsapp_id', 'contacts_whatsapp_id_unique');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return collect(Schema::getConnection()
            ->getSchemaBuilder()
            ->getIndexes($table))
            ->contains(fn (array $index): bool => ($index['name'] ?? '') === $indexName);
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! $this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($indexName): void {
            $table->dropIndex($indexName);
        });
    }
};
