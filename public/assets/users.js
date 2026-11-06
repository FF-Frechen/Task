/* Sicherheitsabfrage für Formulare mit class="js-confirm" (z. B. Benutzer löschen). */
document.addEventListener('submit', (ev) => {
  const form = ev.target;
  if (form instanceof HTMLFormElement && form.classList.contains('js-confirm')) {
    if (!confirm(form.dataset.confirm || 'Wirklich?')) ev.preventDefault();
  }
});
