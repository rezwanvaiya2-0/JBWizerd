#!/usr/bin/env python3
"""
JBWizerd Hook — JetBackup hook script.

JetBackup fires this script for "Pre backup" and "Post backup"
events. JetBackup may send an EMPTY payload on stdin, so this version reads the
REAL backup data from JetBackup itself:

  1. Reads the JetBackup queue log files on disk
     (/usr/local/jetapps/var/log/jetbackup5/queue/...) — real status, account,
     destination, disk usage, timestamps, and ONLY the [ERROR] lines.
  2. Queries the JetBackup API via the jetbackup5api CLI (no key required) for
     authoritative queue/progress data.
  3. POSTs a clean report to the panel (hostname+IP, status, times, duration,
     destination, disk used/free, error log). No server cron needed.

Config file (/JBWizerd/config.json):
  {
    "panel_url": "https://panel.example.com",
    "token": "jb_xxxxxxxxxx"
  }

JetBackup registers hooks manually:
  - Pre backup:  /usr/bin/python3 /JBWizerd/jb_hook.py
  - Post backup: /usr/bin/python3 /JBWizerd/jb_hook.py
"""

import json
import os
import re
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.request

CONFIG_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_PATH = os.path.join(CONFIG_DIR, 'config.json')
LOG_PATH = os.path.join(CONFIG_DIR, 'hook-errors.log')
LOG_MAX_SIZE = 5 * 1024 * 1024       # 5 MB
LOG_KEEP_TAIL = 1 * 1024 * 1024      # keep last 1 MB after trimming

JB_QUEUE_LOG_DIR = '/usr/local/jetapps/var/log/jetbackup5/queue'
RECENT_LOG_WINDOW_HOURS = 72
MAX_LOGS_TO_CHECK = 30

# JetBackup fires hooks for ALL its jobs — including housekeeping ones
# (JB Config, Snapshot Cleanup, Integrity Check, updates, verification...).
# Those contain no cPanel accounts and would pollute the panel with fake
# "success" reports (their group times are also misleading: e.g. a config
# backup shows 5h "total" but only 2s "actual"). Instead of blacklisting each
# housekeeping type (JetBackup can add more), we WHITELIST real account
# backups: only groups/logs that show account-transfer activity are reported.
ACCOUNT_BACKUP_MARKERS = [
    'Starting account backup for',
    'Transferring account',
]

# Safety net for the first seconds of a real backup: before the first account
# transfer line appears in the log, we can't rely on ACCOUNT_BACKUP_MARKERS.
# So we also reject groups whose JOB NAME matches a known housekeeping type.
# Real account backups are named by the user ("Daily Backup", "Daily Local",
# "Daily Remote", ...) — a free-text name never equals these system names.
SYSTEM_JOB_NAMES = [
    'JetBackup Config',
    'Snapshot Cleanup',
    'Integrity Check',
    'Retention Cleanup',
    'Disk Usage Check',
    'Verification',
    'Restore',
    'Transfer',
    'Update',
]


# ============================================================
# Config / helpers
# ============================================================

def load_config():
    with open(CONFIG_PATH) as f:
        return json.load(f)


def get_server_ip():
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.settimeout(3)
        try:
            s.connect(('8.8.8.8', 80))
            ip = s.getsockname()[0]
        except Exception:
            ip = socket.gethostbyname(socket.gethostname())
        finally:
            s.close()
        return ip
    except Exception:
        return ''


def human_bytes(num):
    """Format bytes precisely: '2.86 TB', '796.25 GB', etc."""
    try:
        num = float(num)
    except Exception:
        return ''
    for unit in ['B', 'KB', 'MB', 'GB', 'TB']:
        if num < 1024 or unit == 'TB':
            return '{:.2f} {}'.format(num, unit) if unit != 'B' else '{} {}'.format(int(num), unit)
        num /= 1024.0
    return ''


def get_destination_disk(destination_name):
    """Read the PRECISE disk usage of a JetBackup destination from the API's
    disk_usage.usage/free/total fields (bytes). Works for any destination type
    (SSH, local, S3...) without needing the mount path."""
    if not destination_name:
        return {}
    try:
        res = call_jb_api('listDestinations')
        ddata = res.get('data', {}) if isinstance(res.get('data'), dict) else {}
        dlist = ddata.get('destinations', {})
        if isinstance(dlist, dict):
            dlist = list(dlist.values())
        if not isinstance(dlist, list):
            dlist = []
        for d in dlist:
            if not isinstance(d, dict):
                continue
            if str(d.get('name') or '') != destination_name:
                continue
            du = d.get('disk_usage', {})
            if isinstance(du, dict):
                try:
                    total = int(float(du.get('total', 0) or 0))
                    used = int(float(du.get('usage', 0) or 0))
                    free = int(float(du.get('free', 0) or 0))
                except (TypeError, ValueError):
                    total = used = free = 0
                if total and total > 0:
                    return {
                        'disk_used': human_bytes(used),
                        'disk_free': human_bytes(free),
                        'disk_total': human_bytes(total),
                        'disk_used_pct': '{:.2f}'.format(used / total * 100),
                    }
    except Exception as e:
        log_error("destination disk lookup failed: {}".format(e))
    return {}


def safe_str(value, default=''):
    if value is None:
        return default
    return str(value)


# ============================================================
# JetBackup API (via the jetbackup5api CLI — no key required)
# ============================================================

def _find_jb_cli():
    for p in ['/usr/local/jetapps/usr/bin/jetbackup5api', '/usr/local/jetapps/usr/bin/jetbackup4api', 'jetbackup5api', 'jetbackup4api']:
        try:
            import shutil
            if os.path.exists(p) or shutil.which(p):
                return p
        except Exception:
            pass
    return None


def call_jb_api(function, params=''):
    """Call the JetBackup API via the CLI tool (runs as root, no token needed).
    Returns a parsed dict (JSON or JetBackup indented format), or {} on failure."""
    cli = _find_jb_cli()
    if not cli:
        return {}
    try:
        cmd = [cli, '-F', function]
        if params:
            cmd += ['-D', params]
        out = subprocess.run(cmd, capture_output=True, timeout=30, text=True).stdout
        if not out or not out.strip():
            return {}
        return parse_jb_response(out)
    except Exception as e:
        log_error("jb api cli error: {}".format(e))
        return {}


def parse_jb_response(text):
    """Parse the JetBackup API response. Tries JSON first, then the
    indented 'key: value' format used by jetbackup5api."""
    if not text or not text.strip():
        return {}
    # Try JSON
    try:
        obj = json.loads(text)
        if isinstance(obj, dict):
            return obj
    except Exception:
        pass
    # Try the JetBackup indented format
    try:
        lines = [(len(l) - len(l.lstrip(' ')), l.strip()) for l in text.split('\n') if l.strip()]

        def parse_block(idx, indent):
            obj = {}
            while idx < len(lines):
                cur_indent, content = lines[idx]
                if cur_indent < indent:
                    break
                if ':' not in content:
                    idx += 1
                    continue
                key, _, val = content.partition(':')
                key = key.strip()
                val = val.strip()
                if val == '':
                    obj[key], idx = parse_block(idx + 1, cur_indent + 2)
                else:
                    obj[key] = val
                    idx += 1
            return obj, idx

        parsed, _ = parse_block(0, 0)
        if parsed.get('success') == '1' or 'data' in parsed:
            return parsed
    except Exception:
        pass
    return {}


def _get_config_value(key):
    try:
        cfg = load_config()
        return cfg.get(key, '')
    except Exception:
        return ''


def api_get_backup_groups(limit=6):
    """Fetch recent JetBackup BACKUP queue GROUPS (job-level) via the API.
    Each group is a backup JOB — its started/ended are the JOB times (e.g. 06:00),
    not the per-account item times. Sorted newest-first."""
    groups = []
    try:
        res = call_jb_api('listQueueGroups', 'type=1')
        gdata = res.get('data', {}) if isinstance(res.get('data'), dict) else {}
        glist = gdata.get('groups', {})
        if isinstance(glist, dict):
            glist = list(glist.values())
        if not isinstance(glist, list):
            glist = []
        for g in glist:
            if isinstance(g, dict):
                groups.append(g)
        groups.sort(key=lambda g: str(g.get('started') or g.get('created') or ''), reverse=True)
    except Exception as e:
        log_error("api group scan error: {}".format(e))
    return groups[:limit]


def get_backup_report():
    """Build the WHOLE-JOB backup report.

    Uses the JOB (queue group) start/end times (e.g. 06:00) and aggregates
    ALL accounts + ALL [ERROR] lines from every item log in the group, so the
    panel shows the full server backup, not a single account.
    Returns (report_dict, data_source) — report_dict may be None.
    """
    try:
        groups = api_get_backup_groups()
        if not groups:
            return None, 'none'
        chosen = None
        for g in groups:
            if not is_account_group(g):
                continue
            if not g.get('ended'):
                chosen = g
                break
        if chosen is None:
            for g in groups:
                if not is_account_group(g):
                    continue
                chosen = g
                break
        if chosen is None:
            return None, 'none'
        gid = str(chosen.get('_id') or '')
        job_ended = bool(chosen.get('ended'))
        info = {
            'backup_id': gid,
            'cpanel_user': '',
            'destination': '',
            'disk_used': '', 'disk_free': '', 'disk_total': '', 'disk_used_pct': '',
            'start_time': safe_str(chosen.get('started') or ''),
            'end_time': safe_str(chosen.get('ended') or ''),
            'status': 'running',
            'error': '', 'error_log': '',
            'progress': '',
        }
        # Progress: "19/143 (13%)" — only meaningful while running; for
        # completed jobs we leave it blank (the final status is the report).
        if not job_ended:
            it = int(chosen.get('items') or 0)
            ic = int(chosen.get('items_completed') or 0)
            if it > 0:
                info['progress'] = '{}/{} ({}%)'.format(ic, it, int(ic * 100.0 / it))

        item_list = []
        if gid:
            try:
                res = call_jb_api('listQueueItems', 'group_id=' + gid)
                idata = res.get('data', {}) if isinstance(res.get('data'), dict) else {}
                ilist = idata.get('items', {})
                if isinstance(ilist, dict):
                    ilist = list(ilist.values())
                if isinstance(ilist, list):
                    item_list = ilist
            except Exception:
                pass

        # Track each log's account so we only report the USERS WHO HAD ERRORS.
        all_log_paths = []
        item_accounts = {}
        for it in item_list:
            if not isinstance(it, dict):
                continue
            acct = ''
            d2 = it.get('data')
            if isinstance(d2, dict):
                acct = safe_str(d2.get('account') or d2.get('account_nickname') or '')
            p = str(it.get('file') or '')
            if p and os.path.exists(p) and p not in all_log_paths:
                all_log_paths.append(p)
                item_accounts[p] = acct

        group_log_path = ''
        if chosen.get('log_file'):
            lp = str(chosen.get('log_file') or '')
            if os.path.exists(lp) and lp not in all_log_paths:
                all_log_paths.append(lp)
                item_accounts[lp] = ''
                group_log_path = lp

        # Group errors BY USER so it's clear which error belongs to which account.
        # user_errors: { account -> [deduped error lines] }
        user_errors = {}
        failed_users = []
        dest = ''
        for lp in all_log_paths:
            parsed = parse_backup_log(lp)
            acct = item_accounts.get(lp, '') or parsed.get('cpanel_user', '')
            if parsed.get('error_log'):
                if not acct:
                    acct = 'System'
                # dedup within this log (ignore PID prefix)
                seen_local = set()
                local_lines = []
                for l in parsed['error_log'].split('\n'):
                    l = l.strip()
                    if not l:
                        continue
                    norm = re.sub(r'^\[PID \d+\]\s*', '', l)
                    if norm not in seen_local:
                        seen_local.add(norm)
                        local_lines.append(l)
                bucket = user_errors.setdefault(acct, [])
                for l in local_lines:
                    if l not in bucket:
                        bucket.append(l)
                # the account tied to THIS log had errors → report it
                for u in (parsed.get('cpanel_user') or acct).split(','):
                    u = u.strip()
                    if u and u not in failed_users:
                        failed_users.append(u)
            if parsed.get('destination') and not dest:
                dest = parsed['destination']
            if not info['start_time'] and parsed.get('start_time'):
                info['start_time'] = parsed['start_time']
            if not info['end_time'] and parsed.get('end_time'):
                info['end_time'] = parsed['end_time']

        # ONLY users whose backups had errors are shown — clean accounts stay hidden.
        info['cpanel_user'] = ', '.join(failed_users)

        if user_errors:
            blocks = []
            for u, lines in user_errors.items():
                blocks.append(u + ':' + '\n' + '\n'.join('  ' + l for l in lines))
            info['error_log'] = '\n\n'.join(blocks)
            info['error'] = ''

        info['destination'] = dest

        # Status: if the job has finished, use the GROUP finalize log status
        # (authoritative job result). If unavailable, use the MOST SEVERE
        # status found across the item logs (failed/aborted > partial > success).
        if job_ended:
            job_status = ''
            if group_log_path:
                st = parse_backup_log(group_log_path).get('status')
                if st in ('success', 'failed', 'partial', 'aborted'):
                    job_status = st
            if not job_status:
                seen_status = set()
                for lp in all_log_paths:
                    st = parse_backup_log(lp).get('status')
                    if st in ('success', 'failed', 'partial', 'aborted'):
                        seen_status.add(st)
                if 'failed' in seen_status or 'aborted' in seen_status:
                    job_status = 'failed'
                elif 'partial' in seen_status:
                    job_status = 'partial'
                else:
                    job_status = 'success'
            info['status'] = job_status

        log_error("chose backup job: id={} ended={} users={} start={}".format(
            gid[:12], 'yes' if job_ended else 'no', info['cpanel_user'][:30], info['start_time']))
        return info, 'API'
    except Exception as e:
        log_error("api report scan error: {}".format(e))
        return None, 'none'


# ============================================================
# JetBackup queue log reading (reliable core — no API required)
# ============================================================

def find_recent_logs(max_age_hours=RECENT_LOG_WINDOW_HOURS, limit=MAX_LOGS_TO_CHECK):
    """Return the most recently modified JetBackup queue .log files."""
    if not os.path.isdir(JB_QUEUE_LOG_DIR):
        return []
    logs = []
    now = time.time()
    for dirpath, _dirnames, filenames in os.walk(JB_QUEUE_LOG_DIR):
        for fn in filenames:
            if not fn.endswith('.log'):
                continue
            p = os.path.join(dirpath, fn)
            try:
                mtime = os.path.getmtime(p)
                if now - mtime <= max_age_hours * 3600:
                    logs.append((mtime, p))
            except Exception:
                pass
    logs.sort(reverse=True)
    return [p for _m, p in logs[:limit]]


def parse_log_timestamp(ts):
    """Convert JetBackup log timestamp '[25/Aug/2026 02:54:33 +0000]' to 'Y-m-d H:i:s'."""
    try:
        parts = ts.split('+', 1)[0].strip()
        t = time.strptime(parts, '%d/%b/%Y %H:%M:%S')
        return time.strftime('%Y-%m-%d %H:%M:%S', t)
    except Exception:
        return ''


def is_account_backup(path):
    """True if a JetBackup queue log shows REAL cPanel account activity
    (accounts being backed up/transferred). Housekeeping jobs (JB Config,
    Snapshot Cleanup, Integrity Check, etc.) never contain these lines."""
    if not path or not os.path.exists(path):
        return False
    try:
        with open(path, 'r', errors='replace') as f:
            head = f.read(32768)
    except Exception:
        return False
    return any(m in head for m in ACCOUNT_BACKUP_MARKERS)


def is_account_group(g):
    """Whitelist check for an API queue group. Primary signal is the log
    content (account-transfer lines). As a safety net for the first seconds
    of a backup (before the first account line appears), we also reject
    groups whose JOB NAME matches a known housekeeping type — a real account
    backup is user-named and never equals a system job name."""
    lp = str(g.get('log_file') or '')
    if is_account_backup(lp):
        return True
    name = str((g.get('data') or {}).get('name') or '').strip()
    if not name:
        return False
    return name.lower() not in [n.lower() for n in SYSTEM_JOB_NAMES]


def parse_backup_log(path):
    """Extract real data from a JetBackup queue log file."""
    info = {
        # Strip the leading "1_"/"64_" queue prefix so this ID matches the
        # group _id returned by the API (fixes panel dedupe on LOG-SCAN path).
        'backup_id': re.sub(r'^\d+_', '', os.path.splitext(os.path.basename(path))[0]),
        'cpanel_user': '',
        'destination': '',
        'disk_used': '',
        'disk_free': '',
        'disk_total': '',
        'disk_used_pct': '',
        'start_time': '',
        'end_time': '',
        'status': 'running',
        'error': '',
        'error_log': '',
    }
    try:
        with open(path, 'r', errors='replace') as f:
            content = f.read()
    except Exception:
        return info

    lines = content.split('\n')
    timestamps = []

    for line in lines:
        # [ERROR] lines — the ONLY part we forward (not the full log)
        if '[ERROR]' in line:
            m = re.search(r'\]\s*(.*)$', line)
            err = m.group(1).strip() if m else line.strip()
            if err:
                info['error_log'] += err + '\n'
            continue

        # timestamps
        m = re.match(r'\[(\d{2}/\w{3}/\d{4} \d{2}:\d{2}:\d{2})', line)
        if m:
            timestamps.append(parse_log_timestamp(m.group(1)))

        # account
        m = re.search(r'Starting account backup for\s+(\S+)', line)
        if m and not info['cpanel_user']:
            info['cpanel_user'] = m.group(1).strip()
        m = re.search(r'Transferring account\s+"([^"]+)"', line)
        if m and not info['cpanel_user']:
            info['cpanel_user'] = m.group(1).strip()

        # destination
        m = re.search(r'Transferring account\s+"[^"]+"\s+backup to destination\s+"([^"]+)"', line)
        if m:
            info['destination'] = m.group(1).strip()
        m = re.search(r'Destination\s+"([^"]+)"', line)
        if m and not info['destination']:
            info['destination'] = m.group(1).strip()

        # disk usage:  Destination "DHKB" Disk Space Usage is 78.51% (2.9 TB out of 3.6 TB)
        m = re.search(r'Disk Space Usage is\s+([\d.]+)%\s*\(([\d.]+\s+\S+)\s+out of\s+([\d.]+\s+\S+)\)', line)
        if m:
            info['disk_used_pct'] = m.group(1).strip()
            info['disk_used'] = m.group(2).strip()
            info['disk_total'] = m.group(3).strip()
            try:
                used_pct = float(info['disk_used_pct'])
                total = float(m.group(3).split()[0])
                used = total * used_pct / 100.0
                free = total - used
                info['disk_free'] = '{:.2f} {}'.format(free, m.group(3).split()[1])
            except Exception:
                pass

    # status from the final meaningful lines
    status_keywords = [
        ('Backup Completed', 'success'),
        ('Backup Partially Completed', 'partial'),
        ('Backup Failed', 'failed'),
        ('Backup Aborted', 'aborted'),
    ]
    found = 'running'
    for line in reversed(lines):
        stripped = line.strip()
        if not stripped:
            continue
        if any(s in stripped for s in ['Backup Completed', 'Backup Partially Completed', 'Backup Failed', 'Backup Aborted']):
            for kw, st in status_keywords:
                if kw in stripped:
                    found = st
                    break
            break
        if found != 'running':
            break

    info['status'] = found

    # start / end from first and last timestamps
    if timestamps:
        info['start_time'] = timestamps[0]
        info['end_time'] = timestamps[-1] if len(timestamps) > 1 else ''

    if info['error_log'].strip():
        info['error'] = info['error_log'].strip().split('\n')[0][:500]

    return info


def format_duration(seconds):
    seconds = int(seconds or 0)
    if seconds <= 0:
        return ''
    h, rem = divmod(seconds, 3600)
    m, s = divmod(rem, 60)
    parts = []
    if h:
        parts.append('{} Hour{}'.format(h, 's' if h != 1 else ''))
    if m:
        parts.append('{} Minute{}'.format(m, 's' if m != 1 else ''))
    if s or not parts:
        parts.append('{} Second{}'.format(s, 's' if s != 1 else ''))
    return ' and '.join(parts)


def to_dt(value):
    """Normalize a datetime to 'Y-m-d H:i:s' (handles ISO '2026-08-25T00:00:01+00:00')."""
    if not value:
        return ''
    s = str(value).strip().replace('T', ' ')
    s = s.split('+')[0].split('Z')[0].strip()
    return s


def compute_duration(start_time, end_time):
    try:
        t1 = time.mktime(time.strptime(to_dt(start_time), '%Y-%m-%d %H:%M:%S'))
        t2 = time.mktime(time.strptime(to_dt(end_time), '%Y-%m-%d %H:%M:%S'))
        return format_duration(t2 - t1)
    except Exception:
        return ''


# ============================================================
# Report sending
# ============================================================

def send_report(payload):
    panel_url = _get_config_value('panel_url').rstrip('/')
    token = _get_config_value('token')
    if not panel_url or not token:
        log_error("config.json missing panel_url or token")
        return
    json_bytes = json.dumps(payload, ensure_ascii=False).encode('utf-8')
    req = urllib.request.Request(
        panel_url + '/api/report.php',
        data=json_bytes,
        headers={
            'Content-Type': 'application/json; charset=utf-8',
            'Authorization': 'Bearer ' + token,
            'User-Agent': 'JB-Hook/2.0',
        },
    )
    try:
        urllib.request.urlopen(req, timeout=30)
        log_error("report sent via {}: status={} backup_id={} user={}".format(
            payload.get('_source', '?'), payload.get('status', ''), payload.get('backup_id', '')[:12], payload.get('cpanel_user', '')[:20]))
    except urllib.error.HTTPError as e:
        body = e.read().decode('utf-8', errors='replace')[:500]
        log_error("HTTP {}: panel={} error={}".format(e.code, panel_url, body))
    except Exception as e:
        log_error("Connection failed: panel={} error={}".format(panel_url, str(e)))


# ============================================================
# Main
# ============================================================

def main():
    if not os.path.exists(CONFIG_PATH):
        log_error("config.json not found at " + CONFIG_PATH)
        sys.exit(0)

    # Log the raw hook payload (JetBackup often sends an empty one — that's why
    # we read real data from JetBackup's own logs/API below).
    raw = sys.stdin.read()
    try:
        data = json.loads(raw) if raw.strip() else {}
    except json.JSONDecodeError as e:
        log_error("JSON parse error: " + str(e))
        data = {}
    log_error("RAW payload: " + (raw.strip()[:1000] or '(empty)'))

    hostname = socket.gethostname()
    ip = get_server_ip()

    report = None
    data_source = 'none'

    # --- Primary: JetBackup API (group-level job times + item log details) ---
    report, data_source = get_backup_report()

    # --- Fallback: scan recent log files directly ---
    if report is None:
        for log in find_recent_logs():
            if not is_account_backup(log):
                continue
            report = parse_backup_log(log)
            data_source = 'LOG-SCAN'  # data came from reading log files on disk
            log_error("no API data — using log file: {}".format(os.path.basename(log)))
            break

    if report is None:
        # Nothing found — send a minimal "running" ping so the panel sees the server
        report = {
            'backup_id': '',
            'cpanel_user': '',
            'destination': '',
            'disk_used': '', 'disk_free': '', 'disk_total': '', 'disk_used_pct': '',
            'start_time': '', 'end_time': '',
            'status': 'running',
            'error': '', 'error_log': '',
            'progress': '',
        }

    duration = compute_duration(report.get('start_time'), report.get('end_time'))

    # Override disk with PRECISE values read from the destination mount (JetBackup
    # log rounds to ~2.9 TB; the real filesystem gives 2.86 TB etc.).
    precise = get_destination_disk(report.get('destination', ''))
    if precise:
        report['disk_used'] = precise.get('disk_used', report.get('disk_used', ''))
        report['disk_free'] = precise.get('disk_free', report.get('disk_free', ''))
        report['disk_total'] = precise.get('disk_total', report.get('disk_total', ''))
        report['disk_used_pct'] = precise.get('disk_used_pct', report.get('disk_used_pct', ''))
        log_error("precise destination disk: {} used / {} total ({} free)".format(
            precise.get('disk_used'), precise.get('disk_total'), precise.get('disk_free')))

    payload = {
        'backup_id': report.get('backup_id', ''),
        'server_name': hostname,
        'server_ip': ip,
        'cpanel_user': report.get('cpanel_user', ''),
        'destination': report.get('destination', ''),
        'status': report.get('status', 'running'),
        'start_time': report.get('start_time', ''),
        'end_time': report.get('end_time', ''),
        'duration': duration,
        'progress': report.get('progress', ''),
        'disk_used': report.get('disk_used', ''),
        'disk_free': report.get('disk_free', ''),
        'disk_total': report.get('disk_total', ''),
        'disk_used_pct': report.get('disk_used_pct', ''),
        'error': report.get('error', ''),
        'error_log': report.get('error_log', ''),
        '_source': data_source,
    }

    send_report(payload)

    # Always exit 0 — never block the backup process
    sys.exit(0)


def log_error(msg):
    try:
        line = '{} {}\n'.format(time.strftime('%Y-%m-%d %H:%M:%S'), msg)
        with open(LOG_PATH, 'a') as f:
            f.write(line)
        if os.path.getsize(LOG_PATH) > LOG_MAX_SIZE:
            os.rename(LOG_PATH, LOG_PATH + '.old')
            with open(LOG_PATH + '.old', 'rb') as old, open(LOG_PATH, 'wb') as new:
                old.seek(0, os.SEEK_END)
                fsize = old.tell()
                if fsize > LOG_KEEP_TAIL:
                    old.seek(-LOG_KEEP_TAIL, os.SEEK_END)
                    old.readline()  # skip a partial line at the cut point
                for l in old:
                    new.write(l)
            os.remove(LOG_PATH + '.old')
    except Exception:
        pass


if __name__ == '__main__':
    main()
