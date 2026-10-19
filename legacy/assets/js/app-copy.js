// assets/js/app.js
(function() {
    'use strict';

    // ---------- State ----------
    let currentUser = null;
    let doors = [];
    let logs = [];
    let users = [];
    let schedules = []; // New state for schedules

    const ARDUINO_PROXY = 'api/arduino.php';

    // ---------- Router ----------
    function router() {
        const hash = location.hash || window.DEFAULT_HASH || '#login';
        const route = hash.split('?')[0];
        renderSidebar(route);
        switch (route) {
            case '#login':
                renderLogin();
                break;
            case '#dashboard':
                loadDashboardData().then(renderDashboard);
                break;
            case '#doors':
                loadDoorsData().then(renderDoors);
                break;
            case '#logs':
                loadLogsData().then(renderLogs);
                break;
            case '#users':
                loadUsersData().then(renderUsers);
                break;
            case '#schedules': // New route
                loadSchedulesData().then(renderSchedules);
                break;
            default:
                location.hash = currentUser ? '#dashboard' : '#login';
                break;
        }
    }

    // ---------- Sidebar Rendering ----------
    function renderSidebar(activeRoute) {
        const app = document.getElementById('app');
        if (activeRoute === '#login' || !currentUser) {
            app.innerHTML = `<div id="content" class="full-page"></div>`;
            return;
        }
        // Added Schedules link
        const sidebarHtml = `
            <div class="sidebar" id="sidebar">
                <div class="logo">🚪 درب‌یاب</div>
                <ul>
                    <li><a href="#dashboard" class="${activeRoute === '#dashboard' ? 'active' : ''}"><i>📊</i><span>داشبورد</span></a></li>
                    <li><a href="#doors" class="${activeRoute === '#doors' ? 'active' : ''}"><i>🚪</i><span>درب‌ها</span></a></li>
                    <li><a href="#schedules" class="${activeRoute === '#schedules' ? 'active' : ''}"><i>⏰</i><span>زمان‌بندی</span></a></li>
                    <li><a href="#logs" class="${activeRoute === '#logs' ? 'active' : ''}"><i>📋</i><span>گزارشات</span></a></li>
                    <li><a href="#users" class="${activeRoute === '#users' ? 'active' : ''}"><i>👥</i><span>کاربران</span></a></li>
                </ul>
                <button class="collapse-btn" id="collapseBtn" title="بستن منو">◀</button>
            </div>
            <div class="main-content" id="mainContent">
                <div class="top-bar">
                    <span class="page-title" id="pageTitle"></span>
                    <button class="logout-btn" id="logoutBtn">خروج</button>
                </div>
                <div class="content-area" id="content"></div>
            </div>
        `;
        app.innerHTML = sidebarHtml;

        document.getElementById('collapseBtn')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('collapsed');
        });

        document.getElementById('logoutBtn').addEventListener('click', () => {
            fetch('logout.php', { method: 'POST', credentials: 'same-origin' })
                .then(() => {
                    currentUser = null;
                    location.hash = '#login';
                });
        });
    }

    function setPageTitle(title) {
        const el = document.getElementById('pageTitle');
        if (el) el.textContent = title;
    }

    // ---------- Login ----------
    function renderLogin() {
        const content = document.getElementById('content');
        content.innerHTML = `
            <div class="login-container">
                <div class="login-box">
                    <h2>ورود به سیستم</h2>
                    <form id="loginForm">
                        <div class="form-group">
                            <input type="text" id="username" placeholder=" " required>
                            <label for="username">نام کاربری</label>
                        </div>
                        <div class="form-group">
                            <input type="password" id="password" placeholder=" " required>
                            <label for="password">رمز عبور</label>
                        </div>
                        <button type="submit" class="btn-login">ورود</button>
                        <div class="error-message" id="loginError"></div>
                    </form>
                </div>
            </div>
        `;

        document.getElementById('loginForm').addEventListener('submit', (e) => {
            e.preventDefault();
            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;
            fetch('api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password }),
                credentials: 'same-origin'
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    currentUser = data.user;
                    location.hash = '#dashboard';
                } else {
                    document.getElementById('loginError').textContent = data.error || 'خطا در ورود';
                }
            })
            .catch(() => {
                document.getElementById('loginError').textContent = 'خطا در ارتباط با سرور';
            });
        });
    }

    // ---------- Dashboard ----------
    async function loadDashboardData() {
        await Promise.all([loadDoorsData(), loadLogsData(), loadUsersData(), loadSchedulesData()]);
    }

    function renderDashboard() {
        setPageTitle('داشبورد');
        const content = document.getElementById('content');
        const totalDoors = doors.length;
        const totalUsers = users.length;
        const todayLogs = logs.filter(log => {
            const logDate = new Date(log.access_time);
            const today = new Date();
            return logDate.toDateString() === today.toDateString();
        }).length;
        const totalSchedules = schedules.length;

        content.innerHTML = `
            <div class="card-grid">
                <div class="card">
                    <h3>تعداد درب‌ها</h3>
                    <div class="value">${totalDoors}</div>
                </div>
                <div class="card">
                    <h3>کاربران فعال</h3>
                    <div class="value">${totalUsers}</div>
                </div>
                <div class="card">
                    <h3>گزارشات امروز</h3>
                    <div class="value">${todayLogs}</div>
                </div>
                <div class="card">
                    <h3>زمان‌بندی‌ها</h3>
                    <div class="value">${totalSchedules}</div>
                </div>
            </div>
            <h3>آخرین رویدادها</h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr><th>زمان</th><th>کاربر</th><th>درب</th><th>وضعیت</th></tr>
                    </thead>
                    <tbody>
                        ${logs.slice(0, 5).map(log => `
                            <tr>
                                <td>${new Date(log.access_time).toLocaleString('fa-IR')}</td>
                                <td>${log.person_name || 'نامشخص'}</td>
                                <td>${log.door_name || 'نامشخص'}</td>
                                <td>${log.status === 'granted' ? 'مجاز' : 'غیرمجاز'}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    // ---------- Doors Management ----------
    async function loadDoorsData() {
        try {
            const res = await fetch('api/doors.php', { credentials: 'same-origin' });
            const data = await res.json();
            doors = data.doors || [];
        } catch (e) {
            console.warn('Failed to load doors', e);
            doors = [];
        }
    }

    function renderDoors() {
        setPageTitle('مدیریت درب‌ها');
        const content = document.getElementById('content');
        content.innerHTML = `
            <button class="btn btn-primary" id="addDoorBtn">➕ درب جدید</button>
            <div class="table-container" style="margin-top:20px;">
                <table>
                    <thead>
                        <tr><th>نام درب</th><th>مکان</th><th>وضعیت</th><th>عملیات</th></tr>
                    </thead>
                    <tbody id="doorsTableBody">
                        ${doors.map(door => `
                            <tr data-id="${door.id}">
                                <td>${door.name}</td>
                                <td>${door.location || '—'}</td>
                                <td>${door.is_active ? 'فعال' : 'غیرفعال'}</td>
                                <td>
                                    <button class="btn btn-sm btn-primary toggle-door" data-id="${door.id}">تغییر وضعیت</button>
                                    <button class="btn btn-sm btn-danger delete-door" data-id="${door.id}">حذف</button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
            <!-- Modal for Add Door -->
            <div class="modal-overlay" id="doorModal">
                <div class="modal">
                    <h3>افزودن درب جدید</h3>
                    <form id="addDoorForm">
                        <div class="form-group">
                            <input type="text" id="doorName" placeholder=" " required>
                            <label for="doorName">نام درب</label>
                        </div>
                        <div class="form-group">
                            <input type="text" id="doorLocation" placeholder=" ">
                            <label for="doorLocation">مکان (اختیاری)</label>
                        </div>
                        <div class="toggle-row">
                            <span>فعال بودن:</span>
                            <label class="switch">
                                <input type="checkbox" id="doorEnabled" checked>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" id="cancelDoorBtn">لغو</button>
                            <button type="submit" class="btn btn-primary">ذخیره</button>
                        </div>
                    </form>
                </div>
            </div>
        `;

        document.querySelectorAll('.toggle-door').forEach(btn => {
            btn.addEventListener('click', (e) => toggleDoorStatus(e.target.dataset.id));
        });
        document.querySelectorAll('.delete-door').forEach(btn => {
            btn.addEventListener('click', (e) => deleteDoor(e.target.dataset.id));
        });
        document.getElementById('addDoorBtn').addEventListener('click', openAddDoorModal);
        document.getElementById('cancelDoorBtn')?.addEventListener('click', closeDoorModal);
        document.getElementById('addDoorForm').addEventListener('submit', submitAddDoor);
    }

    function openAddDoorModal() {
        document.getElementById('doorModal').classList.add('active');
    }

    function closeDoorModal() {
        document.getElementById('doorModal').classList.remove('active');
        document.getElementById('addDoorForm').reset();
    }

    function submitAddDoor(e) {
        e.preventDefault();
        const name = document.getElementById('doorName').value;
        const location = document.getElementById('doorLocation').value;
        const enabled = document.getElementById('doorEnabled').checked;

        fetch('api/doors.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, location, is_active: enabled }),
            credentials: 'same-origin'
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                closeDoorModal();
                showToast('درب جدید با موفقیت اضافه شد');
                loadDoorsData().then(renderDoors);
            } else {
                showToast('خطا در افزودن درب: ' + (data.error || ''), 'error');            }
        })
        .catch(() => alert('خطا در ارتباط با سرور'));
    }

    function toggleDoorStatus(doorId) {
        fetch('api/doors.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: doorId, toggle: true }),
            credentials: 'same-origin'
        }).then(() => {
            loadDoorsData().then(renderDoors);
        });
    }

    function deleteDoor(doorId) {
        if (!confirm('آیا از حذف این درب اطمینان دارید؟')) return;
        fetch('api/doors.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: doorId }),
            credentials: 'same-origin'
        }).then(() => {
            loadDoorsData().then(renderDoors);
        });
    }

    // ---------- Users Management ----------
    async function loadUsersData() {
        try {
            const res = await fetch('api/persons.php', { credentials: 'same-origin' });
            const data = await res.json();
            users = data.persons || [];
        } catch (e) {
            console.warn('Failed to load users', e);
            users = [];
        }
    }

    function renderUsers() {
        setPageTitle('مدیریت کاربران');
        const content = document.getElementById('content');
        content.innerHTML = `
            <button class="btn btn-primary" id="addUserBtn">➕ کاربر جدید</button>
            <div class="table-container" style="margin-top:20px;">
                <table>
                    <thead>
                        <tr><th>نام</th><th>شماره کارت / PIN</th><th>سطح دسترسی</th><th>وضعیت</th><th>عملیات</th></tr>
                    </thead>
                    <tbody id="usersTableBody">
                        ${users.map(user => `
                            <tr data-id="${user.id}">
                                <td>${user.name}</td>
                                <td>${user.card_number}</td>
                                <td>${user.role === 'admin' ? 'مدیر' : 'کاربر'}</td>
                                <td>${user.enabled ? 'فعال' : 'غیرفعال'}</td>
                                <td>
                                   <button class="btn btn-sm btn-primary update-user" onclick="toggleUserStatus(${user.id})">تغییر وضعیت</button>
                                   <button class="btn btn-sm btn-danger delete-user" data-id="${user.id}">حذف</button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
            <!-- Modal for Add User -->
            <div class="modal-overlay" id="userModal">
                <div class="modal">
                    <h3>افزودن کاربر جدید</h3>
                    <form id="addUserForm">
                        <div class="form-group">
                            <input type="text" id="userName" placeholder=" " required>
                            <label for="userName">نام کامل</label>
                        </div>
                        <div class="form-group">
                            <input type="text" id="userPin" placeholder=" " required>
                            <label for="userPin">شماره کارت / PIN</label>
                        </div>
                        <div class="form-group">
                            <select id="userRole" class="form-control" required>
                                <option value="user">کاربر عادی</option>
                                <option value="admin">مدیر</option>
                            </select>
                            <label for="userRole">سطح دسترسی</label>
                        </div>
                        <div class="toggle-row">
                            <span>فعال بودن:</span>
                            <label class="switch">
                                <input type="checkbox" id="userEnabled" checked>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" id="cancelUserBtn">لغو</button>
                            <button type="submit" class="btn btn-primary">ذخیره</button>
                        </div>
                    </form>
                </div>
            </div>
        `;
        document.querySelectorAll('.update-user').forEach(btn => {
            btn.addEventListener('click', (e) => toggleUserStatus(e.target.dataset.id));
        });
        document.querySelectorAll('.delete-user').forEach(btn => {
            btn.addEventListener('click', (e) => deleteUser(e.target.dataset.id));
        });
        document.getElementById('addUserBtn').addEventListener('click', openAddUserModal);
        document.getElementById('cancelUserBtn')?.addEventListener('click', closeUserModal);
        document.getElementById('addUserForm').addEventListener('submit', submitAddUser);
    }

    function openAddUserModal() {
        document.getElementById('userModal').classList.add('active');
    }

    function closeUserModal() {
        document.getElementById('userModal').classList.remove('active');
        document.getElementById('addUserForm').reset();
    }

    function submitAddUser(e) {
        e.preventDefault();
        const name = document.getElementById('userName').value;
        const pin = document.getElementById('userPin').value;
        const role = document.getElementById('userRole').value;
        const enabled = document.getElementById('userEnabled').checked;

        fetch('api/persons.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                name, 
                card_number: pin, 
                role, 
                enabled 
            }),
            credentials: 'same-origin'
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                closeUserModal();
                loadUsersData().then(renderUsers);
            } else {
                alert('خطا در افزودن کاربر: ' + (data.error || ''));
            }
        })
        .catch(() => alert('خطا در ارتباط با سرور'));
    }

    function deleteUser(userId) {
        if (!confirm('آیا از حذف این کاربر اطمینان دارید؟')) return;
        fetch('api/persons.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: userId }),
            credentials: 'same-origin'
        }).then(() => {
            loadUsersData().then(renderUsers);
        });
    }

    function toggleUserStatus(userId) {
        fetch('api/persons.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: doorId, toggle: true }),
            credentials: 'same-origin'
        }).then(() => {
            loadUsersData().then(renderUsers);
        });
    }
    async function toggleUserStatus(userId){
        try{
            const response = await fetch(`/api/persons/${userId}/status`,{
                method:'PUT'
            });  
            const result = await response.json();  
            if(result.success){
                loadUsersData();
            } 
        }catch(err){
            console.error("Status toggle error:",err);
        }
    
    }
    

    // ---------- Schedules Management ----------
    async function loadSchedulesData() {
        try {
            const res = await fetch('api/schedules.php', { credentials: 'same-origin' });
            const data = await res.json();
            schedules = data.schedules || [];
        } catch (e) {
            console.warn('Failed to load schedules', e);
            schedules = [];
        }
    }

    function renderSchedules() {
        setPageTitle('مدیریت زمان‌بندی دسترسی');
        const content = document.getElementById('content');
        content.innerHTML = `
            <button class="btn btn-primary" id="addScheduleBtn">➕ زمان‌بندی جدید</button>
            <div class="table-container" style="margin-top:20px;">
                <table>
                    <thead>
                        <tr><th>کاربر</th><th>درب</th><th>روزهای هفته</th><th>ساعت شروع</th><th>ساعت پایان</th><th>فعال</th><th>عملیات</th></tr>
                    </thead>
                    <tbody id="schedulesTableBody">
                        ${schedules.map(sched => `
                            <tr data-id="${sched.id}">
                                <td>${sched.person_name}</td>
                                <td>${sched.door_name}</td>
                                <td>${formatDays(sched.days)}</td>
                                <td>${sched.start_time || '—'}</td>
                                <td>${sched.end_time || '—'}</td>
                                <td>${sched.enabled ? 'فعال' : 'غیرفعال'}</td>
                                <td>
                                    <button class="btn btn-sm btn-danger delete-schedule" data-id="${sched.id}">حذف</button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
            <!-- Modal for Add Schedule -->
            <div class="modal-overlay" id="scheduleModal">
                <div class="modal">
                    <h3>زمان‌بندی جدید</h3>
                    <form id="addScheduleForm">
                        <div class="form-group">
                            <select id="schedulePerson" required>
                                <option value="">انتخاب کاربر</option>
                                ${users.map(user => `<option value="${user.id}">${user.name}</option>`).join('')}
                            </select>
                            <label for="schedulePerson">کاربر</label>
                        </div>
                        <div class="form-group">
                            <select id="scheduleDoor" required>
                                <option value="">انتخاب درب</option>
                                ${doors.map(door => `<option value="${door.id}">${door.name}</option>`).join('')}
                            </select>
                            <label for="scheduleDoor">درب</label>
                        </div>
                        <div class="form-group">
                        <label for="weekday-picker">روزهای هفته</label>
                        <div id="weekday-picker" class="weekday-picker" data-name="schedule-days"></div>
                        </div>                    
                        <div class="schedule-time-inputs">
                            <div class="form-group" style="flex:1;">
                                <input type="time" id="startTime" placeholder=" " required>
                                <label for="startTime">ساعت شروع</label>
                            </div>
                            <div class="form-group" style="flex:1;">
                                <input type="time" id="endTime" placeholder=" " required>
                                <label for="endTime">ساعت پایان</label>
                            </div>
                        </div>
                        <div class="toggle-row">
                            <span>فعال بودن:</span>
                            <label class="switch">
                                <input type="checkbox" id="scheduleEnabled" checked>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" id="cancelScheduleBtn">لغو</button>
                            <button type="submit" class="btn btn-primary">ذخیره</button>
                        </div>
                    </form>
                </div>
            </div>
        `;

        document.getElementById('addScheduleBtn').addEventListener('click', openAddScheduleModal);
        document.getElementById('cancelScheduleBtn')?.addEventListener('click', closeScheduleModal);
        document.getElementById('addScheduleForm').addEventListener('submit', submitAddSchedule);
        document.querySelectorAll('.delete-schedule').forEach(btn => {
            btn.addEventListener('click', (e) => deleteSchedule(e.target.dataset.id));
        });
    }
    // Toast notification system
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        background: ${type === 'success' ? '#5cb85c' : '#d9534f'};
        color: white;
        padding: 12px 24px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        z-index: 2000;
        font-family: inherit;
        direction: rtl;
        animation: slideDown 0.3s ease;
    `;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}
// Format day numbers (1-7) to Persian day names
function formatDays(daysStr) {
    if (!daysStr) return 'همه روزه';
    const dayMap = {
        '1': 'شنبه',
        '2': 'یکشنبه',
        '3': 'دوشنبه',
        '4': 'سه‌شنبه',
        '5': 'چهارشنبه',
        '6': 'پنجشنبه',
        '7': 'جمعه'
    };
    return daysStr.split(',').map(d => dayMap[d] || '').join('، ');
}
    function openAddScheduleModal() {
        // Populate dropdowns with latest users and doors
        const personSelect = document.getElementById('schedulePerson');
        const doorSelect = document.getElementById('scheduleDoor');
        if (personSelect) {
            personSelect.innerHTML = '<option value="">انتخاب کاربر</option>' + 
                users.map(u => `<option value="${u.id}">${u.name}</option>`).join('');
        }
        if (doorSelect) {
            doorSelect.innerHTML = '<option value="">انتخاب درب</option>' + 
                doors.map(d => `<option value="${d.id}">${d.name}</option>`).join('');
        }
        document.getElementById('scheduleModal').classList.add('active');
    }

    function closeScheduleModal() {
        document.getElementById('scheduleModal').classList.remove('active');
        document.getElementById('addScheduleForm').reset();
    }

    function submitAddSchedule(e) {
        e.preventDefault();
        const personId = document.getElementById('schedulePerson').value;
        const doorId = document.getElementById('scheduleDoor').value;
        const startTime = document.getElementById('startTime').value;
        const endTime = document.getElementById('endTime').value;
        const enabled = document.getElementById('scheduleEnabled').checked;

        // Get selected days as comma-separated string
        const dayCheckboxes = document.querySelectorAll('.schedule-days input:checked');
        const days = Array.from(dayCheckboxes).map(cb => cb.value).join(',');

        fetch('api/schedules.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                person_id: personId,
                door_id: doorId,
                days: days,
                start_time: startTime,
                end_time: endTime,
                enabled: enabled
            }),
            credentials: 'same-origin'
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                closeScheduleModal();
                loadSchedulesData().then(renderSchedules);
            } else {
                alert('خطا در افزودن زمان‌بندی: ' + (data.error || ''));
            }
        })
        .catch(() => alert('خطا در ارتباط با سرور'));
    }

    function deleteSchedule(scheduleId) {
        if (!confirm('آیا از حذف این زمان‌بندی اطمینان دارید؟')) return;
        fetch('api/schedules.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: scheduleId }),
            credentials: 'same-origin'
        }).then(() => {
            loadSchedulesData().then(renderSchedules);
        });
    }

    // ---------- Logs ----------
    async function loadLogsData() {
        try {
            const res = await fetch('api/logs.php', { credentials: 'same-origin' });
            const data = await res.json();
            logs = data.logs || [];
        } catch (e) {
            console.warn('Failed to load logs', e);
            logs = [];
        }
    }

    function renderLogs() {
        setPageTitle('گزارشات دسترسی');
        const content = document.getElementById('content');
        content.innerHTML = `
            <div class="table-container">
                <table>
                    <thead>
                        <tr><th>زمان</th><th>کاربر</th><th>درب</th><th>وضعیت</th></tr>
                    </thead>
                    <tbody>
                        ${logs.map(log => `
                            <tr>
                                <td>${new Date(log.access_time).toLocaleString('fa-IR')}</td>
                                <td>${log.person_name || 'نامشخص'}</td>
                                <td>${log.door_name || 'نامشخص'}</td>
                                <td>${log.status === 'granted' ? 'مجاز' : 'غیرمجاز'}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    // ---------- Arduino Communication ----------
    function sendArduinoCommand(endpoint, method = 'GET', data = null) {
        const url = `${ARDUINO_PROXY}?endpoint=${endpoint}`;
        const options = {
            method: method,
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin'
        };
        if (data) {
            options.body = JSON.stringify(data);
        }
        return fetch(url, options).then(res => res.json());
    }

    // ---------- Initialize ----------
    window.addEventListener('hashchange', router);
    window.addEventListener('load', () => {
        fetch('api/auth.php?check', { credentials: 'same-origin' })
            .then(res => res.json())
            .then(data => {
                currentUser = data.authenticated ? data.user : null;
                router();
            })
            .catch(() => {
                currentUser = null;
                router();
            });
    });
    // backend/routes.js (or similar)

// --- FIX FOR ADD DOOR ---
app.post('/api/doors', (req, res) => {
    // Check if the body parser correctly processed the incoming JSON
    if (!req.body || !req.body.doorName || !req.body.location) {
        return res.status(400).json({ message: "Missing door details." });
    }
    // Logic to save door...
    console.log(`Received new door: ${req.body.doorName}`);
    res.status(201).json({ success: true, message: "Door added successfully." });
});

// --- FIX FOR ADD USER ---
// backend/routes/userRoutes.js (GET /api/users handler)

// Assuming 'User' is your database model (Mongoose, Sequelize, etc.)
app.get('/api/persons', async (req, res) => {
    try {
        const dbUsers = await User.find({}); // Retrieve all users

        // CRITICAL TRANSFORMATION STEP: Map DB fields to Frontend expected fields
        const usersForDashboard = dbUsers.map(user => ({
            // **ACTION REQUIRED**: Verify these field names match your frontend expectations
            rfidTag: user.tag_id || user.rfid || user._id, // FIX: Match frontend expected key
            userName: user.name || user.username,          // FIX: Match frontend expected key
            accessLevel: user.level || 'Standard'
        }));

        // If dbUsers was empty, usersForDashboard will be [], causing frontend to display nothing.
        return res.status(200).json(usersForDashboard); 

    } catch (error) {
        console.error("Error fetching users:", error);
        // If this block executes, the frontend likely sees a 500 error.
        return res.status(200).json({ message: "Failed to retrieve user list." });
    }
});


})();