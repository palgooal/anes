@props(['title', 'subtitle' => null, 'active', 'pendingCount' => 0])

@php
    $user = auth()->user();

    /*
     * Administration tabs. "team" is a Blade page; the others are still
     * rendered by resources/js/dashboard.js and open there (?tab=key).
     */
    $tabs = [
        ['key' => 'ov', 'label' => 'نظرة عامة', 'url' => route('staff.dashboard', ['tab' => 'ov'])],
        ['key' => 'req', 'label' => 'الطلبات الواردة', 'url' => route('staff.dashboard', ['tab' => 'req'])],
        ['key' => 'team', 'label' => 'الفريق', 'url' => route('staff.team'), 'badge' => $pendingCount],
        ['key' => 'bene', 'label' => 'المستفيدون', 'url' => route('staff.dashboard', ['tab' => 'bene'])],
        ['key' => 'svc', 'label' => 'الخدمات والمحتوى', 'url' => route('staff.dashboard', ['tab' => 'svc'])],
        ['key' => 'msgs', 'label' => 'الرسائل', 'url' => route('staff.dashboard', ['tab' => 'msgs'])],
    ];

    // REQ-WA-43: only for whoever holds the WhatsApp settings permission.
    if ($user->can('manage-whatsapp')) {
        $tabs[] = ['key' => 'whatsapp', 'label' => 'إعدادات واتساب', 'url' => route('staff.whatsapp')];
    }
@endphp

<!doctype html>
<html lang="ar" dir="rtl" style="--role:{{ $user->role->accentColor() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>{{ $title }} · {{ config('app.name') }}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600&family=Amiri:wght@400;700&display=swap">
@vite(['resources/css/dashboard.css', 'resources/js/staff.js'])
</head>
<body>
<div class="app" dir="rtl" lang="ar">
<header class="top"><div class="wrap bar">
  <a class="brand no-underline" href="{{ route('staff.dashboard') }}">
    <svg width="38" height="38" viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="20" fill="var(--role)"/><path d="M11 32V19a9 9 0 0 1 18 0v13z" fill="#fff"/><path d="M15 32V20a5 5 0 0 1 10 0v12z" fill="var(--role)"/><circle cx="20" cy="23" r="2.4" fill="var(--sun)"/></svg>
    <span>خدمة الدعم والمساندة في الرعاية التلطيفية<small>لوحة الإدارة</small></span>
  </a>
  <div class="ub">
    <span class="who">{{ $user->name }} · {{ $user->role->label() }}</span>
    <form method="POST" action="{{ route('logout') }}">
      @csrf
      <button class="btn btn-g btn-s" type="submit">خروج</button>
    </form>
  </div>
</div></header>

<main class="wrap">
  <div class="hello">
    <div>
      <h1>{{ $title }}</h1>
      @if ($subtitle)
        <p class="muted small">{{ $subtitle }}</p>
      @endif
    </div>
    @isset($actions)
      <div>{{ $actions }}</div>
    @endisset
  </div>

  <nav class="tabs" aria-label="أقسام لوحة الإدارة">
    @foreach ($tabs as $tab)
      <a href="{{ $tab['url'] }}" @class(['on' => $tab['key'] === $active]) @if ($tab['key'] === $active) aria-current="page" @endif>
        {{ $tab['label'] }}<span class="nb">{{ ! empty($tab['badge']) ? strtr((string) $tab['badge'], ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩']) : '' }}</span>
      </a>
    @endforeach
  </nav>

  <div class="grid gap-[18px] pb-[60px]">
    @if (session('status'))
      <p class="flash" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->has('team'))
      <p class="note" role="alert">{{ $errors->first('team') }}</p>
    @endif

    {{ $slot }}
  </div>
</main>
</div>
</body>
</html>
