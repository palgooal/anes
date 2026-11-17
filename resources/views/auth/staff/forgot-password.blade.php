<x-auth-layout title="استعادة كلمة المرور" variant="staff">
  @if (session('sent_to'))
    <div class="grid gap-5 text-center">
      <span class="mx-auto grid size-16 place-items-center rounded-full bg-accent-soft text-accent">
        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
      </span>
      <div class="grid gap-2">
        <h1 class="text-[1.5rem] font-bold">تحقّق من بريدك</h1>
        <p class="text-muted">إن كان <b class="num font-semibold text-ink">{{ session('sent_to') }}</b> مسجّلاً لدينا، فسيصله رابط الاستعادة خلال دقائق. صلاحية الرابط ساعة واحدة.</p>
      </div>
      <a class="btn btn-ghost w-full" href="{{ route('staff.login') }}">العودة إلى تسجيل الدخول</a>
    </div>
  @else
    <form method="POST" action="{{ route('password.email') }}" class="grid gap-5" novalidate data-busy>
      @csrf
      <a class="link justify-self-start text-[.88rem]" href="{{ route('staff.login') }}">→ رجوع</a>
      <div class="grid gap-2">
        <h1 class="text-[1.75rem] font-bold">استعادة كلمة المرور</h1>
        <p class="text-muted">اكتب بريدك المسجّل، وسنرسل لك رابطاً لتعيين كلمة مرور جديدة.</p>
      </div>
      <label class="field" for="email">
        البريد الإلكتروني
        <input class="input" id="email" name="email" type="email" value="{{ old('email') }}" dir="ltr" autocomplete="username" placeholder="name@example.com" autofocus required @error('email') aria-invalid="true" @enderror>
        <span class="err" role="alert">@error('email'){{ $message }}@enderror</span>
      </label>
      <button class="btn btn-primary w-full" type="submit">أرسل رابط الاستعادة</button>
    </form>
  @endif
</x-auth-layout>
