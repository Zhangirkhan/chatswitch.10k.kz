<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Company;
use App\Models\User;
use App\Services\AI\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PromptBuilderCompanyModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_prompt_uses_company_persona_without_manual_assignment(): void
    {
        $company = $this->createTenantCompany(['name' => 'ESL Окна', 'slug' => 'esl-okna']);
        $responder = User::factory()->create([
            'company_id' => $company->id,
            'name' => 'Айгуль Менеджер',
            'is_active' => true,
        ]);
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'ai_enabled' => true,
        ]);

        $built = $this->app->make(PromptBuilder::class)->build($chat, $responder, 'Здравствуйте');
        $system = $built['messages'][0]['content'] ?? '';

        $this->assertStringContainsString('от имени компании «ESL Окна»', $system);
        $this->assertStringContainsString('не называй своё имя', $system);
        $this->assertStringNotContainsString('от имени сотрудника «Айгуль Менеджер»', $system);
    }

    public function test_system_prompt_uses_employee_persona_when_chat_is_assigned(): void
    {
        $company = $this->createTenantCompany(['name' => 'ESL Окна', 'slug' => 'esl-okna-assigned']);
        $responder = User::factory()->create([
            'company_id' => $company->id,
            'name' => 'Айгуль Менеджер',
            'is_active' => true,
        ]);
        $chat = Chat::factory()->create(['company_id' => $company->id]);
        ChatAssignment::query()->create([
            'chat_id' => $chat->id,
            'user_id' => $responder->id,
            'assigned_by' => $responder->id,
        ]);

        $built = $this->app->make(PromptBuilder::class)->build($chat, $responder, 'Здравствуйте');
        $system = $built['messages'][0]['content'] ?? '';

        $this->assertStringContainsString('от имени сотрудника «Айгуль Менеджер»', $system);
        $this->assertStringNotContainsString('не называй своё имя', $system);
    }
}
