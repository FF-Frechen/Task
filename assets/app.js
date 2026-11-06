/* Task – Board-Logik. Kein Framework, keine Abhängigkeiten. */
(() => {
  'use strict';

  const app = document.getElementById('app');
  if (!app) return;

  const CSRF = app.dataset.csrf;
  const ME = Number(app.dataset.me);
  const STATUS = ['idea', 'doing', 'done'];
  const STATUS_LABEL = { idea: 'Ideen', doing: 'In Bearbeitung', done: 'Erledigt' };
  const STATUS_SINGLE = { idea: 'Idee', doing: 'In Bearbeitung', done: 'Erledigt' };
  const TAB_LABEL = { idea: 'Ideen', doing: 'In Arbeit', done: 'Erledigt' };
  const COLORS = {
    red: 'Rot', orange: 'Orange', yellow: 'Gelb', green: 'Grün', teal: 'Türkis',
    blue: 'Blau', purple: 'Lila', pink: 'Pink', gray: 'Grau',
  };
  const NEXT = {
    idea: { to: 'doing', label: 'Starten', icon: '▶' },
    doing: { to: 'done', label: 'Erledigt', icon: '✓' },
    done: { to: 'doing', label: 'Wieder öffnen', icon: '↺' },
  };
  const EMPTY = {
    idea: 'Noch keine Ideen. Einfach oben eintippen.',
    doing: 'Hier landet, woran gerade gearbeitet wird.',
    done: 'Noch nichts erledigt.',
  };
  const DONE_LIMIT = 20;
  const POLL_MS = 15000;

  const board = document.getElementById('board');
  const tabs = document.getElementById('tabs');
  const filterBar = document.getElementById('filter');
  const quickForm = document.getElementById('quick-add');
  const quickTitle = document.getElementById('qa-title');
  const quickUsers = document.getElementById('qa-users');
  const dlg = document.getElementById('task-dialog');
  const form = document.getElementById('task-form');
  const dlgUsers = document.getElementById('dlg-users');
  const dlgColors = document.getElementById('dlg-colors');
  const dlgMeta = document.getElementById('dlg-meta');
  const dlgFiles = document.getElementById('dlg-files');
  const fileInput = document.getElementById('dlg-file-input');
  const dlgStorage = document.getElementById('dlg-storage');
  const uploadBox = document.getElementById('dlg-upload');
  const uploadBar = document.getElementById('dlg-upload-bar');
  const uploadLabel = document.getElementById('dlg-upload-label');
  const toastEl = document.getElementById('toast');

  let state = { users: [], tasks: [] };
  let usersById = new Map();
  let filter = store('filter', 'all'); // 'all' | 'none' | '<userId>'
  let tab = store('tab', 'idea');
  let showAllDone = false;
  let dragging = null;
  let busy = 0;
  let editingId = null;
  const quickAssignees = new Set();
  const dlgAssignees = new Set();

  /* ---------------------------------------------------------------- */
  /* Hilfsfunktionen                                                   */
  /* ---------------------------------------------------------------- */

  function store(key, fallback, value) {
    try {
      if (value === undefined) return localStorage.getItem('task.' + key) ?? fallback;
      localStorage.setItem('task.' + key, value);
    } catch (_) { /* privater Modus o. Ä. */ }
    return fallback;
  }

  /** Erzeugt ein DOM-Element. Texte werden immer als Text (nie als HTML) eingefügt. */
  function h(tag, attrs, ...children) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k === 'dataset') Object.assign(el.dataset, v);
      else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
      else el.setAttribute(k, v === true ? '' : String(v));
    }
    for (const c of children.flat()) {
      if (c === null || c === undefined || c === false) continue;
      el.append(c instanceof Node ? c : document.createTextNode(String(c)));
    }
    return el;
  }

  function initials(name) {
    return Array.from(name).slice(0, 2).join('').toUpperCase();
  }

  function avatar(user) {
    return h('span', { class: 'avatar k-' + user.color, title: user.username }, initials(user.username));
  }

  const dateTime = new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium', timeStyle: 'short' });
  const dayMonth = new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit' });
  const fmt = (iso) => (iso ? dateTime.format(new Date(iso)) : '');

  let toastTimer = null;
  function toast(message, isError = false) {
    // Ein offener Dialog liegt über allem – die Meldung muss dann in den Dialog.
    const host = dlg.open ? dlg : document.body;
    if (toastEl.parentNode !== host) host.append(toastEl);
    toastEl.textContent = message;
    toastEl.classList.toggle('toast-error', isError);
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toastEl.classList.remove('show'), isError ? 5000 : 2500);
  }

  /* ---------------------------------------------------------------- */
  /* Server                                                            */
  /* ---------------------------------------------------------------- */

  async function api(action, body, params) {
    const opts = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CSRF-Token'] = CSRF;
      opts.body = JSON.stringify(body);
    }
    let res;
    try {
      const query = params ? '&' + new URLSearchParams(params).toString() : '';
      res = await fetch('api.php?action=' + encodeURIComponent(action) + query, opts);
    } catch (_) {
      throw new Error('Keine Verbindung zum Server.');
    }
    if (res.status === 401) {
      location.href = 'login.php';
      throw new Error('Bitte neu anmelden.');
    }
    let data = null;
    try { data = await res.json(); } catch (_) { /* kein JSON */ }
    if (!res.ok) throw new Error((data && data.error) || 'Fehler ' + res.status);
    return data;
  }

  function setState(data) {
    state = { users: data.users, tasks: data.tasks };
    usersById = new Map(state.users.map((u) => [u.id, u]));
    if (filter !== 'all' && filter !== 'none' && !usersById.has(Number(filter))) filter = 'all';
    for (const id of [...quickAssignees]) if (!usersById.has(id)) quickAssignees.delete(id);
    render();
  }

  async function refresh() {
    if (dragging || busy) return;
    try {
      setState(await api('state'));
    } catch (_) { /* beim Hintergrund-Abgleich still bleiben */ }
  }

  async function mutate(action, body, okMessage) {
    busy++;
    let data;
    try {
      data = await api(action, body);
    } catch (err) {
      busy--;
      toast(err.message, true);
      refresh();
      return null;
    }
    busy--;
    setState(data);
    if (okMessage) toast(okMessage);
    return data;
  }

  /* ---------------------------------------------------------------- */
  /* Darstellung                                                       */
  /* ---------------------------------------------------------------- */

  function visible(task) {
    if (filter === 'all') return true;
    if (filter === 'none') return task.assignees.length === 0;
    return task.assignees.includes(Number(filter));
  }

  function render() {
    renderQuickUsers();
    renderFilter();

    const groups = { idea: [], doing: [], done: [] };
    for (const t of state.tasks) if (visible(t)) groups[t.status].push(t);

    for (const s of STATUS) {
      const column = board.querySelector(`.column[data-status="${s}"]`);
      column.querySelector('.count').textContent = String(groups[s].length);
      column.classList.toggle('active', tab === s);

      let items = groups[s];
      let hidden = 0;
      if (s === 'done' && !showAllDone && items.length > DONE_LIMIT) {
        hidden = items.length - DONE_LIMIT;
        items = items.slice(0, DONE_LIMIT);
      }
      const children = items.map(card);
      children.push(h('p', { class: 'empty' }, filter === 'all' ? EMPTY[s] : 'Nichts für diesen Filter.'));
      if (hidden) {
        children.push(h('button', {
          type: 'button', class: 'btn btn-ghost btn-block more',
          onclick: () => { showAllDone = true; render(); },
        }, `${hidden} ältere anzeigen`));
      }
      column.querySelector('.cards').replaceChildren(...children);
    }

    tabs.replaceChildren(...STATUS.map((s) => h('button', {
      type: 'button',
      class: 'tab' + (tab === s ? ' active' : ''),
      'aria-pressed': tab === s ? 'true' : 'false',
      onclick: () => { tab = s; store('tab', '', s); render(); },
    }, TAB_LABEL[s], h('span', { class: 'count' }, String(groups[s].length)))));
  }

  function card(t) {
    const assignees = t.assignees.map((id) => usersById.get(id)).filter(Boolean);
    const next = NEXT[t.status];
    const el = h('article', {
      class: 'card' + (t.color ? ' has-color k-' + t.color : ''),
      draggable: 'true',
      tabindex: '0',
      role: 'button',
      'aria-label': `${t.title} – bearbeiten`,
      dataset: { id: String(t.id) },
    },
      h('div', { class: 'card-title' }, t.title),
      t.description ? h('div', { class: 'card-desc' }, t.description) : null,
      h('div', { class: 'card-foot' },
        h('div', { class: 'avatars' },
          assignees.length ? assignees.map(avatar) : h('span', { class: 'unassigned' }, 'Niemand zugeteilt')),
        t.status === 'done' && t.doneAt
          ? h('time', { class: 'card-date', datetime: t.doneAt, title: 'Erledigt am ' + fmt(t.doneAt) },
            dayMonth.format(new Date(t.doneAt)))
          : null,
        t.fileCount
          ? h('span', { class: 'card-files', title: `${t.fileCount} ${t.fileCount === 1 ? 'Datei' : 'Dateien'}` },
            h('span', { 'aria-hidden': 'true' }, '📎'), String(t.fileCount))
          : null,
        h('button', {
          type: 'button',
          class: 'card-move to-' + next.to,
          title: next.label,
          'aria-label': `${next.label}: ${t.title}`,
          onclick: (ev) => { ev.stopPropagation(); quickMove(t, next.to); },
        }, h('span', { 'aria-hidden': 'true' }, next.icon), h('span', { class: 'card-move-label' }, next.label)),
      ),
    );
    el.addEventListener('click', () => openDialog(t.id));
    el.addEventListener('keydown', (ev) => {
      if ((ev.key === 'Enter' || ev.key === ' ') && ev.target === el) {
        ev.preventDefault();
        openDialog(t.id);
      }
    });
    el.addEventListener('dragstart', onDragStart);
    el.addEventListener('dragend', onDragEnd);
    return el;
  }

  function userChip(user, pressed, onToggle, extra) {
    return h('button', {
      type: 'button',
      class: 'chip' + (pressed ? ' on' : ''),
      'aria-pressed': pressed ? 'true' : 'false',
      onclick: onToggle,
    }, avatar(user), h('span', null, user.username), extra ?? null);
  }

  function renderQuickUsers() {
    quickUsers.replaceChildren(...state.users.map((u) => userChip(u, quickAssignees.has(u.id), () => {
      if (quickAssignees.has(u.id)) quickAssignees.delete(u.id); else quickAssignees.add(u.id);
      renderQuickUsers();
    })));
  }

  function renderFilter() {
    const open = state.tasks.filter((t) => t.status !== 'done');
    const opt = (key, label, count, user) => {
      const on = filter === key;
      const onclick = () => { filter = key; store('filter', '', key); render(); };
      const badge = h('span', { class: 'chip-count' }, String(count));
      if (user) return userChip(user, on, onclick, badge);
      return h('button', { type: 'button', class: 'chip' + (on ? ' on' : ''), 'aria-pressed': on ? 'true' : 'false', onclick },
        h('span', null, label), badge);
    };
    filterBar.replaceChildren(
      h('span', { class: 'muted small' }, 'Zeigen:'),
      opt('all', 'Alle', open.length),
      ...state.users.map((u) => opt(String(u.id), u.username,
        open.filter((t) => t.assignees.includes(u.id)).length, u)),
      opt('none', 'Niemand zugeteilt', open.filter((t) => t.assignees.length === 0).length),
    );
  }

  /* ---------------------------------------------------------------- */
  /* Aktionen                                                          */
  /* ---------------------------------------------------------------- */

  quickForm.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const title = quickTitle.value.trim();
    if (!title) return;
    const assignees = [...quickAssignees];
    quickTitle.value = '';
    quickAssignees.clear();
    tab = 'idea';
    store('tab', '', tab);
    const data = await mutate('create', { title, assignees }, 'Idee hinzugefügt');
    if (!data) {
      quickTitle.value = title;
      assignees.forEach((id) => quickAssignees.add(id));
      renderQuickUsers();
    }
    quickTitle.focus();
  });

  function quickMove(task, to) {
    mutate('update', { id: task.id, status: to }, `„${task.title}“ → ${STATUS_SINGLE[to]}`);
  }

  /* ---------------------------------------------------------------- */
  /* Bearbeiten-Dialog                                                 */
  /* ---------------------------------------------------------------- */

  dlgColors.replaceChildren(
    h('label', { class: 'swatch swatch-none', title: 'Keine Farbe' },
      h('input', { type: 'radio', name: 'color', value: '', 'aria-label': 'Keine Farbe' }), h('span')),
    ...Object.entries(COLORS).map(([key, label]) => h('label', { class: 'swatch k-' + key, title: label },
      h('input', { type: 'radio', name: 'color', value: key, 'aria-label': label }), h('span'))),
  );

  function field(name) {
    return form.elements.namedItem(name);
  }

  function renderDialogUsers() {
    dlgUsers.replaceChildren(...state.users.map((u) => userChip(u, dlgAssignees.has(u.id), () => {
      if (dlgAssignees.has(u.id)) dlgAssignees.delete(u.id); else dlgAssignees.add(u.id);
      renderDialogUsers();
    })));
  }

  function openDialog(id) {
    const t = state.tasks.find((x) => x.id === id);
    if (!t) return;
    editingId = id;
    field('title').value = t.title;
    field('description').value = t.description;
    for (const r of form.querySelectorAll('input[name="status"]')) r.checked = r.value === t.status;
    for (const r of form.querySelectorAll('input[name="color"]')) r.checked = r.value === (t.color || '');
    dlgAssignees.clear();
    t.assignees.forEach((uid) => dlgAssignees.add(uid));
    renderDialogUsers();

    const creator = t.createdBy !== null ? usersById.get(t.createdBy) : null;
    const meta = [`Angelegt ${creator ? 'von ' + creator.username + ' ' : ''}am ${fmt(t.createdAt)}`];
    if (t.doneAt) meta.push(`erledigt am ${fmt(t.doneAt)}`);
    dlgMeta.textContent = meta.join(' · ');

    showFiles(id, null);
    loadFiles(id);

    dlg.showModal();
    if (!matchMedia('(pointer: coarse)').matches) field('title').focus();
  }

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (editingId === null) return;
    const checked = (name) => form.querySelector(`input[name="${name}"]:checked`);
    const payload = {
      id: editingId,
      title: field('title').value,
      description: field('description').value,
      assignees: [...dlgAssignees],
      color: checked('color') ? checked('color').value : '',
      status: checked('status') ? checked('status').value : 'idea',
    };
    if (await mutate('update', payload, 'Gespeichert')) dlg.close();
  });

  document.getElementById('dlg-cancel').addEventListener('click', () => dlg.close());

  document.getElementById('dlg-delete').addEventListener('click', async () => {
    const t = state.tasks.find((x) => x.id === editingId);
    const extra = t && t.fileCount ? `\n\n${t.fileCount === 1 ? 'Die angehängte Datei wird' : `Die ${t.fileCount} angehängten Dateien werden`} ebenfalls gelöscht.` : '';
    if (!t || !confirm(`„${t.title}“ wirklich löschen?${extra}`)) return;
    if (await mutate('delete', { id: t.id }, 'Gelöscht')) dlg.close();
  });

  // Klick auf den abgedunkelten Hintergrund schließt den Dialog.
  dlg.addEventListener('click', (ev) => { if (ev.target === dlg) dlg.close(); });
  dlg.addEventListener('close', () => { editingId = null; });

  /* ---------------------------------------------------------------- */
  /* Dateianhänge                                                      */
  /* ---------------------------------------------------------------- */

  let storage = null;     // { used, limit, maxFile } in Bytes
  let filesTaskId = null; // Aufgabe, deren Dateien gerade im Dialog stehen

  function formatBytes(bytes) {
    const units = ['Bytes', 'KB', 'MB', 'GB'];
    let i = 0;
    let v = bytes;
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
    return v.toLocaleString('de-DE', { maximumFractionDigits: i >= 2 ? 1 : 0 }) + ' ' + units[i];
  }

  function fileIcon(mime) {
    if (mime.startsWith('image/')) return '🖼️';
    if (mime.startsWith('video/')) return '🎬';
    if (mime.startsWith('audio/')) return '🎵';
    if (mime === 'application/pdf') return '📄';
    return '📎';
  }

  function showFiles(taskId, files) {
    filesTaskId = taskId;
    if (files === null) {
      dlgFiles.replaceChildren();
      dlgStorage.textContent = '';
      return;
    }
    dlgFiles.replaceChildren(...files.map((f) => {
      const href = 'file.php?id=' + f.id;
      return h('div', { class: 'file' },
        h('a', { class: 'file-link', href, target: '_blank', rel: 'noopener', title: f.name },
          f.thumb
            ? h('img', { class: 'file-thumb', src: href + '&thumb=1', alt: '', loading: 'lazy' })
            : h('span', { class: 'file-thumb file-icon', 'aria-hidden': 'true' }, fileIcon(f.mime)),
          h('span', { class: 'file-name' }, f.name),
          h('span', { class: 'file-size' }, formatBytes(f.size))),
        h('button', {
          type: 'button', class: 'file-del', title: 'Datei löschen', 'aria-label': 'Datei löschen: ' + f.name,
          onclick: () => deleteFile(f),
        }, '×'));
    }));
    renderStorage();
  }

  function renderStorage() {
    dlgStorage.textContent = storage
      ? `${formatBytes(storage.used)} von ${formatBytes(storage.limit)} belegt`
      : '';
  }

  function applyFiles(taskId, data) {
    if (data.storage) storage = data.storage;
    if (editingId === taskId && data.files) showFiles(taskId, data.files);
    else renderStorage();
  }

  async function loadFiles(taskId) {
    try {
      applyFiles(taskId, await api('files', undefined, { task: taskId }));
    } catch (err) {
      if (editingId === taskId) toast(err.message, true);
    }
  }

  async function deleteFile(f) {
    if (!confirm(`Datei „${f.name}“ löschen?`)) return;
    const taskId = filesTaskId;
    const data = await mutate('delete_file', { id: f.id }, 'Datei gelöscht');
    if (data) applyFiles(taskId, data);
  }

  /** Kleines JPEG-Vorschaubild im Browser erzeugen (berücksichtigt die Drehung von Handyfotos). */
  function makeThumb(file) {
    if (!/^image\/(jpeg|png|gif|webp|avif)$/.test(file.type)) return Promise.resolve(null);
    return new Promise((resolve) => {
      const url = URL.createObjectURL(file);
      const img = new Image();
      img.onload = () => {
        const scale = Math.min(1, 480 / Math.max(img.naturalWidth, img.naturalHeight));
        const w = Math.max(1, Math.round(img.naturalWidth * scale));
        const hgt = Math.max(1, Math.round(img.naturalHeight * scale));
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = hgt;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, w, hgt);
        ctx.drawImage(img, 0, 0, w, hgt);
        URL.revokeObjectURL(url);
        canvas.toBlob((blob) => resolve(blob), 'image/jpeg', 0.8);
      };
      img.onerror = () => { URL.revokeObjectURL(url); resolve(null); };
      img.src = url;
    });
  }

  function setProgress(label, fraction) {
    uploadBox.hidden = false;
    uploadLabel.textContent = label;
    uploadBar.style.width = Math.round(fraction * 100) + '%';
  }

  /** Lädt eine Datei hoch. Ergebnis: null bei Erfolg, sonst die Fehlermeldung. */
  function uploadOne(taskId, file, label) {
    return new Promise(async (resolve) => {
      const fd = new FormData();
      fd.append('task', String(taskId));
      fd.append('file', file, file.name);
      const thumb = await makeThumb(file).catch(() => null);
      if (thumb) fd.append('thumb', thumb, 'thumb.jpg');

      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'api.php?action=upload');
      xhr.setRequestHeader('X-CSRF-Token', CSRF);
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.onprogress = (ev) => { if (ev.lengthComputable) setProgress(label, ev.loaded / ev.total); };
      xhr.onload = () => {
        let data = null;
        try { data = JSON.parse(xhr.responseText); } catch (_) { /* kein JSON */ }
        if (xhr.status === 401) { location.href = 'login.php'; return resolve('Bitte neu anmelden.'); }
        if (xhr.status >= 200 && xhr.status < 300 && data) {
          setState(data);
          applyFiles(taskId, data);
          return resolve(null);
        }
        resolve((data && data.error)
          || (xhr.status === 413 ? 'Die Datei ist zu groß.' : 'Upload fehlgeschlagen (Fehler ' + xhr.status + ').'));
      };
      xhr.onerror = () => resolve('Keine Verbindung – Upload fehlgeschlagen.');
      setProgress(label, 0);
      xhr.send(fd);
    });
  }

  fileInput.addEventListener('change', async () => {
    const files = [...fileInput.files];
    fileInput.value = '';
    const taskId = filesTaskId;
    if (!files.length || taskId === null) return;
    if (!storage) await loadFiles(taskId);

    let done = 0;
    let error = null;
    for (const [i, file] of files.entries()) {
      const label = files.length > 1 ? `${i + 1}/${files.length}: ${file.name}` : file.name;
      if (storage && file.size > storage.maxFile) {
        error = `„${file.name}“ ist zu groß (max. ${formatBytes(storage.maxFile)} pro Datei).`;
        continue;
      }
      if (storage && storage.used + file.size > storage.limit) {
        error = `Speicher voll: Das Limit von ${formatBytes(storage.limit)} ist erreicht. `
          + 'Bitte zuerst alte Dateien oder Aufgaben löschen.';
        break;
      }
      busy++;
      const failed = await uploadOne(taskId, file, label);
      busy--;
      if (failed) { error = failed; break; }
      done++;
    }
    uploadBox.hidden = true;
    const success = done === 1 ? '1 Datei hochgeladen.' : `${done} Dateien hochgeladen.`;
    if (error) toast((done ? success + ' ' : '') + error, true);
    else if (done) toast(success);
  });

  /* ---------------------------------------------------------------- */
  /* Drag & Drop (Maus). Auf dem Handy: Knopf auf der Karte / Dialog.   */
  /* ---------------------------------------------------------------- */

  function onDragStart(ev) {
    const el = ev.currentTarget;
    dragging = { id: Number(el.dataset.id), el };
    ev.dataTransfer.effectAllowed = 'move';
    ev.dataTransfer.setData('text/plain', el.dataset.id);
    requestAnimationFrame(() => el.classList.add('dragging'));
  }

  function onDragEnd(ev) {
    ev.currentTarget.classList.remove('dragging');
    board.querySelectorAll('.drop-target').forEach((x) => x.classList.remove('drop-target'));
    if (dragging) { // ohne gültiges Ziel losgelassen → alten Zustand wiederherstellen
      dragging = null;
      render();
    }
  }

  function cardAfter(list, y) {
    for (const c of list.querySelectorAll('.card:not(.dragging)')) {
      const r = c.getBoundingClientRect();
      if (y < r.top + r.height / 2) return c;
    }
    return list.querySelector('.empty');
  }

  for (const column of board.querySelectorAll('.column')) {
    const list = column.querySelector('.cards');
    column.addEventListener('dragover', (ev) => {
      if (!dragging) return;
      ev.preventDefault();
      ev.dataTransfer.dropEffect = 'move';
      list.classList.add('drop-target');
      const after = cardAfter(list, ev.clientY);
      if (after !== dragging.el && after?.previousElementSibling !== dragging.el) {
        list.insertBefore(dragging.el, after);
      }
    });
    column.addEventListener('dragleave', (ev) => {
      if (!column.contains(ev.relatedTarget)) list.classList.remove('drop-target');
    });
    column.addEventListener('drop', (ev) => {
      if (!dragging) return;
      ev.preventDefault();
      const { id } = dragging;
      dragging = null;
      list.classList.remove('drop-target');
      const status = list.dataset.status;
      const order = [...list.querySelectorAll('.card')].map((c) => Number(c.dataset.id));
      const task = state.tasks.find((t) => t.id === id);
      const msg = task && task.status !== status ? `„${task.title}“ → ${STATUS_SINGLE[status]}` : null;
      mutate('move', { id, status, order }, msg);
    });
  }

  /* ---------------------------------------------------------------- */
  /* Handy: nach links/rechts wischen wechselt die Spalte              */
  /* ---------------------------------------------------------------- */

  let touch = null;
  board.addEventListener('touchstart', (ev) => {
    if (ev.touches.length !== 1) { touch = null; return; }
    touch = { x: ev.touches[0].clientX, y: ev.touches[0].clientY, t: Date.now() };
  }, { passive: true });
  board.addEventListener('touchend', (ev) => {
    if (!touch || !matchMedia('(max-width: 760px)').matches) return;
    const dx = ev.changedTouches[0].clientX - touch.x;
    const dy = ev.changedTouches[0].clientY - touch.y;
    const quick = Date.now() - touch.t < 600;
    touch = null;
    if (!quick || Math.abs(dx) < 70 || Math.abs(dx) < Math.abs(dy) * 2) return;
    const i = STATUS.indexOf(tab) + (dx < 0 ? 1 : -1);
    if (i < 0 || i >= STATUS.length) return;
    tab = STATUS[i];
    store('tab', '', tab);
    render();
  }, { passive: true });

  /* ---------------------------------------------------------------- */
  /* Start & automatische Aktualisierung                               */
  /* ---------------------------------------------------------------- */

  if (!STATUS.includes(tab)) tab = 'idea';
  refresh();
  setInterval(() => { if (document.visibilityState === 'visible') refresh(); }, POLL_MS);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') refresh();
  });
})();
