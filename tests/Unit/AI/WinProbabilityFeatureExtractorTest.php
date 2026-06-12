<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\Contact;
use App\Services\AI\ChatSalesStateService;
use App\Services\AI\WinProbabilityFeatureExtractor;
use App\Services\AI\WinProbabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WinProbabilityFeatureExtractorTest extends TestCase
{
    use RefreshDatabase;

    public function test_extractor_returns_fixed_length_vector(): void
    {
        $company = $this->createTenantCompany(['name' => 'ML Co', 'slug' => 'ml-co']);
        $contact = Contact::factory()->create(['company_id' => $company->id]);
        $chat = Chat::factory()->create(['company_id' => $company->id, 'contact_id' => $contact->id]);

        $vector = app(WinProbabilityFeatureExtractor::class)->vectorForChat($chat, app(ChatSalesStateService::class));

        $this->assertCount(count(WinProbabilityFeatureExtractor::FEATURE_KEYS), $vector);
    }

    public function test_service_falls_back_to_heuristic_without_model(): void
    {
        $company = $this->createTenantCompany(['name' => 'Heur Co', 'slug' => 'heur-co']);
        $contact = Contact::factory()->create(['company_id' => $company->id]);
        $chat = Chat::factory()->create(['company_id' => $company->id, 'contact_id' => $contact->id]);

        $result = app(WinProbabilityService::class)->compute($chat);

        $this->assertSame('heuristic', $result['model']['type']);
        $this->assertGreaterThanOrEqual(5, $result['win_probability']);
    }
}
