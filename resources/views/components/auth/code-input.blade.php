@props(['length' => (int) config('auth.one_time_codes.length')])

{{-- Separate digit boxes posted as code[]; resources/js/auth.js adds auto-advance, paste and auto-submit. --}}
<div class="grid gap-2">
  <div class="otp" data-code-input role="group" aria-label="رمز التحقق من {{ $length }} أرقام" @error('code') data-state="error" @enderror>
    @for ($i = 0; $i < $length; $i++)
      <input name="code[]" inputmode="numeric" pattern="[0-9٠-٩]" maxlength="1" required
             @if ($i === 0) autocomplete="one-time-code" @else autocomplete="off" @endif
             aria-label="الرقم {{ $i + 1 }}">
    @endfor
  </div>
  <p class="err text-center" role="alert">@error('code'){{ $message }}@enderror</p>
</div>
