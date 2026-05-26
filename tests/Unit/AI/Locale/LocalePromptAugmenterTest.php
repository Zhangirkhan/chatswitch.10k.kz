<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Locale;

use App\Services\AI\Locale\LocalePromptAugmenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LocalePromptAugmenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_augment_includes_language_profile_block(): void
    {
        config(['locale_assistant.enabled' => true]);

        $augmenter = $this->app->make(LocalePromptAugmenter::class);

        $result = $augmenter->augment('Здравствуйте, можете помочь?');

        $this->assertNotEmpty($result['blocks']);
        $combined = implode("\n", $result['blocks']);
        $this->assertStringContainsString('Языковой профиль', $combined);
        $this->assertStringContainsString('Правила ответа', $combined);
    }
}
