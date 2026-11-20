<x-auth-layout title="تأكيد كلمة المرور" variant="staff">
  <form method="POST" action="{{ route('password.confirm') }}" class="grid gap-5" novalidate data-busy>
    @csrf
    <div class="grid gap-2">
      <p class="text-[.85rem] font-semibold text-role">بوابة فريق العمل</p>
      <h1 class="text-[1.75rem] font-bold sm:text-[2rem]">تأكيد كلمة المرور</h1>
      <p class="text-muted">هذا إجراء حساس. اكتب كلمة المرور للمتابعة، ثم أعد تنفيذ الإجراء.</p>
    </div>

    <div class="field">
      <label for="password">كلمة المرور</label>
      <div class="relative">
        <input class="input pl-12" id="password" name="password" type="password" dir="ltr" autocomplete="current-password" placeholder="••••••••" autofocus required @error('password') aria-invalid="true" @enderror>
        <button type="button" class="absolute inset-y-0 end-0 grid w-12 cursor-pointer place-items-center text-muted hover:text-role" data-reveal-password="password" aria-label="إظهار كلمة المرور" aria-pressed="false">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
      <span class="err" role="alert">@error('password'){{ $message }}@enderror</span>
    </div>
    <button class="btn btn-primary w-full" type="submit">تأكيد</button>
  </form>
</x-auth-layout>
