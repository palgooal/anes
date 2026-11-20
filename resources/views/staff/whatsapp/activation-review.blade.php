@php
    use App\Services\WhatsApp\WhatsAppActivation;
    use App\WhatsAppMode;

    $production = $to === WhatsAppMode::Production;

    $impact = match ($to) {
        WhatsAppMode::Production => 'تصبح إعدادات بيئة الإنتاج في هذه اللوحة مصدر كل رموز الدخول فورًا: رموز المستفيدين، ورموز التحقق الثنائي للفريق، ومنها رمز دخولك أنت. إن فشل الإرسال بهذه الإعدادات بعد التفعيل تتوقف رموز الدخول.',
        WhatsAppMode::Test => 'تصبح إعدادات بيئة الاختبار مصدر رموز الدخول فورًا، ولا تصل الرموز إلا إلى المستلمين المسموح لهم لرقم الاختبار لدى Meta. كل من ليس منهم، من المستفيدين والفريق، ومنهم أنت إن لم يكن رقمك مسجلًا، لن يصله رمز الدخول.',
        WhatsAppMode::Log => 'لا يُرسل أي رمز دخول: تُكتب الرموز في سجل التطبيق المحلي فقط، للتطوير. لن يصل رمز إلى المستفيدين ولا إلى الفريق.',
        default => '',
    };
@endphp

{{-- REQ-WA-66, REQ-WA-71, REQ-WA-24 (6): the activation and its impact, acknowledged before it runs. Nothing is checked at Meta on this page. --}}
<x-staff-layout title="مراجعة التفعيل" subtitle="راجع أثر التفعيل على رموز الدخول قبل تنفيذه" active="whatsapp">
  <form method="POST" action="{{ route('staff.whatsapp.activation') }}" class="card grid gap-4" novalidate autocomplete="off" aria-labelledby="activation-review-title" data-activation-review>
    @csrf @method('PUT')
    <input type="hidden" name="mode" value="{{ $to->value }}">
    <input type="hidden" name="expected_mode" value="{{ $setting->mode->value }}">
    <input type="hidden" name="expected_state" value="{{ $state }}">
    <h2 id="activation-review-title" class="text-[1.15rem]">هل تفعّل إعدادات هذه اللوحة مصدرًا لرموز الدخول؟</h2>

    <div class="grid gap-1">
      @if ($state === WhatsAppActivation::ACTIVE)
        <p>مصدر رموز الدخول الآن: <b>إعدادات هذه اللوحة</b>، بوضع «{{ $setting->mode->label() }}».</p>
      @else
        <p>مصدر رموز الدخول الآن: <b>ملف <bdi class="num" dir="ltr">.env</bdi></b></p>
        <p>السجل قيد التحضير.</p>
      @endif
      <p>بعد التفعيل: <b>إعدادات هذه اللوحة</b>، بوضع «{{ $to->label() }}».</p>
    </div>

    <div class="note" role="alert" data-activation-impact>
      <b>أثر التفعيل:</b> {{ $impact }}
      الجلسات المفتوحة تستمر. لكن إن انتهت جلستك فقد لا تستطيع الدخول إلى اللوحة، ولا يتوفر بعد أمر استعادة من الخادم.
    </div>

    @if ($production)
      <p class="small">
        تُجرى شروط الجاهزية الستة عند الضغط على «تفعيل» على القيم المحفوظة الآن، ولا تُستخدم نتيجة أي فحص سابق.
        إن تغيّر أي إعداد أثناء الفحوص، أو لم يعد اختبار الإرسال المؤكَّد صالحًا، يُرفض التفعيل ولا يتغير شيء.
      </p>

      <label for="reference">النص المرجعي للتحقق من ملكية الرقم
        <input id="reference" name="reference" maxlength="{{ WhatsAppActivation::REFERENCE_MAX }}" autocomplete="off" spellcheck="false" @if ($errors->getBag('activation')->has('reference')) aria-invalid="true" @endif>
        <span class="small muted font-normal">
          سطر واحد من {{ WhatsAppActivation::REFERENCE_MIN }} إلى {{ WhatsAppActivation::REFERENCE_MAX }} حرف يشير إلى مستند التحقق. يُحفظ في سجل تدقيق التفعيل، ولا يُعدّ إثباتًا فعليًا للملكية.
          لا تكتب فيه رمزًا أو مفتاحًا أو رقم هاتف كاملًا: يُرفض ما يشبهها، وهذا لا يكتشف كل سر محتمل.
        </span>
      </label>

      <label class="flex cursor-pointer items-center gap-2.5 font-normal">
        <input type="checkbox" name="acknowledge_ownership" value="1" class="size-[18px] w-auto accent-role">
        <span>أقرّ بتحقق شرط ملكية الرقم واستيفاء المتطلبات ذات الصلة (<bdi class="num whitespace-nowrap" dir="ltr">REQ-AUTH-37</bdi>).</span>
      </label>
    @endif

    <label class="flex cursor-pointer items-center gap-2.5 font-normal">
      <input type="checkbox" name="acknowledge_impact" value="1" class="size-[18px] w-auto accent-role">
      أقرّ بأثر هذا التفعيل على رموز الدخول، ومنها احتمال ألا أستطيع الدخول بنفسي.
    </label>

    <div class="flex flex-wrap gap-3">
      <button class="btn btn-p" type="submit">تفعيل</button>
      <a class="btn btn-g" href="{{ route('staff.whatsapp') }}">إلغاء</a>
    </div>
  </form>
</x-staff-layout>
