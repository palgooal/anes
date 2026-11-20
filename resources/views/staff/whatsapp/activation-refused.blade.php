{{-- AC-WA-47, REQ-WA-67: a refused activation changed nothing. The readiness result of this attempt is shown once and never stored. --}}
<x-staff-layout title="لم يتم التفعيل" subtitle="لم يتغير مصدر رموز الدخول ولا الوضع ولا أي إعداد" active="whatsapp">
  <section class="card grid gap-4" aria-labelledby="activation-refused-title" data-activation-refused>
    <h2 id="activation-refused-title" class="text-[1.15rem]">لم يتم تفعيل «{{ $to->label() }}»</h2>

    <p class="note" role="alert">{{ $message }}</p>

    @if ($recorded === false)
      <p class="small" data-refusal-not-recorded>تعذّر أيضًا تسجيل هذه المحاولة في سجل التدقيق.</p>
    @endif

    <p class="small muted">نتيجة فحوص هذه المحاولة فقط، ولا تُحفظ:</p>
    @include('staff.whatsapp.partials.readiness-checks', ['checks' => $checks])

    <a class="btn btn-g justify-self-start" href="{{ route('staff.whatsapp') }}">العودة إلى الإعدادات</a>
  </section>
</x-staff-layout>
