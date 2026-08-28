# JBWizerd — JetBackup Backup Monitoring

Monitor JetBackup backups across all your servers from one web panel.
- **PHP panel** (works on shared hosting and dedicated servers, no Composer required)
- **MySQL** storage, optimized indexes for 1 year of data
- **JetBackup hook script** (Python, no extra dependencies) collects and reports data
- **JetBackup log parsing** — real backup data even when JetBackup sends an empty hook payload
- **Webhook notifications** to Slack / Discord / any HTTP endpoint

---

## Components

```
jbwizerd/
├── setup.php               ← first-run installer (creates DB + admin user, then disables itself)
├── index.php               ← daily backup report per server (+ Excel export)
├── dashboard.php           ← overview, trend chart, server health
├── servers.php             ← server list, groups, connection status, key management
├── leaderboard.php         ← server reliability ranking
├── webhooks.php            ← Slack/Discord webhook setup
├── users.php               ← team member accounts (email + password login)
├── export.php              ← Excel/CSV export of the report
├── audit.php               ← audit log (logins, actions, device/IP tracking)
├── cron-status.php         ← shows whether the cron jobs are running
├── change-password.php     ← change your own password
├── cli-reset-password.php  ← emergency password reset (SSH/CLI only)
├── api/                    ← endpoints the hook uses (report, register, ping)
├── hook/                   ← jb_hook.py + install.sh (server side)
├── assets/                 ← CSS, JS, theme.js, logo (PHP execution blocked)
├── cron/                   ← webhook retry + retention cleanup
├── includes/               ← config, DB, auth, functions, notifier
├── install.sql             ← MySQL schema
├── .htaccess               ← security headers + file blocking
└── README.md
```

---

## Part 1 — Install the Web Panel

The files in this repo ARE the web panel — the repo root is the web root. Upload the contents directly to your hosting. Every path is relative, so it works at the **domain root** (`https://your-domain.com`) or in a **subfolder** (`https://your-domain.com/panel/`); just point the browser at `setup.php` wherever you placed the files.

### Option A — Domain root (recommended)

1. Upload the repository contents to your site root, e.g. `public_html/`.
2. In your hosting control panel create a **MySQL database** + user.
3. Visit `https://your-domain.com/setup.php`
4. Fill in the database details, panel URL (`https://your-domain.com`), and registration key.
5. Follow the wizard: create tables → create admin user → you're auto-redirected to the login page.
6. **Delete `setup.php`** after installation for security (it disables itself automatically once the admin user exists).

### Option B — Subfolder

1. Upload the repository contents to a folder, e.g. `public_html/panel/`.
2. Create a **MySQL database** + user.
3. Visit `https://your-domain.com/panel/setup.php`
4. Fill in the database details, panel URL (`https://your-domain.com/panel`), and registration key.
5. Follow the wizard: create tables → create admin user → you're auto-redirected to the login page.
6. **Delete `setup.php`** after installation (it disables itself automatically).

Requirements: PHP 7.4+, PDO MySQL, cURL (all standard on shared hosting).

### Set up cron jobs (in cPanel → Cron Jobs)

| Frequency | Command | What it does |
|-----------|---------|--------------|
| Every minute | `php /home/USER/public_html/cron/send-webhooks.php` | Sends queued Slack/Discord notifications (retries with backoff) |
| Daily at 03:00 | `php /home/USER/public_html/cron/cleanup.php` | Deletes expired data + flags backups stuck in "running" >24h as failed (with a webhook alert) |

If you installed in a subfolder, add the subfolder to the path (e.g. `/home/USER/public_html/panel/cron/send-webhooks.php`). You can also copy the exact commands from the **Cron Status** page inside the panel — it pre-fills the real path for you.

The cleanup cron keeps:
- **1 year** of backup data (`RETENTION_DAYS`, default 365)
- **90 days** of webhook logs (`WEBHOOK_LOG_RETENTION_DAYS`)
- **30 days** of finished webhook queue rows (fixed)
- **90 days** of audit log entries (`AUDIT_LOG_RETENTION_DAYS`)
- Flags backups running > **24h** without completion as failed (`STUCK_BACKUP_HOURS`)

---

## Part 2 — Connect a Server

### Automatic registration (recommended)

On each JetBackup server, run:

```bash
bash <(curl -sL https://your-panel.com/hook/install.sh) \
  --panel-url https://your-panel.com \
  --register-key YOUR_REGISTRATION_KEY
```

The server registers itself, receives its **own unique key**, and saves it to `/JBWizerd/config.json`.

> **Registration key** is short and Windows-style: `XXXXX-XXXXX-XXXXX-XXXXX`
> (e.g. `ED63B-D3005-308F0-FE666`). Generate a new one from **Servers → Registration Key → Generate New Key** (admin only).

### Server directory & permissions (after install)

Everything is installed under **`/JBWizerd/`** on each JetBackup server:

| Path | File | Permission |
|------|------|-----------|
| `/JBWizerd/` | Directory | `700` |
| `/JBWizerd/jb_hook.py` | Hook script | `700` |
| `/JBWizerd/config.json` | Panel URL + server token | `600` |
| `/JBWizerd/hook-errors.log` | Debug/error log | `600` |

Verify/fix as root:

```bash
chmod 700 /JBWizerd
chmod 700 /JBWizerd/jb_hook.py
chmod 600 /JBWizerd/config.json
chmod 600 /JBWizerd/hook-errors.log
```

`hook-errors.log` is capped at **5 MB** — when exceeded, only the most recent ~1 MB is kept.

To install to a different directory instead of `/JBWizerd`, override it:

```bash
JB_HOOK_DIR=/custom/path bash install.sh \
  --panel-url https://your-panel.com \
  --register-key YOUR_REGISTRATION_KEY
```

### Manual (generate a key from the panel)

1. Open **Servers → Add Server** in the panel.
2. Copy the one-time key shown.
3. On the server:

```bash
bash <(curl -sL https://your-panel.com/hook/install.sh) \
  --panel-url https://your-panel.com \
  --token XXXX-XXXX-XXXX-XXXX
```

### Replace / regenerate a key

When you click **New Key** on a server, the old key stops working. Update the server with one short command:

```bash
bash <(curl -sL https://your-panel.com/hook/install.sh) \
  --panel-url https://your-panel.com \
  --update-key XXXX-XXXX-XXXX-XXXX
```

---

## Part 3 — Add the Hooks in JetBackup (manual)

The installer installs the files, but you add the hook entries in JetBackup manually:

1. Log in to **WHM** as root.
2. Open **JetBackup 5** → **Hooks** (or your JetBackup version's hook section).
3. Add a **Pre backup** hook:
   ```
   /usr/bin/python3 /JBWizerd/jb_hook.py
   ```
4. Add a **Post backup** hook:
   ```
   /usr/bin/python3 /JBWizerd/jb_hook.py
   ```
   (Both use the same script — the hook type is what JetBackup calls them.)

That's it. Every backup now reports to the panel:
- Server hostname + current IP (auto-updates if the IP changes)
- cPanel usernames (single or multiple, comma-joined)
- Destination name + storage used / total / %
- Start time, end time, human duration ("3 Hours and 44 Minutes and 48 Seconds")
- Status: `running` / `success` / `failed` / `partial` / `aborted`
- Error log — **only the `[ERROR]` lines** are forwarded (never the full log); multiple users' errors are included

Backups stuck in "running" for over **24 hours** are auto-flagged as **failed** by the daily cleanup cron and trigger a **stuck-backup webhook alert**.

### Test the hook manually

```bash
echo '{"status":"success","start_time":"2026-08-23T02:00:00Z","end_time":"2026-08-23T04:00:00Z","username":"testuser"}' \
  | /usr/bin/python3 /JBWizerd/jb_hook.py
```

Errors are logged to `/JBWizerd/hook-errors.log`. The hook always exits 0 and never slows down the backup. The log is capped at **5 MB** — when exceeded, only the most recent ~1 MB of entries is kept.

---

## Part 3b — How the Hook Script Works

### Which JetBackup CLI it uses, and why

The hook calls the **JetBackup API through the `jetbackup5api` command-line tool** (`/usr/local/jetapps/usr/bin/jetbackup5api`, falling back to `jetbackup4api` or PATH lookup for older installs).

Why the CLI instead of the HTTP REST API:

| Reason | Detail |
|---|---|
| **No token needed** | The CLI runs as root and talks to JetBackup over its local socket, so the script needs **no API key setup** — a fresh server just works. |
| **Same backend** | It exposes the exact same function set as the REST API (list queues, items, destinations). |
| **Zero-config** | No keys to generate, store, or rotate for the JetBackup side (the panel still uses its own server token for auth). |

The hook calls three read-only functions:

| Function | Purpose |
|---|---|
| `listQueueGroups` (`type=1`) | Newest backup queue groups — job start/end times, status, log file, item counts, job name |
| `listQueueItems` (`group_id=…`) | Per-account item logs inside a group — used to collect real error lines per account |
| `listDestinations` | Precise destination disk usage (used/free/total in bytes) for accurate storage figures |

The CLI can output JSON or JetBackup's indented `key: value` format — `parse_jb_response()` handles both.

### Logic summary (what the script does on every run)

1. **Ignore the hook payload** — JetBackup often sends an empty payload on stdin; the script reads real data from JetBackup itself instead.
2. **Pick the newest REAL account backup job** from `listQueueGroups`:
   - Skips housekeeping/system jobs (JB Config, Snapshot Cleanup, Integrity Check, etc.) using a **whitelist**: only groups whose log shows account-transfer activity (`Starting account backup for` / `Transferring account`) are reported. A job-name safety net also rejects known system types, covering the first seconds before account lines appear.
   - Prefers a still-`running` group; otherwise the newest finished group.
3. **Running vs finished detection** — a group with no `ended` value is reported as `running` (with live progress like `19/143 (13%)`); a group with `ended` gets the final status.
4. **Collect errors per account** — reads every item log in the group, extracts **only `[ERROR]` lines**, dedupes them (ignoring PID prefixes), and groups them **by cPanel user** (`user1:\n  error line`). Only users whose backups had errors are reported.
5. **Determine final status** — from the group's finalize log; falls back to the most severe status across item logs (`failed/aborted > partial > success`).
6. **Precise disk usage** — overrides rounded log values with exact bytes from `listDestinations`.
7. **Fallback (LOG-SCAN)** — if the API is unavailable, scan recent queue log files on disk (last 72h) with the same account-backup whitelist.
8. **Send report** — POST the clean JSON to the panel (`api/report.php`) with the server hostname + auto-detected IP; always exits 0.

---

## Part 4 — Webhook Notifications (Slack / Discord)

1. Go to **Webhooks → Add Webhook** in the panel.
2. Paste your webhook URL:
   - Slack: `https://hooks.slack.com/services/T.../B.../xxx`
   - Discord: `https://discord.com/api/webhooks/...`
3. Choose the **message format** (Auto-detect recommended — Slack/Discord/generic JSON are detected from the URL).
4. Save, then press **Test** to verify delivery.

The webhook fires on **every** backup event automatically — Backup Failed, Backup Partially Complete, Backup Aborted, and Backup Completed. There is no event selection; every report is sent.

Payloads are color-coded by status (green = success, orange = partial, red = failed/aborted) and contain no emojis.

The **cPanel username** is included **only** on failed / partial / aborted backups (so you can trace the error log by user) — never on successful completions.

Example payload sent to your webhook (partial):

```json
{
  "event": "backup_partial",
  "status": "partial",
  "server": "srv01.example.com",
  "server_ip": "203.0.113.10",
  "cpanel_user": "dsbeta",
  "destination": "DHKB",
  "disk_used": "2.9 TB",
  "disk_free": "0.77 TB",
  "disk_total": "3.6 TB",
  "disk_used_pct": "78.51",
  "start_time": "2026-08-25 02:54:33",
  "end_time": "2026-08-25 04:44:06",
  "duration": "1 Hour and 49 Minutes and 33 Seconds",
  "error": "Failed exporting database data...",
  "error_log": "[ERROR] Failed exporting database data. Database Handler Error: ..."
}
```

Deliveries are retried with backoff (up to `WEBHOOK_MAX_ATTEMPTS`) and shown in the **Delivery Logs** table.

---

### Reset a forgotten password (emergency, SSH/CLI)

If an admin forgets their password (and no other admin is available), run from the panel directory over SSH:

```bash
php cli-reset-password.php admin
```

Run it with no arguments to list all users. It prompts for a new password (≥10 chars, mixed case + number), confirms it, and updates the hash. The script is CLI-only — it refuses to run over the web and is blocked from web access via `.htaccess`.

### Server-side security checklist

- The hook runs only from `/JBWizerd/` and always exits 0 — it never blocks or delays backups.
- Reporting to the panel is **HTTPS-only** (the panel API rejects plain HTTP).
- Rotate server keys with `--update-key` (old key stops working immediately).
- On each JetBackup server: SSH key-only login, restrict egress to HTTPS (443), consider fail2ban.

### Configuration

All settings live in `panel/includes/config.php` (→ now `includes/config.php` — the repo root is the web root):
- `DB_*` — MySQL connection
- `PANEL_URL` — your panel's public URL (auto-detected if unset)
- `REGISTRATION_KEY` — short key used for automatic server registration
- `RETENTION_DAYS` / `WEBHOOK_LOG_RETENTION_DAYS` / `AUDIT_LOG_RETENTION_DAYS` — retention windows
- `STUCK_BACKUP_HOURS` — how long before a "running" backup is flagged as stuck (default 24)
- `TIMEZONE` — display timezone (default `Asia/Dhaka`); panel shows 12-hour AM/PM times
- `SESSION_LIFETIME` / `LOGIN_MAX_ATTEMPTS` / `LOGIN_LOCKOUT_WINDOW` / `REGISTER_MAX_PER_HOUR` — security
- `TRUSTED_PROXIES` — comma-separated proxy IPs whose `X-Forwarded-For` is trusted

