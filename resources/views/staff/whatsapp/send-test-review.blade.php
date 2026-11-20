@php
    use App\WhatsAppEnvironment;

    $envLabel = $environment === WhatsAppEnvironment::Production ? 'بيئة الإنتاج' : 'بيئة الاختبار';
@endphp

{{-- REQ-WA-36, AC-WA-44: the environment and the full number, shown to the administrator only, before sending. --}}
<x-staff-layout title="مراجعة اختبار الإرسال" subtitle="راجع البيئة والرقم قبل إرسال رمز الاختبار" active="whatsapp">
  <form method="POST" action="{{ route('staff.whatsapp.send-test', $environment->value) }}" class="card grid gap-4" novalidate aria-labelledby="review-title" data-send-test-review>
    @csrf
    <input type="hidden" name="phone" value="{{ $phone }}">
    <h2 id="review-title" class="text-[1.15rem]">هل تُرسل رمز الاختبار؟</h2>

    <div class="grid gap-1">
      <p>البيئة: <b>{{ $envLabel }}</b></p>
      <p>الرقم المستهدف: <b class="num" dir="ltr">{{ $formatted }}</b></p>
    </div>

    <div class="info">
      سيُرسل رمز اختبار حقيقي عبر {{ $envLabel }} بإعداداتها المحفوظة، ولا يتغير وضع الإرسال الحالي.
      لا يظهر الرمز في أي مكان: اكتب الرمز الذي يصلك خلال 5 دقائق لتأكيد الاستلام.
      قبول Meta للطلب لا يعني وصول الرسالة.
      اختبارات الإرسال المتبقية في هذه الساعة: <span class="num">{{ $sendsLeft }}</span>.
    </div>

    @if ($environment === WhatsAppEnvironment::Production)
      <p class="note">ستُرسل الرسالة من رقم الإنتاج الرسمي.</p>
    @endif

    <div class="flex flex-wrap gap-3">
      <button class="btn btn-p" type="submit">إرسال رمز الاختبار</button>
      <a class="btn btn-g" href="{{ route('staff.whatsapp') }}">إلغاء</a>
    </div>
  </form>
</x-staff-layout>
