<?php

declare(strict_types=1);

namespace App\Http\Requests\Media;

use App\Enums\MediaPlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportMediaRequest extends FormRequest
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
            'url' => ['required', 'url'],
            'platform' => ['required', Rule::enum(MediaPlatform::class)],
        ];
    }
}
