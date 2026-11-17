<x-auth-layout title="دخول فريق العمل" variant="staff">
  <form method="POST" action="{{ route('staff.login') }}" class="grid gap-5" novalidate data-busy>
    @csrf
    <div class="grid gap-2">
      <p class="text-[.85rem] font-semibold text-role">بوابة فريق العمل</p>
      <h1 class="text-[1.75rem] font-bold sm:text-[2rem]">تسجيل الدخول</h1>
      <p class="text-muted">للمرشدين والمستشارين والإدارة. تُفتح لوحتك تلقائياً حسب صلاحيات حسابك.</p>
    </div>

    @if (session('status'))
      <div class="alert alert-info" role="status">{{ session('status') }}</div>
    @endif
    @error('credentials')
      <div class="alert alert-err" role="alert">{{ $message }}</div>
    @enderror

    <label class="field" for="email">
      البريد الإلكتروني
      <input class="input" id="email" name="email" type="email" value="{{ old('email') }}" dir="ltr" autocomplete="username" placeholder="name@example.com" autofocus required @error('email') aria-invalid="true" @enderror>
      <span class="err" role="alert">@error('email'){{ $message }}@enderror</span>
    </label>
    <div class="field">
      <div class="flex items-center justify-between">
        <label for="password">كلمة المرور</label>
        <a class="link text-[.84rem] font-normal" href="{{ route('password.request') }}">نسيت كلمة المرور؟</a>
      </div>
      <div class="relative">
        <input class="input pl-12" id="password" name="password" type="password" dir="ltr" autocomplete="current-password" placeholder="••••••••" required @error('password') aria-invalid="true" @enderror>
        <button type="button" class="absolute inset-y-0 end-0 grid w-12 cursor-pointer place-items-center text-muted hover:text-role" data-reveal-password="password" aria-label="إظهار كلمة المرور" aria-pressed="false">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
      <span class="err" role="alert">@error('password'){{ $message }}@enderror</span>
    </div>
    <label class="flex cursor-pointer items-center gap-2.5 text-[.9rem]">
      <input type="checkbox" name="remember" value="1" class="size-[18px] accent-role" @checked(old('remember'))>
      تذكّرني على هذا الجهاز
    </label>
    <button class="btn btn-primary w-full" type="submit">دخول</button>
    <p class="text-center text-[.88rem] text-muted">مرشد أو مستشار جديد؟ <a class="link" href="{{ route('staff.join') }}">اطلب الانضمام إلى الفريق</a></p>
  </form>

  <x-slot:footer>
    <p class="text-center text-[.84rem] text-muted">مريض أو مرافق؟ <a class="link" href="{{ route('login') }}">دخول المستفيد</a></p>
  </x-slot:footer>
</x-auth-layout>
