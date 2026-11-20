<?php

namespace App\Http\Requests\Staff\WhatsApp;

use App\Http\Requests\Staff\WhatsApp\Concerns\RedirectsWithoutInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The test code the administrator received on WhatsApp (REQ-WA-36).
 */
class VerifyWhatsAppSendTestRequest extends FormRequest
{
    use RedirectsWithoutInput;

    /**
     * @var string
     */
    protected $errorBag = 'verify_send_test';

    public function authorize(): bool
    {
        return $this->user()->can('manage-whatsapp');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => preg_replace('/\s+/', '', (string) $this->input('code'))]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^\d{4,10}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'اكتب الرمز الذي وصلك على واتساب.',
            'code.regex' => 'الرمز أرقام فقط.',
        ];
    }
}
