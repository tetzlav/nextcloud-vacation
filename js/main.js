document.addEventListener('DOMContentLoaded', () => {
    const yearSelect = document.getElementById('vacation-year');
    if (yearSelect instanceof HTMLSelectElement) {
        yearSelect.addEventListener('change', () => {
            yearSelect.form?.requestSubmit();
        });
    }

    document.querySelectorAll('[data-copy-hash]').forEach((element) => {
        if (!(element instanceof HTMLButtonElement)) {
            return;
        }

        element.addEventListener('click', async () => {
            const hash = element.dataset.copyHash ?? '';
            if (!/^[a-f0-9]{64}$/.test(hash) || !navigator.clipboard) {
                return;
            }

            try {
                await navigator.clipboard.writeText(hash);
                const originalLabel = element.textContent ?? '';
                element.textContent = element.dataset.copiedLabel ?? originalLabel;
                window.setTimeout(() => {
                    element.textContent = originalLabel;
                }, 1600);
            } catch {
                // The browser keeps the hash selectable if clipboard access is denied.
            }
        });
    });
});
