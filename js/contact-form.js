(() => {
  const form = document.getElementById('contactForm');
  if (!form) return;

  const button = document.getElementById('submitButton');
  const status = document.getElementById('formStatus');
  if (!button || !status) return;

  const originalButtonLabel = 'value' in button ? button.value : button.textContent;
  const setButtonLabel = (label) => {
    if ('value' in button) {
      button.value = label;
    } else {
      button.textContent = label;
    }
  };

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (button.disabled) return;

    status.textContent = '';
    status.className = 'form-status';

    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    button.disabled = true;
    form.setAttribute('aria-busy', 'true');
    setButtonLabel('Wird gesendet …');

    try {
      const response = await fetch('/send.php', {
        method: 'POST',
        body: new FormData(form),
        headers: {
          'Accept': 'application/json'
        }
      });

      let data = null;
      try {
        data = await response.json();
      } catch (_) {
        // Falls der Server unerwartet kein JSON liefert.
      }

      if (!response.ok || data?.success !== true) {
        throw new Error(data?.message || 'Die Nachricht konnte nicht versendet werden.');
      }

      status.textContent = data.message || 'Vielen Dank! Deine Nachricht wurde erfolgreich versendet.';
      status.classList.add('success');
      form.reset();
    } catch (error) {
      status.textContent =
        (error instanceof Error && error.message) ||
        'Beim Versand ist ein Fehler aufgetreten. Bitte versuche es später erneut.';
      status.classList.add('error');
    } finally {
      button.disabled = false;
      form.removeAttribute('aria-busy');
      setButtonLabel(originalButtonLabel);
    }
  });
})();
