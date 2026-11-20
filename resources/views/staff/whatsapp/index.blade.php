@php
    use App\WhatsAppEnvironment;
    use App\WhatsAppMode;

    $modeLabels = [
        WhatsAppMode::Disabled->value => 'متوقف: لا يُرسل شيء',
        WhatsAppMode::Log->value => 'تسجيل فقط (للتطوير): لا يُرسل شيء',
        WhatsAppMode::Test->value => 'الإرسال عبر بيئة الاختبار',
        WhatsAppMode::Production->value => 'الإرسال عبر بيئة الإنتاج',
    ];
    $envLabels = [
        WhatsAppEnvironment::Test->value => 'بيئة الاختبار',
        WhatsAppEnvironment::Production->value => 'بيئة الإنتاج',
    ];
    $fieldLabels = [
        'phone_number_id' => 'Phone Number ID',
        'access_token' => 'Access Token',
        'template_name' => 'اسم القالب',
    ];
    $date = fn (?string $iso): string => $iso ? \Illuminate\Support\Carbon::parse($iso)->locale('ar')->translatedFormat('j F Y، H:i') : '—';
@endphp

<x-staff-layout title="إعدادات واتساب" subtitle="ربط WhatsApp Cloud API لإرسال رموز الدخول" active="whatsapp">

  @error('whatsapp')
    <p class="note" role="alert">{{ $message }}</p>
  @enderror

  {{-- REQ-WA-63: sending stopped because required settings are missing. Names only, never values. --}}
  @if ($incomplete)
    <div class="note" role="alert">
      <b>الإرسال متوقف:</b> إعدادات {{ $envLabels[$incomplete['environment']->value] }} ناقصة، فلن تُرسل رموز الدخول حتى تكتمل.
      الحقول الناقصة: {{ collect($incomplete['fields'])->map(fn ($field) => $fieldLabels[$field] ?? $field)->join('، ') }}.
    </div>
  @endif

  @unless ($setting)
    {{-- ============ STILL ON .env ============ --}}
    <section class="card grid gap-3" aria-labelledby="takeover-title">
      <h2 id="takeover-title" class="text-[1.15rem]">الإعدادات تُقرأ حالياً من ملف <span class="num">.env</span></h2>
      <p class="small muted">
        مشغّل الإرسال الحالي في <span class="num">.env</span> هو <span class="num">{{ $envDriver }}</span>.
        لإدارة الإعدادات من هذه اللوحة انقل الإدارة إليها. بعد النقل لا يُقرأ أي إعداد واتساب من <span class="num">.env</span>.
      </p>
      @if ($takeOverAllowed)
      <div class="info">
        تبدأ الإعدادات فارغة، ويكون الوضع
        <b>{{ $modeLabels[WhatsAppMode::Log->value] }}</b>.
        أي إرسال يعمل الآن من <span class="num">.env</span> سيتوقف حتى تُستكمل الإعدادات ويُفعَّل الإرسال.
      </div>
      <form method="POST" action="{{ route('staff.whatsapp.take-over') }}" class="grid gap-3" data-confirm="نقل إدارة إعدادات واتساب إلى اللوحة؟">
        @csrf
        <label class="flex cursor-pointer items-center gap-2.5 font-normal">
          <input type="checkbox" name="confirm" value="1" class="size-[18px] w-auto accent-role" required>
          أفهم أن الإعدادات ستُدار من اللوحة وأن الإرسال من <span class="num">.env</span> سيتوقف.
        </label>
        <span class="err">@error('confirm'){{ $message }}@enderror</span>
        <button class="btn btn-p justify-self-start" type="submit">نقل الإدارة إلى اللوحة</button>
      </form>
      @else
      {{-- REQ-WA-64: refused outside local and testing until PH-WA-6. --}}
      <div class="note" data-take-over-unavailable>
        <b>نقل الإدارة غير متاح في هذه البيئة حاليًا.</b>
        بعد النقل يبدأ الإرسال متوقفًا، ولا توجد بعد في اللوحة وسيلة لتفعيله، فتتوقف كل رموز الدخول، بما فيها رموز الفريق.
        سيُتاح النقل مع مرحلة تفعيل الإنتاج، وحتى ذلك الحين يستمر الإرسال من ملف <span class="num">.env</span>.
      </div>
      @endif
    </section>
  @else
    {{-- ============ STATUS ============ --}}
    <section class="card grid gap-2" aria-labelledby="status-title">
      <div class="sec-h">
        <h2 id="status-title">الحالة</h2>
        <span class="pill role">تُدار من اللوحة</span>
      </div>
      <p>الوضع الحالي: <b>{{ $modeLabels[$setting->mode->value] }}</b></p>
      <p class="small muted">تبديل الوضع وتفعيل الإنتاج غير متاحين بعد في هذه اللوحة.</p>
    </section>

    {{-- ============ GENERAL ============ --}}
    <form method="POST" action="{{ route('staff.whatsapp.general') }}" class="card grid gap-3" novalidate aria-labelledby="general-title">
      @csrf @method('PUT')
      <h2 id="general-title" class="text-[1.15rem]">إعدادات عامة</h2>
      <label for="api_version">نسخة Graph API
        <input id="api_version" name="api_version" dir="ltr" value="{{ $errors->has('api_version') ? old('api_version') : $setting->api_version }}" placeholder="{{ \App\Services\WhatsApp\WhatsAppSettings::DEFAULT_API_VERSION }}" autocomplete="off" @error('api_version') aria-invalid="true" @enderror>
        <span class="err">@error('api_version'){{ $message }}@enderror</span>
      </label>
      <p class="small muted">إن تُرك الحقل فارغاً تُستخدم النسخة {{ \App\Services\WhatsApp\WhatsAppSettings::DEFAULT_API_VERSION }}.</p>
      <button class="btn btn-p justify-self-start" type="submit">حفظ</button>
    </form>

    {{-- ============ ENVIRONMENTS ============ --}}
    <div class="two items-start">
      @foreach (WhatsAppEnvironment::cases() as $environment)
        @php
            $env = $environment->value;
            $bag = $errors->getBag($env);
            $secretsBag = $errors->getBag('secrets_'.$env);
            $value = fn (string $field) => $bag->any() ? old($field) : $setting->field($environment, $field);
            $token = $setting->secretInfo($environment->column('access_token'));
            $appSecret = $setting->secretInfo($environment->column('app_secret'));
            $expiresAt = $setting->field($environment, 'token_expires_at');
            $expirySource = $setting->field($environment, 'token_expiry_source');
        @endphp

        <section class="card grid content-start gap-4" aria-labelledby="{{ $env }}-title">
          <div class="sec-h">
            <h2 id="{{ $env }}-title">{{ $envLabels[$env] }}</h2>
            @if ($setting->mode->environment() === $environment)
              <span class="pill ok">الوضع الحالي</span>
            @endif
          </div>

          <form method="POST" action="{{ route('staff.whatsapp.environment', $env) }}" class="grid gap-3" novalidate>
            @csrf @method('PUT')
            @foreach ([
                'phone_number_id' => ['Phone Number ID', 'من WhatsApp Manager › الأرقام'],
                'waba_id' => ['WhatsApp Business Account ID', null],
                'display_number' => ['رقم العرض', 'بالصيغة الدولية، مثل 966500000000'],
                'template_name' => ['اسم قالب رمز الدخول', 'قالب Authentication المعتمد في Meta'],
                'template_language' => ['لغة القالب', 'مثل ar'],
            ] as $field => [$label, $hint])
              <label for="{{ $env }}-{{ $field }}">{{ $label }}
                <input id="{{ $env }}-{{ $field }}" name="{{ $field }}" dir="ltr" value="{{ $value($field) }}" autocomplete="off" @if ($bag->has($field)) aria-invalid="true" @endif>
                @if ($hint)<span class="small muted font-normal">{{ $hint }}</span>@endif
                <span class="err">{{ $bag->first($field) }}</span>
              </label>
            @endforeach
            <button class="btn btn-p justify-self-start" type="submit">حفظ إعدادات {{ $envLabels[$env] }}</button>
          </form>

          <form method="POST" action="{{ route('staff.whatsapp.secrets', $env) }}" class="grid gap-3 pt-4" style="border-top: 1px dashed var(--line)" novalidate autocomplete="off">
            @csrf @method('PUT')
            <h3 class="text-[1rem]">الأسرار</h3>
            <p class="small muted">لا تُعرض القيم المحفوظة. اترك الحقل فارغاً لإبقاء القيمة الحالية. يُطلب منك تأكيد كلمة المرور عند الحفظ.</p>

            <label for="{{ $env }}-access_token">Access Token
              <input id="{{ $env }}-access_token" name="access_token" type="password" dir="ltr" autocomplete="new-password" spellcheck="false" @if ($secretsBag->has('access_token')) aria-invalid="true" @endif>
              <span class="small muted font-normal">
                @if ($token['saved'])
                  محفوظ ••••<span class="num">{{ $token['last4'] }}</span> · آخر تحديث {{ $date($token['updated_at']) }}{{ $token['updated_by'] ? ' بواسطة '.($editors[$token['updated_by']] ?? 'مستخدم محذوف') : '' }}
                  <br>
                  الانتهاء:
                  @if ($setting->field($environment, 'token_never_expires'))
                    بلا تاريخ انتهاء حسب Meta (يبقى خاضعاً للفحص الدوري)
                  @elseif ($expiresAt)
                    {{ $expiresAt->locale('ar')->translatedFormat('j F Y') }}
                  @else
                    غير معروف
                  @endif
                  @if ($expirySource)<span class="pill">{{ $expirySource }}</span>@endif
                @else
                  غير محفوظ
                @endif
              </span>
              <span class="err">{{ $secretsBag->first('access_token') }}</span>
            </label>

            <label for="{{ $env }}-app_secret">App Secret
              <input id="{{ $env }}-app_secret" name="app_secret" type="password" dir="ltr" autocomplete="new-password" spellcheck="false" @if ($secretsBag->has('app_secret')) aria-invalid="true" @endif>
              <span class="small muted font-normal">
                @if ($appSecret['saved'])
                  محفوظ ••••<span class="num">{{ $appSecret['last4'] }}</span> · آخر تحديث {{ $date($appSecret['updated_at']) }}{{ $appSecret['updated_by'] ? ' بواسطة '.($editors[$appSecret['updated_by']] ?? 'مستخدم محذوف') : '' }}
                @else
                  غير محفوظ
                @endif
              </span>
              <span class="err">{{ $secretsBag->first('app_secret') }}</span>
            </label>

            <button class="btn btn-g justify-self-start" type="submit">حفظ الأسرار</button>
          </form>
        </section>
      @endforeach
    </div>

    {{-- ============ WEBHOOK VERIFY TOKEN ============ --}}
    @php($verify = $setting->secretInfo('webhook_verify_token'))
    <form method="POST" action="{{ route('staff.whatsapp.webhook-token') }}" class="card grid gap-3" novalidate autocomplete="off" aria-labelledby="webhook-title">
      @csrf @method('PUT')
      <h2 id="webhook-title" class="text-[1.15rem]">رمز التحقق من Webhook</h2>
      <p class="small muted">يُستخدم عند ربط حالات تسليم الرسائل من Meta. اترك الحقل فارغاً لإبقاء القيمة الحالية.</p>
      <label for="webhook_verify_token">Verify Token
        <input id="webhook_verify_token" name="webhook_verify_token" type="password" dir="ltr" autocomplete="new-password" spellcheck="false" @error('webhook_verify_token') aria-invalid="true" @enderror>
        <span class="small muted font-normal">
          @if ($verify['saved'])
            محفوظ ••••<span class="num">{{ $verify['last4'] }}</span> · آخر تحديث {{ $date($verify['updated_at']) }}{{ $verify['updated_by'] ? ' بواسطة '.($editors[$verify['updated_by']] ?? 'مستخدم محذوف') : '' }}
          @else
            غير محفوظ
          @endif
        </span>
        <span class="err">@error('webhook_verify_token'){{ $message }}@enderror</span>
      </label>
      <button class="btn btn-g justify-self-start" type="submit">حفظ رمز التحقق</button>
    </form>
  @endunless
</x-staff-layout>
