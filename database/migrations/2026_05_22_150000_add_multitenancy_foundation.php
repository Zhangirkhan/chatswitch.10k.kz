<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('slug', 32)->nullable()->unique()->after('id');
            $table->boolean('is_active')->default(true)->after('description');
            $table->unsignedBigInteger('owner_user_id')->nullable()->after('is_active');
            $table->foreignId('plan_id')->nullable()->after('owner_user_id');
            $table->string('subscription_status', 32)->default('active')->after('plan_id');
            $table->timestamp('trial_ends_at')->nullable()->after('subscription_status');
            $table->timestamp('current_period_ends_at')->nullable()->after('trial_ends_at');
        });

        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->unsignedInteger('price_cents')->default(0);
            $table->string('currency', 3)->default('KZT');
            $table->string('interval', 16)->default('month');
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->foreign('plan_id')->references('id')->on('plans')->nullOnDelete();
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('active');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 64);
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('KZT');
            $table->string('status', 32)->default('draft');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->timestamp('paid_at');
            $table->string('method', 32)->default('other');
            $table->string('external_ref')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tenant_signup_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('company_name');
            $table->string('contact_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->text('message')->nullable();
            $table->string('status', 32)->default('pending');
            $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_super_admin')->default(false)->after('is_active');
        });

        if (Schema::hasColumn('users', 'email')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropUnique(['email']);
            });
            Schema::table('users', function (Blueprint $table): void {
                $table->unique(['company_id', 'email']);
            });
        }

        Schema::table('whatsapp_sessions', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('departments', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        $defaultPlanId = DB::table('plans')->insertGetId([
            'code' => 'starter',
            'name' => 'Starter',
            'price_cents' => 0,
            'currency' => 'KZT',
            'interval' => 'month',
            'features' => json_encode([]),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('companies')->updateOrInsert(
            ['id' => 1],
            [
                'name' => 'Компания',
                'slug' => 'demo',
                'is_active' => true,
                'plan_id' => $defaultPlanId,
                'subscription_status' => 'active',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        $companyId = 1;

        DB::table('whatsapp_sessions')->whereNull('company_id')->update(['company_id' => $companyId]);
        DB::table('departments')->whereNull('company_id')->update(['company_id' => $companyId]);

        foreach (DB::table('company_contact')->select('company_id', 'contact_id')->get() as $row) {
            DB::table('contacts')
                ->where('id', $row->contact_id)
                ->whereNull('company_id')
                ->update(['company_id' => $row->company_id]);
        }
        DB::table('contacts')->whereNull('company_id')->update(['company_id' => $companyId]);

        DB::table('users')->whereNull('company_id')->update(['company_id' => $companyId]);

        $superEmail = env('SUPER_ADMIN_EMAIL', 'super@accel.kz');
        $superExists = DB::table('users')->where('email', $superEmail)->whereNull('company_id')->exists();
        if (! $superExists) {
            DB::table('users')->insert([
                'name' => 'Super Admin',
                'email' => $superEmail,
                'password' => DB::table('users')->where('email', 'admin@accel.kz')->value('password')
                    ?? bcrypt('password'),
                'is_active' => true,
                'is_super_admin' => true,
                'company_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('whatsapp_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
            $table->dropUnique(['session_name']);
            $table->unique(['company_id', 'session_name']);
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
            $table->dropUnique(['whatsapp_id']);
            $table->unique(['company_id', 'whatsapp_id']);
        });

        Schema::table('departments', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
        });

        foreach (DB::table('companies')->whereNull('slug')->get(['id', 'name']) as $row) {
            $base = Str::slug((string) $row->name, '-');
            if ($base === '') {
                $base = 'company';
            }
            $slug = $base;
            $n = 1;
            while (DB::table('companies')->where('slug', $slug)->where('id', '!=', $row->id)->exists()) {
                $slug = $base.'-'.$n;
                $n++;
            }
            DB::table('companies')->where('id', $row->id)->update(['slug' => $slug]);
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->string('slug', 32)->nullable(false)->change();
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropForeign(['plan_id']);
            $table->dropForeign(['owner_user_id']);
            $table->dropColumn([
                'slug', 'is_active', 'owner_user_id', 'plan_id',
                'subscription_status', 'trial_ends_at', 'current_period_ends_at',
            ]);
        });

        Schema::dropIfExists('tenant_signup_requests');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'whatsapp_id']);
            $table->unique('whatsapp_id');
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('whatsapp_sessions', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'session_name']);
            $table->unique('session_name');
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'email']);
            $table->dropColumn('is_super_admin');
            $table->unique('email');
        });
    }
};
