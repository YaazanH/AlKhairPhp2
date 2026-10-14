@props(['content', 'path', 'label', 'textarea' => false, 'readonly' => false])
<div class="grid gap-3 md:grid-cols-2">
    @foreach(['en' => 'English', 'ar' => 'العربية'] as $locale => $language)
        <label class="grid gap-1 text-sm font-medium text-zinc-700">
            <span>{{ $label }} · {{ $language }}</span>
            @if($textarea)
                <textarea name="content[{{ str_replace('.', '][', $path) }}][{{ $locale }}]" rows="3" maxlength="1500" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}" class="rounded-xl border border-zinc-300 bg-white px-3 py-2" @readonly($readonly)>{{ old('content.'.$path.'.'.$locale, data_get($content, $path.'.'.$locale)) }}</textarea>
            @else
                <input name="content[{{ str_replace('.', '][', $path) }}][{{ $locale }}]" value="{{ old('content.'.$path.'.'.$locale, data_get($content, $path.'.'.$locale)) }}" maxlength="1500" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}" class="rounded-xl border border-zinc-300 bg-white px-3 py-2" @readonly($readonly)>
            @endif
        </label>
    @endforeach
</div>
