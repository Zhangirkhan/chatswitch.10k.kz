<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Support\OutboundSenderDisplayName;
use App\Support\OperatorSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class OutboundSenderDisplayNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('administrator');
        Role::findOrCreate('manager');
    }

    #[Test]
    public function it_resolves_operator_label_with_role(): void
    {
        $user = User::factory()->create(['name' => 'Администратор ESL']);
        $user->assignRole('administrator');

        $this->assertSame(
            'Администратор ESL (Администратор)',
            OutboundSenderDisplayName::resolve($user),
        );
    }

    #[Test]
    public function it_resolves_ai_label_for_company_reply(): void
    {
        $chat = Chat::factory()->create();
        $chat->load('company');
        $companyName = trim((string) ($chat->company?->name ?? ''));
        $user = User::factory()->create(['name' => 'Сани', 'company_id' => $chat->company_id]);

        $label = OutboundSenderDisplayName::resolve($user, $chat, [
            'ai' => ['generated' => true, 'reply_as_company' => true],
        ]);

        if ($companyName !== '') {
            $this->assertSame("{$companyName} (AI)", $label);
        } else {
            $this->assertSame('AI', $label);
        }
    }

    #[Test]
    public function it_resolves_ai_label_for_named_responder(): void
    {
        $user = User::factory()->create(['name' => 'Сани']);

        $label = OutboundSenderDisplayName::resolve($user, null, [
            'ai' => ['generated' => true, 'reply_as_company' => false],
        ]);

        $this->assertSame('Сани (AI)', $label);
    }

    #[Test]
    public function it_backfills_from_body_signature_for_legacy_messages(): void
    {
        $message = new Message([
            'direction' => 'outbound',
            'sender_name' => 'Legacy Name',
            'body' => "*Администратор ESL (Администратор)*\nЗдравствуйте!",
        ]);

        $this->assertSame(
            'Администратор ESL (Администратор)',
            OutboundSenderDisplayName::forMessage($message),
        );
    }

    #[Test]
    public function plain_label_matches_operator_signature_without_markdown(): void
    {
        $user = User::factory()->create(['name' => 'Жангирхан']);
        $user->assignRole('manager');

        $this->assertSame(
            trim(OperatorSignature::build($user), '*'),
            OperatorSignature::plainLabel($user),
        );
    }
}
