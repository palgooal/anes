<x-auth-layout title="دخول المستفيد">
  <form method="POST" action="{{ route('login') }}" class="grid gap-6" novalidate data-busy>
    @csrf
    <div class="grid gap-2">
      <p class="text-[.85rem] font-semibold text-role">صفحة المستفيد</p>
      <h1 class="text-[1.75rem] font-bold sm:text-[2rem]">أهلاً بك</h1>
      <p class="text-muted">للمريض والمرافق: ادخل برقم جوالك المسجّل لدى الخدمة، دون كلمة مرور.</p>
    </div>
    <label class="field" for="phone">
      رقم الجوال
      <input class="input text-[1.25rem] tracking-wider" id="phone" name="phone" value="{{ old('phone') }}" dir="ltr" inputmode="tel" autocomplete="tel" placeholder="05XXXXXXXX" maxlength="16" autofocus required @error('phone') aria-invalid="true" @enderror>
      <span class="err" role="alert">@error('phone'){{ $message }}@enderror</span>
    </label>
    <button class="btn btn-primary w-full" type="submit">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 21l1.6-4.8A8.5 8.5 0 1 1 8 19.6z"/></svg>
      أرسل رمز التحقق عبر واتساب
    </button>
    <p class="text-center text-[.84rem] text-muted">سيصلك رمز التحقق في رسالة واتساب.</p>
  </form>

  <x-slot:footer>
    <p class="flex items-center justify-center gap-2 text-[.84rem] text-muted">
      <svg class="flex-none text-spirit" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
      بياناتك سرّية، ولا يطّلع عليها إلا مرشدك.
    </p>
    <p class="text-center text-[.84rem] text-muted">من فريق العمل؟ <a class="link" href="{{ route('staff.login') }}">دخول الفريق</a></p>
  </x-slot:footer>
</x-auth-layout>
