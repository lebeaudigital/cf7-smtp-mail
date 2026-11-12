const form = document.getElementById('cf7-smtp-settings');

if (form) {
    const modeInputs = form.querySelectorAll('input[name="mode"]');
    const panels = form.querySelectorAll('[data-smtp-panel]');
    const preset = document.getElementById('cf7-smtp-preset');
    const host = document.getElementById('cf7-smtp-host');
    const port = document.getElementById('cf7-smtp-port');
    const encryption = document.getElementById('cf7-smtp-encryption');

    const showMode = (mode) => {
        panels.forEach((panel) => {
            panel.hidden = panel.getAttribute('data-smtp-panel') !== mode;
        });

        form.querySelectorAll('.cf7-smtp-mode').forEach((card) => {
            card.classList.toggle('is-active', card.getAttribute('data-mode') === mode);
        });
    };

    modeInputs.forEach((input) => {
        input.addEventListener('change', () => {
            if (input.checked) {
                showMode(input.value);
            }
        });
    });

    const checkedMode = form.querySelector('input[name="mode"]:checked');
    if (checkedMode) {
        showMode(checkedMode.value);
    }

    const syncPreset = () => {
        if (!preset || !host || !port || !encryption) {
            return;
        }

        const selected = preset.selectedOptions[0];
        if (!selected || selected.value === 'custom') {
            return;
        }

        const matches = selected.dataset.host === host.value
            && String(selected.dataset.port) === String(port.value)
            && selected.dataset.encryption === encryption.value;

        if (!matches) {
            preset.value = 'custom';
        }
    };

    if (preset && host && port && encryption) {
        preset.addEventListener('change', () => {
            const selected = preset.selectedOptions[0];
            if (!selected || selected.value === 'custom') {
                return;
            }

            host.value = selected.dataset.host || '';
            port.value = selected.dataset.port || '587';
            encryption.value = selected.dataset.encryption || 'tls';
        });

        [host, port, encryption].forEach((field) => {
            field.addEventListener('input', syncPreset);
        });
    }
}
