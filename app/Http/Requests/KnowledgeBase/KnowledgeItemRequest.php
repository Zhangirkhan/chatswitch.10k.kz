<?php

declare(strict_types=1);

namespace App\Http\Requests\KnowledgeBase;

use Illuminate\Foundation\Http\FormRequest;

final class KnowledgeItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('administrator') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $common = [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'name' => ['required_without:title', 'string', 'max:255'],
            'title' => ['required_without:name', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'content' => ['nullable', 'string', 'max:8000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'is_active' => ['boolean'],
            'include_in_prompt' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];

        $routeName = (string) ($this->route()?->getName() ?? '');

        if (str_contains($routeName, '.services.')) {
            return $common + [
                'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
                'conditions' => ['nullable', 'array'],
            ];
        }

        if (str_contains($routeName, '.rules.')) {
            return $common + [
                'type' => ['nullable', 'string', 'max:80'],
                'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
            ];
        }

        return $common + [
            'sku' => ['nullable', 'string', 'max:120'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_image' => ['nullable', 'boolean'],
            'attributes' => ['nullable', 'array'],
        ];
    }
}
