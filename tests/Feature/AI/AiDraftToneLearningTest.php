<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Jobs\AnalyzeCompanyToneProfileJob;
use App\Jobs\AnalyzeEmployeeToneProfileJob;
use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\Company;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\OutboundChatMessageDispatcher;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AiDraftToneLearningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['employee', 'administrator'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_edited_draft_dispatches_tone_analysis_jobs(): void
    {
        Bus::fake([AnalyzeEmployeeToneProfileJob::class, AnalyzeCompanyToneProfileJob::class]);

        TenantCompany::ensureExists();
        $companyId = TenantCompany::id();
        $employee = User::factory()->create(['company_id' => $companyId]);
        $employee->assignRole('employee');
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $companyId,
            'whatsapp_session_id' => $session->id,
            'ai_mode' => 'draft',
        ]);

        AiResponseLog::create([
            'company_id' => $companyId,
            'chat_id' => $chat->id,
            'user_id' => $employee->id,
            'mode' => 'draft',
            'status' => 'drafted',
            'metadata' => ['draft_reply' => 'Здравствуйте, диван стоит 150 000 тенге.'],
        ]);

        app(OutboundChatMessageDispatcher::class)->sendTextMessage($employee, $chat, [
            'message' => 'Добрый день! Диван от 180 000 ₸, уточню наличие.',
        ]);

        Bus::assertDispatched(AnalyzeEmployeeToneProfileJob::class);
        Bus::assertDispatched(AnalyzeCompanyToneProfileJob::class);

        $log = AiResponseLog::query()->where('chat_id', $chat->id)->latest('id')->first();
        $this->assertTrue((bool) data_get($log?->metadata, 'draft_was_edited'));
        $this->assertSame('heavy', data_get($log?->metadata, 'draft_edit_kind'));
    }

    public function test_punctuation_only_edit_dispatches_tone_jobs(): void
    {
        Bus::fake([AnalyzeEmployeeToneProfileJob::class, AnalyzeCompanyToneProfileJob::class]);

        TenantCompany::ensureExists();
        $companyId = TenantCompany::id();
        $employee = User::factory()->create(['company_id' => $companyId]);
        $employee->assignRole('employee');
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $companyId,
            'whatsapp_session_id' => $session->id,
            'ai_mode' => 'draft',
        ]);

        AiResponseLog::create([
            'company_id' => $companyId,
            'chat_id' => $chat->id,
            'user_id' => $employee->id,
            'mode' => 'draft',
            'status' => 'drafted',
            'metadata' => ['draft_reply' => 'Здравствуйте, диван стоит 150 000 тенге.'],
        ]);

        app(OutboundChatMessageDispatcher::class)->sendTextMessage($employee, $chat, [
            'message' => 'Здравствуйте! Диван стоит 150 000 тенге.',
        ]);

        Bus::assertDispatched(AnalyzeEmployeeToneProfileJob::class);

        $log = AiResponseLog::query()->where('chat_id', $chat->id)->latest('id')->first();
        $this->assertSame('punctuation', data_get($log?->metadata, 'draft_edit_kind'));
    }

    public function test_unchanged_draft_does_not_dispatch_tone_jobs(): void
    {
        Bus::fake([AnalyzeEmployeeToneProfileJob::class, AnalyzeCompanyToneProfileJob::class]);

        $company = Company::create(['name' => 'Co']);
        $employee = User::factory()->create(['company_id' => $company->id]);
        $employee->assignRole('employee');
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        $draft = 'Точный текст черновика без изменений.';
        AiResponseLog::create([
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'user_id' => $employee->id,
            'mode' => 'draft',
            'status' => 'drafted',
            'metadata' => ['draft_reply' => $draft],
        ]);

        app(OutboundChatMessageDispatcher::class)->sendTextMessage($employee, $chat, [
            'message' => $draft,
        ]);

        Bus::assertNotDispatched(AnalyzeEmployeeToneProfileJob::class);
        Bus::assertNotDispatched(AnalyzeCompanyToneProfileJob::class);
    }
}
