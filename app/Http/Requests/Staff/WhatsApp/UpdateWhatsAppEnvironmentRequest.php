<?php

namespace App\Http\Requests\Staff\WhatsApp;

use App\Models\WhatsAppSetting;
use App\WhatsAppEnvironment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Non-secret fields of one environment (test or production).
 */
class UpdateWhatsAppEnvironmentRequest extends FormRequest
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
        $this->errorBag = $environment instanceof WhatsAppEnvironment ? $environment->value : 'default';

        $trimmed = [];

        foreach (['phone_number_id', 'waba_id', 'display_number', 'template_name', 'template_language'] as $field) {
            $value = $this->input($field);
            $trimmed[$field] = is_string($value) ? trim($value) : $value;
        }

        // "+966 50 000 0000" → "966500000000".
        if (is_string($trimmed['display_number']) && $trimmed['display_number'] !== '') {
            $trimmed['display_number'] = preg_replace('/[\s\-+()]/', '', $trimmed['display_number']);
        }

        $this->merge($trimmed);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone_number_id' => ['nullable', 'string', 'regex:/^\d{5,32}$/'],
            'waba_id' => ['nullable', 'string', 'regex:/^\d{5,32}$/'],
            'display_number' => ['nullable', 'string', 'regex:/^\d{8,20}$/'],
            'template_name' => ['nullable', 'string', 'regex:/^[a-z0-9_]{1,100}$/'],
            'template_language' => ['nullable', 'string', 'regex:/^[a-z]{2,3}(_[A-Z]{2})?$/'],
        ];
    }

    /**
     * REQ-WA-10: the same Phone Number ID cannot serve both environments.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $phoneNumberId = $this->input('phone_number_id');
                $environment = $this->route('environment');

                if (! $environment instanceof WhatsAppEnvironment || ! is_string($phoneNumberId) || $phoneNumberId === '') {
                    return;
                }

                $other = WhatsAppSetting::query()->value($environment->other()->column('phone_number_id'));

                if ($other === $phoneNumberId) {
                    $validator->errors()->add('phone_number_id', 'هذا المعرّف مستخدم في البيئة الأخرى. لا يجوز استخدام الرقم نفسه للاختبار والإنتاج.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone_number_id.regex' => 'Phone Number ID أرقام فقط، كما يظهر في WhatsApp Manager.',
            'waba_id.regex' => 'WhatsApp Business Account ID أرقام فقط.',
            'display_number.regex' => 'اكتب الرقم بالصيغة الدولية دون أصفار في أوله، مثل 966500000000.',
            'template_name.regex' => 'اسم القالب أحرف إنجليزية صغيرة وأرقام وشرطة سفلية فقط، كما في Meta.',
            'template_language.regex' => 'رمز اللغة مثل ar أو en_US.',
        ];
    }
}
