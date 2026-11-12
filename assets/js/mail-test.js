const testForm = document.getElementById('cf7-smtp-test');

if (testForm) {
    const result = document.getElementById('cf7-smtp-test-result');
    const submitButton = document.getElementById('cf7-smtp-test-submit');
    const defaultLabel = submitButton ? submitButton.textContent : '';

    testForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!result || !submitButton) {
            return;
        }

        const recipient = new FormData(testForm).get('to');
        result.className = 'cf7-smtp-test-result';
        result.textContent = 'Envoi en cours…';
        submitButton.disabled = true;

        try {
            const response = await fetch(testForm.dataset.restUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': testForm.dataset.nonce || '',
                },
                body: JSON.stringify({ to: recipient }),
            });

            const payload = await response.json();
            const message = payload && payload.message
                ? payload.message
                : 'L’envoi a échoué.';

            result.textContent = message;
            result.classList.add(response.ok ? 'is-success' : 'is-error');
        } catch (error) {
            result.textContent = 'Impossible de contacter le site.';
            result.classList.add('is-error');
        } finally {
            submitButton.disabled = false;
            submitButton.textContent = defaultLabel;
        }
    });
}
