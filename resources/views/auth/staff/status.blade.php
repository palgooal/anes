<x-auth-layout :title="$status === \App\AccountStatus::Pending ? 'حسابك قيد المراجعة' : 'الحساب موقوف'" variant="staff">
  @if ($status === \App\AccountStatus::Pending)
    <div class="grid gap-5 text-center">
      <span class="mx-auto grid size-16 place-items-center rounded-full bg-[color-mix(in_srgb,var(--sun)_22%,var(--bg))] text-[color-mix(in_srgb,var(--sun)_55%,var(--ink))]">
        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h12M6 21h12M7 3c0 5 10 5 10 9s-10 4-10 9M17 3c0 5-10 5-10 9s10 4 10 9"/></svg>
      </span>
      <div class="grid gap-2">
        <h1 class="text-[1.5rem] font-bold">حسابك قيد المراجعة</h1>
        <p class="text-muted">استلمت الإدارة طلب انضمامك، وتراجع مؤهلاتك الآن. يصلك إشعار فور اعتماد الحساب، وعادةً خلال يومي عمل.</p>
      </div>
      <ol class="mx-auto grid w-full gap-2 text-start text-[.9rem]">
        <li class="flex items-center gap-3 rounded-xl bg-ok/10 px-4 py-2.5"><span class="grid size-6 place-items-center rounded-full bg-ok text-[.75rem] text-white">✓</span>استلام الطلب</li>
        <li class="flex items-center gap-3 rounded-xl bg-[color-mix(in_srgb,var(--sun)_16%,var(--bg))] px-4 py-2.5 font-medium"><span class="grid size-6 place-items-center rounded-full bg-sun text-[.75rem] text-sun-ink">٢</span>مراجعة المؤهلات</li>
        <li class="flex items-center gap-3 rounded-xl bg-surface px-4 py-2.5 text-muted"><span class="grid size-6 place-items-center rounded-full bg-line text-[.75rem]">٣</span>تفعيل الحساب</li>
      </ol>
      <a class="btn btn-ghost w-full" href="{{ route('staff.login') }}">العودة إلى تسجيل الدخول</a>
    </div>
  @else
    <div class="grid gap-5 text-center">
      <span class="mx-auto grid size-16 place-items-center rounded-full bg-[color-mix(in_srgb,var(--danger)_12%,var(--bg))] text-danger">
        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/></svg>
      </span>
      <div class="grid gap-2">
        <h1 class="text-[1.5rem] font-bold">الحساب موقوف</h1>
        <p class="text-muted">أوقفت الإدارة هذا الحساب. إن كنت ترى أن ذلك خطأ، تواصل مع إدارة الخدمة.</p>
      </div>
      <a class="btn btn-wa w-full" data-wa="حسابي في بوابة الفريق موقوف، أرجو المراجعة" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">تواصل مع الإدارة</a>
      <a class="link text-[.92rem]" href="{{ route('staff.login') }}">العودة إلى تسجيل الدخول</a>
    </div>
  @endif
</x-auth-layout>
