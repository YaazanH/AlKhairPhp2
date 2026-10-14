<script>
    (() => {
        const workspace = document.querySelector('[data-content-workspace]');
        if (!workspace) return;
        let dirty = false;
        const draft = workspace.querySelector('[data-content-draft]');
        draft?.addEventListener('input', event => {
            if (!event.target.name) return;
            dirty = true;
            const status = workspace.querySelector('[data-draft-status]');
            if (status) status.textContent = status.dataset.unsavedMessage;
        });
        draft?.addEventListener('change', event => { if (event.target.name) dirty = true; });
        draft?.addEventListener('invalid', event => {
            let panel = event.target.closest('details');
            while (panel) { panel.open = true; panel = panel.parentElement.closest('details'); }
        }, true);
        draft?.addEventListener('submit', () => { dirty = false; });
        window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
        workspace.querySelectorAll('a[href^="#"]').forEach(link => link.addEventListener('click', () => {
            const target = document.getElementById(link.hash.slice(1));
            if (target?.tagName === 'DETAILS') target.open = true;
        }));
        const revealHash = () => {
            const target = document.getElementById(location.hash.slice(1));
            if (target?.tagName === 'DETAILS') target.open = true;
        };
        window.addEventListener('hashchange', revealHash);
        revealHash();
        workspace.querySelectorAll('[data-field-search]').forEach(search => search.addEventListener('input', () => {
            const query = search.value.trim().toLocaleLowerCase();
            workspace.querySelectorAll('[data-searchable-field]').forEach(field => { field.hidden = !field.textContent.toLocaleLowerCase().includes(query); });
        }));
    })();
</script>
