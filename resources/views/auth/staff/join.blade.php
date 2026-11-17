<x-auth-layout title="طلب الانضمام إلى الفريق" variant="staff">
  @if (session('join_sent'))
    <div class="grid gap-5 text-center">
      <span class="mx-auto grid size-16 place-items-center rounded-full bg-ok/15 text-ok">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
      </span>
      <div class="grid gap-2">
        <h1 class="text-[1.5rem] font-bold">وصل طلبك</h1>
        <p class="text-muted">شكراً لك. ستراجع الإدارة طلبك ومؤهلاتك، ويصلك إشعار عند اعتماد حسابك لتعيين كلمة المرور.</p>
      </div>
      <a class="btn btn-ghost w-full" href="{{ route('staff.login') }}">العودة إلى تسجيل الدخول</a>
    </div>
  @else
    <form method="POST" action="{{ route('staff.join') }}" class="grid gap-4" novalidate data-busy>
      @csrf
      <a class="link justify-self-start text-[.88rem]" href="{{ route('staff.login') }}">→ رجوع</a>
      <div class="grid gap-2">
        <h1 class="text-[1.75rem] font-bold">طلب الانضمام إلى الفريق</h1>
        <p class="text-muted">تراجع الإدارة طلبك ومؤهلاتك، ثم يُفعّل حسابك.</p>
      </div>
      <label class="field" for="name">الاسم الكامل
        <input class="input" id="name" name="name" value="{{ old('name') }}" autocomplete="name" required @error('name') aria-invalid="true" @enderror>
        <span class="err" role="alert">@error('name'){{ $message }}@enderror</span>
      </label>
      <div class="grid gap-4 sm:grid-cols-2">
        <label class="field" for="email">البريد الإلكتروني
          <input class="input" id="email" name="email" type="email" value="{{ old('email') }}" dir="ltr" autocomplete="email" required @error('email') aria-invalid="true" @enderror>
          <span class="err" role="alert">@error('email'){{ $message }}@enderror</span>
        </label>
        <label class="field" for="phone">رقم الجوال
          <input class="input" id="phone" name="phone" value="{{ old('phone') }}" dir="ltr" inputmode="tel" placeholder="05XXXXXXXX" required @error('phone') aria-invalid="true" @enderror>
          <span class="err" role="alert">@error('phone'){{ $message }}@enderror</span>
        </label>
      </div>
      <label class="field" for="role">الدور
        <select class="input" id="role" name="role" required>
          @foreach ($roles as $role)
            <option value="{{ $role->value }}" @selected(old('role') === $role->value)>{{ $role->label() }}</option>
          @endforeach
        </select>
        <span class="err" role="alert">@error('role'){{ $message }}@enderror</span>
      </label>
      <label class="field" for="qualifications">المؤهلات والخبرة
        <textarea class="input min-h-24 resize-y" id="qualifications" name="qualifications" placeholder="المؤهل العلمي، والخبرة في مرافقة المرضى…" required @error('qualifications') aria-invalid="true" @enderror>{{ old('qualifications') }}</textarea>
        <span class="err" role="alert">@error('qualifications'){{ $message }}@enderror</span>
      </label>
      <button class="btn btn-primary w-full" type="submit">أرسل الطلب</button>
    </form>
  @endif
</x-auth-layout>
