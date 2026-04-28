<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Models\WhatsappSession;
use Illuminate\Foundation\Http\FormRequest;

final class StartChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'phone' => ['nullable', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:120'],
            'whatsapp_session_id' => ['required', 'integer', 'exists:whatsapp_sessions,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (empty($this->input('contact_id')) && empty($this->input('phone'))) {
                $v->errors()->add('phone', 'Укажите контакт или номер телефона.');
            }
        });
    }

    public function resolvedSession(): WhatsappSession
    {
        return WhatsappSession::findOrFail((int) $this->input('whatsapp_session_id'));
    }
}
