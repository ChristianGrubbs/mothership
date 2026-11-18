// The Home calendar widget (docs/features/event-calendar.md): events and campaigns in a month grid and an agenda,
// and a dialog per entry for its tasks, assignees and linked assets. One instance per Home page; home.js hands it
// the card on every paint. Times are wall-clock strings ("2026-11-12T17:00") and are never zone-converted.
(() => {
    'use strict';
    const ROLES = ['Shooting', 'Designing', 'Video', 'Writing', 'Social post'];
    const SOURCE = {manual: 'Added here'};
    const FILTERS = [['all', 'All'], ['mine', 'Mine'], ['open', 'Needs someone']];
    const pad = number => String(number).padStart(2, '0');
    const ymd = date => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    const dayOf = stamp => String(stamp || '').slice(0, 10);
    const at = stamp => { const [d, t = '00:00'] = String(stamp).split('T'); const [y, m, day] = d.split('-').map(Number); const [h, min] = t.split(':').map(Number); return new Date(y, m - 1, day, h || 0, min || 0); };
    const time = stamp => at(stamp).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'});
    const dayLabel = (stamp, options = {weekday: 'short', month: 'short', day: 'numeric'}) => at(stamp).toLocaleDateString([], options);

    function when(event) {
        const start = dayLabel(event.starts_at);
        const endDay = event.ends_at ? dayOf(event.ends_at) : dayOf(event.starts_at);
        if (event.all_day) return endDay !== dayOf(event.starts_at) ? `${start} – ${dayLabel(event.ends_at)}` : `${start} · all day`;
        if (endDay !== dayOf(event.starts_at)) return `${start}, ${time(event.starts_at)} – ${dayLabel(event.ends_at)}, ${time(event.ends_at)}`;
        return `${start} · ${time(event.starts_at)}${event.ends_at ? ` – ${time(event.ends_at)}` : ''}`;
    }

    function create({root, request, esc}) {
        const state = {month: new Date(new Date().getFullYear(), new Date().getMonth(), 1), selected: ymd(new Date()), filter: 'all', events: [], loaded: null, error: null, people: null, card: null, detail: null, picker: null};
        const today = () => ymd(new Date());
        const me = () => Number(root.dataset.user);

        // Six weeks from the Sunday on or before the 1st: what the grid shows and what one load reads.
        const gridStart = () => { const first = new Date(state.month); first.setDate(1 - first.getDay()); return first; };
        const gridDays = () => Array.from({length: 42}, (_, index) => { const day = gridStart(); day.setDate(day.getDate() + index); return day; });

        async function load() {
            const from = gridStart(); const to = gridStart(); to.setDate(to.getDate() + 42);
            const key = ymd(from);
            try {
                const data = await request(`/calendar/events?from=${ymd(from)}&to=${ymd(to)}`);
                if (key !== ymd(gridStart())) return;
                state.events = data.events; state.loaded = key; state.error = null;
            } catch (error) { state.error = error.message; }
            paint();
        }

        const visible = event => state.filter === 'all' || (state.filter === 'mine' ? event.mine > 0 : state.filter === 'open' ? event.unassigned > 0 : event.source === state.filter);
        // A long-running entry (an exhibit, a registration window) sits on its first day; a campaign shows on every day of its run.
        const onDay = (event, day) => dayOf(event.starts_at) === day || (event.kind === 'campaign' && event.ends_at && dayOf(event.starts_at) < day && dayOf(event.ends_at) >= day);
        const eventsOn = day => state.events.filter(event => visible(event) && onDay(event, day));
        const tone = event => event.kind === 'campaign' ? 'campaign' : event.source;
        const badges = event => [event.tasks ? `<span title="Tasks done">${event.tasks_done}/${event.tasks} ✓</span>` : '', event.unassigned ? `<span class="is-open" title="Tasks nobody has taken">${event.unassigned} open</span>` : '', event.assets ? `<span title="Linked assets">${event.assets} ◫</span>` : ''].join('');

        function paint() {
            const card = state.card;
            if (!card?.isConnected) return;
            const body = card.querySelector('.agh-card-body');
            const days = gridDays();
            const month = state.month.getMonth();
            const title = state.month.toLocaleDateString([], {month: 'long', year: 'numeric'});
            const cells = days.map(day => {
                const key = ymd(day); const list = eventsOn(key);
                return `<button type="button" class="agh-cal-day${day.getMonth() !== month ? ' is-out' : ''}${key === today() ? ' is-today' : ''}${key === state.selected ? ' is-selected' : ''}" data-cal-day="${key}" aria-label="${esc(day.toLocaleDateString([], {weekday: 'long', month: 'long', day: 'numeric'}))}, ${list.length} ${list.length === 1 ? 'entry' : 'entries'}"><span class="agh-cal-num">${day.getDate()}</span>${list.slice(0, 2).map(event => `<span class="agh-cal-pill" data-tone="${tone(event)}"${event.status !== 'confirmed' ? ' data-off' : ''}>${esc(event.title)}</span>`).join('')}${list.length > 2 ? `<span class="agh-cal-more">+${list.length - 2} more</span>` : ''}</button>`;
            }).join('');
            const selected = eventsOn(state.selected);
            const upcoming = state.events.filter(event => visible(event) && dayOf(event.starts_at) > state.selected).slice(0, 8);
            const row = event => `<li><button type="button" class="agh-cal-row" data-cal-open="${event.id}"${event.status !== 'confirmed' ? ' data-off' : ''}><span class="agh-cal-dot" data-tone="${tone(event)}"></span><span class="agh-cal-row-text"><strong>${esc(event.title)}</strong><small>${esc(when(event))}${event.location ? ` · ${esc(event.location)}` : ''}${event.status === 'cancelled' ? ' · Cancelled' : ''}</small></span><span class="agh-cal-badges">${badges(event)}</span></button></li>`;
            body.innerHTML = `<div class="agh-cal">
                <div class="agh-cal-bar"><div class="agh-cal-nav"><button class="agh-button" data-cal-step="-1" aria-label="Previous month">‹</button><strong aria-live="polite">${esc(title)}</strong><button class="agh-button" data-cal-step="1" aria-label="Next month">›</button><button class="agh-button" data-cal-today>Today</button></div>
                <div class="agh-cal-filters" role="group" aria-label="Show">${FILTERS.map(([id, label]) => `<button class="agh-trend-day" data-cal-filter="${id}" aria-pressed="${state.filter === id}">${label}</button>`).join('')}</div>
                <button class="agh-button agh-primary" data-cal-add>Add event</button></div>
                ${state.error ? `<p class="agh-signal-state is-offline">${esc(state.error)}</p>` : ''}
                <div class="agh-cal-main"><div class="agh-cal-grid" role="group" aria-label="${esc(title)}">${['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(name => `<span class="agh-cal-dow">${name}</span>`).join('')}${cells}</div>
                <div class="agh-cal-side"><h3>${esc(dayLabel(state.selected, {weekday: 'long', month: 'long', day: 'numeric'}))}</h3>${selected.length ? `<ul class="agh-cal-list">${selected.map(row).join('')}</ul>` : `<p class="agh-caption">${state.loaded ? 'Nothing on the calendar this day.' : 'Loading the calendar…'}</p>`}
                ${upcoming.length ? `<h3>Coming up</h3><ul class="agh-cal-list">${upcoming.map(row).join('')}</ul>` : ''}</div></div></div>`;
            body.querySelectorAll('[data-cal-step]').forEach(button => button.addEventListener('click', () => { state.month = new Date(state.month.getFullYear(), state.month.getMonth() + Number(button.dataset.calStep), 1); state.selected = ymd(state.month); state.events = []; state.loaded = null; paint(); load(); }));
            body.querySelector('[data-cal-today]').addEventListener('click', () => { const now = new Date(); const same = now.getMonth() === state.month.getMonth() && now.getFullYear() === state.month.getFullYear(); state.month = new Date(now.getFullYear(), now.getMonth(), 1); state.selected = today(); if (same) paint(); else { state.events = []; state.loaded = null; paint(); load(); } });
            body.querySelectorAll('[data-cal-filter]').forEach(button => button.addEventListener('click', () => { state.filter = button.dataset.calFilter; paint(); }));
            body.querySelectorAll('[data-cal-day]').forEach(button => button.addEventListener('click', () => { state.selected = button.dataset.calDay; paint(); state.card.querySelector(`[data-cal-day="${state.selected}"]`)?.focus(); }));
            body.querySelectorAll('[data-cal-open]').forEach(button => button.addEventListener('click', () => openEvent(Number(button.dataset.calOpen))));
            body.querySelector('[data-cal-add]').addEventListener('click', () => editEvent(null));
        }

        // ---- The entry dialog: tasks, people and assets. ----
        function dialog() {
            let element = root.querySelector('[data-cal-dialog]');
            if (!element) {
                element = document.createElement('dialog'); element.className = 'agh-settings agh-cal-dialog'; element.dataset.calDialog = ''; root.append(element);
                element.addEventListener('close', () => { state.detail = null; state.picker = null; load(); });
            }
            return element;
        }
        const failure = (element, message) => { const slot = element.querySelector('[data-cal-error]'); if (slot) { slot.textContent = message; slot.hidden = !message; } };
        // Every change answers the entry's full detail, so the dialog repaints from the server's truth.
        async function change(path, options) {
            const element = dialog();
            try { state.detail = await request(path, options); paintEvent(); return true; }
            catch (error) { failure(element, error.message); return false; }
        }
        async function openEvent(id) {
            const element = dialog();
            element.innerHTML = '<p class="agh-caption">Loading…</p>';
            if (!element.open) element.showModal();
            try {
                state.people ??= (await request('/calendar/people')).people;
                state.detail = await request(`/calendar/events/${id}`);
                paintEvent();
            } catch (error) { element.innerHTML = `<form method="dialog"><p class="agh-signal-state is-offline">${esc(error.message)}</p><div class="agh-actions"><button class="agh-button">Close</button></div></form>`; }
        }

        const thumbs = list => list.length ? `<div class="agh-cal-thumbs">${list.map(asset => `<span class="agh-cal-thumb"><a href="${esc(asset.url)}" title="${esc(asset.name)}"><img src="${esc(asset.thumbnail)}" alt="${esc(asset.name)}" loading="lazy" /></a><button type="button" data-cal-unlink="${asset.link_id}" aria-label="Unlink ${esc(asset.name)}">✕</button></span>`).join('')}</div>` : '';
        function taskRow(task, assets) {
            const own = assets.filter(asset => asset.task_id === task.id);
            const people = state.people || [];
            const known = !task.assignee || people.some(person => person.id === task.assignee.id);
            return `<li class="agh-cal-task${task.parent_id ? ' is-sub' : ''}${task.status === 'done' ? ' is-done' : ''}" data-task="${task.id}">
                <div class="agh-cal-task-line"><input type="checkbox" data-cal-done ${task.status === 'done' ? 'checked' : ''} aria-label="Done: ${esc(task.title)}" />
                <span class="agh-cal-task-title"><strong>${esc(task.title)}</strong>${task.role ? `<em>${esc(task.role)}</em>` : ''}${task.status === 'in_progress' ? '<em class="is-progress">In progress</em>' : ''}${task.due_on ? `<small>due ${esc(dayLabel(task.due_on, {month: 'short', day: 'numeric'}))}</small>` : ''}</span>
                <select data-cal-assign aria-label="Who has ${esc(task.title)}"><option value="">Unassigned</option>${known ? '' : `<option value="${task.assignee.id}" selected>${esc(task.assignee.name)}</option>`}${people.map(person => `<option value="${person.id}"${task.assignee?.id === person.id ? ' selected' : ''}>${esc(person.name)}${person.id === me() ? ' (me)' : ''}</option>`).join('')}</select>
                ${!task.assignee ? '<button type="button" class="agh-button" data-cal-claim>Take it</button>' : task.status === 'open' && task.assignee.id === me() ? '<button type="button" class="agh-button" data-cal-start>Start</button>' : ''}
                <button type="button" class="agh-button" data-cal-attach="${task.id}">Add work</button>
                <button type="button" class="agh-cal-x" data-cal-remove aria-label="Delete ${esc(task.title)}">✕</button></div>${thumbs(own)}</li>`;
        }
        function paintEvent() {
            const element = dialog(); const {event, tasks, assets, hidden_assets: hidden} = state.detail;
            const top = tasks.filter(task => !task.parent_id);
            const rows = top.map(task => taskRow(task, assets) + tasks.filter(sub => sub.parent_id === task.id).map(sub => taskRow(sub, assets)).join('')
                + `<li class="agh-cal-task is-sub is-new"><form data-cal-sub="${task.id}"><input name="title" maxlength="200" placeholder="Add a sub-item to “${esc(task.title)}”" aria-label="New sub-item of ${esc(task.title)}" /></form></li>`).join('');
            const loose = assets.filter(asset => asset.task_id === null);
            element.innerHTML = `<div class="agh-dialog-head"><div><p class="agh-eyebrow">${esc([event.kind === 'campaign' ? 'CAMPAIGN' : (SOURCE[event.source] || 'Added here').toUpperCase(), event.category].filter(Boolean).join(' · '))}</p><h2>${esc(event.title)}</h2>
                <p class="agh-subtitle">${esc(when(event))}${event.location ? ` · ${esc(event.location)}` : ''}${event.status === 'cancelled' ? ' · Cancelled' : ''}</p></div>
                <div class="agh-actions">${event.can_edit ? '<button class="agh-button" data-cal-edit>Edit</button>' : ''}${event.can_delete ? '<button class="agh-button" data-cal-delete>Delete</button>' : ''}${event.url ? `<a class="agh-button" href="${esc(event.url)}" target="_blank" rel="noopener noreferrer">Source <span aria-hidden="true">↗</span></a>` : ''}<button class="agh-button" data-cal-close aria-label="Close">✕</button></div></div>
                ${event.description ? `<p class="agh-cal-notes">${esc(event.description)}</p>` : ''}
                <p class="agh-signal-state is-offline" data-cal-error hidden role="alert"></p>
                <section><h3>Tasks <small>${tasks.length ? `${tasks.filter(task => task.status === 'done').length} of ${tasks.length} done` : 'Who is covering this, and what is due'}</small></h3>
                <ul class="agh-cal-tasks">${rows}</ul>
                <form class="agh-cal-new" data-cal-new><input name="title" maxlength="200" required placeholder="Add a task, e.g. Game photos" aria-label="New task" /><input name="role" maxlength="60" list="agh-cal-roles" placeholder="Role" aria-label="Role" /><datalist id="agh-cal-roles">${ROLES.map(role => `<option value="${role}">`).join('')}</datalist>
                <select name="assignee_id" aria-label="Assign to"><option value="">Unassigned</option>${(state.people || []).map(person => `<option value="${person.id}">${esc(person.name)}${person.id === me() ? ' (me)' : ''}</option>`).join('')}</select><input name="due_on" type="date" aria-label="Due date" /><button class="agh-button agh-primary">Add task</button></form></section>
                <section><h3>Assets <small>${assets.length ? `${new Set(assets.map(asset => asset.id)).size} linked` : 'Link files from the Library or upload new work'}${hidden ? ` · ${hidden} you can’t see` : ''}</small></h3>
                ${thumbs(loose) || (assets.length ? '<p class="agh-caption">Every linked file sits under a task above.</p>' : '')}
                <div class="agh-actions agh-cal-left"><button type="button" class="agh-button" data-cal-attach="">Add assets to this ${event.kind === 'campaign' ? 'campaign' : 'event'}</button></div></section>
                <div data-cal-picker></div>`;
            element.querySelector('[data-cal-close]').addEventListener('click', () => element.close());
            element.querySelector('[data-cal-edit]')?.addEventListener('click', () => editEvent(event));
            element.querySelector('[data-cal-delete]')?.addEventListener('click', async () => { if (!confirm(`Delete “${event.title}” with its tasks? Linked files stay in the Library.`)) return; try { await request(`/calendar/events/${event.id}`, {method: 'DELETE'}); element.close(); } catch (error) { failure(element, error.message); } });
            element.querySelector('[data-cal-new]').addEventListener('submit', submit => { submit.preventDefault(); const form = new FormData(submit.target); change(`/calendar/events/${event.id}/tasks`, {method: 'POST', body: {title: form.get('title'), role: form.get('role') || null, assignee_id: form.get('assignee_id') ? Number(form.get('assignee_id')) : null, due_on: form.get('due_on') || null}}); });
            element.querySelectorAll('[data-cal-sub]').forEach(form => form.addEventListener('submit', submit => { submit.preventDefault(); const title = new FormData(form).get('title'); if (String(title).trim()) change(`/calendar/events/${event.id}/tasks`, {method: 'POST', body: {title, parent_id: Number(form.dataset.calSub)}}); }));
            element.querySelectorAll('[data-task]').forEach(item => {
                const id = item.dataset.task; const patch = body => change(`/calendar/tasks/${id}`, {method: 'PATCH', body});
                item.querySelector('[data-cal-done]').addEventListener('change', input => patch({status: input.target.checked ? 'done' : 'open'}));
                item.querySelector('[data-cal-assign]').addEventListener('change', input => patch({assignee_id: input.target.value ? Number(input.target.value) : null}));
                item.querySelector('[data-cal-claim]')?.addEventListener('click', () => patch({assignee_id: me()}));
                item.querySelector('[data-cal-start]')?.addEventListener('click', () => patch({status: 'in_progress'}));
                item.querySelector('[data-cal-remove]').addEventListener('click', () => { if (confirm('Delete this task and its sub-items? Linked files stay on the event.')) change(`/calendar/tasks/${id}`, {method: 'DELETE'}); });
            });
            wireFiles(element);
        }
        function wireFiles(element) {
            element.querySelectorAll('[data-cal-unlink]').forEach(button => button.addEventListener('click', () => change(`/calendar/assets/${button.dataset.calUnlink}`, {method: 'DELETE'})));
            element.querySelectorAll('[data-cal-attach]').forEach(button => button.addEventListener('click', () => { state.picker = {taskId: button.dataset.calAttach ? Number(button.dataset.calAttach) : null, term: '', assets: null, folders: null, busy: ''}; paintPicker(); searchAssets(); if (!savedFolder()) topFolders(); }));
            if (state.picker) paintPicker();
        }

        // ---- Add work: link Library files, or upload new ones into a folder and link them. ----
        const folderKey = 'mothership.calendar.folder';
        const savedFolder = () => { try { return JSON.parse(localStorage.getItem(folderKey) || 'null'); } catch { return null; } };
        async function searchAssets() {
            const picker = state.picker; if (!picker) return;
            try { const data = await request(`/calendar/assets?q=${encodeURIComponent(picker.term)}`); if (state.picker === picker) { picker.assets = data.assets; paintPicker(); } }
            catch (error) { failure(dialog(), error.message); }
        }
        // Before anything is typed the choices are the Library's top folders; typing searches every folder by name.
        async function topFolders() {
            const picker = state.picker; if (!picker) return;
            try {
                const response = await fetch(root.dataset.directories, {credentials: 'same-origin', headers: {Accept: 'application/json'}});
                const data = await response.json();
                if (state.picker !== picker || !response.ok || picker.folders) return;
                picker.folders = (data.data || []).flatMap(top => [{id: top.id, name: top.name}, ...(top.children || []).map(child => ({id: child.id, name: child.name, path_names: [top.name, child.name]}))]).slice(0, 30);
                paintPicker({keepFocus: 'none'});
            } catch { /* The folder box still searches. */ }
        }
        async function searchFolders(term) {
            const picker = state.picker; if (!picker) return;
            if (term.trim().length < 2) { picker.folders = null; return topFolders(); }
            try {
                const response = await fetch(`${root.dataset.directorySearch}?q=${encodeURIComponent(term.trim())}`, {credentials: 'same-origin', headers: {Accept: 'application/json'}});
                const data = await response.json();
                if (state.picker === picker && response.ok) { picker.folders = data.data || []; paintPicker({keepFocus: 'folder'}); }
            } catch { /* The folder list stays as it was. */ }
        }
        async function upload(files) {
            const picker = state.picker; const folder = savedFolder();
            if (!picker || !files.length || !folder) return;
            const form = new FormData(); form.append('directory_id', folder.id); [...files].forEach(file => form.append('files[]', file));
            picker.busy = `Uploading ${files.length} ${files.length === 1 ? 'file' : 'files'}…`; paintPicker();
            try {
                const response = await fetch(root.dataset.upload, {method: 'POST', credentials: 'same-origin', headers: {Accept: 'application/json', 'X-CSRF-TOKEN': root.dataset.csrf}, body: form});
                const data = await response.json().catch(() => ({}));
                if (!response.ok || !data.success) throw new Error(data.message || `The upload failed (${response.status}).`);
                const ids = (data.files || []).map(file => Number(file.id)).filter(Boolean);
                picker.busy = '';
                if (ids.length) await link(ids);
            } catch (error) { picker.busy = ''; paintPicker(); failure(dialog(), error.message); }
        }
        // The Library list is reread afterwards: an upload adds to it, and a linked file is marked.
        const link = async ids => { if (await change(`/calendar/events/${state.detail.event.id}/assets`, {method: 'POST', body: {asset_ids: ids, task_id: state.picker?.taskId ?? null}})) searchAssets(); };
        function paintPicker({keepFocus} = {}) {
            const element = dialog(); const slot = element.querySelector('[data-cal-picker]'); const picker = state.picker;
            if (!slot || !picker) return;
            const task = state.detail.tasks.find(item => item.id === picker.taskId);
            const folder = savedFolder();
            const linked = new Set(state.detail.assets.filter(asset => asset.task_id === picker.taskId).map(asset => asset.id));
            slot.innerHTML = `<section class="agh-cal-picker"><div class="agh-dialog-head"><h3>${`Add work to ${task ? `“${esc(task.title)}”` : 'this entry'}`}</h3><button type="button" class="agh-button" data-cal-picker-close>Done</button></div>
                <div class="agh-cal-upload"><strong>Upload new files</strong>
                <label>Into folder${folder ? ` <b>${esc(folder.name)}</b>` : ''} <input data-cal-folder value="${esc(picker.folderTerm ?? '')}" placeholder="${folder ? 'Type to change the folder' : 'Type a folder name, or pick one below'}" autocomplete="off" /></label>
                ${picker.folders ? `<div class="agh-cal-folders">${picker.folders.length ? picker.folders.map(item => `<button type="button" class="agh-trend-day" data-cal-pick-folder="${item.id}" data-name="${esc((item.path_names || [item.name]).join(' / '))}">${esc((item.path_names || [item.name]).join(' / '))}</button>`).join('') : '<span class="agh-caption">No folder matches.</span>'}</div>` : ''}
                <label class="agh-button${folder ? ' agh-primary' : ''}"${folder ? '' : ' aria-disabled="true"'}>${folder ? 'Choose files to upload' : 'Pick a folder first'}<input type="file" multiple hidden data-cal-files ${folder ? '' : 'disabled'} /></label>
                ${picker.busy ? `<span class="agh-caption" role="status">${esc(picker.busy)}</span>` : ''}</div>
                <div class="agh-cal-library"><strong>Or link files already in the Library</strong><input data-cal-term value="${esc(picker.term)}" placeholder="Search by name" aria-label="Search the Library" />
                ${picker.assets === null ? '<p class="agh-caption">Loading…</p>' : picker.assets.length ? `<div class="agh-cal-results">${picker.assets.map(asset => `<button type="button" data-cal-link="${asset.id}" ${linked.has(asset.id) ? 'disabled' : ''} title="${esc(asset.name)}"><img src="${esc(asset.thumbnail)}" alt="" loading="lazy" /><span>${esc(asset.name)}</span>${linked.has(asset.id) ? '<em>Linked</em>' : ''}</button>`).join('')}</div>` : '<p class="agh-caption">No file matches.</p>'}</div></section>`;
            slot.querySelector('[data-cal-picker-close]').addEventListener('click', () => { state.picker = null; slot.innerHTML = ''; });
            const folderInput = slot.querySelector('[data-cal-folder]'); let folderTimer = 0;
            folderInput.addEventListener('input', () => { picker.folderTerm = folderInput.value; clearTimeout(folderTimer); folderTimer = setTimeout(() => searchFolders(folderInput.value), 250); });
            slot.querySelectorAll('[data-cal-pick-folder]').forEach(button => button.addEventListener('click', () => { try { localStorage.setItem(folderKey, JSON.stringify({id: Number(button.dataset.calPickFolder), name: button.dataset.name})); } catch { /* Private mode: the folder is asked again next time. */ } picker.folders = null; picker.folderTerm = ''; paintPicker(); }));
            slot.querySelector('[data-cal-files]').addEventListener('change', input => upload(input.target.files));
            const termInput = slot.querySelector('[data-cal-term]'); let termTimer = 0;
            termInput.addEventListener('input', () => { picker.term = termInput.value; clearTimeout(termTimer); termTimer = setTimeout(async () => { await searchAssets(); const again = dialog().querySelector('[data-cal-term]'); again?.focus(); again?.setSelectionRange(again.value.length, again.value.length); }, 250); });
            slot.querySelectorAll('[data-cal-link]').forEach(button => button.addEventListener('click', () => link([Number(button.dataset.calLink)])));
            if (keepFocus === 'folder') { const again = slot.querySelector('[data-cal-folder]'); again.focus(); again.setSelectionRange(again.value.length, again.value.length); }
            else if (!keepFocus && picker.assets === null) slot.scrollIntoView({block: 'nearest'});
        }

        // ---- Add or edit a manual event or campaign. ----
        function editEvent(event) {
            const element = dialog();
            const start = event?.starts_at || `${state.selected}T09:00`;
            const value = (stamp, allDay) => stamp ? (allDay ? dayOf(stamp) : stamp.slice(0, 16)) : '';
            const allDay = event?.all_day ?? false;
            element.innerHTML = `<form data-cal-form><div class="agh-dialog-head"><div><p class="agh-eyebrow">${event ? 'EDIT' : 'ADD TO THE CALENDAR'}</p><h2>${event ? esc(event.title) : 'New event or campaign'}</h2></div><button type="button" class="agh-button" data-cal-cancel aria-label="Close">✕</button></div>
                <p class="agh-signal-state is-offline" data-cal-error hidden role="alert"></p>
                <label>Title<input name="title" maxlength="200" required value="${esc(event?.title ?? '')}" placeholder="Gallery opening" /></label>
                <div class="agh-cal-fields"><label>Kind<select name="kind"><option value="event"${event?.kind === 'campaign' ? '' : ' selected'}>Event — one date</option><option value="campaign"${event?.kind === 'campaign' ? ' selected' : ''}>Campaign — runs across dates</option></select></label>
                <label class="agh-cal-check"><input type="checkbox" name="all_day" ${allDay ? 'checked' : ''} /> All day</label></div>
                <div class="agh-cal-fields"><label>Starts<input name="starts_at" required type="${allDay ? 'date' : 'datetime-local'}" value="${esc(value(start, allDay))}" /></label><label>Ends<input name="ends_at" type="${allDay ? 'date' : 'datetime-local'}" value="${esc(value(event?.ends_at, allDay))}" /></label></div>
                <label>Where<input name="location" maxlength="200" value="${esc(event?.location ?? '')}" /></label>
                <label>Notes<textarea name="description" maxlength="4000" placeholder="What it is, what is needed, when it should post">${esc(event?.description ?? '')}</textarea></label>
                <div class="agh-actions"><button type="button" class="agh-button" data-cal-cancel>Cancel</button><button class="agh-button agh-primary">${event ? 'Save' : 'Add to calendar'}</button></div></form>`;
            if (!element.open) element.showModal();
            const form = element.querySelector('[data-cal-form]');
            form.querySelector('[name=all_day]').addEventListener('change', input => ['starts_at', 'ends_at'].forEach(name => { const field = form.querySelector(`[name=${name}]`); const old = field.value; field.type = input.target.checked ? 'date' : 'datetime-local'; field.value = old ? (input.target.checked ? old.slice(0, 10) : `${old.slice(0, 10)}T09:00`) : ''; }));
            element.querySelectorAll('[data-cal-cancel]').forEach(button => button.addEventListener('click', () => event ? openEvent(event.id) : element.close()));
            form.addEventListener('submit', async submit => {
                submit.preventDefault();
                const data = new FormData(form); const whole = data.get('all_day') === 'on';
                const stamp = raw => raw ? (whole ? `${raw}T00:00` : raw) : null;
                const body = {title: data.get('title'), kind: data.get('kind'), all_day: whole, starts_at: stamp(data.get('starts_at')), ends_at: stamp(data.get('ends_at')), location: data.get('location') || null, description: data.get('description') || null};
                try {
                    state.people ??= (await request('/calendar/people')).people;
                    state.detail = await request(event ? `/calendar/events/${event.id}` : '/calendar/events', {method: event ? 'PATCH' : 'POST', body});
                    paintEvent();
                } catch (error) { failure(element, error.message); }
            });
        }

        return {
            mount(card) { state.card = card; paint(); if (state.loaded !== ymd(gridStart())) load(); },
            unmount() { state.card = null; },
            // Home's attention list opens an entry, or turns on a filter ("mine", "open"), without the person finding it in the grid.
            open(id) { openEvent(id); },
            show(filter) { if (FILTERS.some(([id]) => id === filter)) { state.filter = filter; paint(); } },
        };
    }

    window.MothershipHomeCalendar = {create};
})();
