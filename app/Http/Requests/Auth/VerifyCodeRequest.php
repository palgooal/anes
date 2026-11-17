<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VerifyCodeRequest extends FormRequest
{
    /**
     * The code arrives as separate digit boxes (code[]) and may use Arabic-Indic digits.
     */
    protected function prepareForValidation(): void
    {
        $code = $this->input('code');
        $code = is_array($code) ? implode('', $code) : (string) $code;

        $this->merge(['code' => strtr($code, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ])]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $length = (int) config('auth.one_time_codes.length');

        return [
            'code' => ['required', "digits:{$length}"],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'اكتب رمز التحقق.',
            'code.digits' => 'اكتب الرمز كاملاً.',
        ];
    }
}
