#!/bin/bash
# ============================================================
# JBWizerd Hook — Installer
#
# Usage:
#   Install with automatic registration (gets its own key):
#     bash install.sh --panel-url https://panel.example.com --register-key YOUR_REG_KEY
#
#   Install with a direct key (generated from the panel):
#     bash install.sh --panel-url https://panel.example.com --token XXXX-XXXX-XXXX-XXXX
#
#   Update/replace an existing key on an already-installed server:
#     bash install.sh --panel-url https://panel.example.com --update-key XXXX-XXXX-XXXX-XXXX
#
# Flags:
#   --panel-url    <URL>     Panel address (https://panel.example.com)
#   --register-key <KEY>     Panel registration key (auto-register)
#   --token        <KEY>     Direct server key
#   --update-key   <KEY>     Replace the key in config.json
#   --hostname     <NAME>    Override server name sent to the panel
# ============================================================

set -e

# Override install dir for testing / custom setups: JB_HOOK_DIR=/path bash install.sh ...
INSTALL_DIR="${JB_HOOK_DIR:-/JBWizerd}"
HOOK_SOURCE="${BASH_SOURCE[0]%/*}/jb_hook.py"
CONFIG_FILE="$INSTALL_DIR/config.json"

PANEL_URL=""
REGISTER_KEY=""
TOKEN=""
UPDATE_KEY=""
HOSTNAME_OVERRIDE=""

# --- Parse flags ---
while [ $# -gt 0 ]; do
  case "$1" in
    --panel-url)    PANEL_URL="$2"; shift 2 ;;
    --register-key) REGISTER_KEY="$2"; shift 2 ;;
    --token)        TOKEN="$2"; shift 2 ;;
    --update-key)   UPDATE_KEY="$2"; shift 2 ;;
    --hostname)     HOSTNAME_OVERRIDE="$2"; shift 2 ;;
    --help|-h)
      grep '^#' "$0" | sed 's/^# \{0,1\}//' | sed -n '2,28p'; exit 0 ;;
    *) echo "Unknown option: $1 (use --help)"; exit 1 ;;
  esac
done

if [ -z "$PANEL_URL" ]; then
  echo "ERROR: --panel-url is required. Use --help for usage."
  exit 1
fi
PANEL_URL="${PANEL_URL%/}"

# --- Update key mode: just rewrite config.json ---
if [ -n "$UPDATE_KEY" ]; then
  if [ ! -f "$CONFIG_FILE" ]; then
    echo "ERROR: $CONFIG_FILE not found. Run a full install first."
    exit 1
  fi
  /usr/bin/python3 - "$PANEL_URL" "$UPDATE_KEY" "$CONFIG_FILE" <<'PY'
import json, sys
url, key, path = sys.argv[1], sys.argv[2], sys.argv[3]
with open(path) as f:
    cfg = json.load(f)
cfg['panel_url'] = url
cfg['token'] = key
with open(path, 'w') as f:
    json.dump(cfg, f, indent=2)
print("config.json updated.")
PY
  echo "New key saved. Verify with: curl -s -X POST -H 'Authorization: Bearer $UPDATE_KEY' $PANEL_URL/api/ping.php"
  echo ""
  echo "NOTE: JetBackup hooks keep running automatically — no restart needed."
  exit 0
fi

# --- Determine token ---
if [ -n "$TOKEN" ]; then
  FINAL_TOKEN="$TOKEN"
elif [ -n "$REGISTER_KEY" ]; then
  echo "Registering server with the panel..."
  HOSTNAME_ARG="${HOSTNAME_OVERRIDE:-$(hostname -f 2>/dev/null || hostname)}"
  # Detect the server's outbound IP automatically (same method as the hook script)
  SERVER_IP=$(/usr/bin/python3 - <<'PY' 2>/dev/null || true
import socket
s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
try:
    s.connect(('8.8.8.8', 80))
    print(s.getsockname()[0])
except Exception:
    print('')
finally:
    s.close()
PY
)
  RESP=$(curl -s -m 20 -X POST "$PANEL_URL/api/register.php" \
    -H "Content-Type: application/json" \
    -H "X-Registration-Key: $REGISTER_KEY" \
    -d "{\"name\":\"$HOSTNAME_ARG\",\"ip\":\"$SERVER_IP\"}")
  FINAL_TOKEN=$(echo "$RESP" | /usr/bin/python3 -c 'import sys,json; print(json.load(sys.stdin).get("token",""))' 2>/dev/null || true)
  if [ -z "$FINAL_TOKEN" ]; then
    echo "ERROR: Registration failed. Response was:"
    echo "$RESP"
    echo "Check the panel URL and registration key."
    exit 1
  fi
  echo "Registered successfully."
else
  echo "ERROR: Provide either --token or --register-key. Use --help for usage."
  exit 1
fi

# --- Install files ---
echo "Installing hook to $INSTALL_DIR ..."
if [ ! -d "$INSTALL_DIR" ]; then
  mkdir -p "$INSTALL_DIR"
fi

if [ -f "$HOOK_SOURCE" ]; then
  cp "$HOOK_SOURCE" "$INSTALL_DIR/jb_hook.py"
else
  # Support remote install where this script is streamed via curl
  curl -s -m 20 -o "$INSTALL_DIR/jb_hook.py" "$PANEL_URL/hook/jb_hook.py" || {
    echo "ERROR: jb_hook.py not found locally and could not be downloaded from $PANEL_URL/hook/jb_hook.py"
    exit 1
  }
fi
chmod +x "$INSTALL_DIR/jb_hook.py"

cat > "$CONFIG_FILE" <<EOF
{
  "panel_url": "$PANEL_URL",
  "token": "$FINAL_TOKEN"
}
EOF
chmod 600 "$CONFIG_FILE"

# --- Test connection ---
echo "Testing connection to the panel..."
TEST=$(curl -s -m 20 -X POST -H "Authorization: Bearer $FINAL_TOKEN" "$PANEL_URL/api/ping.php")
case "$TEST" in
  *'"ok":true'*)
    echo "Connected successfully. Panel says: $TEST" ;;
  *)
    echo "WARNING: ping test failed. Response: $TEST"
    echo "The key may still work once the panel is reachable."
    ;;
esac

echo ""
echo "============================================================"
echo " INSTALLATION COMPLETE"
echo "============================================================"
echo ""
echo " Files installed:"
echo "   Script:  $INSTALL_DIR/jb_hook.py"
echo "   Config:  $CONFIG_FILE"
echo ""
echo " NEXT STEP — Add the hooks in JetBackup manually:"
echo ""
echo " JetBackup 5 UI:  JetBackup 5 > API / Hooks section"
echo "   - Add a PRE backup hook:"
echo "       /usr/bin/python3 $INSTALL_DIR/jb_hook.py"
echo "   - Add a POST backup hook:"
echo "       /usr/bin/python3 $INSTALL_DIR/jb_hook.py"
echo ""
echo " (Both use the same script. The hook type (PRE/POST) is what"
echo "  JetBackup calls them — add one under each slot.)"
echo ""
echo " TEST the hook manually:"
echo "   echo '{\"status\":\"success\",\"backup_type\":\"public_html\",\"start_time\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",\"end_time\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",\"username\":\"testuser\"}' | /usr/bin/python3 $INSTALL_DIR/jb_hook.py"
echo ""
echo " REPLACE the key later (short command):"
echo "   bash <(curl -sL $PANEL_URL/hook/install.sh) --panel-url $PANEL_URL --update-key NEW_KEY"
echo "============================================================"
