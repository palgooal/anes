@props(['label' => 'رقم الجوال', 'autofocus' => false, 'large' => false])

@php
    $countries = \App\PhoneNumber::allowedCountries();
    $selected = old('country', \App\PhoneNumber::defaultCountry());
@endphp

{{-- Phone number with a country picker (shown only when more than one country is allowed). --}}
<div class="field">
  <label for="phone">{{ $label }}</label>
  <div class="flex gap-2" dir="ltr">
    @if (count($countries) > 1)
      <label for="country" class="sr-only">الدولة</label>
      <select id="country" name="country" class="input w-auto flex-none ps-3 pe-8 font-display" aria-label="الدولة">
        @foreach ($countries as $code)
          <option value="{{ $code }}" @selected($selected === $code)>{{ \App\PhoneNumber::COUNTRIES[$code]['flag'] }} +{{ $code }}</option>
        @endforeach
      </select>
    @else
      <input type="hidden" name="country" value="{{ $countries[0] }}">
    @endif
    <input @class(['input min-w-0 flex-1', 'text-[1.25rem] tracking-wider' => $large]) id="phone" name="phone" value="{{ old('phone') }}"
           inputmode="tel" autocomplete="tel-national" placeholder="05XXXXXXXX" maxlength="18" required
           @if ($autofocus) autofocus @endif @error('phone') aria-invalid="true" @enderror>
  </div>
  <span class="err" role="alert">@error('phone'){{ $message }}@enderror</span>
</div>
