const uriField = document.getElementById('cf7-smtp-redirect-uri');
const copyButton = document.getElementById('cf7-smtp-copy-uri');
const disconnectLink = document.getElementById('cf7-smtp-disconnect');

if (copyButton && uriField) {
    const defaultLabel = copyButton.textContent;

    copyButton.addEventListener('click', async () => {
        let copied = false;

        try {
            await navigator.clipboard.writeText(uriField.value);
            copied = true;
        } catch (error) {
            uriField.focus();
            uriField.select();
            copied = document.execCommand('copy');
        }

        if (!copied) {
            return;
        }

        copyButton.textContent = 'Copié';
        window.setTimeout(() => {
            copyButton.textContent = defaultLabel;
        }, 2000);
    });
}

if (disconnectLink) {
    disconnectLink.addEventListener('click', (event) => {
        const confirmed = window.confirm('Déconnecter ce compte Google ?');
        if (!confirmed) {
            event.preventDefault();
        }
    });
}
