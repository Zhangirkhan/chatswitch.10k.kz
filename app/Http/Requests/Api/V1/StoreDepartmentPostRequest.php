<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\DepartmentPost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDepartmentPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:65535'],
            'status' => ['nullable', Rule::in(DepartmentPost::STATUSES)],
            'due_at' => ['nullable', 'date'],
            'assignee_ids' => ['sometimes', 'nullable', 'array'],
            'assignee_ids.*' => ['integer'],
        ];
    }
}
