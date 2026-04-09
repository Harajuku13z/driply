<?php

declare(strict_types=1);

namespace App\Http\Requests\Outfit;

use App\Enums\OutfitStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOutfitRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'status' => ['nullable', Rule::enum(OutfitStatus::class)],
        ];
    }
}
