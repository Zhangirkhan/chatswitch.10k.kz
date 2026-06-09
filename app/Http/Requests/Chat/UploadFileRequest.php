<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Models\Chat;
use App\Support\ChatUploadMimeRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

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
            'type' => ['nullable', 'string', 'in:image,video,audio,voice,ptt,sticker,gif,document'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $file = $this->file('file');
            if (! $file instanceof UploadedFile) {
                return;
            }

            $type = $this->input('type');
            $uploadType = is_string($type) && $type !== '' ? $type : null;

            if (! ChatUploadMimeRules::accepts($file, $uploadType)) {
                $validator->errors()->add(
                    'file',
                    'Сервер не принимает этот формат файла.',
                );
            }
        });
    }
}
