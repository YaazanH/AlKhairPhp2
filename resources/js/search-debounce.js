export const SEARCH_DEBOUNCE_MS = 500;

export function debounceSearch(callback) {
    let timer = null;
    let latestArgs;
    let latestContext;

    const cancel = () => {
        clearTimeout(timer);
        timer = null;
        latestArgs = undefined;
        latestContext = undefined;
    };

    const flush = () => {
        if (timer === null) return;
        const args = latestArgs;
        const context = latestContext;
        cancel();
        callback.apply(context, args);
    };

    function schedule(...args) {
        cancel();
        latestArgs = args;
        latestContext = this;
        timer = setTimeout(flush, SEARCH_DEBOUNCE_MS);
    }

    schedule.cancel = cancel;
    schedule.flush = flush;
    return schedule;
}
