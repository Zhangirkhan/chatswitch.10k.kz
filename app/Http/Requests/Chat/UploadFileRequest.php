<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Models\Chat;
use App\Support\ChatUploadMimeRules;
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
        $type = $this->input('type');

        return [
            'file' => [
                'required',
                'file',
                'max:65536',
                'mimetypes:'.implode(',', ChatUploadMimeRules::mimetypesFor(is_string($type) ? $type : null)),
            ],
            'caption' => ['nullable', 'string', 'max:1024'],
            'type' => ['nullable', 'string', 'in:image,video,audio,voice,ptt,sticker,gif,document'],
        ];
    }
}
