// DoorLock 2.0 — shared frontend helpers (no dependencies).

export const state = {
    user: null,
    permissions: [],
    csrf: null,
    timezone: 'Asia/Tehran',
    route: 'dashboard',
};

export class ApiError extends Error {
    constructor(message, code = 'request_failed', status = 0, fields = {}) {
        super(message);
        this.name = 'ApiError';
        this.code = code;
        this.status = status;
        this.fields = fields || {};
    }
}

let unauthorizedHandler = () => {};

export function onUnauthorized(handler) {
    unauthorizedHandler = handler;
}

export function can(action) {
    return state.permissions.includes(action);
}

export function encodeQuery(query) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(query || {})) {
        if (value === null || value === undefined || value === '') continue;
        params.set(key, String(value));
    }
    const encoded = params.toString();
    return encoded ? `?${encoded}` : '';
}

export async function api(path, { method = 'GET', body = null, query = null } = {}) {
    const headers = { Accept: 'application/json' };
    if (body !== null) headers['Content-Type'] = 'application/json';
    if (method !== 'GET' && method !== 'HEAD') {
        if (!state.csrf) throw new ApiError('The session is not ready. Reload the page.', 'csrf_missing', 0);
        headers['X-CSRF-Token'] = state.csrf;
    }
    let response;
    try {
        response = await fetch(path + encodeQuery(query), {
            method,
            headers,
            credentials: 'same-origin',
            cache: 'no-store',
            body: body !== null ? JSON.stringify(body) : undefined,
        });
    } catch (error) {
        throw new ApiError('The server could not be reached.', 'network_error', 0);
    }
    const text = await response.text();
    let payload = null;
    if (text) {
        try { payload = JSON.parse(text); } catch { payload = null; }
    }
    if (response.status === 401) {
        unauthorizedHandler();
    }
    if (!response.ok || (payload && payload.success === false)) {
        const error = (payload && payload.error) || {};
        throw new ApiError(
            error.message || `Request failed (HTTP ${response.status}).`,
            error.code || 'http_error',
            response.status,
            error.fields || {}
        );
    }
    if (payload && Object.prototype.hasOwnProperty.call(payload, 'data')) {
        return payload;
    }
    return payload;
}

export async function bootstrapSession() {
    const response = await api('/api/auth.php');
    state.user = response.data.user;
    state.csrf = response.data.csrf_token;
    state.permissions = response.data.permissions || [];
    state.timezone = response.data.site_timezone || 'Asia/Tehran';
    return response.data;
}

export async function login(username, password) {
    const response = await api('/api/auth.php', {
        method: 'POST',
        body: { action: 'login', username, password },
    });
    state.user = response.data.user;
    state.csrf = response.data.csrf_token;
    await bootstrapSession();
    return state.user;
}

export async function logout() {
    try {
        await api('/api/auth.php', { method: 'POST', body: { action: 'logout' } });
    } finally {
        state.user = null;
        state.permissions = [];
        state.csrf = null;
    }
}

/* ---------------------------------------------------------------- DOM --- */

export function h(tag, props = null, ...children) {
    const node = document.createElement(tag);
    if (props) {
        for (const [key, value] of Object.entries(props)) {
            if (value === null || value === undefined || value === false) continue;
            if (key === 'class') node.className = value;
            else if (key === 'dataset') Object.assign(node.dataset, value);
            else if (key === 'style') Object.assign(node.style, value);
            else if (key === 'text') node.textContent = value;
            else if (key === 'html') node.innerHTML = value;
            else if (key.startsWith('on') && typeof value === 'function') {
                node.addEventListener(key.slice(2).toLowerCase(), value);
            } else if (key === 'value') node.value = value;
            else if (key === 'checked' || key === 'disabled' || key === 'required' || key === 'multiple' || key === 'selected') {
                node[key] = Boolean(value);
            } else if (key in node) {
                node[key] = value;
            } else {
                node.setAttribute(key, String(value));
            }
        }
    }
    append(node, children);
    return node;
}

export function append(node, children) {
    for (const child of children.flat(Infinity)) {
        if (child === null || child === undefined || child === false) continue;
        node.appendChild(child instanceof Node ? child : document.createTextNode(String(child)));
    }
    return node;
}

export function clear(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
    return node;
}

export function badge(text, kind = 'muted') {
    return h('span', { class: `badge ${kind}` }, text);
}

export function boolBadge(value, onText = 'فعال', offText = 'غیرفعال') {
    return value ? badge(onText, 'ok') : badge(offText, 'muted');
}

export function fmtDate(value, withTime = true) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    try {
        return new Intl.DateTimeFormat('fa-IR', {
            timeZone: state.timezone,
            dateStyle: 'short',
            ...(withTime ? { timeStyle: 'short' } : {}),
        }).format(date);
    } catch {
        return date.toISOString();
    }
}

export function toLocalInput(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    try {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: state.timezone,
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', hour12: false,
        }).formatToParts(date).reduce((acc, part) => {
            acc[part.type] = part.value;
            return acc;
        }, {});
        return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
    } catch {
        return date.toISOString().slice(0, 16);
    }
}

/* -------------------------------------------------------------- toasts --- */

function toastHost() {
    let host = document.querySelector('.toasts');
    if (!host) {
        host = h('div', { class: 'toasts', role: 'status', 'aria-live': 'polite' });
        document.body.appendChild(host);
    }
    return host;
}

export function toast(message, kind = 'ok', timeout = 4200) {
    const node = h('div', { class: `toast ${kind}` }, message);
    toastHost().appendChild(node);
    window.setTimeout(() => node.remove(), timeout);
}

export function reportError(error) {
    if (error instanceof ApiError) {
        if (error.code === 'csrf_failed') {
            toast('نشست منقضی شده است؛ صفحه دوباره بارگذاری می‌شود.', 'warn');
            window.setTimeout(() => window.location.reload(), 900);
            return;
        }
        toast(error.message, 'error', 6000);
        return;
    }
    console.error(error);
    toast('خطای غیرمنتظره در رابط کاربری رخ داد.', 'error');
}

/* --------------------------------------------------------------- modal --- */

export function openModal({ title, content, actions = [], wide = false, onClose = null }) {
    const close = () => {
        backdrop.remove();
        document.removeEventListener('keydown', onKey);
        if (onClose) onClose();
    };
    const onKey = (event) => {
        if (event.key === 'Escape') close();
    };
    const head = h('div', { class: 'modal-head' },
        h('h3', null, title),
        h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'بستن', onclick: close }, '×')
    );
    const body = h('div', { class: 'modal-body' }, content);
    const footer = actions.length ? h('div', { class: 'form-actions' }, actions) : null;
    const modal = h('div', { class: `modal${wide ? ' wide' : ''}`, role: 'dialog', 'aria-modal': 'true' }, head, body, footer);
    const backdrop = h('div', { class: 'modal-backdrop' }, modal);
    backdrop.addEventListener('mousedown', (event) => {
        if (event.target === backdrop) close();
    });
    document.addEventListener('keydown', onKey);
    document.body.appendChild(backdrop);
    const first = modal.querySelector('input:not([type=hidden]), select, textarea, button');
    if (first) window.setTimeout(() => first.focus(), 50);
    return { close, modal };
}

export function confirmModal(message, confirmLabel = 'تأیید') {
    return new Promise((resolve) => {
        let settled = false;
        const finish = (value) => {
            if (settled) return;
            settled = true;
            handle.close();
            resolve(value);
        };
        const handle = openModal({
            title: 'تأیید عملیات',
            content: h('div', { class: 'notice' }, message),
            actions: [
                h('button', { class: 'btn primary', type: 'button', onclick: () => finish(true) }, confirmLabel),
                h('button', { class: 'btn', type: 'button', onclick: () => finish(false) }, 'انصراف'),
            ],
            onClose: () => finish(false),
        });
    });
}

/* ---------------------------------------------------------------- form --- */

export function field(label, control, hint = null) {
    return h('div', { class: 'form-row' },
        h('label', null, label),
        control,
        hint ? h('div', { class: 'help' }, hint) : null
    );
}

export function input(props) {
    return h('input', { type: 'text', ...props });
}

export function textarea(props) {
    return h('textarea', props);
}

export function select(options, value = '') {
    const control = h('select', null);
    for (const option of options) {
        control.appendChild(h('option', {
            value: option.value,
            selected: String(option.value) === String(value),
        }, option.label));
    }
    return control;
}

export function table(headers, rows, emptyText = 'موردی ثبت نشده است.') {
    const thead = h('thead', null, h('tr', null, ...headers.map((header) => h('th', null, header))));
    const tbody = h('tbody', null);
    if (!rows.length) {
        tbody.appendChild(h('tr', null, h('td', { colSpan: headers.length, class: 'empty' }, emptyText)));
    } else {
        for (const row of rows) {
            tbody.appendChild(h('tr', null, ...row.map((cell) => h('td', null, cell))));
        }
    }
    return h('div', { class: 'table-wrap' }, h('table', null, thead, tbody));
}

export function pager(meta, onPage) {
    if (!meta) return h('span');
    const total = Number(meta.total_pages || 0);
    const page = Number(meta.page || 1);
    return h('div', { class: 'pagination' },
        h('span', null, `${meta.total} مورد — صفحه ${page} از ${Math.max(total, 1)}`),
        h('button', {
            class: 'btn small', type: 'button', disabled: page <= 1,
            onclick: () => onPage(page - 1),
        }, 'قبلی'),
        h('button', {
            class: 'btn small', type: 'button', disabled: page >= total,
            onclick: () => onPage(page + 1),
        }, 'بعدی')
    );
}

export function weekdaysPicker(selected = []) {
    const labels = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
    const values = new Set(selected.map(Number));
    const wrap = h('div', { class: 'weekdays' });
    labels.forEach((label, index) => {
        const day = index + 1;
        const checkbox = h('input', { type: 'checkbox', value: String(day), checked: values.has(day) });
        const item = h('label', { class: `weekday${values.has(day) ? ' on' : ''}` }, checkbox, label);
        checkbox.addEventListener('change', () => item.classList.toggle('on', checkbox.checked));
        wrap.appendChild(item);
    });
    wrap.values = () => Array.from(wrap.querySelectorAll('input:checked')).map((inputEl) => Number(inputEl.value));
    return wrap;
}

export function roleLabel(role) {
    return ({ admin: 'مدیر', operator: 'اپراتور', viewer: 'بازدیدکننده' })[role] || role;
}

export function decisionBadge(log) {
    if (log.decision === 'granted') return badge('مجاز', 'ok');
    if (log.decision === 'error') return badge('خطا', 'warn');
    return badge('رد شد', 'bad');
}

export function reasonLabel(reason) {
    return ({
        granted: 'اجازه داده شد',
        unknown_card: 'کارت ناشناس',
        card_revoked: 'کارت باطل شده',
        card_lost: 'کارت گمشده',
        person_disabled: 'شخص غیرفعال',
        unknown_door: 'درب ناشناس',
        door_inactive: 'درب غیرفعال',
        device_door_mismatch: 'دستگاه/درب ناهمخوان',
        device_disabled: 'دستگاه غیرفعال',
        no_permission: 'بدون مجوز',
        outside_schedule: 'خارج از زمان مجاز',
        explicit_deny: 'منع صریح',
        grant_expired: 'مجوز موقت منقضی',
        grant_exhausted: 'سهمیه مجوز تمام شد',
        grant_revoked: 'مجوز موقت لغو شد',
        service_unavailable: 'سرویس در دسترس نیست',
    })[reason] || reason;
}
