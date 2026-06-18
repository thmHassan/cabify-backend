<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="How to delete your Cabifyit Driver account.">
    <title>Delete Account — Cabifyit Driver</title>
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

        .topbar {
            background: rgba(255, 255, 255, 0.92);
            border-bottom: 1px solid var(--border);
            padding: 1rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 50;
            backdrop-filter: blur(8px);
        }

        .topbar-inner {
            max-width: 40rem;
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
            max-width: 40rem;
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
            font-size: clamp(1.75rem, 5vw, 2.25rem);
            font-weight: 700;
            line-height: 1.2;
            margin: 0 0 0.75rem;
            letter-spacing: -0.02em;
        }

        .hero p {
            margin: 0;
            font-size: 0.9375rem;
            color: #94a3b8;
            max-width: 32rem;
        }

        .page {
            max-width: 40rem;
            margin: -1.75rem auto 0;
            padding: 0 1.5rem 4rem;
            position: relative;
        }

        .steps-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.75rem;
            box-shadow: var(--shadow);
        }

        .steps-card > h2 {
            font-size: 1rem;
            font-weight: 600;
            margin: 0 0 1.5rem;
            color: var(--text);
        }

        .steps {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .step {
            display: flex;
            gap: 1rem;
            position: relative;
            padding-bottom: 1.5rem;
        }

        .step:last-child {
            padding-bottom: 0;
        }

        .step:not(:last-child)::after {
            content: '';
            position: absolute;
            left: 1.125rem;
            top: 2.5rem;
            bottom: 0;
            width: 2px;
            background: linear-gradient(to bottom, #bfdbfe, var(--border));
        }

        .step-num {
            flex-shrink: 0;
            width: 2.25rem;
            height: 2.25rem;
            background: var(--accent-soft);
            color: var(--accent);
            font-size: 0.875rem;
            font-weight: 700;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #bfdbfe;
            position: relative;
            z-index: 1;
        }

        .step:last-child .step-num {
            background: #fef2f2;
            color: #dc2626;
            border-color: #fecaca;
        }

        .step-body {
            padding-top: 0.25rem;
        }

        .step-body p {
            margin: 0;
            font-size: 0.9375rem;
            color: #334155;
        }

        .step-body strong {
            color: var(--text);
        }

        .notice {
            margin-top: 1.25rem;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 0.625rem;
            padding: 1rem 1.25rem;
            font-size: 0.875rem;
            color: #92400e;
        }

        footer {
            max-width: 40rem;
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
            <span class="badge">Account</span>
        </div>
    </header>

    <div class="hero">
        <div class="hero-inner">
            <div class="hero-app">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M17 1.01L7 1c-1.1 0-2 .9-2 2v18c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-1.99-2-1.99zM17 19H7V5h10v14z"/></svg>
                Cabifyit Driver App
            </div>
            <h1>Delete Your Account</h1>
            <p>Follow the steps below to permanently delete your driver account from the Cabifyit Driver app.</p>
        </div>
    </div>

    <div class="page">
        <div class="steps-card">
            <h2>Flow for Deleting an Account</h2>
            <ol class="steps">
                <li class="step">
                    <span class="step-num">1</span>
                    <div class="step-body">
                        <p>Login to the app with your account credentials.</p>
                    </div>
                </li>
                <li class="step">
                    <span class="step-num">2</span>
                    <div class="step-body">
                        <p>Tap on the <strong>menu icon</strong> on the top left corner of the home screen.</p>
                    </div>
                </li>
                <li class="step">
                    <span class="step-num">3</span>
                    <div class="step-body">
                        <p>Scroll down to the end of the menu.</p>
                    </div>
                </li>
                <li class="step">
                    <span class="step-num">4</span>
                    <div class="step-body">
                        <p>Tap on <strong>Delete Account</strong> button.</p>
                    </div>
                </li>
                <li class="step">
                    <span class="step-num">5</span>
                    <div class="step-body">
                        <p>Complete the account deletion process.</p>
                    </div>
                </li>
            </ol>
            <div class="notice">
                Account deletion is permanent. Once completed, your data cannot be recovered.
            </div>
        </div>
    </div>

    <footer>
        &copy; {{ date('Y') }} Cabifyit. All rights reserved.
        &nbsp;&middot;&nbsp;
        <a href="{{ route('privacy-policy') }}">Privacy Policy</a>
    </footer>
</body>
</html>
