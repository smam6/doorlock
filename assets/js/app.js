// DoorLock 2.0 — single-page admin application.

import {
    state, bootstrapSession, login, logout, can, h, clear, toast, reportError,
    onUnauthorized, roleLabel,
} from './core.js';
import { dashboardView, personsView, doorsView, groupsView } from './views/core.js';
import { schedulesView, permissionsView, grantsView, logsView } from './views/access.js';
import { devicesView, usersView, auditView, profileView } from './views/admin.js';

const routes = {
    dashboard: { title: 'داشبورد', icon: '▦', render: dashboardView },
    persons: { title: 'اشخاص', icon: '👤', permission: 'persons.read', render: personsView },
    doors: { title: 'درب‌ها', icon: '🚪', permission: 'doors.read', render: doorsView },
    groups: { title: 'گروه‌ها', icon: '👥', permission: 'groups.read', render: groupsView },
    schedules: { title: 'برنامه‌های زمانی', icon: '🗓', permission: 'schedules.read', render: schedulesView },
    permissions: { title: 'مجوزهای صریح', icon: '🔑', permission: 'permissions.read', render: permissionsView },
    grants: { title: 'مجوزهای موقت', icon: '⏱', permission: 'grants.read', render: grantsView },
    devices: { title: 'دستگاه‌ها', icon: '📟', permission: 'devices.read', render: devicesView },
    logs: { title: 'گزارش تردد', icon: '📜', permission: 'logs.read', render: logsView },
    audit: { title: 'ممیزی', icon: '🛡', permission: 'audit.read', render: auditView },
    users: { title: 'کاربران', icon: '⚙', permission: 'users.read', render: usersView },
    profile: { title: 'حساب من', icon: '👤', render: profileView },
};

const appRoot = document.getElementById('app');

const navItems = () => Object.entries(routes).filter(([, route]) => !route.permission || can(route.permission));

function loginScreen(message = '') {
    clear(appRoot);
    const username = h('input', { type: 'text', autocomplete: 'username', required: true, placeholder: 'نام کاربری' });
    const password = h('input', { type: 'password', autocomplete: 'current-password', required: true, placeholder: 'گذرواژه' });
    const errorBox = h('div', { class: 'login-error' }, message);
    const submit = h('button', { class: 'btn primary', type: 'submit' }, 'ورود به پنل');
    const form = h('form', { class: 'login-card', novalidate: true },
        h('div', { class: 'brand' }, 'درب', h('span', null, '‌یاب')),
        h('div', { class: 'subtitle' }, 'سیستم کنترل دسترسی مبتنی بر RFID'),
        h('div', { class: 'form-row' }, h('label', null, 'نام کاربری'), username),
        h('div', { class: 'form-row' }, h('label', null, 'گذرواژه'), password),
        errorBox,
        h('div', { class: 'form-actions' }, submit),
        h('div', { class: 'help', style: { marginTop: '16px', textAlign: 'center' } },
            'ورود ناموفق محدود می‌شود. پس از چند تلاش، حساب موقتاً قفل خواهد شد.')
    );
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        errorBox.textContent = '';
        submit.disabled = true;
        submit.textContent = 'در حال ورود…';
        try {
            await login(username.value.trim(), password.value);
            await renderShell();
        } catch (error) {
            errorBox.textContent = error.message || 'ورود ناموفق بود.';
        } finally {
            submit.disabled = false;
            submit.textContent = 'ورود به پنل';
        }
    });
    appRoot.appendChild(h('div', { class: 'login-shell' }, form));
    username.focus();
}

function renderShell() {
    clear(appRoot);
    const nav = h('nav', null);
    const sidebar = h('aside', { class: 'sidebar' },
        h('div', { class: 'logo' }, 'درب', h('span', null, '‌یاب')),
        nav,
        h('div', { class: 'spacer' }),
        h('div', { class: 'userbox' },
            h('div', { class: 'who' }, h('strong', null, state.user.username),
                `${roleLabel(state.user.role)}${state.user.is_active ? '' : ' — غیرفعال'}`),
            h('button', {
                class: 'btn small', type: 'button',
                onclick: async () => {
                    try { await logout(); } catch (error) { reportError(error); }
                    loginScreen('از حساب خود خارج شدید.');
                },
            }, 'خروج از حساب')
        )
    );
    const title = h('h1', { id: 'page-title' }, routes[state.route].title);
    const sub = h('div', { class: 'hint' }, 'ورود امن • کنترل نقش‌محور • ثبت کامل رخدادها');
    const actions = h('div', { class: 'btn-row' });
    const view = h('div', { id: 'view' });
    const main = h('main', { class: 'main' },
        h('header', { class: 'topbar' }, h('div', null, title, sub), actions),
        view
    );
    appRoot.appendChild(h('div', { class: 'layout' }, sidebar, main));

    function paintNav() {
        clear(nav);
        for (const [key, route] of navItems()) {
            nav.appendChild(h('button', {
                class: `nav-item${key === state.route ? ' active' : ''}`,
                type: 'button',
                onclick: () => { window.location.hash = `#/${key}`; },
            }, h('span', { class: 'ico' }, route.icon), route.title));
        }
    }

    function paint() {
        const route = routes[state.route] || routes.dashboard;
        title.textContent = route.title;
        clear(actions);
        clear(view);
        paintNav();
        route.render(view, actions).catch((error) => {
            reportError(error);
            clear(view);
            view.appendChild(h('div', { class: 'notice' }, 'بارگذاری این بخش ناموفق بود.'));
        });
    }

    window.__paintShell = paint;
    paint();
}

async function routeFromHash() {
    const raw = (window.location.hash || '#/dashboard').replace(/^#\/?/, '').split('?')[0];
    const name = routes[raw] ? raw : 'dashboard';
    const route = routes[name];
    if (route.permission && !can(route.permission)) {
        window.location.hash = '#/dashboard';
        return;
    }
    state.route = name;
    if (window.__paintShell) window.__paintShell();
}

onUnauthorized(() => {
    state.user = null;
    state.csrf = null;
    if (!document.querySelector('.login-shell')) {
        loginScreen('نشست شما منقضی شده است. دوباره وارد شوید.');
    }
});

window.addEventListener('hashchange', routeFromHash);

(async function boot() {
    try {
        const session = await bootstrapSession();
        if (!session.authenticated) {
            loginScreen();
            return;
        }
        await routeFromHash();
        if (!document.querySelector('.layout')) {
            await renderShell();
        }
    } catch (error) {
        if (error && error.status === 500) {
            loginScreen('سرور در دسترس نیست یا پیکربندی آن کامل نشده است.');
        } else {
            loginScreen();
        }
        console.error(error);
    }
})();
