<x-auth-layout title="رقم غير مسجّل">
  <div class="grid gap-5 text-center">
    <span class="mx-auto grid size-16 place-items-center rounded-full bg-[color-mix(in_srgb,var(--sun)_22%,var(--bg))] text-[color-mix(in_srgb,var(--sun)_55%,var(--ink))]">
      <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/></svg>
    </span>
    <div class="grid gap-2">
      <h1 class="text-[1.5rem] font-bold">هذا الرقم غير مسجّل لدينا</h1>
      <p class="text-muted">الرقم <b class="num font-semibold text-ink">{{ $phone }}</b> ليس مسجّلاً في الخدمة بعد. سجّل عبر واتساب، ويصلك رمز الدخول بعد تفعيل حسابك.</p>
    </div>
    <a class="btn btn-wa w-full" data-wa="أرغب في التسجيل في خدمة الدعم والمساندة" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 21l1.6-4.8A8.5 8.5 0 1 1 8 19.6z"/></svg>
      سجّل عبر واتساب
    </a>
    <a class="link text-[.92rem]" href="{{ route('login') }}">جرّب رقماً آخر</a>
  </div>
</x-auth-layout>
