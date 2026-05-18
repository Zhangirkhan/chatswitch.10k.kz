<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Models\Chat;
use Illuminate\Foundation\Http\FormRequest;

final class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Chat|null $chat */
        $chat = $this->route('chat');

        return $chat !== null && $this->user()?->can('sendMessage', $chat) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['required_without:product_id', 'nullable', 'string', 'max:4096'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'display_message' => ['nullable', 'string', 'max:4096'],
            'quoted_message_id' => ['nullable', 'string', 'max:255'],
            'mentions' => ['nullable', 'array', 'max:20'],
            'mentions.*' => ['string', 'max:255'],
            'mentions_meta' => ['nullable', 'array', 'max:20'],
            'mentions_meta.*.id' => ['required_with:mentions_meta', 'string', 'max:255'],
            'mentions_meta.*.number' => ['required_with:mentions_meta', 'string', 'max:32'],
            'mentions_meta.*.label' => ['required_with:mentions_meta', 'string', 'max:120'],
        ];
    }
}
