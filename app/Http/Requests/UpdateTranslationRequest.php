<?php

namespace App\Http\Requests;

use App\Models\Locale;
use App\Models\Translation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTranslationRequest extends FormRequest
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
        /** @var Translation $translation */
        $translation = $this->route('translation');

        $localeCode = $this->input('locale', $translation->locale->code);
        $localeId = Locale::query()->where('code', $localeCode)->value('id');

        return [
            'key' => [
                'sometimes',
                'required',
                'string',
                'max:191',
                Rule::unique('translations', 'translation_key')
                    ->where(fn ($query) => $query->where('locale_id', $localeId))
                    ->ignore($translation->id),
            ],
            'locale' => ['sometimes', 'required', 'string', 'exists:locales,code'],
            'content' => ['sometimes', 'required', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ];
    }
}
