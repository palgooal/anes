<x-auth-layout title="أُوقف الدخول مؤقتاً" :variant="$variant">
  <div class="grid gap-5 text-center">
    <span class="mx-auto grid size-16 place-items-center rounded-full bg-[color-mix(in_srgb,var(--danger)_12%,var(--bg))] text-danger">
      <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
    </span>
    <div class="grid gap-2">
      <h1 class="text-[1.5rem] font-bold">أُوقف الدخول مؤقتاً</h1>
      <p class="text-muted">محاولات خاطئة متكررة. لحماية حسابك يمكنك المحاولة مجدداً بعد
        <b class="font-semibold text-ink" data-countdown data-seconds="{{ $seconds }}" data-done-text="الآن">{{ ceil($seconds / 60) }} دقيقة</b>.</p>
    </div>
    @if ($variant === 'staff')
      <a class="btn btn-primary w-full" href="{{ route('password.request') }}">استعادة كلمة المرور</a>
    @endif
    <a class="btn btn-ghost w-full" id="lock-retry" href="{{ route($retryRoute) }}">حاول مجدداً</a>
    <a class="link text-[.92rem]" data-wa="لا أستطيع الدخول إلى حسابي" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">تحتاج مساعدة؟ راسلنا على واتساب</a>
  </div>
</x-auth-layout>
