<x-auth-layout title="رمز التحقق">
  <div class="grid gap-6">
    <a class="link justify-self-start text-[.88rem]" href="{{ route('login') }}">→ رجوع</a>
    <div class="grid gap-2">
      <h1 class="text-[1.75rem] font-bold">أدخل رمز التحقق</h1>
      <p class="text-muted">أرسلنا رمزاً إلى واتساب على الرقم <b class="num font-semibold text-ink">{{ $phone }}</b> · <a class="link" href="{{ route('login') }}">غيّرت رقمي؟</a></p>
    </div>
    <form method="POST" action="{{ route('login.verify') }}" class="grid gap-6" novalidate data-busy>
      @csrf
      <x-auth.code-input />
      <button class="btn btn-primary w-full" type="submit">تحقّق ودخول</button>
    </form>
    <x-auth.resend :action="route('login.resend')" :seconds="$resendIn" />
  </div>
</x-auth-layout>
