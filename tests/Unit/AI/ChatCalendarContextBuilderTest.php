<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\CalendarEvent;
use App\Models\Chat;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AI\ChatCalendarContextBuilder;
use App\Support\TenantCompany;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ChatCalendarContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_includes_past_appointment_in_context_block(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'module_calendar'],
            ['value' => 'on'],
        );

        $user = User::factory()->create(['company_id' => TenantCompany::id()]);
        $chat = Chat::factory()->create(['company_id' => TenantCompany::id()]);

        CalendarEvent::query()->create([
            'user_id' => $user->id,
            'assignee_user_id' => $user->id,
            'chat_id' => $chat->id,
            'title' => 'Массаж',
            'starts_at' => Carbon::now()->subDays(3)->setTime(13, 0),
            'ends_at' => Carbon::now()->subDays(3)->setTime(14, 0),
            'all_day' => false,
        ]);

        $block = app(ChatCalendarContextBuilder::class)->buildContextBlock($chat);

        $this->assertStringContainsString('Прошедшие', $block);
        $this->assertStringContainsString('Массаж', $block);
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} 13:00/', $block);
    }
}
