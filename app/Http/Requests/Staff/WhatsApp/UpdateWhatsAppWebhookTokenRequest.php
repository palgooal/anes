<?php

namespace App\Http\Requests\Staff\WhatsApp;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The webhook verify token (used by the delivery webhook, PH-WA-8). Empty keeps the stored value.
 */
class UpdateWhatsAppWebhookTokenRequest extends FormRequest
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
            'webhook_verify_token' => ['nullable', 'string', 'max:255', 'regex:/^\S{8,}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'webhook_verify_token.regex' => 'رمز التحقق 8 أحرف على الأقل، دون مسافات.',
        ];
    }
}
