<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class KnowledgeArticleStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'max:100'],
            'title'    => ['required', 'string', 'max:255'],
            'content'  => ['required', 'string'],
            'keywords' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'category.required' => 'Kategori wajib diisi.',
            'title.required'    => 'Judul wajib diisi.',
            'content.required'  => 'Konten wajib diisi.',
        ];
    }
}
