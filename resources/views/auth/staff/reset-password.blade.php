<x-auth-layout title="تعيين كلمة مرور جديدة" variant="staff">
  <form method="POST" action="{{ route('password.update') }}" class="grid gap-5" novalidate data-busy>
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <div class="grid gap-2">
      <h1 class="text-[1.75rem] font-bold">كلمة مرور جديدة</h1>
      <p class="text-muted">اختر كلمة مرور من ٨ أحرف على الأقل.</p>
    </div>
    <label class="field" for="email">
      البريد الإلكتروني
      <input class="input" id="email" name="email" type="email" value="{{ old('email', $email) }}" dir="ltr" autocomplete="username" required @error('email') aria-invalid="true" @enderror>
      <span class="err" role="alert">@error('email'){{ $message }}@enderror</span>
    </label>
    <label class="field" for="password">
      كلمة المرور الجديدة
      <input class="input" id="password" name="password" type="password" dir="ltr" autocomplete="new-password" autofocus required @error('password') aria-invalid="true" @enderror>
      <span class="err" role="alert">@error('password'){{ $message }}@enderror</span>
    </label>
    <label class="field" for="password_confirmation">
      تأكيد كلمة المرور
      <input class="input" id="password_confirmation" name="password_confirmation" type="password" dir="ltr" autocomplete="new-password" required>
    </label>
    <button class="btn btn-primary w-full" type="submit">حفظ كلمة المرور</button>
  </form>
</x-auth-layout>
