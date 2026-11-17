<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>{{ auth()->user()->role->label() }} · {{ config('app.name') }}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600&family=Amiri:wght@400;700&display=swap">
@vite(['resources/css/dashboard.css', 'resources/js/dashboard.js'])
</head>
<body>
<div class="app" dir="rtl" lang="ar">
<header class="top"><div class="wrap bar">
  <div class="brand">
    <svg width="38" height="38" viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="20" fill="var(--role)"/><path d="M11 32V19a9 9 0 0 1 18 0v13z" fill="#fff"/><path d="M15 32V20a5 5 0 0 1 10 0v12z" fill="var(--role)"/><circle cx="20" cy="23" r="2.4" fill="var(--sun)"/></svg>
    <span>خدمة الدعم والمساندة في الرعاية التلطيفية<small id="role-label"></small></span>
  </div>
  <div class="ub"><span class="who" id="who"></span><button class="btn btn-g btn-s" type="button" id="out" hidden>خروج</button></div>
</div></header>
<div class="demo"><div class="wrap">نموذج تجريبي: البيانات والأسماء غير حقيقية، ولا يُحفظ شيء ولا يُرسل، وكل صفحة مستقلة عن غيرها في هذا النموذج.</div></div>

<main class="wrap">
  <section class="login" id="login">
    <div class="grid gap-3 content-start">
      <span class="rolebadge" id="l-badge"></span>
      <h1 id="l-title"></h1>
      <p class="muted" id="l-desc"></p>
      <ul class="small muted m-0 ps-4.5 grid gap-1" id="l-feat"></ul>
    </div>
    <form class="card" id="lform" novalidate></form>
  </section>
  <section id="dash" hidden>
    <div class="hello"><div><h1 id="hi"></h1><p class="muted small" id="hi-sub"></p></div><div id="hi-act"></div></div>
    <nav class="tabs" id="tabs" role="tablist"></nav>
    <div id="pane"></div>
  </section>
</main>

<div class="overlay" id="modal" hidden><div class="modal" role="dialog" aria-modal="true" aria-labelledby="m-title">
  <header><h3 id="m-title"></h3><button class="x" type="button" id="m-x" aria-label="إغلاق">×</button></header>
  <div id="m-body"></div>
</div></div>
<div class="toast" id="toast" role="status" hidden></div>
</div>

{{-- Read by resources/js/dashboard.js: role panel, the signed-in user's names, WhatsApp number. --}}
<script type="application/json" id="app-config">@json($config)</script>
<form id="logout-form" method="POST" action="{{ route('logout') }}" hidden>@csrf</form>
</body>
</html>
