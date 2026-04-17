<?php
// Simple frontend for Nawala API checker.
// Runs on XAMPP (htdocs/nawala-api/index.php).
// UI: Inter, theme vars, mode terang saja.
?>
<!doctype html>
<html lang="id">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="theme-color" content="#f7941d" />
    <title>ABSPositif — Cek Nawala Domain Komdigi</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet" />
    <style>
      :root {
        --background: 210 25% 97%;
        --foreground: 222 47% 11%;
        --card: 0 0% 100%;
        --card-foreground: 222 47% 11%;
        --primary: 199 89% 48%;
        --primary-foreground: 0 0% 100%;
        --secondary: 215 28% 17%;
        --secondary-foreground: 210 40% 98%;
        --muted: 214 32% 91%;
        --muted-foreground: 215 16% 47%;
        --accent: 172 66% 50%;
        --accent-foreground: 222 47% 11%;
        --destructive: 0 84% 60%;
        --destructive-foreground: 0 0% 100%;
        --success: 142 76% 36%;
        --success-foreground: 0 0% 100%;
        --warning: 38 92% 50%;
        --warning-foreground: 222 47% 11%;
        --border: 214 32% 91%;
        --input: 214 32% 91%;
        --ring: 199 89% 48%;
        --radius: 0.75rem;
      }

      *,
      *::before,
      *::after {
        box-sizing: border-box;
      }

      html {
        -webkit-text-size-adjust: 100%;
      }

      body {
        margin: 0;
        min-height: 100vh;
        font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        background-color: hsl(var(--background));
        color: hsl(var(--foreground));
        background-image:
          radial-gradient(ellipse at top, hsl(199 89% 48% / 0.06) 0%, transparent 50%),
          radial-gradient(ellipse at bottom right, hsl(172 66% 50% / 0.04) 0%, transparent 50%);
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
        line-height: 1.5;
      }

      /* Grid halus seperti nawala.asia */
      body::before {
        content: '';
        position: fixed;
        inset: 0;
        pointer-events: none;
        z-index: 0;
        background-image:
          linear-gradient(to right, hsl(199 89% 48% / 0.04) 1px, transparent 1px),
          linear-gradient(to bottom, hsl(199 89% 48% / 0.04) 1px, transparent 1px);
        background-size: 40px 40px;
        opacity: 0.9;
      }

      .wrap {
        position: relative;
        z-index: 1;
        max-width: 56rem;
        margin: 0 auto;
        padding: 1.5rem 1rem 3rem;
      }

      @media (min-width: 640px) {
        .wrap {
          padding: 2.5rem 1.25rem 4rem;
        }
      }

      .hero {
        text-align: center;
        margin-bottom: 2rem;
        padding: 0 0.5rem;
      }

      @media (min-width: 640px) {
        .hero {
          padding: 0 1.5rem;
        }
      }

      .hero-icon-wrap {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 5rem;
        height: 5rem;
        margin: 0 auto 1rem;
        border-radius: 1rem;
        background: linear-gradient(to bottom right, hsl(var(--primary) / 0.2), hsl(var(--accent) / 0.1));
        border: 1px solid hsl(var(--primary) / 0.2);
        box-shadow: 0 0 20px -5px hsl(199 89% 48% / 0.4);
      }

      .hero-icon-wrap svg {
        width: 2.5rem;
        height: 2.5rem;
        color: hsl(var(--primary));
      }

      .hero-title {
        margin: 0;
        font-size: 1.75rem;
        font-weight: 700;
        letter-spacing: -0.03em;
        line-height: 1.15;
        background: linear-gradient(to right, hsl(var(--foreground)), hsl(var(--foreground) / 0.72));
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
      }

      @media (min-width: 768px) {
        .hero-title {
          font-size: 2.25rem;
        }
      }

      .hero-sub {
        margin: 0.75rem auto 0;
        max-width: 42rem;
        font-size: 0.875rem;
        color: hsl(var(--muted-foreground));
        line-height: 1.55;
      }

      .card {
        background: hsl(var(--card) / 0.85);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        color: hsl(var(--card-foreground));
        border: 1px solid hsl(var(--border) / 0.5);
        border-radius: var(--radius);
        padding: 1.25rem;
        box-shadow:
          0 1px 2px rgb(0 0 0 / 0.05),
          0 20px 40px -12px hsl(var(--primary) / 0.12);
      }

      @media (min-width: 640px) {
        .card {
          padding: 1.75rem;
        }
      }

      .card-inner-title {
        font-size: 0.9375rem;
        font-weight: 600;
        margin: 0 0 1rem;
        color: hsl(var(--foreground));
      }

      .textarea-wrap {
        position: relative;
        margin-top: 0.5rem;
      }

      .textarea-main {
        min-height: 280px;
        font-family: 'JetBrains Mono', ui-monospace, monospace;
        font-size: 0.875rem;
        line-height: 1.5;
        padding-bottom: 2.25rem;
      }

      .line-counter {
        position: absolute;
        bottom: 0.75rem;
        right: 0.75rem;
        font-size: 0.75rem;
        font-family: 'JetBrains Mono', ui-monospace, monospace;
        padding: 0.25rem 0.5rem;
        border-radius: calc(var(--radius) - 4px);
        background: hsl(var(--secondary) / 0.35);
        color: hsl(var(--muted-foreground));
        border: 1px solid hsl(var(--border) / 0.4);
      }

      .line-counter.over {
        background: hsl(var(--destructive) / 0.2);
        color: hsl(var(--destructive));
        border-color: hsl(var(--destructive) / 0.25);
      }

      .form-error {
        display: none;
        align-items: flex-start;
        gap: 0.5rem;
        margin-top: 0.75rem;
        padding: 0.5rem 0.75rem;
        font-size: 0.875rem;
        color: hsl(var(--destructive));
        background: hsl(var(--destructive) / 0.08);
        border: 1px solid hsl(var(--destructive) / 0.22);
        border-radius: calc(var(--radius) - 2px);
      }

      .form-error.visible {
        display: flex;
      }

      .form-error svg {
        flex-shrink: 0;
        width: 1rem;
        height: 1rem;
        margin-top: 0.125rem;
      }

      label.field-label {
        display: block;
        margin: 1rem 0 0.375rem;
        font-size: 0.8125rem;
        font-weight: 500;
        color: hsl(var(--foreground));
      }

      label.field-label:first-of-type {
        margin-top: 0;
      }

      .input,
      .textarea {
        width: 100%;
        padding: 0.625rem 0.875rem;
        font-size: 0.9375rem;
        font-family: inherit;
        color: hsl(var(--foreground));
        background: hsl(var(--background));
        border: 1px solid hsl(var(--input));
        border-radius: calc(var(--radius) - 2px);
        outline: none;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
      }

      .input::placeholder,
      .textarea::placeholder {
        color: hsl(var(--muted-foreground));
      }

      .input:focus,
      .textarea:focus {
        border-color: hsl(var(--primary) / 0.5);
        box-shadow: 0 0 0 3px hsl(var(--primary) / 0.15);
      }

      .textarea {
        resize: vertical;
        min-height: 5rem;
        margin-top: 0;
      }

      .row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        align-items: flex-end;
        margin-top: 0.75rem;
      }

      .row .grow {
        flex: 1 1 200px;
        min-width: 0;
      }

      .btn-check {
        width: 100%;
        margin-top: 1rem;
        padding: 0.625rem 1.25rem;
        min-height: 3rem;
        font-size: 1rem;
        font-weight: 600;
        font-family: inherit;
        color: hsl(var(--primary-foreground));
        background: hsl(var(--primary));
        border: none;
        border-radius: calc(var(--radius) - 2px);
        cursor: pointer;
        box-shadow: 0 0 20px -5px hsl(199 89% 48% / 0.55);
        transition: filter 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
      }

      .btn-check:hover:not(:disabled) {
        filter: brightness(1.05);
        transform: scale(1.01);
      }

      .btn-check:disabled {
        opacity: 0.55;
        cursor: not-allowed;
        transform: none;
      }

      .btn-check .spin {
        width: 1.25rem;
        height: 1.25rem;
        animation: spin 0.7s linear infinite;
      }

      @keyframes spin {
        to {
          transform: rotate(360deg);
        }
      }

      .loading-panel {
        display: none;
        margin-top: 1.25rem;
        border-radius: calc(var(--radius) - 2px);
        border: 1px solid hsl(var(--border) / 0.5);
        padding: 1rem;
        background: hsl(var(--muted) / 0.2);
      }

      .loading-panel.visible {
        display: block;
        animation: fade-in 0.25s ease-out;
      }

      .skel {
        height: 0.75rem;
        border-radius: 4px;
        background: linear-gradient(
          90deg,
          hsl(var(--muted) / 0.35) 0%,
          hsl(var(--muted) / 0.55) 50%,
          hsl(var(--muted) / 0.35) 100%
        );
        background-size: 200% 100%;
        animation: shimmer 1.2s ease-in-out infinite;
        margin-bottom: 0.5rem;
      }

      .skel:last-child {
        margin-bottom: 0;
        width: 66%;
      }

      @keyframes shimmer {
        0% {
          background-position: 100% 0;
        }
        100% {
          background-position: -100% 0;
        }
      }

      /* Dashboard hasil — ala nawala.asia */
      .batch-dashboard {
        margin-top: 1.5rem;
        display: none;
        flex-direction: column;
        gap: 1rem;
        animation: fade-in 0.35s ease-out;
      }

      .batch-dashboard.visible {
        display: flex;
      }

      .dash-stats {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
      }

      @media (max-width: 520px) {
        .dash-stats {
          grid-template-columns: 1fr;
        }
      }

      .stat-card {
        display: flex;
        align-items: center;
        gap: 0.875rem;
        padding: 1rem 1.1rem;
        border-radius: var(--radius);
        border: 1px solid hsl(var(--border));
        background: hsl(var(--card) / 0.6);
      }

      .stat-card--ok {
        border-color: hsl(142 70% 40% / 0.45);
        box-shadow: inset 0 0 0 1px hsl(142 70% 40% / 0.08);
      }

      .stat-card--bad {
        border-color: hsl(var(--destructive) / 0.45);
        box-shadow: inset 0 0 0 1px hsl(var(--destructive) / 0.08);
      }

      .stat-card__ico {
        flex-shrink: 0;
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 0.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
      }

      .stat-card--ok .stat-card__ico {
        background: hsl(142 70% 40% / 0.18);
        color: hsl(142 70% 45%);
      }

      .stat-card--bad .stat-card__ico {
        background: hsl(var(--destructive) / 0.18);
        color: hsl(var(--destructive));
      }

      .stat-card__ico svg {
        width: 1.25rem;
        height: 1.25rem;
        stroke-width: 2;
      }

      .stat-card__val {
        font-size: 1.75rem;
        font-weight: 800;
        line-height: 1.1;
        letter-spacing: -0.03em;
      }

      .stat-card--ok .stat-card__val {
        color: hsl(142 70% 48%);
      }

      .stat-card--bad .stat-card__val {
        color: hsl(var(--destructive));
      }

      .stat-card__lbl {
        font-size: 0.8125rem;
        font-weight: 600;
        color: hsl(var(--muted-foreground));
        margin-top: 0.125rem;
      }

      .results-panel {
        border-radius: var(--radius);
        border: 1px solid hsl(var(--border) / 0.55);
        background: hsl(var(--card) / 0.5);
        padding: 1.1rem 1.15rem 0.75rem;
      }

      .results-head {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1rem;
      }

      .results-head h2 {
        margin: 0;
        font-size: 1.0625rem;
        font-weight: 700;
        letter-spacing: -0.02em;
      }

      .results-sub {
        margin: 0.25rem 0 0;
        font-size: 0.8125rem;
        color: hsl(var(--muted-foreground));
      }

      .filter-pills {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.5rem;
      }

      .pill-tab {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.45rem 0.8rem;
        font-size: 0.75rem;
        font-weight: 600;
        font-family: inherit;
        border-radius: 9999px;
        border: 1px solid hsl(var(--border));
        background: transparent;
        color: hsl(var(--muted-foreground));
        cursor: pointer;
        transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
      }

      .pill-tab svg {
        width: 0.875rem;
        height: 0.875rem;
        flex-shrink: 0;
      }

      .pill-tab:hover {
        background: hsl(var(--muted) / 0.25);
      }

      .pill-tab.pill-all.active {
        background: hsl(199 89% 48%);
        color: #fff;
        border-color: hsl(199 89% 48%);
        box-shadow: 0 0 16px -4px hsl(199 89% 48% / 0.65);
      }

      .pill-tab.pill-active {
        border-color: hsl(142 70% 40% / 0.55);
        color: hsl(142 70% 48%);
      }

      .pill-tab.pill-active.active {
        background: hsl(142 70% 40% / 0.15);
        border-color: hsl(142 70% 45%);
        color: hsl(142 76% 55%);
      }

      .pill-tab.pill-blocked {
        border-color: hsl(var(--destructive) / 0.45);
        color: hsl(var(--destructive));
      }

      .pill-tab.pill-blocked.active {
        background: hsl(var(--destructive) / 0.12);
        border-color: hsl(var(--destructive) / 0.65);
      }

      .table-wrap {
        overflow-x: auto;
        margin: 0 -0.15rem;
        max-height: min(420px, 55vh);
        overflow-y: auto;
      }

      .results-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8125rem;
      }

      .results-table th {
        text-align: left;
        padding: 0.65rem 0.6rem;
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        color: hsl(var(--muted-foreground));
        border-bottom: 1px solid hsl(var(--border) / 0.7);
        white-space: nowrap;
      }

      .results-table td {
        padding: 0.85rem 0.6rem;
        border-bottom: 1px solid hsl(var(--border) / 0.35);
        vertical-align: middle;
      }

      .results-table tbody tr:last-child td {
        border-bottom: none;
      }

      .domain-cell {
        font-family: 'JetBrains Mono', ui-monospace, monospace;
        font-weight: 600;
        word-break: break-all;
        color: hsl(var(--foreground));
      }

      .domain-cell.is-blocked {
        color: hsl(0 85% 68%);
      }

      .status-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.2rem 0.6rem;
        border-radius: 9999px;
        font-size: 0.6875rem;
        font-weight: 700;
        white-space: nowrap;
      }

      .status-pill .dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor;
        opacity: 0.95;
      }

      .status-pill--blocked {
        background: hsl(var(--destructive) / 0.14);
        color: hsl(0 90% 72%);
        border: 1px solid hsl(var(--destructive) / 0.4);
      }

      .status-pill--ok {
        background: hsl(142 70% 40% / 0.12);
        color: hsl(142 76% 50%);
        border: 1px solid hsl(142 70% 40% / 0.35);
      }

      #toastHost {
        position: fixed;
        bottom: 1rem;
        right: 1rem;
        z-index: 100;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        max-width: 22rem;
        pointer-events: none;
      }

      .toast {
        pointer-events: auto;
        padding: 0.75rem 0.875rem;
        border-radius: var(--radius);
        border: 1px solid hsl(var(--border));
        background: hsl(var(--card));
        color: hsl(var(--card-foreground));
        box-shadow: 0 10px 25px -5px rgb(0 0 0 / 0.12);
        font-size: 0.8125rem;
        animation: fade-in 0.25s ease-out;
      }

      .toast strong {
        display: block;
        font-size: 0.875rem;
        margin-bottom: 0.25rem;
      }

      .toast.destructive {
        border-color: hsl(var(--destructive) / 0.35);
        background: hsl(var(--destructive) / 0.08);
      }

      .toast.destructive strong {
        color: hsl(var(--destructive));
      }

      .status {
        display: none;
        margin-top: 1.25rem;
        padding: 1rem 1.125rem;
        border-radius: calc(var(--radius) - 2px);
        border: 1px solid hsl(var(--border));
        background: hsl(var(--muted) / 0.25);
        animation: fade-in 0.3s ease-out forwards;
      }

      @keyframes fade-in {
        from {
          opacity: 0;
          transform: translateY(8px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }

      .status .title {
        font-weight: 700;
        font-size: 1rem;
        margin-bottom: 0.5rem;
        letter-spacing: -0.02em;
      }

      .status.ok {
        border-color: hsl(var(--success) / 0.45);
        background: hsl(var(--success) / 0.08);
      }
      .status.ok .title {
        color: hsl(var(--success));
      }

      .status.down_dns {
        border-color: hsl(var(--destructive) / 0.4);
        background: hsl(var(--destructive) / 0.06);
      }
      .status.down_dns .title {
        color: hsl(var(--destructive));
      }

      .status.down_http {
        border-color: hsl(330 75% 55% / 0.4);
        background: hsl(330 75% 55% / 0.06);
      }
      .status.down_http .title {
        color: hsl(330 70% 50%);
      }

      .status.blocked {
        border-color: hsl(var(--warning) / 0.55);
        background: hsl(var(--warning) / 0.1);
      }
      .status.blocked .title {
        color: hsl(var(--warning));
      }

      .status.invalid {
        border-color: hsl(var(--warning) / 0.45);
        background: hsl(var(--warning) / 0.08);
      }
      .status.invalid .title {
        color: hsl(var(--warning));
      }

      .kv {
        display: grid;
        grid-template-columns: minmax(0, 140px) 1fr;
        gap: 0.375rem 1rem;
        font-size: 0.8125rem;
      }

      @media (max-width: 480px) {
        .kv {
          grid-template-columns: 1fr;
        }
        .kv .k {
          margin-top: 0.25rem;
        }
        .kv .k:first-child {
          margin-top: 0;
        }
      }

      .kv .k {
        color: hsl(var(--muted-foreground));
        font-weight: 500;
      }

      .kv > div:not(.k) {
        color: hsl(var(--foreground));
        word-break: break-word;
      }

      .msg {
        margin-top: 0.75rem;
        font-size: 0.875rem;
        font-weight: 600;
        color: hsl(var(--foreground));
      }

      pre.raw {
        white-space: pre-wrap;
        word-break: break-word;
        margin: 0.75rem 0 0;
        padding: 0.75rem 1rem;
        font-family: 'JetBrains Mono', ui-monospace, monospace;
        font-size: 0.75rem;
        line-height: 1.5;
        border-radius: calc(var(--radius) - 4px);
        background: hsl(var(--muted) / 0.35);
        border: 1px solid hsl(var(--border) / 0.5);
        max-height: 280px;
        overflow: auto;
        color: hsl(var(--foreground));
      }

      .footer {
        margin-top: 1.25rem;
        padding-top: 1rem;
        border-top: 1px solid hsl(var(--border));
        font-size: 0.75rem;
        color: hsl(var(--muted-foreground));
      }

      .footer code {
        font-family: 'JetBrains Mono', ui-monospace, monospace;
        font-size: 0.7rem;
        padding: 0.125rem 0.375rem;
        border-radius: 0.25rem;
        background: hsl(var(--muted) / 0.5);
        color: hsl(var(--foreground));
      }

      .sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        border: 0;
      }
    </style>
  </head>
  <body>
    <div id="toastHost" aria-live="polite"></div>

    <div class="wrap">
      <header class="hero">
        <div class="hero-icon-wrap" aria-hidden="true">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
          </svg>
        </div>
        <h1 class="hero-title">ABSPositif — Cek Nawala Domain Terblokir Komdigi</h1>
        <p class="hero-sub">Satu domain atau IP per baris (maks. 100).</p>
      </header>

      <div class="card">
        <p class="card-inner-title">Daftar domain / IP</p>
        <label class="field-label sr-only" for="domainsBatch">Satu domain atau IP per baris</label>
        <div class="textarea-wrap">
          <textarea
            id="domainsBatch"
            class="textarea textarea-main"
            spellcheck="false"
            autocomplete="off"
            placeholder="contoh.com&#10;blog.domain.co.id&#10;8.8.8.8"
          ></textarea>
          <span id="lineCounter" class="line-counter" aria-live="polite">0/100</span>
        </div>

        <div id="formError" class="form-error" role="alert">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
          <span id="formErrorText"></span>
        </div>

        <button id="btnCheck" class="btn-check" type="button">
          <svg class="btn-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" width="20" height="20" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
          </svg>
          <span id="btnCheckLabel">Cek Nawala</span>
        </button>

        <div id="loadingPanel" class="loading-panel" aria-hidden>
          <div class="skel"></div>
          <div class="skel"></div>
          <div class="skel"></div>
          <div class="skel"></div>
        </div>

        <div id="statusBox" class="status">
          <div id="statusTitle" class="title"></div>
          <div class="kv">
            <div class="k">Domain</div>
            <div id="kvDomain"></div>
            <div class="k">DNS resolved</div>
            <div id="kvDns"></div>
            <div class="k">HTTP reachable</div>
            <div id="kvHttp"></div>
            <div class="k">Terindikasi diblokir</div>
            <div id="kvBlocked"></div>
            <div class="k">Alasan</div>
            <div id="kvReason"></div>
            <div class="k">Waktu</div>
            <div id="kvTime"></div>
          </div>
          <div class="msg" id="statusMessage"></div>
          <pre id="rawResponse" class="raw"></pre>
        </div>

        <div id="batchBox" class="batch-dashboard">
          <div class="dash-stats" id="dashStats">
            <div class="stat-card stat-card--ok">
              <div class="stat-card__ico" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                </svg>
              </div>
              <div>
                <div class="stat-card__val" id="statActiveNum">0</div>
                <div class="stat-card__lbl">Active</div>
              </div>
            </div>
            <div class="stat-card stat-card--bad">
              <div class="stat-card__ico" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0-9.75v.008M12 18.75v-.008M4.5 19.5h15a1.5 1.5 0 001.329-2.177l-7.5-14.25a1.5 1.5 0 00-2.658 0l-7.5 14.25A1.5 1.5 0 004.5 19.5z" />
                </svg>
              </div>
              <div>
                <div class="stat-card__val" id="statBlockedNum">0</div>
                <div class="stat-card__lbl">Blocked</div>
              </div>
            </div>
          </div>

          <div class="results-panel">
            <div class="results-head">
              <div>
                <h2>Hasil Pengecekan</h2>
                <p class="results-sub" id="batchSubtitle">Total 0 domain diperiksa</p>
              </div>
              <div class="filter-pills" id="batchTabs">
                <button type="button" class="pill-tab pill-all active" data-filter="all">
                  Semua (<span id="countAll">0</span>)
                </button>
                <button type="button" class="pill-tab pill-active" data-filter="active">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                  </svg>
                  Active (<span id="countActive">0</span>)
                </button>
                <button type="button" class="pill-tab pill-blocked" data-filter="blocked">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0-9.75v.008M12 18.75v-.008M4.5 19.5h15a1.5 1.5 0 001.329-2.177l-7.5-14.25a1.5 1.5 0 00-2.658 0l-7.5 14.25A1.5 1.5 0 004.5 19.5z" />
                  </svg>
                  Blocked (<span id="countBlocked">0</span>)
                </button>
              </div>
            </div>
            <div class="table-wrap">
              <table class="results-table">
                <thead>
                  <tr>
                    <th>Domain</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody id="batchTableBody"></tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="footer">
          <code>/api/check.php?domain=…</code>
          ·
          <code>POST /api/check_batch.php</code>
        </div>
      </div>
    </div>

    <script>
      const apiUrl = 'api/check.php';
      const $ = (id) => document.getElementById(id);
      const DOMAIN_RE =
        /^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i;
      const IPV4_RE =
        /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;

      function isValidLine(s) {
        const t = (s || '').trim();
        if (!t) return false;
        return DOMAIN_RE.test(t) || IPV4_RE.test(t);
      }

      function getLinesFromTextarea(raw) {
        return (raw || '')
          .split(/\n/)
          .map((l) => l.trim())
          .filter((l) => l.length > 0);
      }

      let batchItemsCache = [];
      let batchFilter = 'all';

      document.documentElement.classList.remove('dark');
      try {
        localStorage.removeItem('nawala-theme');
      } catch (e) {}

      function updateLineCounter() {
        const lines = getLinesFromTextarea($('domainsBatch').value);
        const n = lines.length;
        const el = $('lineCounter');
        el.textContent = n + '/100';
        el.classList.toggle('over', n > 100);
        const over = n > 100;
        $('btnCheck').disabled = over;
      }

      $('domainsBatch').addEventListener('input', updateLineCounter);
      updateLineCounter();

      function showFormError(msg) {
        $('formErrorText').textContent = msg;
        $('formError').classList.add('visible');
      }

      function hideFormError() {
        $('formError').classList.remove('visible');
        $('formErrorText').textContent = '';
      }

      function toast(title, description, destructive) {
        const host = $('toastHost');
        const el = document.createElement('div');
        el.className = 'toast' + (destructive ? ' destructive' : '');
        el.innerHTML = '<strong>' + escapeHtml(title) + '</strong><div>' + escapeHtml(description) + '</div>';
        host.appendChild(el);
        setTimeout(() => {
          el.style.opacity = '0';
          el.style.transition = 'opacity 0.25s ease';
          setTimeout(() => el.remove(), 280);
        }, 4200);
      }

      function escapeHtml(s) {
        return String(s)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;');
      }

      async function checkDomain(domain) {
        const params = new URLSearchParams({ domain });
        const res = await fetch(apiUrl + '?' + params.toString(), { method: 'GET' });
        return await res.json();
      }

      async function checkBatchNewlineBody(validLines) {
        const body = validLines.join('\n');
        const res = await fetch('api/check_batch.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ name: body }),
        });
        if (!res.ok) throw new Error('Gagal menghubungi API batch');
        return await res.json();
      }

      function setStatusClass(status) {
        const box = $('statusBox');
        box.classList.remove('ok', 'down_dns', 'down_http', 'blocked', 'invalid');
        if (status === 'ok') box.classList.add('ok');
        if (status === 'down_dns') box.classList.add('down_dns');
        if (status === 'down_http') box.classList.add('down_http');
        if (status === 'blocked') box.classList.add('blocked');
        if (status === 'invalid') box.classList.add('invalid');
      }

      function setLoading(on) {
        $('loadingPanel').classList.toggle('visible', on);
        $('loadingPanel').setAttribute('aria-hidden', on ? 'false' : 'true');
        const label = $('btnCheckLabel');
        const btn = $('btnCheck');
        const iconSearch = btn.querySelector('.btn-icon');
        if (on) {
          btn.disabled = true;
          label.textContent = 'Memeriksa...';
          if (iconSearch) iconSearch.classList.add('spin');
        } else {
          label.textContent = 'Cek Nawala';
          if (iconSearch) iconSearch.classList.remove('spin');
          updateLineCounter();
        }
      }

      function nawalaBlockedCell(x) {
        return !!(x && x.nawala && x.nawala.blocked);
      }

      function networkBlockedCell(x) {
        return !!(x && x.network && x.network.blocked);
      }

      function isBlockedRow(x) {
        return nawalaBlockedCell(x) || networkBlockedCell(x);
      }

      function pillHtml(blocked) {
        if (blocked) {
          return '<span class="status-pill status-pill--blocked"><span class="dot" aria-hidden="true"></span>Blocked</span>';
        }
        return '<span class="status-pill status-pill--ok"><span class="dot" aria-hidden="true"></span>Active</span>';
      }

      function updateBatchStats() {
        const items = batchItemsCache;
        const total = items.length;
        const blockedCount = items.filter(isBlockedRow).length;
        const activeCount = total - blockedCount;
        $('statActiveNum').textContent = String(activeCount);
        $('statBlockedNum').textContent = String(blockedCount);
        $('countAll').textContent = String(total);
        $('countActive').textContent = String(activeCount);
        $('countBlocked').textContent = String(blockedCount);
        $('batchSubtitle').textContent = 'Total ' + total + ' domain diperiksa';
      }

      function setPillTabActive(filter) {
        document.querySelectorAll('#batchTabs .pill-tab').forEach((b) => {
          b.classList.toggle('active', b.getAttribute('data-filter') === filter);
        });
      }

      function renderBatchTable() {
        const tbody = $('batchTableBody');
        tbody.innerHTML = '';
        let rows = batchItemsCache.slice();
        if (batchFilter === 'blocked') rows = rows.filter(isBlockedRow);
        else if (batchFilter === 'active') rows = rows.filter((x) => !isBlockedRow(x));

        for (const x of rows) {
          const d = x.domain || '-';
          const tr = document.createElement('tr');
          tr.innerHTML =
            '<td class="domain-cell' +
            (isBlockedRow(x) ? ' is-blocked' : '') +
            '">' +
            escapeHtml(d) +
            '</td><td>' +
            pillHtml(isBlockedRow(x)) +
            '</td>';
          tbody.appendChild(tr);
        }
      }

      function showBatchDashboard() {
        $('batchBox').classList.add('visible');
      }

      function hideBatchDashboard() {
        $('batchBox').classList.remove('visible');
      }

      document.querySelectorAll('#batchTabs .pill-tab').forEach((btn) => {
        btn.addEventListener('click', () => {
          const f = btn.getAttribute('data-filter') || 'all';
          batchFilter = f;
          setPillTabActive(f);
          renderBatchTable();
        });
      });

      $('btnCheck').addEventListener('click', async () => {
        hideFormError();
        const lines = getLinesFromTextarea($('domainsBatch').value);

        if (lines.length === 0) {
          showFormError('Masukkan minimal satu domain atau IP (satu per baris).');
          return;
        }

        if (lines.length > 100) {
          toast('Maksimal 100 domain', 'Kurangi jumlah baris sebelum mengecek lagi.', true);
          return;
        }

        const valid = [];
        const invalid = [];
        for (const line of lines) {
          if (isValidLine(line)) valid.push(line.trim());
          else invalid.push(line);
        }

        if (invalid.length > 0) {
          const preview = invalid.slice(0, 3).join(', ') + (invalid.length > 3 ? '…' : '');
          toast('Input tidak valid', invalid.length + ' baris diabaikan: ' + preview, true);
        }

        if (valid.length === 0) {
          showFormError('Tidak ada domain atau IP yang valid.');
          return;
        }

        hideBatchDashboard();
        $('statusBox').style.display = 'none';
        setLoading(true);

        const t0 = performance.now();
        const minSkel = 450;

        try {
          if (valid.length === 1) {
            const data = await checkDomain(valid[0]);
            await delay(Math.max(0, minSkel - (performance.now() - t0)));
            const row = {
              domain: data.domain || valid[0],
              nawala: { blocked: !!(data.nawala && data.nawala.blocked) },
              network: { blocked: !!(data.network && data.network.blocked) },
            };
            batchItemsCache = [row];
            batchFilter = 'all';
            setPillTabActive('all');
            updateBatchStats();
            renderBatchTable();
            showBatchDashboard();
          } else {
            const payload = await checkBatchNewlineBody(valid);
            await delay(Math.max(0, minSkel - (performance.now() - t0)));
            const items = Array.isArray(payload.data) ? payload.data : [];
            batchItemsCache = items;
            batchFilter = 'all';
            setPillTabActive('all');
            updateBatchStats();
            renderBatchTable();
            showBatchDashboard();
          }
        } catch (err) {
          $('statusBox').style.display = 'block';
          hideBatchDashboard();
          setStatusClass('invalid');
          $('statusTitle').textContent = 'Gagal cek';
          $('statusMessage').textContent = String(err && err.message ? err.message : err);
          $('rawResponse').textContent = '';
        } finally {
          setLoading(false);
        }
      });

      function delay(ms) {
        return new Promise((r) => setTimeout(r, ms));
      }
    </script>
  </body>
</html>
