<?php

namespace App\Http\Requests\Staff\WhatsApp;

use App\Http\Requests\Staff\WhatsApp\Concerns\RedirectsWithoutInput;
use App\Services\WhatsApp\WhatsAppActivation;
use App\WhatsAppMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reviewing an activation (REQ-WA-66): the mode to activate. Never "disabled".
 * Nothing typed is flashed back to the session.
 */
class ReviewActivationRequest extends FormRequest
{
    use RedirectsWithoutInput;

    /**
     * @var string
     */
    protected $errorBag = 'activation';

    /**
     * @var string
     */
    protected $redirectRoute = 'staff.whatsapp';

    public function authorize(): bool
    {
        return $this->user()->can('manage-whatsapp');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in(array_map(fn (WhatsAppMode $mode) => $mode->value, WhatsAppActivation::TARGETS))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mode.required' => 'اختر الوضع المطلوب تفعيله.',
            'mode.in' => 'لا يمكن تفعيل هذا الوضع.',
        ];
    }

    public function mode(): WhatsAppMode
    {
        return WhatsAppMode::from($this->validated('mode'));
    }
}
