{{-- Keep error pages independent of the asset build and database-backed layouts. --}}
<style>
    @font-face {
        font-family: 'DubaiApp2026';
        src: url('{{ asset('fonts/dubai/Dubai-Regular.woff2') }}') format('woff2');
        font-weight: 400;
        font-display: swap;
    }

    @font-face {
        font-family: 'DubaiApp2026';
        src: url('{{ asset('fonts/dubai/Dubai-Medium.woff2') }}') format('woff2');
        font-weight: 500;
        font-display: swap;
    }

    @font-face {
        font-family: 'DubaiApp2026';
        src: url('{{ asset('fonts/dubai/Dubai-Bold.woff2') }}') format('woff2');
        font-weight: 700;
        font-display: swap;
    }

    :root {
        color-scheme: light;
        --error-background: #f6f7f1;
        --error-text: #123326;
        --error-muted: #5f7564;
        --error-focus: #126d3b;
    }

    @media (prefers-color-scheme: dark) {
        :root:not([data-appearance='light']) {
            color-scheme: dark;
            --error-background: #101a14;
            --error-text: #eef8f0;
            --error-muted: #accdb5;
            --error-focus: #75d89c;
        }
    }

    :root[data-appearance='dark'] {
        color-scheme: dark;
        --error-background: #101a14;
        --error-text: #eef8f0;
        --error-muted: #accdb5;
        --error-focus: #75d89c;
    }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        background: var(--error-background);
        color: var(--error-text);
        font-family: 'DubaiApp2026', Tahoma, system-ui, sans-serif;
        -webkit-font-smoothing: antialiased;
    }

    .error-page {
        display: grid;
        min-height: 100vh;
        min-height: 100svh;
        place-items: center;
        padding: 2rem 1.5rem 5rem;
        text-align: center;
    }

    .error-page__content {
        width: 100%;
        max-width: 36rem;
    }

    .error-page__label {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        margin: 0 0 0.5rem;
        color: var(--error-muted);
        font-family: Tahoma, system-ui, sans-serif;
        font-size: 0.8125rem;
        font-weight: 700;
        letter-spacing: 0.38em;
        line-height: 1;
    }

    .error-page__label::before,
    .error-page__label::after {
        width: 2.25rem;
        height: 1px;
        background: currentColor;
        content: '';
        opacity: 0.45;
    }

    .error-page__code {
        margin: 0 0 1rem;
        font-size: clamp(7rem, 18vw, 8.5rem);
        font-weight: 700;
        line-height: 1;
    }

    .error-page__title {
        margin: 0 0 1.25rem;
        color: var(--error-muted);
        font-size: clamp(1.375rem, 3vw, 1.625rem);
        font-weight: 400;
        line-height: 1.4;
        overflow-wrap: anywhere;
        text-wrap: balance;
    }

    .error-page__detail {
        margin: 0 auto 1.25rem;
        max-width: 30rem;
        color: var(--error-muted);
        font-size: 1rem;
        line-height: 1.6;
        overflow-wrap: anywhere;
    }

    .error-page__home {
        display: inline-flex;
        min-height: 2.75rem;
        align-items: center;
        justify-content: center;
        padding: 0.5rem 1.125rem;
        border-radius: 0.625rem;
        background: linear-gradient(135deg, #14723e, #0b4f2a);
        color: #fff;
        font-size: 1.0625rem;
        font-weight: 500;
        line-height: 1.5;
        text-decoration: none;
    }

    .error-page__home:hover { background: #0b4f2a; }

    .error-page__home:focus-visible {
        outline: 3px solid var(--error-focus);
        outline-offset: 4px;
    }
</style>
