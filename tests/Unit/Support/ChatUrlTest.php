<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Chat;
use App\Models\Company;
use App\Support\ChatUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class ChatUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_url_includes_tenant_slug(): void
    {
        $company = Company::query()->create([
            'name' => 'ESL',
            'slug' => 'esl',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        $chat = Chat::factory()->create(['company_id' => $company->id]);

        URL::forceRootUrl('https://esl.accel.kz');

        $url = ChatUrl::show($chat);

        $this->assertStringContainsString('esl.accel.kz/chats/'.$chat->id, $url);
    }
}
