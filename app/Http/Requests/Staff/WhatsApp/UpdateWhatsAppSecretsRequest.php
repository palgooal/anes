<?php

namespace App\Http\Requests\Staff\WhatsApp;

use App\WhatsAppEnvironment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Secrets of one environment. Empty fields keep their stored value (REQ-WA-18).
 */
class UpdateWhatsAppSecretsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-whatsapp');
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Each environment's form shows its own errors.
        $environment = $this->route('environment');
        $this->errorBag = $environment instanceof WhatsAppEnvironment ? 'secrets_'.$environment->value : 'default';
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'access_token' => ['nullable', 'string', 'max:1024', 'regex:/^\S{20,}$/'],
            'app_secret' => ['nullable', 'string', 'max:255', 'regex:/^\S{8,}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'access_token.regex' => 'الصق رمز الوصول كاملاً دون مسافات.',
            'access_token.max' => 'رمز الوصول أطول من المعتاد. تأكد أنك لصقت الرمز وحده.',
            'app_secret.regex' => 'الصق App Secret كاملاً دون مسافات.',
            'app_secret.max' => 'App Secret أطول من المعتاد.',
        ];
    }
}
