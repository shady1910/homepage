(() => {
  const form = document.getElementById('contactForm');
  if (!form) return;

  const button = document.getElementById('submitButton');
  const status = document.getElementById('formStatus');
  if (!button || !status) return;

  const kidsCount = document.getElementById('number_kids');
  const childrenAges = document.getElementById('childrenAges');
  const updateChildrenAges = () => {
    const count = kidsCount.valueAsNumber;
    const target = Number.isInteger(count) && count >= 0 && count <= 99 ? count : 0;
    while (childrenAges.children.length > target) {
      childrenAges.lastElementChild.remove();
    }
    while (childrenAges.children.length < target) {
      const number = childrenAges.children.length + 1;
      const column = document.createElement('div');
      column.className = 'col-sm-6';
      column.innerHTML = `
        <div class="form-floating mb-4">
          <input class="form-control" type="number" id="child_age_${number}"
            name="children_ages[]" min="0" max="17" step="1" placeholder="Alter" required>
          <label for="child_age_${number}">Alter Kind ${number} (Jahre, bei Anreise)</label>
        </div>`;
      childrenAges.append(column);
    }
  };
  if (kidsCount && childrenAges) {
    kidsCount.addEventListener('input', updateChildrenAges);
    kidsCount.addEventListener('change', updateChildrenAges);
    form.addEventListener('reset', () => {
      childrenAges.replaceChildren();
      setTimeout(updateChildrenAges, 0);
    });
    updateChildrenAges();
  }

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

    if (!form.availabilityCalendar || !form.availabilityCalendar.validate()) {
      status.textContent = 'Bitte wählen Sie einen verfügbaren Reisezeitraum im Kalender.';
      status.classList.add('error');
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
        if (response.status === 409 || response.status === 503) {
          await form.availabilityCalendar.reload();
        }
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
