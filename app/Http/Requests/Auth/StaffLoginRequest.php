<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StaffLoginRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'اكتب بريدك الإلكتروني.',
            'email.email' => 'اكتب بريداً إلكترونياً صحيحاً.',
            'password.required' => 'اكتب كلمة المرور.',
        ];
    }

    /**
     * Key for counting failed passwords: per email and network address.
     */
    public function throttleKey(): string
    {
        return 'staff-login:'.Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
