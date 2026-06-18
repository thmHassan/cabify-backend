<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Privacy Policy for Cabifyit Driver — ride-hailing and dispatch platform.">
    <title>Privacy Policy — Cabifyit Driver</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f1f5f9;
            --surface: #ffffff;
            --text: #0f172a;
            --muted: #64748b;
            --border: #e2e8f0;
            --accent: #2563eb;
            --accent-dark: #1d4ed8;
            --accent-soft: #eff6ff;
            --header-from: #0f172a;
            --header-to: #1e3a5f;
            --shadow: 0 4px 24px rgba(15, 23, 42, 0.06);
            --radius: 0.875rem;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Inter, system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.7;
            -webkit-font-smoothing: antialiased;
        }

        /* ── Header ── */
        .topbar {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 1rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 50;
            backdrop-filter: blur(8px);
            background: rgba(255, 255, 255, 0.92);
        }

        .topbar-inner {
            max-width: 52rem;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            font-size: 1.0625rem;
            font-weight: 700;
            color: var(--text);
            text-decoration: none;
        }

        .brand-icon {
            width: 2rem;
            height: 2rem;
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .brand-icon svg {
            width: 1.125rem;
            height: 1.125rem;
            fill: #fff;
        }

        .badge {
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--accent);
            background: var(--accent-soft);
            padding: 0.3rem 0.75rem;
            border-radius: 999px;
            border: 1px solid #bfdbfe;
        }

        /* ── Hero ── */
        .hero {
            background: linear-gradient(135deg, var(--header-from) 0%, var(--header-to) 60%, #1e40af 100%);
            color: #fff;
            padding: 3rem 1.5rem 3.5rem;
            position: relative;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            pointer-events: none;
        }

        .hero-inner {
            max-width: 52rem;
            margin: 0 auto;
            position: relative;
        }

        .hero-app {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8125rem;
            font-weight: 500;
            color: #93c5fd;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.15);
            padding: 0.35rem 0.875rem;
            border-radius: 999px;
            margin-bottom: 1rem;
        }

        .hero h1 {
            font-size: clamp(1.75rem, 5vw, 2.375rem);
            font-weight: 700;
            line-height: 1.2;
            margin: 0 0 0.75rem;
            letter-spacing: -0.02em;
        }

        .hero-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.875rem;
            color: #94a3b8;
        }

        .hero-meta span {
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }

        .hero-meta svg {
            width: 1rem;
            height: 1rem;
            opacity: 0.7;
        }

        /* ── Main content ── */
        .page {
            max-width: 52rem;
            margin: -1.75rem auto 0;
            padding: 0 1.5rem 4rem;
            position: relative;
        }

        .intro-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.5rem 1.75rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            border-left: 4px solid var(--accent);
        }

        .intro-card p {
            margin: 0;
            color: var(--muted);
            font-size: 0.9375rem;
        }

        /* ── Sections ── */
        .section {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.5rem 1.75rem;
            margin-bottom: 1rem;
            box-shadow: var(--shadow);
        }

        .section-header {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .section-num {
            flex-shrink: 0;
            width: 2.25rem;
            height: 2.25rem;
            background: var(--accent-soft);
            color: var(--accent);
            font-size: 0.875rem;
            font-weight: 700;
            border-radius: 0.625rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #bfdbfe;
        }

        .section h2 {
            font-size: 1.125rem;
            font-weight: 600;
            margin: 0;
            line-height: 2.25rem;
            color: var(--text);
        }

        .section p {
            margin: 0 0 0.875rem;
            color: #334155;
            font-size: 0.9375rem;
        }

        .section p:last-child { margin-bottom: 0; }

        /* ── Sub-cards ── */
        .sub-grid {
            display: grid;
            gap: 0.75rem;
            margin: 1rem 0;
        }

        @media (min-width: 540px) {
            .sub-grid { grid-template-columns: 1fr 1fr; }
        }

        .sub-card {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 0.625rem;
            padding: 1rem 1.125rem;
        }

        .sub-card h3 {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--accent-dark);
            margin: 0 0 0.375rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .sub-card p {
            font-size: 0.875rem;
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
        }

        /* ── Lists ── */
        .check-list {
            list-style: none;
            margin: 0.75rem 0 0;
            padding: 0;
        }

        .check-list li {
            position: relative;
            padding: 0.4rem 0 0.4rem 1.625rem;
            color: #334155;
            font-size: 0.9375rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .check-list li:last-child { border-bottom: none; }

        .check-list li::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0.75rem;
            width: 0.5rem;
            height: 0.5rem;
            background: var(--accent);
            border-radius: 50%;
            opacity: 0.7;
        }

        .dot-list {
            margin: 0.5rem 0 0.875rem;
            padding-left: 1.25rem;
        }

        .dot-list li {
            color: #334155;
            font-size: 0.9375rem;
            margin-bottom: 0.375rem;
        }

        /* ── Highlight ── */
        .highlight {
            background: linear-gradient(135deg, #eff6ff, #f0fdf4);
            border: 1px solid #bfdbfe;
            border-radius: 0.625rem;
            padding: 1rem 1.25rem;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .highlight-icon {
            flex-shrink: 0;
            width: 2rem;
            height: 2rem;
            background: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #bfdbfe;
        }

        .highlight-icon svg {
            width: 1rem;
            height: 1rem;
            stroke: #16a34a;
        }

        .highlight p {
            margin: 0;
            font-size: 0.9375rem;
            font-weight: 500;
            color: #166534;
        }

        /* ── Contact ── */
        .contact-card {
            background: linear-gradient(135deg, var(--header-from), var(--header-to));
            border-radius: var(--radius);
            padding: 1.75rem;
            color: #fff;
            margin-top: 0.5rem;
        }

        .contact-card h2 {
            font-size: 1.125rem;
            font-weight: 600;
            margin: 0 0 0.5rem;
            color: #fff;
        }

        .contact-card p {
            margin: 0;
            font-size: 0.9375rem;
            color: #94a3b8;
            line-height: 1.65;
        }

        /* ── Footer ── */
        footer {
            max-width: 52rem;
            margin: 0 auto;
            padding: 0 1.5rem 2.5rem;
            color: var(--muted);
            font-size: 0.8125rem;
            text-align: center;
        }

        footer a {
            color: var(--accent);
            text-decoration: none;
        }

        footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-inner">
            <a href="{{ url('/') }}" class="brand">
                <span class="brand-icon">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>
                    </svg>
                </span>
                Cabifyit
            </a>
            <span class="badge">Privacy Policy</span>
        </div>
    </header>

    <div class="hero">
        <div class="hero-inner">
            <div class="hero-app">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M17 1.01L7 1c-1.1 0-2 .9-2 2v18c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-1.99-2-1.99zM17 19H7V5h10v14z"/></svg>
                Cabifyit Driver App
            </div>
            <h1>Privacy Policy</h1>
            <div class="hero-meta">
                <span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Effective Date: June 18, 2026
                </span>
                <span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    Your data, protected
                </span>
            </div>
        </div>
    </div>

    <div class="page">
        <div class="intro-card">
            <p>Cabifyit Driver respects your privacy. This Privacy Policy explains how we collect, use, share, and protect information when you use the Cabifyit Driver mobile application.</p>
        </div>

        <article class="section">
            <div class="section-header">
                <span class="section-num">1</span>
                <h2>Information We Collect</h2>
            </div>
            <p>We may collect the following information:</p>
            <div class="sub-grid">
                <div class="sub-card">
                    <h3>Personal Information</h3>
                    <p>Full name, phone number, email address, password, company ID, profile photo, and account details.</p>
                </div>
                <div class="sub-card">
                    <h3>Driver &amp; Vehicle</h3>
                    <p>Vehicle type, vehicle service, registration info, driver documents, document numbers, issue/expiry dates, and uploaded document images.</p>
                </div>
                <div class="sub-card">
                    <h3>Location Information</h3>
                    <p>Precise and approximate location data while using the app. When online or on an active ride, the app may collect location in the background to show nearby requests, track rides, support navigation, improve safety, and complete ride services.</p>
                </div>
                <div class="sub-card">
                    <h3>Ride Information</h3>
                    <p>Ride requests, accepted/completed/cancelled rides, pickup and drop-off details, fares, waiting time, ratings, and ride status.</p>
                </div>
                <div class="sub-card">
                    <h3>Payment &amp; Wallet</h3>
                    <p>Wallet balance, transaction history, package purchases, and payment status. Card payments may be handled by third-party providers such as Stripe. We do not store full card details in the app.</p>
                </div>
                <div class="sub-card">
                    <h3>Messages &amp; Support</h3>
                    <p>Messages exchanged with customers, support tickets, contact-us messages, and related communication history.</p>
                </div>
                <div class="sub-card" style="grid-column: 1 / -1;">
                    <h3>Device &amp; App Information</h3>
                    <p>Device identifier, push notification token, app version, operating system, IP address, crash or diagnostic data, and basic usage information.</p>
                </div>
            </div>
        </article>

        <article class="section">
            <div class="section-header">
                <span class="section-num">2</span>
                <h2>How We Use Your Information</h2>
            </div>
            <p>We use your information to:</p>
            <ul class="check-list">
                <li>Create and manage your driver account.</li>
                <li>Verify your identity, documents, and vehicle details.</li>
                <li>Show nearby ride requests.</li>
                <li>Track ride progress and provide route-related services.</li>
                <li>Enable communication between drivers, customers, and support.</li>
                <li>Process wallet transactions, package purchases, and payments.</li>
                <li>Send ride updates, notifications, alerts, and service messages.</li>
                <li>Improve app performance, safety, and reliability.</li>
                <li>Detect fraud, misuse, unauthorized access, or policy violations.</li>
                <li>Comply with legal, regulatory, and app store requirements.</li>
            </ul>
        </article>

        <article class="section">
            <div class="section-header">
                <span class="section-num">3</span>
                <h2>Location Data</h2>
            </div>
            <p>Cabifyit Driver depends on location services. We may collect location data when the app is open, running in the background, or during active driver availability and rides.</p>
            <p>Location data is used to match drivers with ride requests, calculate routes and distances, track ride status, provide safety support, and improve service quality. You can disable location permissions in your device settings, but some app features may not work properly without location access.</p>
        </article>

        <article class="section">
            <div class="section-header">
                <span class="section-num">4</span>
                <h2>Camera, Photos, and Files</h2>
            </div>
            <p>The app may request access to your camera, photo library, or files so you can upload profile pictures, driver documents, vehicle documents, and other required verification materials. We only use these files for account, document, vehicle, and service verification purposes.</p>
        </article>

        <article class="section">
            <div class="section-header">
                <span class="section-num">5</span>
                <h2>Notifications</h2>
            </div>
            <p>We may use push notifications to send ride requests, ride updates, account alerts, wallet updates, support messages, and important service notices. You can manage notification permissions in your device settings.</p>
        </article>

        <article class="section">
            <div class="section-header">
                <span class="section-num">6</span>
                <h2>Sharing of Information</h2>
            </div>
            <p>We may share information with:</p>
            <ul class="dot-list">
                <li><strong>Customers</strong>, when needed for ride services — such as driver name, profile image, vehicle details, ride status, and location during a ride.</li>
                <li><strong>Service providers</strong> who help operate the app, including hosting, maps, notifications, analytics, payment processing, and customer support.</li>
                <li><strong>Payment providers</strong> such as Stripe for secure payment processing.</li>
                <li><strong>Government, law enforcement, or regulatory authorities</strong> when required by law or to protect safety and legal rights.</li>
                <li><strong>Business partners or affiliated companies</strong> where necessary to provide Cabifyit services.</li>
            </ul>
            <div class="highlight">
                <span class="highlight-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </span>
                <p>We do not sell your personal information.</p>
            </div>
        </article>

        <article class="section">
            <div class="section-header">
                <span class="section-num">7</span>
                <h2>Third-Party Services</h2>
            </div>
            <p>The app may use third-party services, including but not limited to Firebase, Google Maps, MapLibre, Barikoi, Mapifyit, Stripe, and hosting or backend service providers. These services may process data according to their own privacy policies.</p>
        </article>

        <article class="section">
            <div class="section-header">
                <span class="section-num">8</span>
                <h2>Data Retention</h2>
            </div>
            <p>We keep your information for as long as needed to provide the app, maintain your account, meet legal obligations, resolve disputes, prevent fraud, and enforce our agreements. Some ride, payment, support, and verification records may be retained after account closure where required for legal, safety, accounting, or business purposes.</p>
        </article>

        <article class="section">
            <div class="section-header">
                <span class="section-num">9</span>
                <h2>Data Security</h2>
            </div>
            <p>We use reasonable technical and organizational measures to protect your information from unauthorized access, loss, misuse, or alteration. However, no method of transmission or storage is completely secure, and we cannot guarantee absolute security.</p>
        </article>

        <article class="section">
            <div class="section-header">
                <span class="section-num">10</span>
                <h2>Your Choices and Rights</h2>
            </div>
            <p>Depending on your location, you may have the right to access, update, correct, or delete your personal information. You may also request account deletion, withdraw certain permissions, or contact support about privacy-related concerns.</p>
            <p>Disabling permissions such as location, camera, photos, or notifications may limit app functionality.</p>
        </article>

        <article class="section">
            <div class="section-header">
                <span class="section-num">11</span>
                <h2>Children's Privacy</h2>
            </div>
            <p>Cabifyit Driver is intended for registered drivers and is not directed to children. We do not knowingly collect personal information from children.</p>
        </article>

        <article class="section">
            <div class="section-header">
                <span class="section-num">12</span>
                <h2>Changes to This Privacy Policy</h2>
            </div>
            <p>We may update this Privacy Policy from time to time. Any changes will be posted in the app or on the publicly accessible privacy policy page. The updated policy will apply from the effective date shown above.</p>
        </article>

        <article class="contact-card">
            <h2>13. Contact Us</h2>
            <p>If you have questions about this Privacy Policy or how your information is handled, please contact Cabifyit support through the app or through the official Cabifyit contact channel.</p>
        </article>
    </div>

    <footer>
        &copy; {{ date('Y') }} Cabifyit. All rights reserved.
        &nbsp;&middot;&nbsp;
        <a href="{{ route('privacy-policy') }}">Privacy Policy</a>
    </footer>
</body>
</html>
