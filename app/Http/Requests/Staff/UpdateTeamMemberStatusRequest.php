<?php

namespace App\Http\Requests\Staff;

use App\AccountStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamMemberStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-team');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(AccountStatus::class)->only([AccountStatus::Active, AccountStatus::Suspended])],
        ];
    }
}
