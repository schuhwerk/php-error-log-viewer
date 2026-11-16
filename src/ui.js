'use strict';

const LEVELS = ['error', 'warning', 'notice', 'deprecated', 'info', 'debug', ''];
const PAGE = 300; // Rows drawn until all are asked for.
const INTERVAL = 3000;
// A path with a line number: "/a/b.php(12)", "/a/b.php:12", "C:\a\b.php on line 12".
const PATH = /((?:[A-Z]:)?[\\/][^:(\s]+)(?: on line |[:(])(\d+)\)?/g;

/** @param {string} id @return {HTMLElement} */
const $ = id => document.getElementById(id);

/** @param {string} tag @param {Object} props @param {...(Node|string)} children @return {HTMLElement} */
const el = (tag, props = {}, ...children) => {
	const node = Object.assign(document.createElement(tag), props);
	node.append(...children);
	return node;
};

const config = JSON.parse($('config').textContent);
const prefs = { file: 0, sort: 't', desc: true, auto: true, notify: false, off: [] };
try {
	Object.assign(prefs, JSON.parse(localStorage.getItem('log-viewer')));
} catch {}
if (!config.files[prefs.file]) prefs.file = 0;

/** rows: id → {id, n, t, lvl, msg, el, drawn, fresh}. from: the byte the next poll reads from. */
const state = { rows: new Map(), from: 0, loaded: false, filter: null, limit: PAGE, busy: false, unseen: 0, error: '' };

/** Keeps the preferences. */
const save = () => {
	try {
		localStorage.setItem('log-viewer', JSON.stringify(prefs));
	} catch {}
};

/**
 * @param {string} query
 * @param {string} method
 * @return {Promise<Object>} The decoded answer. Rejects with the server's message.
 */
async function request(query, method = 'GET') {
	const response = await fetch(query, { method, headers: { 'X-Log-Viewer': '1' }, cache: 'no-store' });
	if (!response.ok) throw new Error(await response.text() || response.statusText);
	return response.json();
}

/** @param {number} bytes @return {string} */
const size = bytes => bytes < 1024 ? `${bytes} B` : bytes < 1048576 ? `${(bytes / 1024).toFixed(1)} KB` : `${(bytes / 1048576).toFixed(1)} MB`;

/** @param {?number} time Unix seconds. @return {string} Local time, with the date unless it is today. */
function clock(time) {
	if (!time) return '';
	const text = new Date(time * 1000).toLocaleString('sv-SE');
	return text.startsWith(new Date().toLocaleDateString('sv-SE')) ? text.slice(11) : text;
}

/** @param {string} text @return {(Node|string)[]} The text, its file paths as editor links. */
function linkify(text) {
	const { template, search, replace } = config.link;
	if (!template) return [text];
	const parts = [];
	let last = 0;
	for (const match of text.matchAll(PATH)) {
		const path = search ? match[1].replace(search, () => replace) : match[1];
		const href = template.replace('{{path}}', () => encodeURI(path)).replace('{{line_number}}', match[2]);
		parts.push(text.slice(last, match.index), el('a', { href }, match[0]));
		last = match.index + match[0].length;
	}
	return [...parts, text.slice(last)];
}

/** @param {string} text @return {HTMLElement} One line as it is, more lines folded behind the first. */
function message(text) {
	const cut = text.indexOf('\n');
	if (cut < 0) return el('div', {}, ...linkify(text));
	const details = el('details', {}, el('summary', {}, ...linkify(text.slice(0, cut))));
	// The body can be long: built when first opened.
	details.addEventListener('toggle', () => details.append(el('pre', {}, ...linkify(text.slice(cut + 1)))), { once: true });
	return details;
}

/** @param {Object} row @return {HTMLElement} The row's element, brought up to date. */
function paint(row) {
	row.el ??= el('tr', {}, el('td'), el('td'), el('td'), el('td'));
	const [count, time, level, text] = row.el.children;
	row.el.className = row.lvl;
	count.textContent = row.n;
	time.textContent = clock(row.t);
	level.textContent = row.lvl;
	if (row.drawn !== row.msg) {
		const open = text.firstChild?.open;
		text.replaceChildren(message(row.msg));
		if (open) text.firstChild.open = true;
		row.drawn = row.msg;
	}
	if (row.fresh) {
		row.fresh = false;
		row.el.animate({ opacity: [0.2, 1] }, 800);
	}
	return row.el;
}

/** @param {Object} row @return {boolean} Whether the level buttons and the filter let it through. */
const matches = row => !prefs.off.includes(row.lvl) && (!state.filter || state.filter.test(row.msg));

/** @param {Object} a @param {Object} b @return {number} */
function compare(a, b) {
	const x = a[prefs.sort] ?? 0;
	const y = b[prefs.sort] ?? 0;
	return (x < y ? -1 : x > y ? 1 : 0) * (prefs.desc ? -1 : 1);
}

/** Draws the table, the level buttons and the status line from the state. */
function render() {
	const all = [...state.rows.values()];
	const shown = all.filter(matches).sort(compare);
	$('rows').replaceChildren(...shown.slice(0, state.limit).map(paint));
	$('more').hidden = shown.length <= state.limit;
	$('more').textContent = `Show all ${shown.length} rows`;
	$('status').textContent = state.error || (shown.length ? '' : all.length ? 'Nothing matches.' : state.loaded ? 'The log is empty.' : 'Loading…');

	const counts = {};
	for (const row of all) counts[row.lvl] = (counts[row.lvl] || 0) + 1;
	$('levels').replaceChildren(...LEVELS.filter(level => counts[level]).map(level => {
		const button = el('button', { type: 'button', className: level }, `${level || 'other'} ${counts[level]}`);
		button.setAttribute('aria-pressed', !prefs.off.includes(level));
		button.onclick = () => {
			prefs.off = prefs.off.includes(level) ? prefs.off.filter(off => off !== level) : [...prefs.off, level];
			save();
			render();
		};
		return button;
	}));

	for (const button of document.querySelectorAll('[data-sort]')) {
		const sorted = button.dataset.sort === prefs.sort;
		button.parentNode.toggleAttribute('aria-sort', sorted);
		if (sorted) button.parentNode.setAttribute('aria-sort', prefs.desc ? 'descending' : 'ascending');
	}
}

/** @param {Object[]} rows What a poll brought. Counts them in the tab title and notifies while the tab is in the background. */
function announce(rows) {
	const hits = rows.filter(matches);
	if (!hits.length || !document.hidden) return;
	state.unseen += hits.length;
	document.title = `(${state.unseen}) ${config.files[prefs.file]}`;
	if (!prefs.notify || Notification.permission !== 'granted') return;
	const note = new Notification(`${hits.length} new in ${config.files[prefs.file]}`, {
		body: hits.slice(0, 3).map(row => row.msg.split('\n', 1)[0].slice(0, 160)).join('\n'),
		tag: 'log-viewer', // One notification, replaced: a busy log does not pile them up.
	});
	note.onclick = () => {
		window.focus();
		note.close();
	};
}

/** Fetches what the log gained and merges it into the rows. */
async function poll() {
	if (state.busy) return;
	state.busy = true;
	try {
		const answer = await request(`?log=${prefs.file}&from=${state.from}`);
		if (answer.reset) state.rows.clear();
		const rows = answer.rows.map(row => {
			const old = state.rows.get(row.id);
			const merged = { ...old, ...row, n: row.n + (old?.n ?? 0), fresh: state.loaded };
			state.rows.set(row.id, merged);
			return merged;
		});
		const cut = state.loaded ? state.cut : answer.from > 0;
		$('size').textContent = size(answer.size) + (cut ? ', end only' : '');
		$('clear').disabled = !answer.writable || !answer.size;
		if (state.loaded) announce(rows);
		const redraw = rows.length || answer.reset || !state.loaded || state.error;
		Object.assign(state, { from: answer.to, loaded: true, cut, error: '' });
		if (redraw) render();
	} catch (error) {
		state.rows.clear();
		Object.assign(state, { from: 0, loaded: false, error: error.message });
		$('size').textContent = '';
		$('clear').disabled = true;
		render();
	} finally {
		state.busy = false;
	}
}

/** Starts over with the selected log. */
function load() {
	state.rows.clear();
	Object.assign(state, { from: 0, loaded: false, limit: PAGE, error: '' });
	document.title = config.files[prefs.file];
	render();
	poll();
}

$('file').append(...config.files.map((name, index) => el('option', { value: index, selected: index === prefs.file }, name)));
$('file').onchange = event => {
	prefs.file = +event.target.value;
	save();
	load();
};

$('filter').oninput = event => {
	const text = event.target.value;
	event.target.setCustomValidity('');
	try {
		state.filter = text ? new RegExp(text, 'i') : null;
	} catch {
		// Not a regular expression (yet): match it literally.
		event.target.setCustomValidity('Not a regular expression, matched as plain text.');
		state.filter = { test: message => message.toLowerCase().includes(text.toLowerCase()) };
	}
	state.limit = PAGE;
	render();
};

$('auto').checked = prefs.auto;
$('auto').onchange = event => {
	prefs.auto = event.target.checked;
	save();
};

const notifiable = 'Notification' in window && isSecureContext;
$('notify').disabled = !notifiable;
$('notify').checked = notifiable && prefs.notify && Notification.permission === 'granted';
if (notifiable) $('notify').parentNode.title = 'Notifies about new entries that pass the filter while this tab is in the background.';
else {
	$('notify').parentNode.append(' (needs HTTPS or localhost)');
	$('notify').parentNode.title = 'Browsers allow notifications over HTTPS or on localhost only. To get them here: run "php -S localhost:8799" in the viewer\'s folder and open http://localhost:8799/. Or, in Chrome, list this address under chrome://flags/#unsafely-treat-insecure-origin-as-secure.';
}
$('notify').onchange = async event => {
	prefs.notify = event.target.checked && await Notification.requestPermission() === 'granted';
	event.target.checked = prefs.notify;
	if (Notification.permission === 'denied') event.target.parentNode.title = 'Blocked in the browser\'s settings for this site.';
	save();
};

$('clear').onclick = async () => {
	if (!confirm(`Empty ${config.files[prefs.file]}? This cannot be undone.`)) return;
	try {
		await request(`?clear=${prefs.file}`, 'POST');
		load();
	} catch (error) {
		state.error = error.message;
		render();
	}
};

$('more').onclick = () => {
	state.limit = Infinity;
	render();
};

for (const button of document.querySelectorAll('[data-sort]')) {
	button.onclick = () => {
		prefs.desc = button.dataset.sort === prefs.sort ? !prefs.desc : true;
		prefs.sort = button.dataset.sort;
		save();
		render();
	};
}

document.addEventListener('visibilitychange', () => {
	if (document.hidden) return;
	state.unseen = 0;
	document.title = config.files[prefs.file];
});

load();
setInterval(() => prefs.auto && poll(), INTERVAL);
