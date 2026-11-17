<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="wa-number" content="{{ $waNumber }}">
<title>{{ config('app.name') }}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600&family=Amiri:wght@400;700&display=swap">
@vite(['resources/css/site.css', 'resources/js/site.js'])
</head>
<body>
<div class="site" dir="rtl" lang="ar">

<header class="top">
  <div class="wrap nav">
    <a class="brand" href="#home">
      <svg width="40" height="40" viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="20" fill="var(--spirit)"/><path d="M11 32V19a9 9 0 0 1 18 0v13z" fill="#fff"/><path d="M15 32V20a5 5 0 0 1 10 0v12z" fill="var(--spirit)"/><circle cx="20" cy="23" r="2.4" fill="var(--sun)"/></svg>
      <span class="bname">خدمة الدعم والمساندة لمرضى الرعاية التلطيفية<small>الدعم الديني والروحي لمرضى الرعاية التلطيفية</small></span>
    </a>
    <nav class="links" aria-label="القائمة الرئيسية">
      <a href="#home" data-nav="home">الرئيسية</a>
      <a href="#about" data-nav="about">عن الخدمة</a>
      <a href="#services" data-nav="services">خدماتنا</a>
      <a href="#spiritual" data-nav="spiritual">الدعم الروحي</a>
      <a href="#blog" data-nav="blog">المدونة</a>
      <a href="#record" data-nav="record" id="nav-rec" hidden>سجلي</a>
      <a href="#contact" data-nav="contact">راسلنا</a>
    </nav>
    <div class="userbox">
      <span hidden><span class="hello" id="hello" hidden></span><button class="btn btn-ghost btn-sm demo-btn" type="button" id="btn-demo">استعراض تجريبي</button><a class="btn btn-primary btn-sm" href="#register" id="btn-auth">تسجيل</a><button class="btn btn-ghost btn-sm" id="btn-out" type="button" hidden>خروج</button></span>
      @auth
      <a class="btn btn-ghost btn-sm" href="{{ route('dashboard') }}" aria-label="صفحتي" title="صفحتي"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg><span class="hidden xs:inline">صفحتي</span></a>
      @else
      <a class="btn btn-ghost btn-sm" href="{{ route('login') }}" aria-label="دخول المستفيد" title="دخول المريض أو المرافق"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg><span class="hidden xs:inline">دخول</span></a>
      @endauth
      <a class="btn btn-wa btn-sm" data-wa="أرغب في طلب خدمة من خدمة الدعم والمساندة لمرضى الرعاية التلطيفية" target="_blank" rel="noopener"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 21l1.6-4.8A8.5 8.5 0 1 1 8 19.6z"/></svg>اطلب عبر واتساب</a>
    </div>
  </div>
</header>

<main>

<!-- ================= HOME ================= -->
<div data-view="home">
<section class="hero" id="sec-home">
  <div class="wrap hero-grid">
    <div>
      <p class="eyebrow">الدعم الديني والروحي لمرضى الرعاية التلطيفية</p>
      <h1><em>الملاذ الآمن للروح</em> في رحلة المرض</h1>
      <p class="lead">مرشدون مؤهلون يرافقون مرضى الرعاية التلطيفية وأسرهم: يؤنسون القلب، ويجيبون عن أسئلة الأجر والمصير والعبادة، ويواسون عند الفقد. من المنزل، وفي الوقت المناسب لك.</p>
      <div class="cta">
        <a class="btn btn-wa" data-wa="أرغب في طلب جلسة دعم روحي" target="_blank" rel="noopener"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 21l1.6-4.8A8.5 8.5 0 1 1 8 19.6z"/></svg>اطلب الخدمة عبر واتساب</a>
        <a class="btn btn-ghost" href="#services">تعرّف على خدماتنا</a>
      </div>
      <div class="facts"><span>الطلب والمتابعة عبر واتساب</span><span>جلسات مرئية وصوتية من المنزل</span><span>للمريض والمرافق والأسرة</span></div>
    </div>
    <article class="mcard bg-[linear-gradient(145deg,var(--spirit)_0%,color-mix(in_srgb,var(--spirit)_55%,var(--accent))_100%)]" aria-label="بطاقة تعريفية بالخدمة">
      <div class="who">
        <svg width="46" height="46" viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="20" fill="#fff"/><path d="M11 32V19a9 9 0 0 1 18 0v13z" fill="var(--spirit)"/><path d="M15 32V20a5 5 0 0 1 10 0v12z" fill="#fff"/><circle cx="20" cy="23" r="2.4" fill="var(--sun)"/></svg>
        <div><b>خدمة الدعم والمساندة لمرضى الرعاية التلطيفية</b><span>الدعم الديني والروحي لمرضى الرعاية التلطيفية</span></div>
      </div>
      <p class="tagline">حين يتعب الجسد، تحتاج الروح من يؤنسها</p>
      <div class="mchips"><span>جلسات الدعم الروحي</span><span>التدبر</span><span>الرقية</span><span>زاد المريض</span><span>الفقد والمواساة</span><span>الاستشارة الفقهية</span><span>الوصايا</span></div>
      <div class="mstats">
        <div><b>٧</b>خدمات</div>
        <div><b>٦</b>أنواع جلسات</div>
        <div><b>عن بُعد</b>من سرير المريض</div>
      </div>
      <p class="aff">هذه الخدمة تابعة لجمعية الدعم الديني والروحي لمرضى الطب التلطيفي، ترخيص <span class="num">1000630400</span></p>
    </article>
  </div>
</section>

<section class="section band" id="sec-about">
  <div class="wrap">
    <div class="about">
      <div class="about-text">
        <p class="eyebrow">عن الخدمة</p>
        <h2>نرعى الروح كما يُرعى الجسد</h2>
        <span class="first"><i></i>خدمة متخصصة في الدعم الديني والروحي لمرضى الرعاية التلطيفية</span>
        <p>مرحلة المرض مرحلة قلق وأسئلة: عن المعنى، والأجر، والمصير، وكيف يعبد المريض ربه وهو على فراشه. والخدمة متخصصة في الإجابة عن هذه الحاجة، بمرشدين مؤهلين شرعياً ومدرَّبين على مرافقة المرضى وأسرهم.</p>
        <p>نبدأ بفهم احتياج المستفيد عبر مقياس قبلي، ثم نرافقه بجلسات مختارة لحاله، ونتابعه بخطة واضحة عبر واتساب أو المنصة، ونسند أسرته في رحلتها حتى بعد الفقد.</p>
      </div>
      <div class="pillars" id="pillars"></div>
    </div>
    <div class="values">
      <div class="value"><i>✓</i><div><b>أصالة شرعية</b><p class="muted small">كل ما نقدمه مستند إلى الكتاب والسنة وفقه المريض.</p></div></div>
      <div class="value"><i>✓</i><div><b>رحمة وإنصات</b><p class="muted small">نسمع قبل أن نوجّه، ونبدأ مع المريض من حيث هو.</p></div></div>
      <div class="value"><i>✓</i><div><b>المريض والأسرة معاً</b><p class="muted small">المرافق والأسرة شركاء في الرحلة ولهم نصيب من الدعم.</p></div></div>
    </div>
  </div>
</section>

<section class="section" id="sec-services">
  <div class="wrap">
    <div class="head">
      <p class="eyebrow">خدماتنا</p>
      <h2>خدمات الدعم الديني والروحي</h2>
      <p class="lead">تعرّف على خدماتنا، ثم اطلب ما تحتاجه عبر واتساب.</p>
    </div>
    <div class="cards" id="sv-grid"></div>
    <div class="sec-h"><h3>أنواع جلسات الدعم الروحي</h3><a class="small" href="#spiritual-support">تفاصيل الجلسات</a></div>
    <div class="chips mt-0" id="sv-types"></div>
    <div class="sec-h"><h3>فريق المرشدين</h3></div>
    <div class="cards" id="sv-team"></div>
  </div>
</section>

<section class="pt-0 pb-17">
  <div class="wrap">
    <div class="instant bg-[linear-gradient(135deg,var(--spirit),color-mix(in_srgb,var(--spirit)_55%,var(--accent)))]">
      <div>
        <h2>تحدّث مع مرشد اليوم</h2>
        <p>ضاق صدرك أو جاءك خبر صعب؟ نصلك بالمرشد المناوب خلال ساعة، دون مقياس ودون انتظار.</p>
        <button class="btn text-spirit" type="button" id="instant-btn">احجز الآن ←</button>
      </div>
      <div class="orbit" aria-hidden="true"><span class="o1">ع ب</span><span class="o2">أ ق</span><span class="o3">ص ش</span><span class="o4">م ح</span><i class="live"></i></div>
    </div>
  </div>
</section>

<section class="section band" id="sec-steps">
  <div class="wrap">
    <div class="head">
      <p class="eyebrow">خطوات طلب الخدمة</p>
      <h2>أربع خطوات، وكلها عبر واتساب</h2>
    </div>
    <ol class="steps steps4">
      <li><h3>سجّل بياناتك عبر واتساب</h3><p>راسلنا على واتساب بصفتك المريض أو المرافق، وأرسل الاسم والمنطقة والمستشفى.</p></li>
      <li><h3>اختر الخدمة</h3><p>جلسات الدعم الروحي، أو التدبر، أو الاستشارة الفقهية، أو غيرها من خدماتنا.</p></li>
      <li><h3>اختر موعدك</h3><p>نرسل لك مواعيد المرشد المتاحة، فتختار ما يناسبك ويصلك التأكيد.</p></li>
      <li><h3>احضر جلستك</h3><p>يصلك رابط الجلسة أو موعد الاتصال، ثم يتابعك المرشد عبر واتساب.</p></li>
    </ol>
    <div class="mt-5.5"><a class="btn btn-wa" data-wa="أرغب في طلب خدمة من خدمة الدعم والمساندة لمرضى الرعاية التلطيفية" target="_blank" rel="noopener"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 21l1.6-4.8A8.5 8.5 0 1 1 8 19.6z"/></svg>ابدأ الآن عبر واتساب</a></div>
  </div>
</section>

<section class="section" id="sec-blog">
  <div class="wrap">
    <div class="head">
      <p class="eyebrow">مدونة الخدمة</p>
      <h2>ندوات وبرامج روحية مجانية</h2>
      <p class="lead">يقدّمها مرشدو الخدمة للمرضى والمرافقين ومن يهمّه الأمر. سجّل مجاناً عبر واتساب، أو اقرأ مقالات التوعية.</p>
    </div>
    <div class="filters" role="group" aria-label="تصفية المدونة">
      <button type="button" class="on" data-f="all">الكل</button>
      <button type="button" data-f="ندوة">الندوات</button>
      <button type="button" data-f="برنامج">البرامج</button>
      <button type="button" data-f="مقال">المقالات</button>
    </div>
    <div class="cards" id="blog-list"></div>
  </div>
</section>

<section class="section band" id="sec-faq">
  <div class="wrap">
    <div class="head"><p class="eyebrow">الأسئلة الشائعة</p><h2>أسئلة يطرحها المرضى وأسرهم</h2></div>
    <div class="faq-search"><input id="faq-q" type="search" placeholder="ابحث عن إجابة لسؤالك…" aria-label="ابحث في الأسئلة الشائعة"></div>
    <div class="faq" id="faq-list"></div>
    <p class="empty max-w-[820px]" id="faq-none" hidden>لم نجد سؤالاً مطابقاً. <a href="#contact">أرسل لنا استفسارك</a> وسنجيبك.</p>
  </div>
</section>

<section class="section" id="sec-contact">
  <div class="wrap max-w-[820px]">
    <div>
      <p class="eyebrow">راسلنا</p>
      <h2>شكوى، مقترح، أو استفسار</h2>
      <p class="lead mt-2.5">راسلنا على واتساب، ونردّ خلال يوم عمل واحد.</p>
      <div class="flex flex-wrap gap-2 mt-4"><a class="btn btn-wa btn-sm" data-wa="لدي استفسار:" target="_blank" rel="noopener">استفسار</a><a class="btn btn-wa btn-sm" data-wa="لدي مقترح:" target="_blank" rel="noopener">مقترح</a><a class="btn btn-wa btn-sm" data-wa="لدي شكوى:" target="_blank" rel="noopener">شكوى</a></div>
      <div class="channels">
        <a class="channel no-underline text-inherit" data-wa="لدي استفسار:" target="_blank" rel="noopener"><span class="ico bg-[#1fa855]"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M3 21l1.6-4.8A8.5 8.5 0 1 1 8 19.6z"/></svg></span><div><b>واتساب الخدمة</b><span class="wa-num"></span></div></a>
        <a class="channel soc-x no-underline text-inherit" target="_blank" rel="noopener"><span class="ico bg-[#0b0f14] text-white"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 4l16 16M20 4L4 20"/></svg></span><div><b>إكس (تويتر) الجمعية</b><span class="soc-label">يُضاف معرّف الحساب</span></div></a>
        <a class="channel soc-tt no-underline text-inherit" target="_blank" rel="noopener"><span class="ico bg-[#111] text-white"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v11.5a3.5 3.5 0 1 1-3.5-3.5"/><path d="M14 3c.5 2.5 2.5 4.5 5 4.8"/></svg></span><div><b>تيك توك الجمعية</b><span class="soc-label">يُضاف معرّف الحساب</span></div></a>
      </div>
      <p class="license">خدمة الدعم والمساندة لمرضى الرعاية التلطيفية<br><span class="small muted">الدعم الديني والروحي لمرضى الرعاية التلطيفية</span><br>هذه الخدمة تابعة لجمعية الدعم الديني والروحي لمرضى الطب التلطيفي، ترخيص <span class="num">1000630400</span></p>
      <p class="urgent">الخدمة لا تقدّم خدمات طبية أو طوارئ. عند تدهور مفاجئ اتصل بالرقم <b class="num">997</b> أو توجّه إلى أقرب طوارئ.</p>
    </div>
    <form class="card" id="contact-form" novalidate hidden>
      <fieldset class="border-0 p-0 m-0 grid gap-2">
        <legend class="font-medium text-[.9rem] mb-1.5">نوع الرسالة</legend>
        <div class="seg">
          <label><input type="radio" name="ctype" id="ct-1" value="شكوى">شكوى</label>
          <label><input type="radio" name="ctype" id="ct-2" value="مقترح">مقترح</label>
          <label><input type="radio" name="ctype" id="ct-3" value="استفسار" checked>استفسار</label>
        </div>
      </fieldset>
      <div class="row">
        <label for="c-name">الاسم<input id="c-name" autocomplete="name"><span class="err" id="ce-name"></span></label>
        <label for="c-phone">رقم الجوال<input id="c-phone" inputmode="tel" dir="ltr" placeholder="05XXXXXXXX"><span class="err" id="ce-phone"></span></label>
      </div>
      <label for="c-msg">الرسالة<textarea id="c-msg"></textarea><span class="err" id="ce-msg"></span></label>
      <button class="btn btn-primary" type="submit">أرسل الرسالة</button>
      <div class="ok-msg" id="c-ok" hidden></div>
    </form>
  </div>
</section>
</div>

<!-- ================= REGISTER ================= -->
<div data-view="register" hidden>
  <div class="wrap">
    <nav class="crumbs"><a href="#home">الرئيسية</a><span>التسجيل</span></nav>
    <div class="vhead">
      <p class="eyebrow">التسجيل</p>
      <h2>سجّل لتحجز وتطّلع على الأقسام الداخلية</h2>
      <p class="lead" id="reg-why">التسجيل مطلوب مرة واحدة للمريض أو المرافق قبل أي حجز.</p>
    </div>
    <div class="two pb-15">
      <form class="card" id="reg-form" novalidate>
        <div class="demo-box">
          <div><b>تريد أن ترى الموقع بعد التسجيل؟</b><p class="small muted">ادخل بحساب تجريبي جاهز، وتصفّح كل الأقسام والسجل والحجز.</p></div>
          <button class="btn btn-primary demo-btn" type="button">استعرض الآن</button>
        </div>
        <div class="or">أو سجّل بياناتك</div>
        <div class="nafath">
          <b>الدخول عبر نفاذ</b>
          <p class="small muted">يُستكمل الاسم ورقم الهوية وتاريخ الميلاد والجنس تلقائياً من النفاذ الوطني الموحد.</p>
          <button class="btn btn-ghost" type="button" id="nafath-start">التحقق عبر نفاذ</button>
          <div id="nafath-wait" hidden>
            <p class="small">افتح تطبيق نفاذ واختر الرقم:</p>
            <p class="code num" id="nafath-code">٤٧</p>
            <button class="btn btn-primary btn-sm" type="button" id="nafath-ok">تم التأكيد في التطبيق</button>
            <p class="small muted mt-1.5">نسخة عرض: يملأ الزر بيانات تجريبية. الربط الفعلي يتطلب اعتماد المنصة لدى نفاذ.</p>
          </div>
        </div>
        <div class="or">أو أدخل بياناتك</div>
        <fieldset class="border-0 p-0 m-0 grid gap-2">
          <legend class="font-medium text-[.9rem] mb-1.5">هل أنت المريض أم المرافق؟</legend>
          <div class="seg">
            <label><input type="radio" name="role" id="r-patient" value="مريض" checked>أنا المريض</label>
            <label><input type="radio" name="role" id="r-comp" value="مرافق">أنا مرافق</label>
          </div>
        </fieldset>
        <label for="r-name">الاسم الكامل <span class="verified" data-v hidden>تم التحقق</span><input id="r-name" autocomplete="name"><span class="err" id="re-name"></span></label>
        <div class="row">
          <label for="r-phone">رقم الجوال<input id="r-phone" inputmode="tel" dir="ltr" placeholder="05XXXXXXXX"><span class="err" id="re-phone"></span></label>
          <label for="r-id">رقم الهوية أو الإقامة <span class="verified" data-v hidden>تم التحقق</span><input id="r-id" inputmode="numeric" dir="ltr" maxlength="10" placeholder="1XXXXXXXXX"><span class="err" id="re-id"></span></label>
        </div>
        <div class="row">
          <label for="r-dob">تاريخ الميلاد <span class="verified" data-v hidden>تم التحقق</span><input id="r-dob" type="date"><span class="err" id="re-dob"></span></label>
          <label for="r-gender">الجنس<select id="r-gender"><option value="">اختر</option><option>ذكر</option><option>أنثى</option></select><span class="err" id="re-gender"></span></label>
        </div>
        <div class="row">
          <label for="r-region">المنطقة<select id="r-region"></select><span class="err" id="re-region"></span></label>
          <label for="r-hosp">المستشفى التابع له المريض<input id="r-hosp" placeholder="مثال: مستشفى الملك فهد"><span class="err" id="re-hosp"></span></label>
        </div>
        <button class="btn btn-primary" type="submit">أكمل التسجيل</button>
      </form>
      <div class="card grid gap-3 content-start bg-surface">
        <h3>لماذا نطلب هذه البيانات؟</h3>
        <p class="muted small">رقم الهوية وتاريخ الميلاد للتحقق من الشخص وربط ملفه بالمستشفى. المنطقة والمستشفى لتنسيق الرعاية مع الفريق المعالج. رقم الجوال لإرسال تأكيد الموعد ورابط الجلسة.</p>
        <h3>المرافق</h3>
        <p class="muted small">يستطيع المرافق التسجيل ليحجز للمريض أو ليستفيد من الخدمات لنفسه، مثل جلسات الدعم النفسي وبرنامج الفقد والمواساة.</p>
        <p class="info">هذه نسخة عرض: البيانات لا تُحفظ ولا تُرسل، وتُمسح عند إغلاق الصفحة.</p>
      </div>
    </div>
  </div>
</div>

<!-- ================= SPIRITUAL PORTAL ================= -->
<div data-view="spiritual" hidden>
  <section class="portal">
    <div class="wrap">
      <nav class="crumbs"><a href="#home">الرئيسية</a><span>الدعم الديني والروحي</span></nav>
      <div class="portal-hero mt-5">
        <svg class="emblem" viewBox="0 0 200 200" role="img" aria-label="شعار الدعم الديني والروحي: الملاذ الآمن للروح">
          <circle cx="100" cy="100" r="96" fill="var(--spirit-soft)"/>
          <path d="M52 168V96a48 48 0 0 1 96 0v72z" fill="var(--spirit)"/>
          <path d="M66 168V100a34 34 0 0 1 68 0v68z" fill="var(--bg)"/>
          <path d="M100 76v12" stroke="var(--sun)" stroke-width="3" stroke-linecap="round"/>
          <path d="M88 92h24l-4 34H92z" fill="var(--sun)"/>
          <circle cx="100" cy="110" r="5" fill="#fff"/>
          <path d="M60 168h80" stroke="var(--spirit)" stroke-width="4" stroke-linecap="round"/>
        </svg>
        <div class="grid gap-3">
          <p class="eyebrow">الدعم الديني والروحي</p>
          <h1>الملاذ الآمن للروح</h1>
          <p class="lead">مرحلة المرض مرحلة قلق وأسئلة، عن المعنى والأجر والمصير. والدعم الروحي فيها هو الملاذ الذي تأوي إليه الروح لتطمئن. نرافقك بجلسات إرشاد وتدبّر ورقية، وبرامج تعين المريض على استثمار أيامه، وتواسي الأسرة عند الفقد.</p>
          <p class="verse">﴿الَّذِينَ آمَنُوا وَتَطْمَئِنُّ قُلُوبُهُم بِذِكْرِ اللَّهِ ۗ أَلَا بِذِكْرِ اللَّهِ تَطْمَئِنُّ الْقُلُوبُ﴾<small>سورة الرعد، الآية ٢٨</small></p>
        </div>
      </div>
      <div class="tiles" id="sp-tiles"></div>
      <p class="lock mt-4" id="lock-note" hidden>يلزم التسجيل للدخول إلى الأقسام الداخلية.</p>
      <button class="btn btn-ghost btn-sm demo-btn mt-2" type="button" id="portal-demo" hidden>استعراض تجريبي دون تسجيل</button>
    </div>
  </section>
</div>

<!-- ================= SPIRITUAL SUPPORT (scale + booking) ================= -->
<div data-view="spiritual-support" hidden>
  <div class="wrap pb-15">
    <nav class="crumbs"><a href="#home">الرئيسية</a><span><a href="#spiritual">الدعم الروحي</a></span><span>جلسات الدعم الروحي</span></nav>
    <div class="vhead">
      <h2>جلسات الدعم الروحي</h2>
      <p class="lead">جلسات فردية مع مرشد ديني وروحي، تُطلب عبر واتساب، وتختار طريقة البدء التي تناسبك.</p>
      <p class="pnote">(تنويه: يُطلب الدعم الروحي عبر واتساب. وبإمكان المريض إجراء المقياس القبلي الذي يرسله له المرشد، ليحدد له الجلسة المناسبة، أو اختيار جلسة مباشرة وإجراء المقياس لاحقاً. وتظهر نتيجة المقياس للمرشد فقط، ويرشّح النظام نوع الجلسة وخطتها، فيختار المرشد الأنسب للمريض ويبدأ معه الجلسة الأولى، ثم يرسل له خطة متابعة الجلسة عبر واتساب. وإن اختار المريض جلسة مباشرة، اختار من مواعيد المرشد المتاحة، ثم تبدأ الجلسة، ثم يفعّل المرشد متابعته على هذه الجلسة وخطتها.)</p>
    </div>
    <div class="paths">
      <div class="path cursor-default"><span class="rec">موصى به</span><b>ابدأ بالمقياس القبلي</b><span class="d">يرسل لك المرشد مقياساً قصيراً عبر واتساب، ويطّلع على نتيجتك ويحدد لك الجلسة المناسبة وخطتها.</span><a class="btn btn-wa btn-sm justify-self-start" data-wa="أرغب في البدء بالمقياس القبلي لجلسات الدعم الروحي" target="_blank" rel="noopener"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 21l1.6-4.8A8.5 8.5 0 1 1 8 19.6z"/></svg>اطلب المقياس عبر واتساب</a></div>
      <div class="path cursor-default"><b>اختر جلسة مباشرة</b><span class="d">اختر نوع الجلسة من القائمة أدناه واطلبها عبر واتساب، وتجري المقياس لاحقاً.</span><button class="btn btn-ghost btn-sm justify-self-start" type="button" id="go-types">اعرض أنواع جلسات الدعم الديني والروحي</button></div>
    </div>
    <div class="sec-h scroll-mt-[80px]" id="ss-types-d"><h3>أنواع جلسات الدعم الديني والروحي</h3></div>
    <div class="stypes" id="ssd-types"></div>
    <div hidden>
    <div class="paths" id="ss-paths">
      <button class="path" type="button" data-path="scale"><span class="rec">موصى به</span><b>ابدأ بالمقياس القبلي</b><span class="d">تجيب عن مقياس قصير، فيطّلع المرشد على نتيجتك ويحدد لك الجلسة المناسبة وخطتها، ثم تختار موعدك.</span></button>
      <button class="path" type="button" data-path="direct"><b>اختر جلسة مباشرة</b><span class="d">تختار نوع الجلسة بنفسك وتحجز موعدها، وتجري المقياس لاحقاً.</span></button>
    </div>
    <div class="stepper" id="ss-stepper" hidden><span id="st1" class="on"></span><span id="st2"></span></div>

    <form class="card max-w-[820px]" id="scale-form" novalidate hidden>
      <label class="max-w-[320px]" for="s-about">أجيب عن<select id="s-about"><option>نفسي</option><option>المريض الذي أرافقه</option></select></label>
      <p class="small muted">ضع علامة على مدى انطباق كل عبارة خلال الأسبوعين الماضيين. تظهر إجاباتك ونتيجتها للمرشد فقط.</p>
      <div id="scale-items"></div>
      <span class="err" id="scale-err"></span>
      <button class="btn btn-spirit" type="submit">أرسل إجاباتي إلى المرشد</button>
      <p class="info"><b>تنويه:</b> عبارات هذا المقياس مبدئية للعرض فقط، وستُضاف المقاييس المعتمدة لاحقاً.</p>
    </form>

    <div class="max-w-[820px] grid gap-4.5">
      <div class="card grid gap-2 bg-spirit-soft border-transparent" id="ss-sent" hidden>
        <h3>أُرسلت إجاباتك إلى المرشد</h3>
        <p class="muted">تظهر النتيجة للمرشد فقط. سيطّلع عليها ويحدد لك نوع الجلسة المناسبة وخطتها، ويبدأ معك الجلسة الأولى. اختر الآن موعدك من مواعيده المتاحة.</p>
      </div>
      <div class="card grid gap-3.5" id="ss-types-card" hidden>
        <h3>اختر نوع الجلسة</h3>
        <p class="small muted">اختر ما يناسب حالك الآن. ويصلك المقياس القبلي في صفحة سجلي لتجريه لاحقاً.</p>
        <div class="stypes" id="ss-types" role="radiogroup" aria-label="نوع الجلسة"></div>
      </div>
      <div class="card grid gap-3.5" id="ss-slots-card" hidden>
        <h3>اختر موعدك من مواعيد المرشد المتاحة</h3>
        <label class="max-w-[360px]" for="ss-guide">المرشد<select id="ss-guide"></select></label>
        <div class="slots" id="ss-slots"></div>
        <button class="btn btn-spirit" type="button" id="ss-book" disabled>احجز الجلسة</button>
        <div class="ok-msg" id="ss-ok" hidden></div>
      </div>
      <button class="btn btn-ghost btn-sm justify-self-start" type="button" id="ss-change" hidden>تغيير الطريقة</button>
    </div>
    </div>
  </div>
</div>

<!-- ================= TADABBUR ================= -->
<div data-view="tadabbur" hidden>
  <div class="wrap pb-15">
    <nav class="crumbs"><a href="#home">الرئيسية</a><span><a href="#spiritual">الدعم الروحي</a></span><span>جلسات التدبر</span></nav>
    <div class="vhead">
      <h2>جلسات التدبر</h2>
      <p class="lead">لقاءات جماعية مرئية، يقود كل منها مرشد. اختر الجلسة المناسبة واطلب مقعدك عبر واتساب.</p>
    </div>
    <div class="cards" id="tad-list"></div>
  </div>
</div>

<!-- ================= RUQYA ================= -->
<div data-view="ruqya" hidden>
  <div class="wrap pb-15">
    <nav class="crumbs"><a href="#home">الرئيسية</a><span><a href="#spiritual">الدعم الروحي</a></span><span>كيف ترقي نفسك</span></nav>
    <div class="vhead">
      <h2>كيف ترقي نفسك</h2>
      <p class="lead">الرقية دعاء وقرآن، يقرؤها المريض على نفسه، أو يقرؤها عليه مرافقه إن شقّ عليه ذلك. وهي لا تغني عن العلاج الطبي، فالأخذ بالأسباب من التوكل.</p>
    </div>
    <div class="ruqya">
      <div class="rstep"><span class="n">قبل أن تبدأ</span><h3>حضور القلب واليقين</h3><p class="muted">اقرأ وأنت موقن أن الشفاء من الله، حاضر القلب لمعاني ما تقرأ. ويُستحب أن تكون على طهارة، ولا يُشترط ذلك للمريض العاجز.</p></div>
      <div class="rstep"><span class="n">الخطوة الأولى</span><h3>اقرأ من القرآن</h3><p class="muted">اقرأ سورة الفاتحة، وآية الكرسي، وخواتيم سورة البقرة، ثم سورة الإخلاص والفلق والناس ثلاث مرات. وانفث في كفيك وامسح بهما ما استطعت من جسدك.</p><p class="src">كان النبي ﷺ إذا اشتكى يقرأ على نفسه بالمعوّذات وينفث. متفق عليه.</p></div>
      <div class="rstep"><span class="n">الخطوة الثانية</span><h3>ضع يدك على موضع الألم</h3><p class="dua">«ضَعْ يَدَكَ عَلَى الَّذِي تَأَلَّمَ مِنْ جَسَدِكَ، وَقُلْ: بِاسْمِ اللهِ ثَلَاثًا، وَقُلْ سَبْعَ مَرَّاتٍ: أَعُوذُ بِاللهِ وَقُدْرَتِهِ مِنْ شَرِّ مَا أَجِدُ وَأُحَاذِرُ»</p><p class="src">رواه مسلم من حديث عثمان بن أبي العاص رضي الله عنه.</p></div>
      <div class="rstep"><span class="n">الخطوة الثالثة</span><h3>ادعُ بدعاء الشفاء</h3><p class="dua">«اللَّهُمَّ رَبَّ النَّاسِ، أَذْهِبِ الْبَأْسَ، اشْفِ أَنْتَ الشَّافِي، لَا شِفَاءَ إِلَّا شِفَاؤُكَ، شِفَاءً لَا يُغَادِرُ سَقَمًا»</p><p class="src">متفق عليه.</p></div>
      <div class="rstep"><span class="n">للمرافق والزائر</span><h3>ما يقوله عند المريض</h3><p class="dua">«أَسْأَلُ اللهَ الْعَظِيمَ، رَبَّ الْعَرْشِ الْعَظِيمِ، أَنْ يَشْفِيَكَ» سبع مرات</p><p class="src">رواه أبو داود والترمذي.</p></div>
      <div class="rstep bg-spirit-soft border-transparent"><h3>تحتاج من يرقيك أو يجيب عن سؤالك؟</h3><p class="muted">احجز جلسة دعم روحي، وسيرافقك المرشد في الرقية والدعاء.</p><a class="btn btn-spirit justify-self-start" href="#spiritual-support">احجز جلسة دعم روحي</a></div>
    </div>
  </div>
</div>

<!-- ================= ZAD ================= -->
<div data-view="zad" hidden>
  <div class="wrap pb-15">
    <nav class="crumbs"><a href="#home">الرئيسية</a><span><a href="#spiritual">الدعم الروحي</a></span><span>زاد المريض</span></nav>
    <div class="vhead">
      <h2>زاد المريض</h2>
      <p class="lead">مجموعة متنوعة من البرامج تلامس احتياج المريض وتزيد إيمانه. اختر البرنامج، واشترك فيه عبر واتساب.</p>
    </div>
    <div class="zbig" role="tablist" aria-label="أقسام زاد المريض">
      <button type="button" class="zb on" data-z="omr" role="tab"><span class="zi bg-accent">${I.clock}</span><b>استثمار العمر</b><span>ختمات وحلقات وصدقة جارية</span></button>
      <button type="button" class="zb" data-z="baq" role="tab"><span class="zi bg-spirit">${I.leaf}</span><b>الباقيات الصالحات</b><span>برنامج يومي لمدة شهر</span></button>
    </div>

    <div data-zp="omr">
      <section class="zsec">
        <h3>١. ختمة القرآن</h3>
        <p class="muted">جدول متابعة لختم القرآن في مدة تناسب قدرتك، ويصلك وِرد كل يوم على واتساب حتى تتم الختمة بإذن الله.</p>
        <fieldset class="fs"><legend>نوع الختمة</legend>
          <div class="stypes" id="kh-type">
            <label class="stype" for="kh-t1"><input type="radio" name="kh-t" id="kh-t1" value="ختمة تلاوة" checked><b>ختمة تلاوة</b><span class="d">يصلك الحزب اليومي للقراءة أو الاستماع.</span></label>
            <label class="stype" for="kh-t2"><input type="radio" name="kh-t" id="kh-t2" value="ختمة مع تفسير"><b>ختمة مع تفسير</b><span class="d">يصلك الحزب مع تفسيره الميسّر.</span></label>
            <label class="stype" for="kh-t3"><input type="radio" name="kh-t" id="kh-t3" value="ختمة مع غريب القرآن"><b>ختمة مع غريب القرآن</b><span class="d">يصلك الحزب مع بيان معاني ألفاظه الغريبة.</span></label>
          </div>
        </fieldset>
        <fieldset class="fs"><legend>مدة الختمة</legend>
          <div class="seg">
            <label for="kh-d1"><input type="radio" name="kh-d" id="kh-d1" value="شهر">خلال شهر</label>
            <label for="kh-d2"><input type="radio" name="kh-d" id="kh-d2" value="شهرين" checked>خلال شهرين</label>
            <label for="kh-d3"><input type="radio" name="kh-d" id="kh-d3" value="3 أشهر">خلال ٣ أشهر</label>
          </div>
        </fieldset>
        <div class="khres"><div><b id="kh-sum"></b><p class="small muted" id="kh-det"></p></div><a class="btn btn-wa" id="kh-go" target="_blank" rel="noopener"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 21l1.6-4.8A8.5 8.5 0 1 1 8 19.6z"/></svg>اشترك في الختمة</a></div>
      </section>

      <section class="zsec">
        <h3>٢. حلقات القرآن</h3>
        <div class="cards">
          <div class="sess"><h4>حلقة تصحيح التلاوة</h4><p class="muted small">تقرأ على معلم أو معلمة عن بُعد، فيصحح لك التلاوة بهدوء، وبقدر ما يسمح به جهدك.</p><a class="btn btn-wa btn-sm justify-self-start" data-wa="أرغب في الالتحاق بحلقة تصحيح التلاوة" target="_blank" rel="noopener"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 21l1.6-4.8A8.5 8.5 0 1 1 8 19.6z"/></svg>التحق بالحلقة</a></div>
          <div class="sess"><h4>حلقة الحفظ</h4><p class="muted small">مقدار يسير تحفظه وتسمّعه، يناسب قدرتك وحالتك الصحية.</p><a class="btn btn-wa btn-sm justify-self-start" data-wa="أرغب في الالتحاق بحلقة الحفظ" target="_blank" rel="noopener"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 21l1.6-4.8A8.5 8.5 0 1 1 8 19.6z"/></svg>التحق بالحلقة</a></div>
        </div>
      </section>

      <section class="zsec">
        <h3>٣. صدقتي الجارية</h3>
        <p class="muted">ثلاثة أبواب للصدقة، اختر ما يناسبك منها.</p>
        <div class="cards">
          <div class="sess">
            <h4>أفكار للصدقة اليومية</h4>
            <p class="muted small">يصلك كل يوم عمل يسير تتصدق به ولو كنت على فراشك، ومن ذلك:</p>
            <ul class="zlist">
              <li><b>مساعدة الآخرين:</b> «كُلُّ مَعْرُوفٍ صَدَقَةٌ» <span class="src">رواه البخاري</span></li>
              <li><b>الابتسامة:</b> «تَبَسُّمُكَ فِي وَجْهِ أَخِيكَ لَكَ صَدَقَةٌ» <span class="src">رواه الترمذي</span></li>
              <li><b>سنة الضحى:</b> «وَيُجْزِئُ مِنْ ذَلِكَ رَكْعَتَانِ يَرْكَعُهُمَا مِنَ الضُّحَى» <span class="src">رواه مسلم</span></li>
            </ul>
            <div class="flex flex-wrap gap-2"><a class="btn btn-wa btn-sm" data-wa="أرغب في الاشتراك في أفكار الصدقة اليومية" target="_blank" rel="noopener"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 21l1.6-4.8A8.5 8.5 0 1 1 8 19.6z"/></svg>اشترك في الأفكار اليومية</a></div>
          </div>
          <div class="sess">
            <h4>صدقة عنك أو عن والديك</h4>
            <p class="muted small">صدقة تقوم بها لنفسك، أو تجعل أجرها لوالديك. نعينك على اختيار الصدقة المناسبة وإيصالها إلى أهلها.</p>
            <p class="small">«إِذَا مَاتَ الْإِنْسَانُ انْقَطَعَ عَنْهُ عَمَلُهُ إِلَّا مِنْ ثَلَاثَةٍ: إِلَّا مِنْ صَدَقَةٍ جَارِيَةٍ، أَوْ عِلْمٍ يُنْتَفَعُ بِهِ، أَوْ وَلَدٍ صَالِحٍ يَدْعُو لَهُ» <span class="src">رواه مسلم</span></p>
            <div class="flex flex-wrap gap-2"><a class="btn btn-wa btn-sm" data-wa="أرغب في صدقة عن نفسي" target="_blank" rel="noopener"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 21l1.6-4.8A8.5 8.5 0 1 1 8 19.6z"/></svg>صدقة عن نفسي</a><a class="btn btn-ghost btn-sm" data-wa="أرغب في صدقة عن والديّ" target="_blank" rel="noopener">صدقة عن والديّ</a></div>
          </div>
          <div class="sess">
            <h4>أمنية وأثر</h4>
            <p class="muted small">نحقق رغبتك في صدقة يبقى أثرها، ويتم العمل باسمك أو باسم من تحب، ومن ذلك:</p>
            <div class="chips"><span class="chip">وقف كرسي متحرك</span><span class="chip">توزيع ماء</span><span class="chip">كفالة</span></div>
            <a class="btn btn-wa btn-sm justify-self-start" data-wa="أرغب في التسجيل في أمنية وأثر (وقف كرسي / توزيع ماء / كفالة)" target="_blank" rel="noopener"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 21l1.6-4.8A8.5 8.5 0 1 1 8 19.6z"/></svg>سجّل رغبتك</a>
          </div>
        </div>
      </section>
    </div>

    <div data-zp="baq" hidden>
      <section class="zsec">
        <p class="dua text-[1.25rem]">﴿وَالْبَاقِيَاتُ الصَّالِحَاتُ خَيْرٌ عِندَ رَبِّكَ ثَوَابًا وَخَيْرٌ أَمَلًا﴾<span class="src block font-body">سورة الكهف، الآية ٤٦</span></p>
        <h3>برنامج الباقيات الصالحات</h3>
        <p class="muted">برنامج لمدة شهر، يصلك فيه كل يوم على واتساب برنامج متكامل لليوم، يسير على المريض ويملأ يومه بالخير.</p>
        <div class="card grid gap-2.5 bg-spirit-soft border-transparent">
          <b class="font-display">مثال لبرنامج يوم واحد</b>
          <ol class="side-steps text-ink">
            <li><b>آية اليوم:</b> آية قصيرة مع تأمل في معناها.</li>
            <li><b>ورد الذكر:</b> سبحان الله، والحمد لله، ولا إله إلا الله، والله أكبر.</li>
            <li><b>دعاء اليوم:</b> دعاء مأثور يردده المريض.</li>
            <li><b>عمل صالح يسير:</b> صدقة أو صلة أو كلمة طيبة.</li>
            <li><b>وقفة تأمل:</b> سؤال قصير يتأمله في نعم الله.</li>
          </ol>
          <div class="chips"><span class="chip">٣٠ يوماً</span><span class="chip">رسالة يومية على واتساب</span><span class="chip">للمريض والمرافق</span></div>
        </div>
        <a class="btn btn-wa mt-3.5" data-wa="أرغب في الاشتراك في برنامج الباقيات الصالحات" target="_blank" rel="noopener"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 21l1.6-4.8A8.5 8.5 0 1 1 8 19.6z"/></svg>اشترك في البرنامج</a>
      </section>
    </div>
    <div hidden><div id="zad-1"></div><div id="zad-2"></div></div>
  </div>
</div>

<!-- ================= FIQH ================= -->
<div data-view="fiqh" hidden>
  <div class="wrap pb-15">
    <nav class="crumbs"><a href="#home">الرئيسية</a><span><a href="#spiritual">الدعم الروحي</a></span><span>الاستشارة الفقهية</span></nav>
    <div class="vhead">
      <h2>الاستشارة الفقهية</h2>
      <p class="lead">المرض لا يُسقط عن المسلم صلته بربه، لكنه يفتح له أبواب الرخصة والتيسير. يجيبك مرشد فقهي عن أحكام عبادتك في مرضك، بما يناسب حالتك الصحية.</p>
      <p class="verse font-naskh text-[1.3rem]">﴿فَاتَّقُوا اللَّهَ مَا اسْتَطَعْتُمْ﴾<span class="src block font-body">سورة التغابن، الآية ١٦</span></p>
    </div>
    <div class="two">
      <div class="card grid gap-3 content-start">
        <h3>مما نجيب عنه</h3>
        <ul class="side-steps text-ink">
          <li>الطهارة والتيمم للمريض، ومع القسطرة والجروح والجبائر.</li>
          <li>الصلاة قاعداً أو على جنب أو بالإيماء، والجمع بين الصلاتين.</li>
          <li>الصيام والفطر والفدية والقضاء.</li>
          <li>الحج والعمرة عن المريض أو بالإنابة.</li>
          <li>أحكام التداوي والأدوية والأجهزة الطبية.</li>
          <li>أحكام المرافق في عبادته ورعايته للمريض.</li>
        </ul>
      </div>
      <div class="card grid gap-3.5 content-start bg-spirit-soft border-transparent">
        <h3>كيف تطلب الاستشارة؟</h3>
        <div class="flex flex-wrap gap-2"><a class="btn btn-wa" id="bot-ask" target="_blank" rel="noopener"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 21l1.6-4.8A8.5 8.5 0 1 1 8 19.6z"/></svg>أرسل سؤالك للبوت</a><button class="btn btn-spirit" type="button" data-sp-book="fiqh">احجز جلسة</button></div>
        <ol class="side-steps text-ink">
          <li>ترسل سؤالك إلى البوت الفقهي عبر واتساب.</li>
          <li>يجيبك البوت فوراً إن وُجد الجواب في المرجع الفقهي المعتمد.</li>
          <li>إن لم يوجد الجواب، يُحال سؤالك إلى المستشار الفقهي.</li>
          <li>يصلك رد المستشار على واتساب.</li>
        </ol>
        <div class="staff [border-top:1px_dashed_var(--line)]"><div class="person [--c:var(--spirit)]"><span class="avatar">ص ش</span><div><b>د. صالح الشهري</b><span>مرشد فقهي، فقه المريض</span></div></div><div class="person [--c:var(--spirit)]"><span class="avatar">أ ق</span><div><b>أ. أمل القرشي</b><span>مرشدة فقهية</span></div></div></div>
      </div>
    </div>
  </div>
</div>

<!-- ================= WILLS ================= -->
<div data-view="wills" hidden>
  <div class="wrap pb-15">
    <nav class="crumbs"><a href="#home">الرئيسية</a><span><a href="#spiritual">الدعم الروحي</a></span><span>استشارة الوصايا</span></nav>
    <div class="vhead">
      <h2>استشارة الوصايا <span class="text-[.6em] font-medium text-spirit">(بالشراكة مع مركز واقف)</span></h2>
      <p class="lead">الوصية طمأنينة للقلب وحفظ للحقوق، ويستحب للمسلم أن يكتبها صحيحاً كان أو مريضاً. نعينك على كتابتها على الوجه الشرعي، بهدوء ودون استعجال.</p>
      <p class="dua text-[1.15rem]">«مَا حَقُّ امْرِئٍ مُسْلِمٍ لَهُ شَيْءٌ يُوصِي فِيهِ، يَبِيتُ لَيْلَتَيْنِ إِلَّا وَوَصِيَّتُهُ مَكْتُوبَةٌ عِنْدَهُ»<span class="src block font-body">متفق عليه</span></p>
    </div>
    <div class="two">
      <div class="card grid gap-3 content-start">
        <h3>مما نعينك فيه</h3>
        <ul class="side-steps text-ink">
          <li>صيغة الوصية وما يُكتب فيها.</li>
          <li>الوصية بالثلث فأقل في الصدقة والوقف لغير الوارث.</li>
          <li>توثيق الديون والحقوق التي لك وعليك.</li>
          <li>المسامحة ورد المظالم وطلب الحِل.</li>
          <li>الوصية بالأهل والأولاد في دينهم ورعايتهم.</li>
          <li>الإرشاد إلى توثيق الوصية لدى الجهات المختصة.</li>
        </ul>
        <p class="info">الاستشارة إرشادية، وتوثيق الوصية رسمياً يكون لدى الجهات المختصة.</p>
      </div>
      <div class="card grid gap-3.5 content-start bg-spirit-soft border-transparent">
        <h3>كيف تطلب الاستشارة؟</h3>
        <p class="muted small">احجز جلسة، ويمكن أن يحضرها أحد أفراد أسرتك إن رغبت، أو أرسل سؤالك مكتوباً، ويصلك الرد عبر واتساب.</p>
        <div class="flex flex-wrap gap-2"><button class="btn btn-spirit" type="button" data-sp-book="wills">احجز جلسة</button><button class="btn btn-ghost" type="button" data-sp-ask="wills">أرسل سؤالك</button></div>
        <div class="staff [border-top:1px_dashed_var(--line)]"><div class="person [--c:var(--spirit)]"><span class="avatar">س ع</span><div><b>د. سليمان العمري</b><span>مستشار في الوصايا والمواريث</span></div></div><div class="person [--c:var(--spirit)]"><span class="avatar">ص ش</span><div><b>د. صالح الشهري</b><span>مرشد فقهي</span></div></div></div>
      </div>
    </div>
  </div>
</div>

<!-- ================= LOSS ================= -->
<div data-view="loss" hidden>
  <div class="wrap pb-15">
    <nav class="crumbs"><a href="#home">الرئيسية</a><span><a href="#spiritual">الدعم الروحي</a></span><span>الفقد والمواساة</span></nav>
    <div class="vhead">
      <h2>الفقد والمواساة</h2>
      <p class="lead">نرافق الأسرة بعد رحيل من تحب، بالكلمة الصادقة والتعزية المشروعة، وبمساحة آمنة للحزن.</p>
      <p class="verse font-naskh text-[1.3rem]">﴿الَّذِينَ إِذَا أَصَابَتْهُم مُّصِيبَةٌ قَالُوا إِنَّا لِلَّهِ وَإِنَّا إِلَيْهِ رَاجِعُونَ﴾<span class="src block font-body">سورة البقرة، الآية ١٥٦</span></p>
    </div>
    <div class="cards">
      <div class="sess">
        <span class="ico w-11 h-11 rounded-xl grid place-items-center bg-spirit-soft text-spirit">${I.drop}</span>
        <h3>حجز جلسة مواساة</h3>
        <p class="muted">جلسة فردية أو عائلية مع مرشد مختص بالفقد، مرئية أو صوتية.</p>
        <button class="btn btn-spirit justify-self-start" type="button" id="loss-book">احجز جلسة</button>
      </div>
      <div class="sess">
        <span class="ico w-11 h-11 rounded-xl grid place-items-center bg-accent-soft text-accent">${I.send}</span>
        <h3>برنامج الفقد والمواساة</h3>
        <p class="muted">برنامج مستمر على تلجرام: رسائل تعزية، ولقاءات دورية، ومجتمع يفهم ما تمرّ به.</p>
        <a class="btn btn-primary justify-self-start" id="tg-link" href="#loss">انضم عبر تلجرام</a>
        <p class="small muted" id="tg-note">يُضاف رابط قناة البرنامج هنا.</p>
      </div>
    </div>
  </div>
</div>

<!-- ================= PROVIDER AUTH ================= -->
<div data-view="provider-auth" hidden>
  <div class="wrap pb-15">
    <nav class="crumbs"><a href="#home">الرئيسية</a><span>بوابة مقدمي الخدمة</span></nav>
    <div class="vhead"><p class="eyebrow">للمرشدين الدينيين والروحيين</p><h2>بوابة المرشدين</h2><p class="lead">ادخل إلى لوحتك، أو قدّم طلب انضمام. لا يُفعّل الحساب إلا بعد موافقة إدارة الخدمة.</p></div>
    <div class="tabs" id="pa-tabs" role="tablist">
      <button type="button" class="on" data-pa="login" role="tab">تسجيل الدخول</button>
      <button type="button" data-pa="join" role="tab">طلب انضمام</button>
    </div>
    <div class="two" data-papane="login">
      <form class="card" id="pl-form" novalidate>
        <label for="pl-email">البريد الإلكتروني<input id="pl-email" type="email" dir="ltr" autocomplete="username"></label>
        <label for="pl-pass">كلمة المرور<input id="pl-pass" type="password" dir="ltr" autocomplete="current-password"></label>
        <span class="err" id="pl-err"></span>
        <button class="btn btn-primary" type="submit">دخول</button>
      </form>
      <div class="card grid gap-3 content-start bg-surface">
        <h3>استعراض تجريبي</h3>
        <p class="small muted">ادخل بحساب معتمد جاهز لترى لوحة مقدم الخدمة.</p>
        <div class="flex flex-wrap gap-2"><button class="btn btn-spirit btn-sm" type="button" data-pvdemo="0">استعرض كمرشد</button></div>
        <p class="small muted">أو ادخل يدوياً: <span class="num">guide@latif.demo</span>، وكلمة المرور <span class="num">Latif2026</span></p>
      </div>
    </div>
    <div class="two" data-papane="join" hidden>
      <form class="card" id="pj-form" novalidate>
        <fieldset class="border-0 p-0 m-0 grid gap-2">
          <legend class="font-medium text-[.9rem] mb-1.5">نوع الحساب</legend>
          <div class="seg">
            <label><input type="radio" name="ptype" id="pt-guide" value="مرشد" checked>مرشد ديني وروحي</label>
          </div>
        </fieldset>
        <label for="pj-name">الاسم الكامل<input id="pj-name" autocomplete="name"><span class="err" id="pje-name"></span></label>
        <div class="row">
          <label for="pj-email">البريد الإلكتروني<input id="pj-email" type="email" dir="ltr" autocomplete="email"><span class="err" id="pje-email"></span></label>
          <label for="pj-phone">رقم الجوال<input id="pj-phone" inputmode="tel" dir="ltr" placeholder="05XXXXXXXX"><span class="err" id="pje-phone"></span></label>
        </div>
        <div class="row">
          <label for="pj-id">رقم الهوية أو الإقامة<input id="pj-id" inputmode="numeric" dir="ltr" maxlength="10"><span class="err" id="pje-id"></span></label>
          <label for="pj-gender">الجنس<select id="pj-gender"><option value="">اختر</option><option>ذكر</option><option>أنثى</option></select><span class="err" id="pje-gender"></span></label>
        </div>
        <label for="pj-spec">التخصص<select id="pj-spec"></select><span class="small muted" id="pj-spec-hint"></span><span class="err" id="pje-spec"></span></label>
        <div class="row">
          <label for="pj-lic"><span id="pj-lic-label">رقم التصنيف المهني</span><input id="pj-lic"><span class="err" id="pje-lic"></span></label>
          <label for="pj-years">سنوات الخبرة<input id="pj-years" type="number" min="0" max="60" inputmode="numeric"><span class="err" id="pje-years"></span></label>
        </div>
        <label for="pj-bio">نبذة مختصرة<textarea id="pj-bio" placeholder="تظهر للمرضى في صفحة القسم"></textarea></label>
        <div class="row">
          <label for="pj-pass">كلمة المرور<input id="pj-pass" type="password" dir="ltr" autocomplete="new-password"><span class="err" id="pje-pass"></span></label>
          <label for="pj-pass2">تأكيد كلمة المرور<input id="pj-pass2" type="password" dir="ltr" autocomplete="new-password"><span class="err" id="pje-pass2"></span></label>
        </div>
        <label class="flex gap-2 items-start font-normal" for="pj-agree"><input class="w-auto mt-1.5" type="checkbox" id="pj-agree">أقرّ بصحة البيانات، وأوافق على مراجعة الإدارة لمؤهلاتي قبل تفعيل الحساب.</label>
        <span class="err" id="pje-agree"></span>
        <button class="btn btn-primary" type="submit">أرسل طلب الانضمام</button>
      </form>
      <div class="card grid gap-3 content-start bg-surface">
        <h3>كيف يُفعّل حسابك؟</h3>
        <ol class="side-steps"><li>تقدّم بطلب الانضمام وتختار تخصصك.</li><li>تراجع الإدارة بياناتك ومؤهلاتك.</li><li>عند الموافقة يُفعّل حسابك وتصلك رسالة.</li><li>تظهر لك المواعيد والمرضى في لوحتك.</li></ol>
        <p class="info">كلمة المرور ٨ أحرف على الأقل، فيها حروف وأرقام.</p>
      </div>
    </div>
  </div>
</div>

<!-- ================= PROVIDER DASHBOARD ================= -->
<div data-view="provider" hidden>
  <div class="wrap pb-15">
    <nav class="crumbs"><a href="#home">الرئيسية</a><span>لوحة مقدم الخدمة</span></nav>
    <div class="py-6 max-w-[720px]" id="pv-pending" hidden></div>
    <div id="pv-dash" hidden>
      <div class="vhead"><h2 id="pv-hello"></h2><p class="lead">مواعيدك ومرضاك وإحصاءاتك. المقاييس ونماذج الختام التي ترسلها تصل إلى صفحة "سجلي" عند المريض.</p></div>
      <div class="pcard">
        <div class="who"><span class="big" id="pv-init"></span><div><b class="font-display" id="pv-name"></b><p class="small" id="pv-spec"></p></div></div>
        <dl><div><dt>حالة الحساب</dt><dd><span class="status done">مفعّل</span></dd></div><div><dt>البريد</dt><dd class="num" id="pv-email"></dd></div><div><dt>الجوال</dt><dd class="num" id="pv-phone"></dd></div><div><dt>الخبرة</dt><dd id="pv-years"></dd></div></dl>
      </div>
      <div class="kpis" id="pv-kpis"></div>
      <div class="tabs" id="pv-tabs" role="tablist">
        <button type="button" class="on" data-pv="up" role="tab">المواعيد القادمة</button>
        <button type="button" data-pv="done" role="tab">المواعيد المنجزة</button>
        <button type="button" data-pv="pts" role="tab">مرضاي</button>
        <button type="button" data-pv="inbox" role="tab">الرسائل <span id="pv-in-n" class="nbadge"></span></button>
        <button type="button" data-pv="profile" role="tab">الملف الشخصي</button>
      </div>
      <div data-pvpane="up"><div class="list" id="pv-up"></div></div>
      <div data-pvpane="done" hidden><div class="list" id="pv-done"></div></div>
      <div data-pvpane="pts" hidden><div class="pts" id="pv-pts"></div></div>
      <div data-pvpane="inbox" hidden><div class="hist" id="pv-inbox"></div></div>
      <div data-pvpane="profile" hidden>
        <div class="two">
          <form class="card" id="pp-form" novalidate>
            <h3>البيانات الشخصية والتخصص</h3>
            <label for="pp-name">الاسم الكامل<input id="pp-name"><span class="err" id="ppe-name"></span></label>
            <div class="row">
              <label for="pp-email">البريد الإلكتروني<input id="pp-email" type="email" dir="ltr"><span class="err" id="ppe-email"></span></label>
              <label for="pp-phone">رقم الجوال<input id="pp-phone" dir="ltr" inputmode="tel"><span class="err" id="ppe-phone"></span></label>
            </div>
            <div class="row">
              <label for="pp-spec">التخصص<select id="pp-spec"></select><span class="small muted" id="pp-spec-hint"></span></label>
              <label for="pp-years">سنوات الخبرة<input id="pp-years" type="number" min="0" max="60"></label>
            </div>
            <label for="pp-bio">نبذة مختصرة<textarea id="pp-bio"></textarea></label>
            <button class="btn btn-primary" type="submit">احفظ التعديلات</button>
            <div class="ok-msg" id="pp-ok" hidden></div>
          </form>
          <form class="card content-start" id="pw-form" novalidate>
            <h3>تغيير كلمة المرور</h3>
            <label for="pw-cur">كلمة المرور الحالية<input id="pw-cur" type="password" dir="ltr" autocomplete="current-password"><span class="err" id="pwe-cur"></span></label>
            <label for="pw-new">كلمة المرور الجديدة<input id="pw-new" type="password" dir="ltr" autocomplete="new-password"><span class="err" id="pwe-new"></span></label>
            <label for="pw-new2">تأكيد كلمة المرور الجديدة<input id="pw-new2" type="password" dir="ltr" autocomplete="new-password"><span class="err" id="pwe-new2"></span></label>
            <button class="btn btn-primary" type="submit">غيّر كلمة المرور</button>
            <div class="ok-msg" id="pw-ok" hidden></div>
          </form>
        </div>
      </div>
      <p class="info mt-5.5">نسخة عرض: المرضى والمواعيد تجريبية. لترى ما يصل إلى المريض، اضغط "خروج" ثم "استعراض تجريبي" كمريض، وافتح "سجلي".</p>
    </div>
  </div>
</div>

<!-- ================= ADMIN ================= -->
<div data-view="admin-login" hidden>
  <div class="wrap pb-15">
    <nav class="crumbs"><a href="#home">الرئيسية</a><span>دخول الإدارة</span></nav>
    <div class="vhead"><p class="eyebrow">الإدارة</p><h2>دخول مدير النظام</h2><p class="lead">لوحة الإدارة لها كل الصلاحيات: الأقسام والمحتوى، وحسابات مقدمي الخدمة، والإحصاءات، والرسائل.</p></div>
    <div class="two">
      <form class="card" id="al-form" novalidate>
        <label for="al-email">البريد الإلكتروني<input id="al-email" type="email" dir="ltr" autocomplete="username"></label>
        <label for="al-pass">كلمة المرور<input id="al-pass" type="password" dir="ltr" autocomplete="current-password"></label>
        <span class="err" id="al-err"></span>
        <button class="btn btn-primary" type="submit">دخول</button>
      </form>
      <div class="card grid gap-3 content-start bg-surface">
        <h3>استعراض تجريبي</h3>
        <p class="small muted">ادخل بحساب المدير التجريبي، أو استخدم: <span class="num">admin@latif.demo</span> وكلمة المرور <span class="num">Latif2026</span></p>
        <div class="flex flex-wrap gap-2"><button class="btn btn-primary btn-sm" type="button" id="al-demo">استعرض كمدير عام</button><button class="btn btn-ghost btn-sm" type="button" data-mgrdemo="spiritual">مدير قسم الدعم الروحي</button></div>
        <p class="small muted">مدير القسم يرى ويعدّل قسمه فقط: <span class="num">spiritual@latif.demo</span> بكلمة المرور نفسها.</p>
        <p class="info">في النسخة الفعلية تكون لوحة الإدارة على رابط خاص، بتحقق ثنائي، ولا تظهر في الموقع العام.</p>
      </div>
    </div>
  </div>
</div>

<div data-view="admin" hidden>
  <div class="wrap pb-15">
    <nav class="crumbs"><a href="#home">الرئيسية</a><span>لوحة الإدارة</span></nav>
    <div class="vhead"><p class="eyebrow" id="ad-eyebrow">مدير النظام</p><h2 id="ad-title">لوحة الإدارة</h2><p class="lead" id="ad-lead">كل ما تغيّره هنا يظهر فوراً في الموقع وحسابات المستفيدين ومقدمي الخدمة.</p><p class="pnote" id="ad-perms"></p></div>
    <div class="tabs" id="ad-tabs" role="tablist">
      <button type="button" class="on" data-ad-tab="ov" role="tab">نظرة عامة</button>
      <button type="button" data-ad-tab="prov" role="tab">المرشدون <span id="ad-pend-n"></span></button>
      <button type="button" data-ad-tab="depts" role="tab">الخدمات</button>
      <button type="button" data-ad-tab="content" role="tab">المحتوى</button>
      <button type="button" data-ad-tab="msgs" role="tab">الرسائل</button>
      <button type="button" data-ad-tab="mgrs" role="tab" id="ad-mgrs-tab">مدراء الأقسام</button>
    </div>
    <div data-adpane="ov">
      <div class="kpis" id="ov-kpis"></div>
      <div class="two">
        <div class="card grid gap-3 content-start"><h3>مقدمو الخدمة المعتمدون حسب التخصص</h3><div id="ov-bars" class="bars"></div></div>
        <div class="card grid gap-3 content-start"><h3>آخر النشاط</h3><div id="ov-act" class="hist"></div></div>
      </div>
    </div>
    <div data-adpane="prov" hidden>
      <div class="kpis" id="ad-kpis"></div>
      <div class="apps" id="ad-list"></div>
    </div>
    <div data-adpane="depts" hidden>
      <div hidden><div class="apps" id="ad-depts"></div></div>
      <div id="ad-sp-wrap">
      <div class="sec-h"><h3 class="mt-0">خدماتنا</h3><span class="small muted">تظهر في بوابة الدعم الروحي</span></div>
      <div class="apps" id="ad-tiles"></div>
      <form class="card mt-3.5" id="nt-form" novalidate>
        <h3>إضافة قسم أو جلسة جديدة داخل الدعم الديني والروحي</h3>
        <p class="small muted">يظهر القسم أو الجلسة فوراً بطاقةً في بوابة الدعم الروحي، بصفحة خاصة وزر حجز اختياري.</p>
        <div class="row">
          <label for="nt-title">اسم القسم<input id="nt-title" placeholder="مثال: جلسة الاستشارة الأسرية"><span class="err" id="nte-title"></span></label>
          <label for="nt-meta">عبارة مختصرة تحت البطاقة<input id="nt-meta" placeholder="مثال: جلسة فردية"></label>
        </div>
        <label for="nt-desc">وصف قصير<input id="nt-desc"><span class="err" id="nte-desc"></span></label>
        <label for="nt-body">محتوى صفحة القسم<textarea class="min-h-[120px]" id="nt-body" placeholder="فقرة في كل سطر"></textarea></label>
        <label class="flex gap-2 items-center font-normal" for="nt-book"><input class="w-auto" type="checkbox" id="nt-book" checked>إظهار زر حجز جلسة في القسم</label>
        <button class="btn btn-spirit" type="submit">أضف القسم</button>
        <div class="ok-msg" id="nt-ok" hidden></div>
      </form>
      </div>
    </div>
    <div data-adpane="content" hidden>
      <div class="two">
        <form class="card" id="nb-form" novalidate>
          <h3>إضافة إلى المدونة</h3>
          <fieldset class="fs"><legend>النوع</legend><div class="seg"><label for="nb-k1"><input type="radio" name="nb-k" id="nb-k1" value="ندوة" checked>ندوة</label><label for="nb-k2"><input type="radio" name="nb-k" id="nb-k2" value="برنامج">برنامج</label><label for="nb-k3"><input type="radio" name="nb-k" id="nb-k3" value="مقال">مقال</label></div></fieldset>
          <label for="nb-t">العنوان<input id="nb-t"><span class="err" id="nbe-t"></span></label>
          <label for="nb-by">المقدّم أو الكاتب<input id="nb-by"><span class="err" id="nbe-by"></span></label>
          <div class="row" id="nb-when"><label for="nb-d">اليوم<select id="nb-d"></select></label><label for="nb-time">الوقت<input id="nb-time" value="٨:٠٠ م"></label></div>
          <label for="nb-len"><span id="nb-len-l">المدة</span><input id="nb-len" value="ساعة واحدة"></label>
          <label for="nb-body" id="nb-body-w" hidden>نص المقال (فقرة في كل سطر)<textarea class="min-h-[120px]" id="nb-body"></textarea></label>
          <button class="btn btn-primary" type="submit">انشر في المدونة</button>
          <div class="ok-msg" id="nb-ok" hidden></div>
        </form>
        <div class="grid gap-5.5 content-start">
          <form class="card" id="nz-form" novalidate>
            <h3>إضافة مجموعة إلى زاد المريض</h3>
            <label for="nz-sec">القسم<select id="nz-sec"><option value="1">استثمار العمر</option><option value="2">زاد للسكينة</option></select></label>
            <label for="nz-t">اسم المجموعة<input id="nz-t"><span class="err" id="nze-t"></span></label>
            <label for="nz-d">الوصف<input id="nz-d"><span class="err" id="nze-d"></span></label>
            <div class="row"><label for="nz-f">التكرار<select id="nz-f"><option>يومي</option><option>أسبوعي</option><option>جلسة شهرية</option></select></label><label for="nz-s">نموذج من المحتوى<input id="nz-s"></label></div>
            <button class="btn btn-spirit" type="submit">أضف المجموعة</button>
            <div class="ok-msg" id="nz-ok" hidden></div>
          </form>
          <form class="card" id="nf-form" novalidate>
            <h3>إضافة سؤال شائع</h3>
            <label for="nf-q">السؤال<input id="nf-q"><span class="err" id="nfe-q"></span></label>
            <label for="nf-a">الإجابة<textarea id="nf-a"></textarea><span class="err" id="nfe-a"></span></label>
            <button class="btn btn-primary" type="submit">أضف السؤال</button>
            <div class="ok-msg" id="nf-ok" hidden></div>
          </form>
        </div>
      </div>
    </div>
    <div data-adpane="mgrs" hidden>
      <div class="two">
        <div class="grid gap-3 content-start"><h3>مدراء الأقسام</h3><div class="apps" id="mg-list"></div></div>
        <form class="card" id="mg-form" novalidate>
          <h3>إضافة مدير قسم</h3>
          <p class="small muted">يدخل مدير القسم من صفحة دخول الإدارة، ويرى قسمه فقط: مقدمو الخدمة فيه، وأقسامه الفرعية، وإحصاءاته، ومراسلة فريقه والمستفيدين.</p>
          <label for="mg-name">الاسم<input id="mg-name"><span class="err" id="mge-name"></span></label>
          <label for="mg-email">البريد الإلكتروني<input id="mg-email" type="email" dir="ltr"><span class="err" id="mge-email"></span></label>
          <label for="mg-sec">القسم<select id="mg-sec"><option value="spiritual">قسم الدعم الديني والروحي</option></select></label>
          <label for="mg-pass">كلمة مرور مبدئية<input id="mg-pass" type="password" dir="ltr" autocomplete="new-password"><span class="err" id="mge-pass"></span></label>
          <button class="btn btn-primary" type="submit">أضف المدير</button>
          <div class="ok-msg" id="mg-ok" hidden></div>
        </form>
      </div>
    </div>
    <div data-adpane="msgs" hidden>
      <div class="two">
        <form class="card" id="ms-form" novalidate>
          <h3>رسالة جديدة</h3>
          <label for="ms-to">إلى<select id="ms-to">
            <option value="person">شخص محدد</option>
            <option value="providers">كل مقدمي الخدمة</option>
            
            <option value="guides">كل المرشدين</option>
            <option value="patients">كل المرضى</option>
            <option value="companions">كل المرافقين</option>
            <option value="beneficiaries">كل المستفيدين (مرضى ومرافقين)</option>
            <option value="all">الجميع</option>
          </select></label>
          <label for="ms-person" id="ms-person-w">المستلم<select id="ms-person"></select><span class="err" id="mse-person"></span></label>
          <label for="ms-sub">العنوان<input id="ms-sub"><span class="err" id="mse-sub"></span></label>
          <label for="ms-body">نص الرسالة<textarea class="min-h-[120px]" id="ms-body"></textarea><span class="err" id="mse-body"></span></label>
          <button class="btn btn-primary" type="submit">أرسل الرسالة</button>
          <div class="ok-msg" id="ms-ok" hidden></div>
        </form>
        <div class="card grid gap-3 content-start"><h3>الرسائل المرسلة</h3><div class="hist" id="ms-sent"></div></div>
      </div>
    </div>
  </div>
</div>

<!-- ================= SPIRITUAL CUSTOM SECTION ================= -->
<div data-view="spcustom" hidden>
  <div class="wrap pb-15">
    <nav class="crumbs"><a href="#home">الرئيسية</a><span><a href="#spiritual">الدعم الروحي</a></span><span id="spc-crumb"></span></nav>
    <div class="vhead"><h2 id="spc-title"></h2><p class="lead" id="spc-desc"></p></div>
    <div class="card grid gap-3 max-w-[820px]" id="spc-body"></div>
  </div>
</div>

<!-- ================= RECORD ================= -->
<div data-view="record" hidden>
  <div class="wrap pb-15">
    <nav class="crumbs"><a href="#home">الرئيسية</a><span>سجلي</span></nav>
    <div class="vhead"><h2>سجلي</h2><p class="lead" id="rec-sub">حجوزاتك ومواعيدك ومقاييسك ومتابعتك الروحية في مكان واحد.</p></div>
    <div class="pcard">
      <div class="who"><span class="big" id="rec-init"></span><div><b class="font-display" id="rec-name"></b><p class="small muted" id="rec-role"></p></div></div>
      <dl><div><dt>رقم الملف</dt><dd class="num">LT-2026-0418</dd></div><div><dt>المنطقة</dt><dd id="rec-region"></dd></div><div><dt>المستشفى</dt><dd id="rec-hosp"></dd></div><div><dt>المرشد المسؤول</dt><dd>أ. عبدالرحمن البقمي</dd></div></dl>
    </div>
    <div class="kpis" id="rec-kpis"></div>
    <div id="rec-tasks"></div>
    <div class="tabs" role="tablist">
      <button type="button" class="on" data-tab="bookings" role="tab">حجوزاتي</button>
      <button type="button" data-tab="inbox" role="tab">رسائلي <span id="tab-in-n" class="nbadge"></span></button>
      <button type="button" data-tab="appts" role="tab">مواعيدي</button>
      <button type="button" data-tab="scales" role="tab">مقاييسي <span id="tab-sc-n"></span></button>
      <button type="button" data-tab="spirit" role="tab">الدعم الروحي</button>
      <button type="button" data-tab="zadsubs" role="tab">زاد المريض</button>
      <button type="button" data-tab="reports" role="tab">تقارير الجلسات</button>
    </div>
    <div data-pane="bookings"><div class="list" id="rec-bookings"></div></div>
    <div data-pane="appts" hidden><h3 class="mb-3">القادمة</h3><div class="list" id="rec-up"></div><h3 class="mt-[26px] mx-0 mb-3">السابقة</h3><div class="list" id="rec-past"></div></div>
    <div data-pane="inbox" hidden><div class="hist" id="rec-inbox"></div></div>
    <div data-pane="scales" hidden><div id="rec-scales"></div></div>
    <div data-pane="spirit" hidden><div id="rec-spirit"></div></div>
    <div data-pane="zadsubs" hidden><div id="rec-zad"></div></div>
    <div data-pane="medical" hidden><div class="mgrid" id="rec-medical"></div></div>
    <div data-pane="reports" hidden><div class="list" id="rec-reports"></div></div>
    <p class="info mt-5.5">التقارير والمواعيد بيانات تجريبية لعرض الفكرة. ولا يطّلع على سجل المستفيد إلا مرشده ومن يأذن له.</p>
  </div>
</div>

</main>

<footer class="relative border-t border-line bg-surface text-ink">
  <div class="h-1 bg-[linear-gradient(90deg,var(--spirit),var(--accent),var(--grape))]" aria-hidden="true"></div>
  <div class="wrap pt-14 pb-6">
    <div class="grid grid-cols-2 gap-x-6 gap-y-10 lg:grid-cols-[1.5fr_1fr_1fr_1.3fr] lg:gap-12">

      <!-- brand -->
      <div class="col-span-2 grid content-start gap-4 lg:col-span-1">
        <a class="flex items-center gap-3 no-underline" href="#home">
          <svg class="flex-none" width="48" height="48" viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="20" fill="var(--spirit)"/><path d="M11 32V19a9 9 0 0 1 18 0v13z" fill="#fff"/><path d="M15 32V20a5 5 0 0 1 10 0v12z" fill="var(--spirit)"/><circle cx="20" cy="23" r="2.4" fill="var(--sun)"/></svg>
          <span class="font-display text-[1.05rem] leading-snug font-bold">خدمة الدعم والمساندة<span class="block text-[.8rem] font-normal text-muted">لمرضى الرعاية التلطيفية</span></span>
        </a>
        <p class="max-w-[38ch] text-[.92rem] leading-[1.9] text-muted">خدمة متخصصة في الدعم الديني والروحي لمرضى الرعاية التلطيفية وأسرهم، عن بُعد.</p>
        <p class="flex items-start gap-2.5 rounded-xl border border-line bg-bg px-3.5 py-3 text-[.84rem] leading-[1.8]">
          <svg class="mt-1 flex-none text-spirit" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 8.5-7 10-4-1.5-7-5.5-7-10V6z"/><path d="M9 12l2 2 4-4"/></svg>
          <span>تابعة لجمعية الدعم الديني والروحي لمرضى الطب التلطيفي<span class="mt-0.5 block text-muted">ترخيص رقم <span class="num font-semibold text-ink">1000630400</span></span></span>
        </p>
        <div class="flex gap-2.5" aria-label="حسابات الجمعية">
          <a class="grid size-10 place-items-center rounded-full border border-line bg-bg text-muted transition hover:-translate-y-0.5 hover:border-wa hover:bg-wa hover:text-white" data-wa="أرغب في طلب خدمة" target="_blank" rel="noopener" title="واتساب" aria-label="واتساب"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21l1.6-4.8A8.5 8.5 0 1 1 8 19.6z"/></svg></a>
          <a class="soc-x grid size-10 place-items-center rounded-full border border-line bg-bg text-muted transition hover:-translate-y-0.5 hover:border-ink hover:bg-ink hover:text-bg" target="_blank" rel="noopener" title="إكس (تويتر)" aria-label="إكس (تويتر)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 4l16 16M20 4L4 20"/></svg></a>
          <a class="soc-tt grid size-10 place-items-center rounded-full border border-line bg-bg text-muted transition hover:-translate-y-0.5 hover:border-ink hover:bg-ink hover:text-bg" target="_blank" rel="noopener" title="تيك توك" aria-label="تيك توك"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v11.5a3.5 3.5 0 1 1-3.5-3.5"/><path d="M14 3c.5 2.5 2.5 4.5 5 4.8"/></svg></a>
        </div>
      </div>

      <!-- services -->
      <nav class="[--c:var(--mint)]" aria-label="خدماتنا">
        <h4 class="foot-h">خدماتنا</h4>
        <ul class="foot-links">
          <li><a href="#spiritual-support">جلسات الدعم الروحي</a></li>
          <li><a href="#tadabbur">جلسات التدبر</a></li>
          <li><a href="#fiqh">الاستشارة الفقهية</a></li>
          <li><a href="#wills">استشارة الوصايا</a></li>
          <li><a href="#loss">الفقد والمواساة</a></li>
        </ul>
      </nav>

      <!-- links -->
      <nav class="[--c:var(--sun)]" aria-label="روابط مهمة">
        <h4 class="foot-h">روابط مهمة</h4>
        <ul class="foot-links">
          <li><a href="#blog">المدونة</a></li>
          <li><a href="#faq">الأسئلة الشائعة</a></li>
          <li><a href="#contact">راسلنا</a></li>
          <li><a href="#ruqya">كيف ترقي نفسك</a></li>
          <li><a href="#zad">زاد المريض</a></li>
          <li><a href="{{ route('login') }}">دخول المستفيد</a></li>
          <li><a href="{{ route('staff.login') }}">دخول فريق العمل</a></li>
        </ul>
      </nav>

      <!-- request -->
      <div class="col-span-2 [--c:var(--grape)] lg:col-span-1">
        <h4 class="foot-h">اطلب الخدمة</h4>
        <div class="grid gap-3 rounded-card border border-line bg-bg p-5">
          <p class="text-[.9rem] leading-[1.8] text-muted">كل الطلبات والحجوزات والمتابعة تتم عبر واتساب، ونردّ خلال يوم عمل واحد.</p>
          <a class="btn btn-wa w-full" data-wa="أرغب في طلب خدمة من خدمة الدعم والمساندة لمرضى الرعاية التلطيفية" target="_blank" rel="noopener"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 21l1.6-4.8A8.5 8.5 0 1 1 8 19.6z"/></svg>راسلنا على واتساب</a>
          <p class="text-center text-[.8rem] text-muted">رقم واتساب الخدمة: <span class="num wa-num"></span></p>
        </div>
        <form id="news-form" hidden><input id="news-email"></form><p id="news-msg" hidden></p>
      </div>
    </div>

    <div class="mt-12 flex flex-col gap-2 border-t border-line pt-6 text-center text-[.8rem] leading-[1.8] text-muted lg:flex-row lg:items-center lg:justify-between lg:text-start">
      <span>© ٢٠٢٦ خدمة الدعم والمساندة لمرضى الرعاية التلطيفية. جميع الحقوق محفوظة.</span>
      <span class="lg:max-w-[60ch] lg:text-end">المحتوى التوعوي لا يغني عن سؤال أهل العلم والطبيب المختص. أسماء المرشدين والمواعيد في هذه النسخة تجريبية.</span>
    </div>
  </div>
</footer>

<nav class="mbar" aria-label="تنقل سريع">
  <a href="#home" data-nav="home"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 11l9-7 9 7v9H3z"/></svg>الرئيسية</a>
  <a href="#services" data-nav="services"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></svg>خدماتنا</a>
  <a href="#spiritual" data-nav="spiritual" class="sp"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 21V11a7 7 0 0 1 14 0v10z"/></svg>الدعم الروحي</a>
  <a href="#blog" data-nav="blog"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v16H4zM8 9h8M8 13h8M8 17h5"/></svg>المدونة</a>
  <a href="#record" data-nav="record" hidden><span id="mb-rec">سجلي</span></a><a class="wa" data-wa="أرغب في طلب خدمة من خدمة الدعم والمساندة لمرضى الرعاية التلطيفية" target="_blank" rel="noopener"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21l1.6-4.8A8.5 8.5 0 1 1 8 19.6z"/></svg>واتساب</a>
</nav>

<div class="overlay" id="doc-modal" hidden>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="d-title">
    <header><h3 id="d-title"></h3><button class="x" type="button" id="d-close" aria-label="إغلاق">×</button></header>
    <div class="doc-body" id="d-body"></div>
  </div>
</div>

<div class="overlay" id="modal" hidden>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="m-title">
    <header><h3 id="m-title"></h3><button class="x" type="button" id="m-close" aria-label="إغلاق">×</button></header>
    <form class="grid gap-3.5" id="m-form" novalidate>
      <fieldset class="border-0 p-0 m-0" id="m-typebox">
        <legend class="font-medium text-[.9rem] mb-2">نوع الطلب</legend>
        <div class="seg">
          <label><input type="radio" name="mtype" id="mt-s" value="جلسة">جلسة</label>
          <label><input type="radio" name="mtype" id="mt-c" value="استشارة">استشارة</label>
        </div>
      </fieldset>
      <label for="m-staff">المختص<select id="m-staff"></select></label>
      <div class="row">
        <label for="m-day">اليوم<select id="m-day"></select></label>
        <label for="m-time">الوقت<select id="m-time"></select></label>
      </div>
      <fieldset class="border-0 p-0 m-0">
        <legend class="font-medium text-[.9rem] mb-2">طريقة الجلسة</legend>
        <div class="seg">
          <label><input type="radio" name="mmode" id="mm-v" value="مرئية" checked>مرئية</label>
          <label><input type="radio" name="mmode" id="mm-a" value="صوتية">صوتية</label>
        </div>
      </fieldset>
      <label for="m-notes">ما الذي تودّ أن يعرفه المختص؟<textarea id="m-notes" placeholder="اختياري"></textarea></label>
      <button class="btn btn-primary" type="submit" id="m-submit">أكّد الطلب</button>
    </form>
    <div class="ok-msg" id="m-ok" hidden></div>
  </div>
</div>
</div>
</body>
</html>
