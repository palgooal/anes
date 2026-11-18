<?php

namespace App\Http\Requests\Auth;

use App\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PhoneLoginRequest extends FormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => PhoneNumber::normalize($this->input('phone'), $this->input('country')) ?? $this->input('phone'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'regex:'.PhoneNumber::validationPattern()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => 'اكتب رقم جوالك.',
            'phone.regex' => 'رقم الجوال غير صحيح. اكتبه هكذا 05XXXXXXXX، وتأكد من اختيار الدولة.',
        ];
    }
}
