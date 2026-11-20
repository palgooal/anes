<?php

namespace App\Http\Requests\Staff\WhatsApp;

use App\Services\WhatsApp\WhatsAppSettingsManager;
use App\WhatsAppMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The mode an administrator wants to switch to, before the review (REQ-WA-13, REQ-WA-71).
 * Production is never accepted here: it is reached by activation only (REQ-WA-14).
 */
class ReviewWhatsAppModeRequest extends FormRequest
{
    /**
     * @var string
     */
    protected $errorBag = 'mode';

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
            'mode' => ['required', 'string', Rule::in(array_map(fn (WhatsAppMode $mode) => $mode->value, WhatsAppSettingsManager::SWITCHABLE_MODES))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mode.required' => 'اختر الوضع المطلوب.',
            'mode.in' => 'لا يمكن اختيار هذا الوضع من هنا. التفعيل إلى الإنتاج يتم بإجراء التفعيل بعد فحوص الجاهزية.',
        ];
    }

    public function mode(): WhatsAppMode
    {
        return WhatsAppMode::from($this->validated('mode'));
    }
}
