@props(['action', 'seconds' => 0])

{{-- "Request a new code" with a countdown; works without JavaScript once the wait is over. --}}
<form method="POST" action="{{ $action }}" class="text-center text-[.88rem] text-muted" aria-live="polite">
  @csrf
  @if (session('status'))
    <p class="mb-1 font-medium text-ok">{{ session('status') }}</p>
  @endif
  <span id="resend-wait" @if ($seconds === 0) hidden @endif>يمكنك طلب رمز جديد بعد
    <b class="font-semibold text-ink" data-countdown data-seconds="{{ $seconds }}" data-hide="#resend-wait" data-reveal="#resend-btn"></b>
  </span>
  <button type="submit" class="link" id="resend-btn" @if ($seconds > 0) hidden @endif>أعد إرسال الرمز</button>
</form>
