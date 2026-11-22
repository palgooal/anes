<x-auth-layout title="التحقق الثنائي" variant="staff">
  <div class="grid gap-6">
    <a class="link justify-self-start text-[.88rem]" href="{{ route('staff.login') }}">→ رجوع</a>
    <div class="grid gap-2">
      <h1 class="text-[1.75rem] font-bold">التحقق الثنائي</h1>
      @if ($channel === 'mail')
        <p class="text-muted">لحماية بيانات المستفيدين، أدخل الرمز المرسل إلى بريدك الإلكتروني <b class="num font-semibold text-ink" dir="ltr">{{ $destination }}</b>.</p>
      @else
        <p class="text-muted">لحماية بيانات المستفيدين، أدخل الرمز المرسل على واتساب إلى جوالك المنتهي بـ <b class="num font-semibold text-ink">••{{ substr($phone, -2) }}</b>.</p>
      @endif
    </div>
    <form method="POST" action="{{ route('staff.verify') }}" class="grid gap-6" novalidate data-busy>
      @csrf
      <x-auth.code-input />
      <button class="btn btn-primary w-full" type="submit">تحقّق ودخول</button>
    </form>
    <x-auth.resend :action="route('staff.resend')" :seconds="$resendIn" />
  </div>
</x-auth-layout>
