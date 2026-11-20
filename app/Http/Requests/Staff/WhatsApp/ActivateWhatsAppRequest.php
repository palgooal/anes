<?php

namespace App\Http\Requests\Staff\WhatsApp;

use App\Models\WhatsAppSetting;
use App\Services\WhatsApp\WhatsAppActivation;
use App\WhatsAppMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Activating after the review (REQ-WA-66, REQ-WA-24 (6), AC-WA-54).
 *
 * - The impact on login codes is acknowledged for every mode (REQ-WA-71).
 * - Production also needs the acknowledgement of REQ-AUTH-37 and its reference
 *   text, checked on the server (WhatsAppActivation::referenceProblem()).
 * - The reference text is never flashed back to the session.
 */
class ActivateWhatsAppRequest extends ReviewActivationRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'expected_mode' => ['required', 'string', Rule::enum(WhatsAppMode::class)],
            'expected_state' => ['required', 'string', Rule::in([WhatsAppActivation::PREPARING, WhatsAppActivation::ACTIVE])],
            'acknowledge_impact' => ['accepted'],
            'acknowledge_ownership' => [Rule::requiredIf(fn () => $this->input('mode') === WhatsAppMode::Production->value), 'accepted_if:mode,production'],
            'reference' => [Rule::requiredIf(fn () => $this->input('mode') === WhatsAppMode::Production->value), 'nullable', 'string'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('mode') !== WhatsAppMode::Production->value || $validator->errors()->has('reference')) {
                    return;
                }

                if ($problem = WhatsAppActivation::referenceProblem($this->input('reference'), WhatsAppSetting::query()->first())) {
                    $validator->errors()->add('reference', $problem);
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return parent::messages() + [
            'expected_mode.required' => 'افتح مراجعة التفعيل من جديد.',
            'expected_mode.enum' => 'افتح مراجعة التفعيل من جديد.',
            'expected_state.required' => 'افتح مراجعة التفعيل من جديد.',
            'expected_state.in' => 'افتح مراجعة التفعيل من جديد.',
            'acknowledge_impact.accepted' => 'أقرّ بأثر التفعيل على رموز الدخول، ومنها دخولك أنت.',
            'acknowledge_ownership.required' => 'أقرّ بتحقق شرط ملكية الرقم (REQ-AUTH-37).',
            'acknowledge_ownership.accepted_if' => 'أقرّ بتحقق شرط ملكية الرقم (REQ-AUTH-37).',
            'reference.required' => 'اكتب النص المرجعي لمستند التحقق من ملكية الرقم.',
            'reference.string' => 'اكتب النص المرجعي لمستند التحقق من ملكية الرقم.',
        ];
    }

    public function expectedMode(): WhatsAppMode
    {
        return WhatsAppMode::from($this->validated('expected_mode'));
    }

    public function reference(): ?string
    {
        return $this->mode() === WhatsAppMode::Production ? (string) $this->validated('reference') : null;
    }
}
