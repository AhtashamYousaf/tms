<?php

namespace App\Http\Requests;

use App\Models\Locale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTranslationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $localeId = Locale::query()->where('code', $this->input('locale'))->value('id');

        return [
            'key' => [
                'required',
                'string',
                'max:191',
                Rule::unique('translations', 'translation_key')
                    ->where(fn ($query) => $query->where('locale_id', $localeId)),
            ],
            'locale' => ['required', 'string', 'exists:locales,code'],
            'content' => ['required', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ];
    }
}
