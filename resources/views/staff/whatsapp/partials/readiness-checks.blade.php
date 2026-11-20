{{-- The six readiness conditions of one run (REQ-WA-24): status, reason and non-secret details. Expects $checks. --}}
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

    <ol class="grid list-none gap-3 p-0">
      @foreach ($checks as $number => $check)
        @php
            [$label, $tone] = $labels[$check['status']];
        @endphp
        <li class="grid gap-1 pt-3" style="border-top: 1px dashed var(--line)" data-readiness-check="{{ $number }}" data-status="{{ $check['status'] }}">
          <div class="flex flex-wrap items-center gap-2">
            <b>(<span class="num">{{ $number }}</span>) {{ $titles[$number] }}@if ($number === 6) <bdi class="num whitespace-nowrap" dir="ltr">REQ-AUTH-37</bdi>@endif</b>
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
