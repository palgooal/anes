@php
    use App\Services\WhatsApp\WhatsAppReadiness;

    $technicalPassed = collect($result['checks'])->except(6)->every(fn (array $check) => $check['status'] === WhatsAppReadiness::PASSED);
@endphp

{{-- REQ-WA-24, REQ-WA-25: the result of one run, shown once and never stored. --}}
<x-staff-layout title="جاهزية بيئة الإنتاج" subtitle="نتيجة فحص واحد، لا تُحفظ ولا تُعدّ جاهزية دائمة" active="whatsapp">
  <section class="card grid gap-4" aria-labelledby="readiness-title" data-readiness>
    <div class="sec-h">
      <h2 id="readiness-title" class="text-[1.15rem]">شروط الجاهزية</h2>
      {{-- The five technical checks are judged on their own: the acknowledgement (6) is only asked for at activation. --}}
      @if ($result['ready'])
        <span class="pill ok" data-readiness-overall="ready">الشروط الستة مستوفاة</span>
      @elseif ($technicalPassed)
        <span class="pill ok" data-readiness-overall="technical-passed">الشروط التقنية الخمسة مستوفاة</span>
      @else
        <span class="pill new" data-readiness-overall="technical-failed">شروط تقنية غير مستوفاة</span>
      @endif
    </div>

    @if (! $result['ready'] && $technicalPassed)
      <p class="small" data-readiness-acknowledgement-pending>
        يبقى الإقرار المرجعي بالشرط (6)، ويُقدَّم عند التفعيل وليس في هذا الفحص. لذلك لا تُعدّ الجاهزية للتفعيل مكتملة بعد، وهذا ليس فشلًا في الفحص التقني.
      </p>
    @endif

    <p class="small muted">
      فُحصت في <span class="num" dir="ltr">{{ $checkedAt->format('Y-m-d H:i') }}</span>. الشروط كلها إلزامية. هذا الفحص لم يغيّر الوضع ولا مصدر رموز الدخول ولا أي إعداد، ولا يفعّل الإنتاج، ونتيجته صالحة لهذه المرة فقط.
    </p>

    @include('staff.whatsapp.partials.readiness-checks', ['checks' => $result['checks']])

    <p class="note small">
      نجاح فحص الرمز وحده لا يثبت الجاهزية، وقبول Meta لرسالة لا يعني وصولها: الشرط (5) لا يستوفيه إلا رمز كتبه مدير بعد أن وصله. التفعيل لا يتم من هذه الصفحة.
    </p>

    <a class="btn btn-g justify-self-start" href="{{ route('staff.whatsapp') }}">العودة إلى الإعدادات</a>
  </section>
</x-staff-layout>
