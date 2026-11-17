<?php

namespace App\Http\Requests\Staff;

use App\Models\User;
use App\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-team');
    }

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
            'role' => ['required', Rule::enum(UserRole::class)->except(UserRole::beneficiaries())],
            'email' => ['required', 'string', 'email', 'max:190', Rule::unique('users', 'email')],
            'phone' => ['required', 'regex:/^05\d{8}$/', Rule::unique('users', 'phone')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اكتب اسم العضو.',
            'name.min' => 'اكتب اسم العضو كاملاً.',
            'role.required' => 'اختر الدور.',
            'role.enum' => 'اختر دوراً من القائمة.',
            'email.required' => 'اكتب البريد الإلكتروني.',
            'email.email' => 'اكتب بريداً إلكترونياً صحيحاً.',
            'email.unique' => 'هذا البريد مسجّل لعضو آخر.',
            'phone.required' => 'اكتب رقم الجوال، فرمز الدخول يُرسل إليه.',
            'phone.regex' => 'اكتب رقم جوال من ١٠ أرقام يبدأ بـ 05.',
            'phone.unique' => 'هذا الرقم مسجّل لحساب آخر.',
        ];
    }
}
