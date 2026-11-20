<?php

namespace App\Http\Requests\Staff\WhatsApp;

use App\Services\WhatsApp\WhatsAppSettingsManager;
use App\WhatsAppMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Switching the mode after the review (REQ-WA-13, REQ-WA-16, REQ-WA-71).
 *
 * The acknowledgements are checked here, on the server: the review screen only
 * shows the warnings. expected_mode is the mode the administrator reviewed, so a
 * change made in the meantime is refused instead of being overwritten.
 */
class SwitchWhatsAppModeRequest extends ReviewWhatsAppModeRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'expected_mode' => ['required', 'string', Rule::enum(WhatsAppMode::class)],
            'acknowledge_impact' => ['accepted'],
        ];
    }

    /**
     * REQ-WA-16: in a production application the test number only reaches the
     * recipients registered for it, so this is acknowledged separately.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('mode') === WhatsAppMode::Test->value
                    && app()->isProduction()
                    && ! $this->boolean('acknowledge_test_recipients')) {
                    $validator->errors()->add('acknowledge_test_recipients', 'أقرّ بأن رموز الدخول لن تصل إلا إلى المستلمين المسجلين لرقم الاختبار.');
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
            'expected_mode.required' => 'افتح مراجعة التبديل من جديد.',
            'expected_mode.enum' => 'افتح مراجعة التبديل من جديد.',
            'acknowledge_impact.accepted' => 'أقرّ بأثر التبديل على رموز الدخول، ومنها دخولك أنت.',
        ];
    }

    /**
     * An attempt to switch to production is audited (REQ-WA-14); other validation
     * failures are not. This runs once, after the permission check (authorize) and
     * the password confirmation middleware, and before anything is written.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        if ($this->attemptsProduction()) {
            app(WhatsAppSettingsManager::class)->recordProductionAttempt($this->user(), $this);
        }

        parent::failedValidation($validator);
    }

    private function attemptsProduction(): bool
    {
        $mode = $this->input('mode');

        return is_string($mode) && strtolower(trim($mode)) === WhatsAppMode::Production->value;
    }

    public function expectedMode(): WhatsAppMode
    {
        return WhatsAppMode::from($this->validated('expected_mode'));
    }
}
