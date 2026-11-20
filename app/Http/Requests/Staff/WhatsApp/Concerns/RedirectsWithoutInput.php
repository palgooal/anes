<?php

namespace App\Http\Requests\Staff\WhatsApp\Concerns;

use Illuminate\Contracts\Validation\Validator;

/**
 * On a validation error, go back with the errors only: nothing typed is flashed
 * to the session for refilling the form (AC-WA-22, AC-WA-44).
 *
 * Scoped to the administrator's send test (the test code and the recipient's
 * number). The application-wide dontFlash list and the OTP forms are unchanged.
 */
trait RedirectsWithoutInput
{
    protected function failedValidation(Validator $validator): void
    {
        $exception = $validator->getException();

        // A ready response is returned as is by the exception handler, so the
        // handler's own redirect, which flashes the input, never runs.
        throw (new $exception($validator, redirect()->to($this->getRedirectUrl())->withErrors($validator->errors(), $this->errorBag)))
            ->errorBag($this->errorBag);
    }
}
