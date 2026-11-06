<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/views/layout.php';

$me = require_login();

page_start((string)cfg('app_name'), $me, 'board-page');
?>
<main id="app" class="app" data-csrf="<?= e(csrf_token()) ?>" data-me="<?= (int)$me['id'] ?>">

  <form id="quick-add" class="quick-add" autocomplete="off">
    <div class="quick-add-main">
      <input id="qa-title" class="quick-add-input" maxlength="200" required enterkeyhint="send"
             placeholder="Neue Idee …" aria-label="Neue Idee">
      <button type="submit" class="btn btn-primary quick-add-btn" aria-label="Hinzufügen">
        <span class="quick-add-plus" aria-hidden="true">+</span><span class="quick-add-label">Hinzufügen</span>
      </button>
    </div>
    <div class="quick-add-row">
      <span class="muted small">Für:</span>
      <div id="qa-users" class="chips" role="group" aria-label="Zuständig"></div>
    </div>
  </form>

  <div class="toolbar">
    <div id="filter" class="chips" role="group" aria-label="Filter"></div>
  </div>

  <nav id="tabs" class="tabs" aria-label="Spalten"></nav>

  <div class="board" id="board">
    <section class="column" data-status="idea">
      <h2 class="column-head"><span class="dot dot-idea"></span>Ideen <span class="count"></span></h2>
      <div class="cards" data-status="idea"></div>
    </section>
    <section class="column" data-status="doing">
      <h2 class="column-head"><span class="dot dot-doing"></span>In Bearbeitung <span class="count"></span></h2>
      <div class="cards" data-status="doing"></div>
    </section>
    <section class="column" data-status="done">
      <h2 class="column-head"><span class="dot dot-done"></span>Erledigt <span class="count"></span></h2>
      <div class="cards" data-status="done"></div>
    </section>
  </div>
</main>

<dialog id="task-dialog" class="dialog" aria-labelledby="dlg-heading">
  <form id="task-form" method="dialog" class="stack">
    <h2 id="dlg-heading">Aufgabe</h2>
    <label>Titel
      <input name="title" required maxlength="200">
    </label>
    <label>Beschreibung
      <textarea name="description" rows="4" maxlength="5000" placeholder="Details, Links, Notizen …"></textarea>
    </label>
    <div class="field">
      <span class="field-label">Zuständig</span>
      <div id="dlg-users" class="chips"></div>
    </div>
    <div class="field">
      <span class="field-label">Status</span>
      <div class="segmented" id="dlg-status">
        <label><input type="radio" name="status" value="idea"><span>Idee</span></label>
        <label><input type="radio" name="status" value="doing"><span>In Bearbeitung</span></label>
        <label><input type="radio" name="status" value="done"><span>Erledigt</span></label>
      </div>
    </div>
    <div class="field">
      <span class="field-label">Farbe</span>
      <div id="dlg-colors" class="swatches" role="radiogroup"></div>
    </div>
    <p class="meta muted small" id="dlg-meta"></p>
    <div class="dialog-actions">
      <button type="button" class="btn btn-danger btn-ghost" id="dlg-delete">Löschen</button>
      <span class="spacer"></span>
      <button type="button" class="btn" id="dlg-cancel">Abbrechen</button>
      <button type="submit" class="btn btn-primary">Speichern</button>
    </div>
  </form>
</dialog>

<div id="toast" class="toast" role="status" aria-live="polite"></div>
<?php
page_end(['app.js']);
