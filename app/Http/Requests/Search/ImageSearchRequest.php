<?php

declare(strict_types=1);

namespace App\Http\Requests\Search;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImageSearchRequest extends FormRequest
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
            'query' => ['required', 'string', 'max:500'],
            'outfit_id' => [
                'nullable',
                'uuid',
                Rule::exists('outfits', 'id')->where('user_id', (int) $this->user()?->id),
            ],
        ];
    }
}
