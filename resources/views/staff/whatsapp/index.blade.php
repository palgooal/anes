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
    $checkLabels = [
        'token' => 'Access Token',
        'phone_number' => 'رقم الهاتف',
        'template' => 'القالب',
    ];
    $checkPills = [
        'passed' => ['ok', 'ناجح'],
        'failed' => ['new', 'فشل'],
        'skipped' => ['', 'لم يُفحص'],
    ];
    $connectionTest = session('connection_test');
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
        لإدارة الإعدادات من هذه اللوحة انقل الإدارة إليها. النقل يُنشئ سجلًا قيد التحضير، وتبقى رموز الدخول تُرسل بإعدادات <span class="num">.env</span> حتى تفعيل صريح.
      </p>
      <div class="info">
        تبدأ الإعدادات فارغة، ويُحفظ الوضع
        <b data-initial-mode="{{ $initialMode->value }}">{{ $modeLabels[$initialMode->value] }}</b>، ولا أثر له قبل التفعيل.
        لا يتغير الإرسال بالنقل: يستمر من <span class="num">.env</span> طوال التحضير.
      </div>
      <form method="POST" action="{{ route('staff.whatsapp.take-over') }}" class="grid gap-3" data-confirm="نقل إدارة إعدادات واتساب إلى اللوحة؟">
        @csrf
        <label class="flex cursor-pointer items-center gap-2.5 font-normal">
          <input type="checkbox" name="confirm" value="1" class="size-[18px] w-auto accent-role" required>
          أفهم أن الإعدادات ستُدار من اللوحة قيد التحضير، وأن الإرسال يبقى من <span class="num">.env</span> حتى التفعيل.
        </label>
        <span class="err">@error('confirm'){{ $message }}@enderror</span>
        <button class="btn btn-p justify-self-start" type="submit">نقل الإدارة إلى اللوحة</button>
      </form>
    </section>
  @else
    {{-- ============ STATUS ============ --}}
    <section class="card grid gap-2" aria-labelledby="status-title">
      <div class="sec-h">
        <h2 id="status-title">الحالة</h2>
        @if ($setting->isDatabaseSource())
          <span class="pill ok" data-source="database">مفعّل</span>
        @else
          <span class="pill wait" data-source="env">قيد التحضير</span>
        @endif
      </div>
      {{-- REQ-WA-65: the effective OTP source and the row state. --}}
      @if ($setting->isDatabaseSource())
        <p>مصدر رموز الدخول: <b>إعدادات هذه اللوحة</b> منذ <span class="num" dir="ltr">{{ $setting->database_source_activated_at->format('Y-m-d') }}</span>.</p>
        <p>الوضع الحالي: <b>{{ $modeLabels[$setting->mode->value] }}</b></p>
      @else
        <p>مصدر رموز الدخول: <b>ملف <bdi class="num" dir="ltr">.env</bdi></b></p>
        <p>السجل قيد التحضير، فلا تؤثر هذه الإعدادات في الإرسال حتى تفعيل صريح.</p>
        <p class="small muted">الوضع المحفوظ: {{ $modeLabels[$setting->mode->value] }}، ولا أثر له قبل التفعيل.</p>
      @endif
      @php $modeBag = $errors->getBag('mode'); @endphp
      @if ($modeBag->any())
        <p class="note" role="alert">{{ $modeBag->first() }}</p>
      @endif

      {{-- REQ-WA-13, REQ-WA-65: switching the mode only once the database is the OTP source. --}}
      @if ($setting->isDatabaseSource())
        <form method="POST" action="{{ route('staff.whatsapp.mode.review') }}" class="grid gap-3 pt-3" style="border-top: 1px dashed var(--line)" data-mode-switch>
          @csrf
          <label for="mode">تبديل الوضع
            <select id="mode" name="mode">
              @foreach (\App\Services\WhatsApp\WhatsAppSettingsManager::SWITCHABLE_MODES as $option)
                @if ($option !== $setting->mode && ($option !== WhatsAppMode::Log || app()->environment('local', 'testing')))
                  <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endif
              @endforeach
            </select>
          </label>
          <span class="small muted">ستُعرض عليك قبل التبديل آثاره على رموز الدخول، ويُطلب تأكيد كلمة المرور. التفعيل إلى الإنتاج لا يتم من هنا.</span>
          <button class="btn btn-g justify-self-start" type="submit">مراجعة التبديل</button>
        </form>
      @else
        <p class="small muted" data-mode-switch-unavailable>تبديل الوضع غير متاح: السجل قيد التحضير، ومصدر رموز الدخول الفعّال ما زال ملف <bdi class="num" dir="ltr">.env</bdi></p>
      @endif

      {{-- REQ-WA-66: activation, reviewed with its impact before it runs. Production needs the readiness checks. --}}
      @php $activationBag = $errors->getBag('activation'); @endphp
      @if ($activationBag->any())
        <p class="note" role="alert" data-activation-error>{{ $activationBag->first() }}</p>
      @endif
      @if ($activationTargets !== [])
        <form method="POST" action="{{ route('staff.whatsapp.activation.review') }}" class="grid gap-3 pt-3" style="border-top: 1px dashed var(--line)" data-activation>
          @csrf
          <label for="activation-mode">التفعيل
            <select id="activation-mode" name="mode">
              @foreach ($activationTargets as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
              @endforeach
            </select>
          </label>
          <span class="small muted">يجعل إعدادات هذه اللوحة مصدر رموز الدخول. التفعيل إلى الإنتاج يُجري شروط الجاهزية الستة عند تنفيذه، ويطلب نصًّا مرجعيًّا للإقرار. ستُعرض عليك آثاره قبل تنفيذه، ويُطلب تأكيد كلمة المرور.</span>
          <button class="btn btn-g justify-self-start" type="submit">مراجعة التفعيل</button>
        </form>
      @elseif ($setting->isDatabaseSource())
        <p class="small muted" data-activation-done>الإنتاج مفعّل مصدرًا لرموز الدخول.</p>
      @endif
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

    {{-- ============ SEND TEST: CODE CHECK (REQ-WA-36, AC-WA-23, AC-WA-43) ============ --}}
    @php $verifyBag = $errors->getBag('verify_send_test'); @endphp
    @if ($pendingTest || $verifyBag->any())
      <form method="POST" action="{{ route('staff.whatsapp.send-test.verify') }}" class="card grid gap-3" novalidate autocomplete="off" aria-labelledby="verify-title" data-send-test-verify>
        @csrf
        <h2 id="verify-title" class="text-[1.15rem]">تأكيد استلام رمز الاختبار</h2>
        @if ($pendingTest)
          <p class="small muted">
            أُرسل رمز اختبار عبر {{ $envLabels[$pendingTest['context']['environment']] ?? '' }} إلى <span class="num" dir="ltr">••••{{ $pendingTest['context']['last4'] }}</span>.
            قبول Meta للطلب لا يعني وصول الرسالة. اكتب الرمز الذي وصلك خلال
            <span class="num">{{ max(1, (int) ceil(($pendingTest['expires_at'] - now()->getTimestamp()) / 60)) }}</span> دقيقة على الأكثر.
            المحاولات المتبقية: <span class="num">{{ $pendingTest['attempts_left'] }}</span>.
          </p>
        @endif
        <label for="send-test-code">الرمز الذي وصلك
          <input id="send-test-code" name="code" value="" inputmode="numeric" dir="ltr" autocomplete="one-time-code" @if ($verifyBag->has('code')) aria-invalid="true" @endif>
          <span class="err">{{ $verifyBag->first('code') }}</span>
        </label>
        <button class="btn btn-p justify-self-start" type="submit">تأكيد الاستلام</button>
      </form>
    @endif

    {{-- ============ ENVIRONMENTS ============ --}}
    <div class="two items-start">
      @foreach (WhatsAppEnvironment::cases() as $environment)
        @php
            $env = $environment->value;
            $bag = $errors->getBag($env);
            $secretsBag = $errors->getBag('secrets_'.$env);
            $frozen = $environment === WhatsAppEnvironment::Production ? $frozenProduction : [];
            // A frozen field always shows the stored value, never what was sent (REQ-WA-15).
            $value = fn (string $field) => $bag->any() && ! in_array($field, $frozen, true) ? old($field) : $setting->field($environment, $field);
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
            @if ($frozen !== [])
              <p class="note" id="{{ $env }}-frozen" data-frozen-identifiers>
                معرّفات الإنتاج (Phone Number ID وWABA ID واسم القالب ولغته) لا تتغير ما دام وضع الإنتاج نافذًا. تدوير Access Token متاح أدناه.
              </p>
            @endif
            @if ($bag->has('frozen'))
              <p class="note" role="alert">{{ $bag->first('frozen') }}</p>
            @endif
            @foreach ([
                'phone_number_id' => ['Phone Number ID', 'من WhatsApp Manager › الأرقام'],
                'waba_id' => ['WhatsApp Business Account ID', null],
                'display_number' => ['رقم العرض', 'بالصيغة الدولية، مثل 966500000000'],
                'template_name' => ['اسم قالب رمز الدخول', 'قالب Authentication المعتمد في Meta'],
                'template_language' => ['لغة القالب', 'مثل ar'],
            ] as $field => [$label, $hint])
              <label for="{{ $env }}-{{ $field }}">{{ $label }}
                <input id="{{ $env }}-{{ $field }}" name="{{ $field }}" dir="ltr" value="{{ $value($field) }}" autocomplete="off" @if ($bag->has($field)) aria-invalid="true" @endif @if (in_array($field, $frozen, true)) readonly class="opacity-60" aria-describedby="{{ $env }}-frozen" @endif>
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

          {{-- ============ TESTS (REQ-WA-35, REQ-WA-36) ============ --}}
          @php $sendBag = $errors->getBag('send_test_'.$env); @endphp
          <div class="grid gap-3 pt-4" style="border-top: 1px dashed var(--line)" data-tests="{{ $env }}">
            <h3 class="text-[1rem]">اختبار {{ $envLabels[$env] }}</h3>
            <p class="small muted">يعمل الاختبار بإعدادات هذه البيئة المحفوظة، ولا يغيّر وضع الإرسال الحالي ولا يفعّل الإنتاج.</p>

            <form method="POST" action="{{ route('staff.whatsapp.connection-test', $env) }}" class="grid gap-2">
              @csrf
              <button class="btn btn-g justify-self-start" type="submit">اختبار الاتصال</button>
              <span class="small muted">يفحص الرمز والرقم والقالب لدى Meta، ولا يرسل أي رسالة.</span>
            </form>

            @if ($environment === WhatsAppEnvironment::Production)
              {{-- REQ-WA-24: run on request only, never on opening the page. --}}
              <form method="POST" action="{{ route('staff.whatsapp.readiness') }}" class="grid gap-2" data-readiness-form>
                @csrf
                <button class="btn btn-g justify-self-start" type="submit">فحص جاهزية الإنتاج</button>
                <span class="small muted">يفحص شروط الجاهزية الستة دون إرسال أي رسالة، ولا يغيّر الوضع ولا مصدر رموز الدخول، ولا يفعّل الإنتاج.</span>
              </form>
            @endif

            @if (($connectionTest['environment'] ?? null) === $env)
              <ul class="grid list-none gap-2 p-0" data-connection-results>
                @foreach ($connectionTest['checks'] as $name => $check)
                  <li class="info">
                    <span class="pill {{ $checkPills[$check['status']][0] }}">{{ $checkPills[$check['status']][1] }}</span>
                    <b>{{ $checkLabels[$name] ?? $name }}:</b> {{ $check['message'] }}
                    @if ($check['details'])
                      <span class="block num" dir="ltr">{{ collect($check['details'])->map(fn ($detail, $key) => $key.': '.$detail)->join(' · ') }}</span>
                    @endif
                  </li>
                @endforeach
              </ul>
            @endif

            <form method="POST" action="{{ route('staff.whatsapp.send-test.review', $env) }}" class="grid gap-3" novalidate autocomplete="off">
              @csrf
              <label for="{{ $env }}-send-test-phone">رقم يصله اختبار الإرسال
                <input id="{{ $env }}-send-test-phone" name="phone" value="" inputmode="tel" dir="ltr" @if ($sendBag->has('phone')) aria-invalid="true" @endif>
                <span class="small muted font-normal">يُرسل رمز اختبار حقيقي برمز لا يظهر في أي مكان. ستراجع البيئة والرقم وتؤكد كلمة المرور قبل الإرسال. الحد {{ \App\Services\WhatsApp\WhatsAppSendTester::MAX_SENDS_PER_HOUR }} اختبارات في الساعة.</span>
                <span class="err">{{ $sendBag->first('phone') }}</span>
              </label>
              <button class="btn btn-g justify-self-start" type="submit">مراجعة الإرسال</button>
            </form>
          </div>
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
