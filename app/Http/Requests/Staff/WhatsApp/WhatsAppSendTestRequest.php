<?php

namespace App\Http\Requests\Staff\WhatsApp;

use App\Http\Requests\Staff\WhatsApp\Concerns\RedirectsWithoutInput;
use App\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The recipient of an administrator's send test (REQ-WA-36), for the review
 * screen and for the send itself. Errors never repeat the number, and the
 * number is not flashed back: only the review screen shows it in full (AC-WA-44).
 */
class WhatsAppSendTestRequest extends FormRequest
{
    use RedirectsWithoutInput;

    /**
     * Errors go to the environment's own bag so they show under its card.
     *
     * @var string
     */
    protected $errorBag = 'send_test';

    public function authorize(): bool
    {
        return $this->user()->can('manage-whatsapp');
    }

    protected function prepareForValidation(): void
    {
        $this->errorBag = 'send_test_'.$this->route('environment')?->value;

        $this->merge([
            'phone' => PhoneNumber::normalize($this->input('phone')) ?? $this->input('phone'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:'.PhoneNumber::validationPattern()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => 'اكتب رقم الجوال الذي يصله اختبار الإرسال.',
            'phone.regex' => 'رقم الجوال غير صحيح، أو دولته غير مسموحة.',
        ];
    }
}
