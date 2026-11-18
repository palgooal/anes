@props(['variant' => 'beneficiary', 'title'])

@php
    $isStaff = $variant === 'staff';
    $brandColor = $isStaff ? 'var(--accent)' : 'var(--spirit)';
    $subtitle = $isStaff ? 'بوابة فريق العمل' : 'لمرضى الرعاية التلطيفية';
@endphp

<!doctype html>
<html lang="ar" dir="rtl" @if ($isStaff) style="--role:var(--accent)" @endif>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="wa-number" content="{{ $waNumber }}">
<title>{{ $title }} · {{ config('app.name') }}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600&family=Amiri:wght@400;700&display=swap">
@vite(['resources/css/auth.css', 'resources/js/auth.js'])
</head>
<body>
<div class="grid min-h-dvh lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)]">

  {{-- ============ FORM COLUMN ============ --}}
  <main class="flex flex-col px-4 pt-5 pb-8 sm:px-8">
    <div class="flex items-center justify-between gap-3">
      <a href="{{ route('home') }}" class="flex items-center gap-2.5 font-display text-[.95rem] leading-tight font-bold lg:invisible">
        <svg width="38" height="38" viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="20" fill="{{ $brandColor }}"/><path d="M11 32V19a9 9 0 0 1 18 0v13z" fill="#fff"/><path d="M15 32V20a5 5 0 0 1 10 0v12z" fill="{{ $brandColor }}"/><circle cx="20" cy="23" r="2.4" fill="var(--sun)"/></svg>
        <span>خدمة الدعم والمساندة<span class="block text-[.74rem] font-normal text-muted">{{ $subtitle }}</span></span>
      </a>
      <a href="{{ route('home') }}" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-[.85rem] text-muted transition-colors hover:bg-surface hover:text-ink">
        العودة إلى الموقع
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
      </a>
    </div>

    <div class="mx-auto my-auto w-full max-w-[420px] py-10">
      @if (request()->boolean('expired'))
        <div class="alert alert-warn mb-6" role="status">
          <svg class="mt-1 flex-none" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
          <span>انتهت جلستك لحمايتك. سجّل الدخول مرة أخرى للمتابعة.</span>
        </div>
      @endif

      @foreach (['throttle', 'send'] as $key)
        @error($key)
          <div class="alert alert-err mb-6" role="alert">{{ $message }}</div>
        @enderror
      @endforeach

      <div class="step">
        {{ $slot }}
      </div>

      @isset($footer)
        <div class="mt-8 grid gap-4">{{ $footer }}</div>
      @endisset
    </div>
  </main>

  {{-- ============ BRAND PANEL ============ --}}
  @if ($isStaff)
    <aside class="relative hidden overflow-hidden bg-[linear-gradient(150deg,var(--accent)_0%,color-mix(in_srgb,var(--accent)_60%,var(--ink))_100%)] p-12 text-white lg:flex lg:flex-col dark:bg-[linear-gradient(150deg,color-mix(in_srgb,var(--accent)_30%,var(--bg))_0%,var(--surface)_100%)]" aria-label="عن بوابة الفريق">
      <span class="pointer-events-none absolute -top-24 -left-24 size-80 rounded-full bg-white/8" aria-hidden="true"></span>
      <span class="pointer-events-none absolute top-1/3 -left-40 size-[420px] rounded-full border-2 border-white/12" aria-hidden="true"></span>
      <span class="pointer-events-none absolute -right-10 -bottom-14 size-48 rounded-full bg-white/8" aria-hidden="true"></span>

      <a href="{{ route('home') }}" class="relative flex items-center gap-3 font-display text-[1.1rem] leading-tight font-bold">
        <svg width="48" height="48" viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="20" fill="#fff"/><path d="M11 32V19a9 9 0 0 1 18 0v13z" fill="var(--accent)"/><path d="M15 32V20a5 5 0 0 1 10 0v12z" fill="#fff"/><circle cx="20" cy="23" r="2.4" fill="var(--sun)"/></svg>
        <span>خدمة الدعم والمساندة<span class="block text-[.82rem] font-normal opacity-85">بوابة فريق العمل</span></span>
      </a>

      <div class="relative my-auto grid max-w-[30rem] gap-7 py-12">
        <h2 class="text-[2rem] leading-[1.4] font-bold">كل ما تحتاجه لمرافقة المستفيدين، في لوحة واحدة</h2>
        <ul class="grid gap-3">
          <li class="flex items-center gap-3 rounded-2xl bg-white/10 p-3.5"><span class="grid size-10 flex-none place-items-center rounded-xl bg-spirit"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M5 21V11a7 7 0 0 1 14 0v10zM9 21v-6a3 3 0 0 1 6 0v6"/></svg></span><span><b class="block font-display">المرشدون</b><span class="text-[.86rem] opacity-85">الطلبات والمواعيد وتوثيق الجلسات والمقاييس</span></span></li>
          <li class="flex items-center gap-3 rounded-2xl bg-white/10 p-3.5"><span class="grid size-10 flex-none place-items-center rounded-xl bg-grape"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M12 6c-2-1.5-5-2-8-2v14c3 0 6 .5 8 2 2-1.5 5-2 8-2V4c-3 0-6 .5-8 2zM12 6v14"/></svg></span><span><b class="block font-display">المستشارون</b><span class="text-[.86rem] opacity-85">الأسئلة الفقهية والإحالات الطبية والوصايا</span></span></li>
          <li class="flex items-center gap-3 rounded-2xl bg-white/10 p-3.5"><span class="grid size-10 flex-none place-items-center rounded-xl bg-coral"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg></span><span><b class="block font-display">الإدارة</b><span class="text-[.86rem] opacity-85">توزيع الطلبات واعتماد الحسابات والإحصاءات</span></span></li>
        </ul>
      </div>

      <p class="relative flex max-w-[30rem] items-center gap-2 text-[.82rem] opacity-85">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 8.5-7 10-4-1.5-7-5.5-7-10V6z"/></svg>
        الدخول محمي بالتحقق الثنائي، وكل اطلاع على ملفات المستفيدين مسجَّل.
      </p>
    </aside>
  @else
    <aside class="relative hidden overflow-hidden bg-[linear-gradient(150deg,var(--spirit)_0%,color-mix(in_srgb,var(--spirit)_55%,var(--accent))_100%)] p-12 text-white lg:flex lg:flex-col dark:bg-[linear-gradient(150deg,color-mix(in_srgb,var(--spirit)_30%,var(--bg))_0%,var(--surface)_100%)]" aria-label="عن الخدمة">
      <span class="pointer-events-none absolute -top-24 -left-24 size-80 rounded-full bg-white/10" aria-hidden="true"></span>
      <span class="pointer-events-none absolute -right-12 -bottom-16 size-56 rounded-full bg-sun/90" aria-hidden="true"></span>
      <span class="pointer-events-none absolute top-1/3 -left-40 size-[420px] rounded-full border-2 border-white/15" aria-hidden="true"></span>

      <a href="{{ route('home') }}" class="relative flex items-center gap-3 font-display text-[1.1rem] leading-tight font-bold">
        <svg width="48" height="48" viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="20" fill="#fff"/><path d="M11 32V19a9 9 0 0 1 18 0v13z" fill="var(--spirit)"/><path d="M15 32V20a5 5 0 0 1 10 0v12z" fill="#fff"/><circle cx="20" cy="23" r="2.4" fill="var(--sun)"/></svg>
        <span>خدمة الدعم والمساندة<span class="block text-[.82rem] font-normal opacity-85">لمرضى الرعاية التلطيفية</span></span>
      </a>

      <div class="relative my-auto grid max-w-[30rem] gap-6 py-12">
        <p class="font-naskh text-[2.1rem] leading-[1.7]">﴿أَلَا بِذِكْرِ اللَّهِ تَطْمَئِنُّ الْقُلُوبُ﴾</p>
        <p class="text-[1.05rem] leading-[1.9] opacity-90">صفحتك الخاصة: مواعيدك مع مرشدك، ومقاييسك، وخطة متابعتك، وزاد المريض، وأسئلتك الفقهية، في مكان واحد.</p>
        <ul class="grid gap-3 text-[.95rem]">
          <li class="flex items-center gap-3"><span class="grid size-8 flex-none place-items-center rounded-full bg-white/18">✓</span>دخول برقم جوالك، دون كلمة مرور</li>
          <li class="flex items-center gap-3"><span class="grid size-8 flex-none place-items-center rounded-full bg-white/18">✓</span>يدخل المرافق برقمه ليتابع المريض</li>
          <li class="flex items-center gap-3"><span class="grid size-8 flex-none place-items-center rounded-full bg-white/18">✓</span>جلسات من المنزل، في الوقت المناسب لك</li>
        </ul>
      </div>

      <p class="relative max-w-[30rem] text-[.82rem] leading-[1.8] opacity-85">تابعة لجمعية الدعم الديني والروحي لمرضى الطب التلطيفي، ترخيص <span class="num">1000630400</span></p>
    </aside>
  @endif
</div>
</body>
</html>
