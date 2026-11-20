@php
    use App\Services\WhatsApp\WhatsAppReadiness;

    $titles = [
        1 => 'اكتمال إعدادات بيئة الإنتاج',
        2 => 'صلاحية Access Token وحالة انتهائه',
        3 => 'رقم الإنتاج لدى Meta ومطابقة رقم العرض',
        4 => 'القالب: معتمد، من فئة AUTHENTICATION، وبنفس اللغة',
        5 => 'اختبار إرسال مؤكَّد من اللوحة خلال آخر 24 ساعة',
        6 => 'الإقرار بتحقق شرط ملكية الرقم',
    ];
    $labels = [
        WhatsAppReadiness::PASSED => ['مستوفى', 'ok'],
        WhatsAppReadiness::FAILED => ['غير مستوفى', 'new'],
        WhatsAppReadiness::PENDING => ['عند التفعيل', 'wait'],
    ];
    $technicalPassed = collect($result['checks'])->except(6)->every(fn (array $check) => $check['status'] === WhatsAppReadiness::PASSED);
    $detailLabels = [
        'missing' => 'الحقول الناقصة',
        'expiry' => 'انتهاء الرمز',
        'meta_last4' => 'آخر 4 أرقام لدى Meta',
        'saved_last4' => 'آخر 4 أرقام المحفوظة',
        'language' => 'اللغة',
        'status' => 'الحالة',
        'category' => 'الفئة',
        'code_expiration_minutes' => 'مدة صلاحية الرمز في التذييل',
        'confirmed_at' => 'وقت التأكيد',
        'recipient_last4' => 'آخر 4 أرقام للمستلم',
    ];
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

    <ol class="grid list-none gap-3 p-0">
      @foreach ($result['checks'] as $number => $check)
        @php
            [$label, $tone] = $labels[$check['status']];
        @endphp
        <li class="grid gap-1 pt-3" style="border-top: 1px dashed var(--line)" data-readiness-check="{{ $number }}" data-status="{{ $check['status'] }}">
          <div class="flex flex-wrap items-center gap-2">
            <b>(<span class="num">{{ $number }}</span>) {{ $titles[$number] }}@if ($number === 6) <bdi class="num" dir="ltr">REQ-AUTH-37</bdi>@endif</b>
            <span class="pill {{ $tone }}">{{ $label }}</span>
          </div>
          <p class="small">{{ $check['message'] }}</p>
          @if ($check['details'] !== [])
            <ul class="small muted grid list-none gap-0.5 p-0">
              @foreach ($check['details'] as $key => $value)
                <li>{{ $detailLabels[$key] ?? $key }}: <span class="num" dir="ltr">{{ $value }}</span></li>
              @endforeach
            </ul>
          @endif
        </li>
      @endforeach
    </ol>

    <p class="note small">
      نجاح فحص الرمز وحده لا يثبت الجاهزية، وقبول Meta لرسالة لا يعني وصولها: الشرط (5) لا يستوفيه إلا رمز كتبه مدير بعد أن وصله. التفعيل لا يتم من هذه الصفحة.
    </p>

    <a class="btn btn-g justify-self-start" href="{{ route('staff.whatsapp') }}">العودة إلى الإعدادات</a>
  </section>
</x-staff-layout>
