<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>App Maintenance</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #17202a;
            background: #f4f7f9;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: #f4f7f9;
        }

        button,
        input,
        textarea {
            font: inherit;
        }

        .shell {
            width: min(1100px, calc(100% - 32px));
            margin: 0 auto;
            padding: 32px 0;
        }

        .topbar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 24px;
        }

        h1 {
            margin: 0;
            font-size: 28px;
            line-height: 1.2;
            font-weight: 700;
            letter-spacing: 0;
        }

        .subtitle {
            margin: 8px 0 0;
            color: #5d6b78;
            font-size: 14px;
        }

        .status-pill {
            min-width: 150px;
            border-radius: 999px;
            border: 1px solid #cad5df;
            background: #ffffff;
            padding: 9px 14px;
            text-align: center;
            font-size: 13px;
            font-weight: 700;
            color: #44515e;
        }

        .status-pill.danger {
            border-color: #efb5ac;
            background: #fff0ee;
            color: #a73424;
        }

        .panel {
            border: 1px solid #d6e0e8;
            border-radius: 8px;
            background: #ffffff;
            box-shadow: 0 12px 32px rgba(24, 39, 55, 0.08);
        }

        .section {
            padding: 22px;
            border-bottom: 1px solid #e3eaf0;
        }

        .section:last-child {
            border-bottom: 0;
        }

        .section-title {
            margin: 0 0 14px;
            font-size: 15px;
            font-weight: 700;
            color: #263442;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 700;
            color: #344452;
        }

        input,
        textarea {
            width: 100%;
            border: 1px solid #c8d4de;
            border-radius: 6px;
            background: #ffffff;
            padding: 11px 12px;
            color: #17202a;
            outline: none;
        }

        input:focus,
        textarea:focus {
            border-color: #2271b1;
            box-shadow: 0 0 0 3px rgba(34, 113, 177, 0.15);
        }

        textarea {
            min-height: 92px;
            resize: vertical;
        }

        .switch-row {
            display: grid;
            grid-template-columns: 1fr auto;
            align-items: center;
            gap: 16px;
            padding: 16px;
            border: 1px solid #d6e0e8;
            border-radius: 8px;
            background: #fbfcfd;
        }

        .switch-title {
            margin: 0;
            font-weight: 700;
        }

        .switch-copy {
            margin: 4px 0 0;
            color: #64727f;
            font-size: 13px;
        }

        .toggle {
            display: inline-grid;
            grid-template-columns: 1fr 1fr;
            width: 190px;
            overflow: hidden;
            border: 1px solid #bdcbd6;
            border-radius: 6px;
            background: #ffffff;
        }

        .toggle input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .toggle span {
            display: block;
            padding: 10px 12px;
            text-align: center;
            font-size: 13px;
            font-weight: 700;
            color: #5a6875;
            cursor: pointer;
        }

        .toggle input:checked + span {
            background: #2271b1;
            color: #ffffff;
        }

        .toggle label:first-child input:checked + span {
            background: #147a4d;
        }

        .toggle label:last-child input:checked + span {
            background: #b43b2b;
        }

        .actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
            padding: 18px 22px;
            background: #fbfcfd;
            border-top: 1px solid #e3eaf0;
        }

        .btn {
            border: 1px solid #b8c7d2;
            border-radius: 6px;
            background: #ffffff;
            color: #263442;
            padding: 10px 16px;
            font-weight: 700;
            cursor: pointer;
        }

        .btn.primary {
            border-color: #195f95;
            background: #2271b1;
            color: #ffffff;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: wait;
        }

        .notice {
            display: none;
            margin-bottom: 18px;
            border-radius: 6px;
            padding: 12px 14px;
            font-size: 14px;
            font-weight: 600;
        }

        .notice.show {
            display: block;
        }

        .notice.success {
            border: 1px solid #9bcdb2;
            background: #eef9f2;
            color: #17613d;
        }

        .notice.error {
            border: 1px solid #e3aaa1;
            background: #fff0ee;
            color: #9c3123;
        }

        .help {
            margin-top: 12px;
            color: #64727f;
            font-size: 12px;
            line-height: 1.5;
        }

        @media (max-width: 760px) {
            .topbar,
            .grid,
            .switch-row {
                grid-template-columns: 1fr;
            }

            .topbar {
                display: block;
            }

            .status-pill {
                margin-top: 14px;
                width: 100%;
            }

            .toggle {
                width: 100%;
            }

            .actions {
                flex-direction: column-reverse;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
    <main class="shell">
        <div class="topbar">
            <div>
                <h1>App Maintenance</h1>
                <p class="subtitle">Global Super Admin control for Driver and Customer mobile apps.</p>
            </div>
            <div id="overallStatus" class="status-pill">Loading</div>
        </div>

        <div id="notice" class="notice"></div>

        <form id="maintenanceForm" class="panel">
            <section class="section">
                <h2 class="section-title">Access Token</h2>
                <label for="token">Super Admin Bearer Token</label>
                <input id="token" type="password" autocomplete="off" placeholder="Paste super admin API token">
                <p class="help">The token is stored only in this browser local storage for API calls.</p>
            </section>

            <section class="section">
                <h2 class="section-title">App Status</h2>
                <div class="grid">
                    <div class="switch-row">
                        <div>
                            <p class="switch-title">Driver App</p>
                            <p class="switch-copy">Disable to show maintenance screen in driver app.</p>
                        </div>
                        <div class="toggle" role="radiogroup" aria-label="Driver app status">
                            <label>
                                <input type="radio" name="driver_app" value="enable" checked>
                                <span>Enable</span>
                            </label>
                            <label>
                                <input type="radio" name="driver_app" value="disable">
                                <span>Disable</span>
                            </label>
                        </div>
                    </div>

                    <div class="switch-row">
                        <div>
                            <p class="switch-title">Customer App</p>
                            <p class="switch-copy">Disable to show maintenance screen in customer app.</p>
                        </div>
                        <div class="toggle" role="radiogroup" aria-label="Customer app status">
                            <label>
                                <input type="radio" name="customer_app" value="enable" checked>
                                <span>Enable</span>
                            </label>
                            <label>
                                <input type="radio" name="customer_app" value="disable">
                                <span>Disable</span>
                            </label>
                        </div>
                    </div>
                </div>
            </section>

            <section class="section">
                <h2 class="section-title">Message And Schedule</h2>
                <label for="message">Maintenance Message</label>
                <textarea id="message" name="message">Now this app is under maintenance</textarea>

                <div class="grid" style="margin-top: 16px;">
                    <div>
                        <label for="starts_at">Starts At</label>
                        <input id="starts_at" name="starts_at" type="datetime-local">
                    </div>
                    <div>
                        <label for="ends_at">Ends At</label>
                        <input id="ends_at" name="ends_at" type="datetime-local">
                    </div>
                </div>
                <p class="help">Leave schedule empty if maintenance should apply immediately whenever app status is disabled.</p>
            </section>

            <div class="actions">
                <button id="refreshBtn" class="btn" type="button">Refresh</button>
                <button id="saveBtn" class="btn primary" type="submit">Save Settings</button>
            </div>
        </form>
    </main>

    <script>
        const tokenInput = document.getElementById('token');
        const form = document.getElementById('maintenanceForm');
        const notice = document.getElementById('notice');
        const statusPill = document.getElementById('overallStatus');
        const refreshBtn = document.getElementById('refreshBtn');
        const saveBtn = document.getElementById('saveBtn');

        tokenInput.value = localStorage.getItem('super_admin_token') || '';

        function selected(name) {
            return form.querySelector(`input[name="${name}"]:checked`).value;
        }

        function setSelected(name, value) {
            const input = form.querySelector(`input[name="${name}"][value="${value || 'enable'}"]`);
            if (input) {
                input.checked = true;
            }
        }

        function localDateValue(value) {
            if (!value) {
                return '';
            }

            const date = new Date(value);
            if (Number.isNaN(date.getTime())) {
                return '';
            }

            const offsetDate = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
            return offsetDate.toISOString().slice(0, 16);
        }

        function showNotice(type, message) {
            notice.className = `notice ${type} show`;
            notice.textContent = message;
        }

        function clearNotice() {
            notice.className = 'notice';
            notice.textContent = '';
        }

        function headers() {
            const token = tokenInput.value.trim();
            localStorage.setItem('super_admin_token', token);

            return {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`,
            };
        }

        function updateOverallStatus(setting) {
            const maintenanceOn = setting?.driver?.maintenance || setting?.customer?.maintenance;
            statusPill.textContent = maintenanceOn ? 'Maintenance Active' : 'Apps Enabled';
            statusPill.classList.toggle('danger', maintenanceOn);
        }

        async function loadSettings() {
            clearNotice();
            refreshBtn.disabled = true;
            statusPill.textContent = 'Loading';

            try {
                const response = await fetch('/api/super-admin/app-maintenance', {
                    headers: headers(),
                });
                const data = await response.json();

                if (!response.ok || data.error) {
                    throw new Error(data.message || 'Unable to load settings');
                }

                const setting = data.setting || {};
                setSelected('driver_app', setting.driver_app);
                setSelected('customer_app', setting.customer_app);
                document.getElementById('message').value = setting.message || 'Now this app is under maintenance';
                document.getElementById('starts_at').value = localDateValue(setting.starts_at);
                document.getElementById('ends_at').value = localDateValue(setting.ends_at);
                updateOverallStatus(setting);
            } catch (error) {
                statusPill.textContent = 'Needs Token';
                statusPill.classList.add('danger');
                showNotice('error', error.message);
            } finally {
                refreshBtn.disabled = false;
            }
        }

        async function saveSettings(event) {
            event.preventDefault();
            clearNotice();
            saveBtn.disabled = true;

            const payload = {
                driver_app: selected('driver_app'),
                customer_app: selected('customer_app'),
                message: document.getElementById('message').value.trim(),
                starts_at: document.getElementById('starts_at').value || null,
                ends_at: document.getElementById('ends_at').value || null,
            };

            try {
                const response = await fetch('/api/super-admin/app-maintenance', {
                    method: 'POST',
                    headers: headers(),
                    body: JSON.stringify(payload),
                });
                const data = await response.json();

                if (!response.ok || data.error) {
                    throw new Error(data.message || 'Unable to save settings');
                }

                updateOverallStatus(data.setting || {});
                showNotice('success', data.message || 'Settings saved successfully');
            } catch (error) {
                showNotice('error', error.message);
            } finally {
                saveBtn.disabled = false;
            }
        }

        refreshBtn.addEventListener('click', loadSettings);
        form.addEventListener('submit', saveSettings);
        tokenInput.addEventListener('change', () => localStorage.setItem('super_admin_token', tokenInput.value.trim()));

        if (tokenInput.value.trim()) {
            loadSettings();
        } else {
            statusPill.textContent = 'Needs Token';
            statusPill.classList.add('danger');
        }
    </script>
</body>
</html>
