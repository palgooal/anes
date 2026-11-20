<?php

namespace App\Http\Requests\Staff\WhatsApp;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Settings shared by both environments.
 */
class UpdateWhatsAppGeneralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-whatsapp');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'api_version' => ['nullable', 'string', 'regex:/^v\d{1,2}\.\d{1,2}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'api_version.regex' => 'اكتب نسخة Graph API بهذه الصيغة: v23.0',
        ];
    }
}
