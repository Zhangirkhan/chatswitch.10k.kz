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
        if (! Schema::hasColumn('users', 'email') || ! Schema::hasColumn('users', 'company_id')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $this->dropIndexIfExists('users', 'users_email_unique');
            $this->dropIndexIfExists('users', 'users_company_id_email_unique');
        } else {
            $this->dropLegacyEmailUniqueForSqlite();
        }

        if (! $this->indexExists('users', 'users_company_id_email_unique')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unique(['company_id', 'email'], 'users_company_id_email_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'email')) {
            return;
        }

        $this->dropIndexIfExists('users', 'users_company_id_email_unique');

        if (Schema::getConnection()->getDriverName() === 'mysql' && ! $this->indexExists('users', 'users_email_unique')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unique('email', 'users_email_unique');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return collect(Schema::getConnection()
                ->getSchemaBuilder()
                ->getIndexes($table))
                ->contains(fn (array $index): bool => ($index['name'] ?? '') === $indexName);
        }

        return collect(DB::select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$indexName]))->isNotEmpty();
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

    private function dropLegacyEmailUniqueForSqlite(): void
    {
        if ($this->indexExists('users', 'users_company_id_email_unique')) {
            return;
        }

        try {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropUnique(['email']);
            });
        } catch (Throwable) {
            try {
                Schema::table('users', function (Blueprint $table): void {
                    $table->dropIndex('users_email_unique');
                });
            } catch (Throwable) {
                // Fresh sqlite schema from multitenancy migration may already use composite unique.
            }
        }
    }
};
