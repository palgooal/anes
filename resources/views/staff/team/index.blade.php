@php
    $statusLabels = [
        'pending' => ['بانتظار الموافقة', 'wait'],
        'active' => ['مفعّل', 'ok'],
        'suspended' => ['موقوف', 'cx'],
    ];
    $filters = [
        '' => ['الكل', array_sum($counts)],
        'pending' => ['بانتظار الموافقة', $counts['pending'] ?? 0],
        'active' => ['مفعّل', $counts['active'] ?? 0],
        'suspended' => ['موقوف', $counts['suspended'] ?? 0],
    ];
    $toArabic = fn (int|string $n): string => strtr((string) $n, ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩']);
@endphp

<x-staff-layout title="الفريق" subtitle="اعتماد طلبات الانضمام، وإضافة الأعضاء، وإيقاف الحسابات" active="team" :pending-count="$counts['pending'] ?? 0">
  <div class="two items-start">

    {{-- ============ MEMBERS ============ --}}
    <section class="grid gap-3.5" aria-labelledby="members-title">
      <h2 id="members-title" class="sr-only">أعضاء الفريق</h2>

      <div class="grid gap-3">
        <nav class="filter-links" aria-label="تصفية حسب الحالة">
          @foreach ($filters as $key => [$label, $count])
            <a href="{{ route('staff.team', array_filter(['status' => $key, 'q' => $search])) }}" @class(['on' => ($status?->value ?? '') === $key])>
              {{ $label }} <b>{{ $toArabic($count) }}</b>
            </a>
          @endforeach
        </nav>
        <form method="GET" action="{{ route('staff.team') }}" class="search" role="search">
          @if ($status)
            <input type="hidden" name="status" value="{{ $status->value }}">
          @endif
          <label for="q" class="sr-only">ابحث في الفريق</label>
          <input id="q" name="q" type="search" value="{{ $search }}" placeholder="ابحث بالاسم أو البريد أو الجوال…">
          <button class="btn btn-g btn-s" type="submit">بحث</button>
        </form>
      </div>

      <div class="list">
        @forelse ($members as $member)
          @php
              [$statusLabel, $statusPill] = $statusLabels[$member->status->value];
              $isMe = $member->is(auth()->user());
          @endphp
          <article class="item" style="--c: {{ $member->role->accentColor() }}" aria-labelledby="m{{ $member->id }}">
            <div class="col-span-full grid gap-1">
              <div class="sec-h">
                <h4 id="m{{ $member->id }}">{{ $member->name }} <span class="pill">{{ $member->role->label() }}</span>@if ($isMe) <span class="pill role">أنت</span>@endif</h4>
                <span class="pill {{ $statusPill }} no-underline">{{ $statusLabel }}</span>
              </div>
              <p><span class="num">{{ $member->email }}</span> · <span class="num">{{ $member->phone ? \App\PhoneNumber::format($member->phone) : '—' }}</span> · انضم {{ $member->created_at->locale('ar')->diffForHumans() }}</p>
              @if ($member->status === \App\AccountStatus::Pending && $member->qualifications)
                <div class="quote mt-1.5"><b class="mb-1 block text-[.82rem] text-muted">المؤهلات والخبرة</b>{{ $member->qualifications }}</div>
              @endif
            </div>

            @unless ($isMe)
              <div class="acts">
                @switch($member->status)
                  @case(\App\AccountStatus::Pending)
                    <form method="POST" action="{{ route('staff.team.status', $member) }}">
                      @csrf @method('PATCH')
                      <input type="hidden" name="status" value="active">
                      <button class="btn btn-p btn-s" type="submit">موافقة وتفعيل</button>
                    </form>
                    <form method="POST" action="{{ route('staff.team.destroy', $member) }}" data-confirm="رفض طلب انضمام {{ $member->name }} وحذفه؟">
                      @csrf @method('DELETE')
                      <button class="btn btn-g btn-s" type="submit">رفض الطلب</button>
                    </form>
                    @break
                  @case(\App\AccountStatus::Active)
                    <form method="POST" action="{{ route('staff.team.status', $member) }}" data-confirm="إيقاف حساب {{ $member->name }}؟ لن يستطيع الدخول حتى يُعاد تفعيله.">
                      @csrf @method('PATCH')
                      <input type="hidden" name="status" value="suspended">
                      <button class="btn btn-g btn-s" type="submit">إيقاف</button>
                    </form>
                    @break
                  @case(\App\AccountStatus::Suspended)
                    <form method="POST" action="{{ route('staff.team.status', $member) }}">
                      @csrf @method('PATCH')
                      <input type="hidden" name="status" value="active">
                      <button class="btn btn-p btn-s" type="submit">إعادة التفعيل</button>
                    </form>
                    @break
                @endswitch
              </div>
            @endunless
          </article>
        @empty
          <p class="empty">
            @if ($search !== '')
              لا توجد نتائج لـ «{{ $search }}».
            @elseif ($status === \App\AccountStatus::Pending)
              لا توجد طلبات انضمام بانتظار الموافقة.
            @else
              لا يوجد أعضاء في هذه القائمة.
            @endif
          </p>
        @endforelse
      </div>

      @if ($members->hasPages())
        <nav class="pager" aria-label="الصفحات">
          @if ($members->previousPageUrl())
            <a class="btn btn-g btn-s" href="{{ $members->previousPageUrl() }}">→ السابق</a>
          @endif
          <span class="small muted">صفحة {{ $toArabic($members->currentPage()) }} من {{ $toArabic($members->lastPage()) }}</span>
          @if ($members->nextPageUrl())
            <a class="btn btn-g btn-s" href="{{ $members->nextPageUrl() }}">التالي ←</a>
          @endif
        </nav>
      @endif
    </section>

    {{-- ============ ADD MEMBER ============ --}}
    <form method="POST" action="{{ route('staff.team.store') }}" class="card grid content-start gap-3" novalidate aria-labelledby="add-title">
      @csrf
      <h2 id="add-title" class="text-[1.15rem]">إضافة عضو إلى الفريق</h2>
      <p class="small muted">يُفعّل الحساب مباشرة، وتصل العضو رسالة على بريده لتعيين كلمة المرور.</p>

      <label for="name">الاسم
        <input id="name" name="name" value="{{ old('name') }}" autocomplete="off" required @error('name') aria-invalid="true" @enderror>
        <span class="err">@error('name'){{ $message }}@enderror</span>
      </label>
      <label for="role">الدور
        <select id="role" name="role" required>
          @foreach ($roles as $role)
            <option value="{{ $role->value }}" @selected(old('role', \App\UserRole::Guide->value) === $role->value)>{{ $role->label() }}</option>
          @endforeach
        </select>
        <span class="err">@error('role'){{ $message }}@enderror</span>
      </label>
      <label for="email">البريد الإلكتروني
        <input id="email" name="email" type="email" dir="ltr" value="{{ old('email') }}" autocomplete="off" required @error('email') aria-invalid="true" @enderror>
        <span class="err">@error('email'){{ $message }}@enderror</span>
      </label>
      <div class="grid gap-[5px]">
        <label for="phone" class="text-[.88rem] font-medium">رقم الجوال (يصله رمز الدخول على واتساب)</label>
        <div class="flex gap-2" dir="ltr">
          @if (count($countries) > 1)
            <select name="country" class="w-auto flex-none" aria-label="الدولة">
              @foreach ($countries as $code)
                <option value="{{ $code }}" @selected(old('country', $countries[0]) === $code)>{{ \App\PhoneNumber::COUNTRIES[$code]['flag'] }} +{{ $code }}</option>
              @endforeach
            </select>
          @else
            <input type="hidden" name="country" value="{{ $countries[0] }}">
          @endif
          <input id="phone" name="phone" class="min-w-0 flex-1" inputmode="tel" placeholder="05XXXXXXXX" value="{{ old('phone') }}" autocomplete="off" required @error('phone') aria-invalid="true" @enderror>
        </div>
        <span class="err">@error('phone'){{ $message }}@enderror</span>
      </div>
      <button class="btn btn-p justify-self-start" type="submit">أضف وأرسل الدعوة</button>
    </form>
  </div>
</x-staff-layout>
