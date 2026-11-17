<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class JoinTeamRequest extends FormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['phone' => User::normalizePhone($this->input('phone'))]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190', Rule::unique('users', 'email')],
            'phone' => ['required', 'regex:/^05\d{8}$/', Rule::unique('users', 'phone')],
            'role' => ['required', Rule::enum(UserRole::class)->only(UserRole::joinable())],
            'qualifications' => ['required', 'string', 'min:10', 'max:3000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اكتب اسمك الكامل.',
            'name.min' => 'اكتب اسمك الكامل.',
            'email.required' => 'اكتب بريدك الإلكتروني.',
            'email.email' => 'اكتب بريداً إلكترونياً صحيحاً.',
            'email.unique' => 'هذا البريد مسجّل لدينا. جرّب تسجيل الدخول أو استعادة كلمة المرور.',
            'phone.required' => 'اكتب رقم جوالك.',
            'phone.regex' => 'اكتب رقم جوال من ١٠ أرقام يبدأ بـ 05.',
            'phone.unique' => 'هذا الرقم مسجّل لدينا.',
            'role.required' => 'اختر الدور.',
            'role.enum' => 'اختر دوراً من القائمة.',
            'qualifications.required' => 'اكتب نبذة عن مؤهلاتك.',
            'qualifications.min' => 'اكتب نبذة عن مؤهلاتك.',
        ];
    }
}
