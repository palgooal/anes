<?php

namespace App\Http\Requests\Staff\WhatsApp;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Explicit confirmation before management moves from .env to the panel (REQ-WA-05).
 */
class TakeOverWhatsAppSettingsRequest extends FormRequest
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
            'confirm' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm.accepted' => 'أكّد أنك تفهم أثر نقل الإدارة إلى اللوحة.',
        ];
    }
}
