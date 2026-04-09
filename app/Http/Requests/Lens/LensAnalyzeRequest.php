<?php

declare(strict_types=1);

namespace App\Http\Requests\Lens;

use Illuminate\Foundation\Http\FormRequest;

class LensAnalyzeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => ['required_without:image_url', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'image_url' => ['required_without:image', 'nullable', 'url'],
        ];
    }
}
