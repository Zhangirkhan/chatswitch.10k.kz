<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\AiExperiment;
use App\Models\AiExperimentVariant;
use App\Models\Chat;
use App\Models\Contact;
use App\Services\AI\PromptExperimentResolver;
use App\Support\AiFeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PromptExperimentResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigns_stable_variant_for_same_chat(): void
    {
        $company = $this->createTenantCompany(['name' => 'AB Co', 'slug' => 'ab-co']);
        AiFeatureFlags::enable(AiFeatureFlags::PROMPT_EXPERIMENTS, $company->id);

        $experiment = AiExperiment::query()->create([
            'company_id' => $company->id,
            'slug' => 'reply-tone',
            'name' => 'Reply tone test',
            'target' => AiExperiment::TARGET_AI_REPLY,
            'status' => AiExperiment::STATUS_ACTIVE,
            'traffic_percent' => 100,
            'started_at' => now(),
        ]);

        AiExperimentVariant::query()->create([
            'experiment_id' => $experiment->id,
            'key' => 'A',
            'config' => ['prompt_addon' => 'Be concise.'],
            'is_control' => true,
        ]);
        AiExperimentVariant::query()->create([
            'experiment_id' => $experiment->id,
            'key' => 'B',
            'config' => ['prompt_addon' => 'Be warm.'],
            'is_control' => false,
        ]);

        $contact = Contact::factory()->create(['company_id' => $company->id]);
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
        ]);

        $resolver = app(PromptExperimentResolver::class);
        $first = $resolver->resolveForChat($chat);
        $second = $resolver->resolveForChat($chat);

        $this->assertNotNull($first);
        $this->assertSame($first->variantKey, $second->variantKey);
        $this->assertSame($first->experimentId, $second->experimentId);
    }
}
