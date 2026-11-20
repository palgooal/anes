@php
    use App\WhatsAppMode;

    $impact = match ($to) {
        WhatsAppMode::Disabled => 'تتوقف كل رموز الدخول فورًا: رموز المستفيدين، ورموز التحقق الثنائي للفريق، ومنها رمز دخولك أنت.',
        WhatsAppMode::Test => 'تُرسل رموز الدخول برقم بيئة الاختبار، ولا تصل إلا إلى المستلمين المسموح لهم لهذا الرقم لدى Meta. كل من ليس منهم، من المستفيدين والفريق، ومنهم أنت إن لم يكن رقمك مسجلًا، لن يصله رمز الدخول.',
        WhatsAppMode::Log => 'لا يُرسل أي رمز دخول: تُكتب الرموز في سجل التطبيق المحلي فقط، للتطوير. لن يصل رمز إلى المستفيدين ولا إلى الفريق.',
        default => '',
    };
@endphp

{{-- REQ-WA-13, REQ-WA-16, REQ-WA-71: the current and requested mode and their impact, acknowledged before switching. --}}
<x-staff-layout title="مراجعة تبديل الوضع" subtitle="راجع أثر التبديل على رموز الدخول قبل تنفيذه" active="whatsapp">
  <form method="POST" action="{{ route('staff.whatsapp.mode') }}" class="card grid gap-4" novalidate aria-labelledby="mode-review-title" data-mode-review>
    @csrf @method('PUT')
    <input type="hidden" name="mode" value="{{ $to->value }}">
    <input type="hidden" name="expected_mode" value="{{ $current->value }}">
    <h2 id="mode-review-title" class="text-[1.15rem]">هل تبدّل وضع الإرسال؟</h2>

    <div class="grid gap-1">
      <p>الوضع الحالي: <b>{{ $current->label() }}</b></p>
      <p>الوضع المطلوب: <b>{{ $to->label() }}</b></p>
    </div>

    <div class="note" role="alert" data-mode-impact>
      <b>أثر التبديل:</b> {{ $impact }}
      الجلسات المفتوحة تستمر. لكن إن انتهت جلستك فقد لا تستطيع الدخول إلى اللوحة حتى يُعاد الإرسال من الخادم.
    </div>

    @if ($testRecipientsWarning)
      {{-- REQ-WA-16 --}}
      <div class="note" role="alert" data-test-recipients-warning>
        <b>تنبيه:</b> هذا تطبيق الإنتاج. رقم الاختبار لا يصل إلا إلى مستلمين محددين، فلن تصل رموز الدخول إلى المستفيدين.
      </div>
      <label class="flex cursor-pointer items-center gap-2.5 font-normal">
        <input type="checkbox" name="acknowledge_test_recipients" value="1" class="size-[18px] w-auto accent-role">
        أقرّ بأن رموز الدخول لن تصل إلا إلى المستلمين المسجلين لرقم الاختبار.
      </label>
      <span class="err">{{ $errors->getBag('mode')->first('acknowledge_test_recipients') }}</span>
    @endif

    <label class="flex cursor-pointer items-center gap-2.5 font-normal">
      <input type="checkbox" name="acknowledge_impact" value="1" class="size-[18px] w-auto accent-role">
      أقرّ بأثر هذا التبديل على رموز الدخول، ومنها احتمال ألا أستطيع الدخول بنفسي.
    </label>

    <div class="flex flex-wrap gap-3">
      <button class="btn btn-p" type="submit">تبديل الوضع</button>
      <a class="btn btn-g" href="{{ route('staff.whatsapp') }}">إلغاء</a>
    </div>
  </form>
</x-staff-layout>
