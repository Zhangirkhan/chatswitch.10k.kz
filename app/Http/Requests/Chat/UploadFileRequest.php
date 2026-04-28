<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Models\Chat;
use Illuminate\Foundation\Http\FormRequest;

final class UploadFileRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:65536'],
            'caption' => ['nullable', 'string', 'max:1024'],
            'type' => ['nullable', 'string', 'in:image,video,audio,voice,sticker,gif,document'],
        ];
    }
}
