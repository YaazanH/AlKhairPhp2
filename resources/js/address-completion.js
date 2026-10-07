export function addressKey(text) {
    return text.replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي')
        .replace(/[\u064B-\u065Fـ]/g, '').replace(/\s+/g, ' ').trim();
}

export function addressCompletion(query, place) {
    if (!query.trim() || !place) return '';
    // Map results use canonical separators; users need not type those exactly.
    const completionKey = text => addressKey(text.replace(/\s*[-–—]\s*/g, ' '));
    const key = completionKey(query);
    const value = completionKey(place.value);
    const start = value.indexOf(key);
    if (start < 0 || (start > 0 && value[start - 1] !== ' ')) return '';
    // Find the original-text boundary, preserving Arabic shaping and spelling.
    let end = 0;
    while (end < place.value.length && completionKey(place.value.slice(0, end)).length < start + key.length) end++;
    const suffix = place.value.slice(end).replace(/^\s+/, query.endsWith(' ') ? '' : ' ');
    const remainder = /[-–—]\s*$/.test(query) ? suffix.replace(/^\s*[-–—]\s*/, query.endsWith(' ') ? '' : ' ') : suffix;
    return remainder.trim() ? remainder : '';
}

export function addressInput({ value, places, endpoint }) {
    return {
        query: value, places, remote: [], active: 0, focused: false, dismissed: false,
        timer: null, controller: null, generation: 0, atEnd: true,
        get matches() {
            const words = addressKey(this.query).replace(/[-–—]/g, ' ').split(' ').filter(Boolean);
            if (!words.length) return [];
            const local = this.places.filter(place => words.every(word => addressKey(place.value + ' ' + place.aliases.join(' ')).includes(word)));
            const unique = [...local, ...this.remote].filter((place, index, all) => all.findIndex(p => p.value === place.value) === index);
            return unique.filter(place => addressCompletion(this.query, place));
        },
        get selected() { return this.matches[this.active] || this.matches[0]; },
        get suffix() { return this.focused && !this.dismissed && this.atEnd ? addressCompletion(this.query, this.selected) : ''; },
        input(event) {
            this.query = event.target.value; this.active = 0; this.dismissed = false; this.remote = [];
            this.caret();
            clearTimeout(this.timer); this.controller?.abort();
            const generation = ++this.generation;
            if (this.query.trim().length < 3 || this.query.length > 200) return;
            this.timer = setTimeout(() => this.lookup(this.query, generation), 650);
        },
        async lookup(query, generation) {
            const controller = new AbortController(); this.controller = controller;
            try {
                const url = new URL(endpoint, window.location.origin); url.searchParams.set('q', query.trim());
                const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'application/json' } });
                if (!response.ok) return;
                const data = await response.json();
                if (generation === this.generation && this.query === query) this.remote = data.suggestions || [];
            } catch { /* Free-text entry and local completion remain usable offline. */ }
        },
        caret() {
            const input = this.$refs.input;
            this.atEnd = input.selectionStart === input.value.length && input.selectionEnd === input.value.length && Math.abs(input.scrollLeft) < 1;
        },
        cycle(direction) {
            this.dismissed = false;
            if (this.matches.length) this.active = (this.active + direction + this.matches.length) % this.matches.length;
        },
        accept(event) {
            if (!this.suffix || !this.selected) return;
            event?.preventDefault();
            const value = this.selected.value;
            this.query = value.split(' - ').length < 3 ? value + ' - ' : value;
            clearTimeout(this.timer); this.controller?.abort(); ++this.generation;
            this.$refs.input.value = this.query;
            this.$refs.input.dispatchEvent(new Event('input', { bubbles: true }));
            clearTimeout(this.timer); this.controller?.abort(); ++this.generation;
            this.dismissed = true;
            this.$refs.input.focus();
            this.$refs.input.setSelectionRange(this.query.length, this.query.length);
        },
        destroy() { clearTimeout(this.timer); this.controller?.abort(); ++this.generation; },
    };
}
