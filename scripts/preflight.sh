#!/usr/bin/env bash
#
# Mnemosyne development preflight.
# Fast, read-only, idempotent, no sudo, no secrets in output.
# Exit code: 0 when ready (warnings allowed), 1 on any blocking failure.
#
# Tunables:
#   PREFLIGHT_DISK_WARN_PCT   warn when filesystem usage exceeds this (default 85)
#   PREFLIGHT_SKIP_PUBLIC=1   skip the public HTTPS check (e.g. offline work)

set -u

EXPECTED_USER="mnemosyne"
EXPECTED_REPO="/srv/projects/mnemosyne"
EXPECTED_ORIGIN="https://github.com/sandrosciessere/mnemosyne.git"
DATA_ROOT="/srv/data/mnemosyne"
LOCAL_BASE="http://127.0.0.1:${MNEMOSYNE_HTTP_PORT:-8100}"
PUBLIC_URL="https://mnemosyne.shellrent.com/health/live"
DISK_WARN_PCT="${PREFLIGHT_DISK_WARN_PCT:-85}"

FAILED=0
WARNED=0

ok()   { printf '[OK]   %s\n' "$1"; }
warn() { printf '[WARN] %s\n' "$1"; WARNED=1; }
fail() { printf '[FAIL] %s\n' "$1"; FAILED=1; }

echo "Mnemosyne Development Preflight"
echo

# --- Identity ---------------------------------------------------------------
CURRENT_USER="$(id -un)"
if [ "$CURRENT_USER" = "$EXPECTED_USER" ]; then
    ok "user: $CURRENT_USER"
else
    fail "user: $CURRENT_USER (expected $EXPECTED_USER — run: sudo -iu mnemosyne)"
fi

# --- Repository -------------------------------------------------------------
REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || true)"
if [ -z "$REPO_ROOT" ] && [ -d "$EXPECTED_REPO/.git" ]; then
    REPO_ROOT="$EXPECTED_REPO"
fi

if [ -z "$REPO_ROOT" ]; then
    fail "repository: not inside a git checkout and $EXPECTED_REPO not accessible"
else
    if [ "$REPO_ROOT" = "$EXPECTED_REPO" ]; then
        ok "repository: $REPO_ROOT"
    else
        warn "repository: $REPO_ROOT (expected $EXPECTED_REPO)"
    fi
    cd "$REPO_ROOT" || fail "repository: cannot cd into $REPO_ROOT"
fi

# --- Git --------------------------------------------------------------------
if [ -n "$REPO_ROOT" ]; then
    ORIGIN_URL="$(git remote get-url origin 2>/dev/null || echo '')"
    if [ "$ORIGIN_URL" = "$EXPECTED_ORIGIN" ]; then
        ok "git remote: origin correct, no embedded credentials"
    elif printf '%s' "$ORIGIN_URL" | grep -q '@'; then
        fail "git remote: origin URL appears to embed credentials"
    else
        warn "git remote: unexpected origin URL"
    fi

    BRANCH="$(git branch --show-current 2>/dev/null || echo '')"
    ok "git branch: ${BRANCH:-<detached>}"

    if [ -z "$(git status --porcelain 2>/dev/null)" ]; then
        ok "git working tree: clean"
    else
        warn "git working tree: uncommitted changes present"
    fi

    if git ls-files --error-unmatch .env >/dev/null 2>&1; then
        fail "secrets: .env is tracked by git"
    elif [ -f .env ]; then
        ENV_STAT="$(stat -c '%U %a' .env 2>/dev/null || echo '')"
        if [ "$ENV_STAT" = "mnemosyne 600" ]; then
            ok "secrets: .env untracked, mnemosyne 0600"
        else
            warn "secrets: .env has unexpected owner/mode ($ENV_STAT — expected mnemosyne 600)"
        fi
    else
        warn "secrets: .env missing (stack cannot start without it)"
    fi

    if git ls-remote --exit-code origin HEAD >/dev/null 2>&1; then
        ok "GitHub access"
    else
        warn "GitHub access: origin unreachable (network or credentials)"
    fi

    REPO_OWNER="$(stat -c '%U:%G' "$REPO_ROOT" 2>/dev/null || echo '')"
    if [ "$REPO_OWNER" = "mnemosyne:mnemosyne" ]; then
        FOREIGN="$(find "$REPO_ROOT" -maxdepth 2 -not -user mnemosyne -not -path '*/.git/*' 2>/dev/null | head -3)"
        if [ -z "$FOREIGN" ]; then
            ok "repository ownership: mnemosyne:mnemosyne"
        else
            warn "repository ownership: foreign-owned entries found (e.g. $(echo "$FOREIGN" | head -1))"
        fi
    else
        fail "repository ownership: $REPO_OWNER (expected mnemosyne:mnemosyne)"
    fi
fi

# --- Data storage -----------------------------------------------------------
if [ -d "$DATA_ROOT" ]; then
    DATA_OWNER="$(stat -c '%U:%G' "$DATA_ROOT" 2>/dev/null || echo '')"
    if [ "$DATA_OWNER" != "mnemosyne:mnemosyne" ]; then
        fail "data storage: $DATA_ROOT owned by $DATA_OWNER"
    elif [ -w "$DATA_ROOT/tmp" ]; then
        ok "data storage: $DATA_ROOT (tmp writable)"
    else
        fail "data storage: $DATA_ROOT/tmp not writable"
    fi
else
    fail "data storage: $DATA_ROOT missing"
fi

# --- Docker -----------------------------------------------------------------
if docker info >/dev/null 2>&1; then
    ok "docker: daemon reachable without sudo"
else
    fail "docker: daemon not reachable (docker group membership?)"
fi

if docker compose version >/dev/null 2>&1; then
    if [ -n "$REPO_ROOT" ] && docker compose config --quiet >/dev/null 2>&1; then
        ok "compose: configuration valid"
    else
        fail "compose: docker compose config failed"
    fi
else
    fail "compose: docker compose not available"
fi

# --- Stack services ---------------------------------------------------------
# pg/redis/app/web are blocking; horizon/scheduler/ai-worker degrade to WARN.
if [ -n "$REPO_ROOT" ] && docker info >/dev/null 2>&1; then
    PS_OUT="$(docker compose ps --format '{{.Service}}\t{{.Status}}' 2>/dev/null || echo '')"
    for SVC in pg redis app web horizon scheduler ai-worker; do
        LINE="$(printf '%s\n' "$PS_OUT" | awk -F'\t' -v s="$SVC" '$1 == s { print $2 }')"
        case "$SVC" in
            pg|redis|app|web) LEVEL=fail ;;
            *) LEVEL=warn ;;
        esac
        if [ -z "$LINE" ]; then
            $LEVEL "$SVC: not running"
        elif printf '%s' "$LINE" | grep -qi 'unhealthy'; then
            $LEVEL "$SVC: unhealthy ($LINE)"
        elif printf '%s' "$LINE" | grep -qi 'restarting'; then
            $LEVEL "$SVC: restarting"
        elif printf '%s' "$LINE" | grep -qi '^Up'; then
            ok "$SVC: $LINE"
        else
            $LEVEL "$SVC: $LINE"
        fi
    done
fi

# --- Local health endpoints -------------------------------------------------
for EP in /health/live /health/ready /api/v1/health; do
    if curl -fsS -m 5 "$LOCAL_BASE$EP" >/dev/null 2>&1; then
        ok "local $EP"
    else
        fail "local $EP unreachable or failing ($LOCAL_BASE)"
    fi
done

# --- Public endpoint (warning only: local development stays possible) -------
if [ "${PREFLIGHT_SKIP_PUBLIC:-0}" = "1" ]; then
    warn "public endpoint: check skipped (PREFLIGHT_SKIP_PUBLIC=1)"
elif curl -fsS -m 8 "$PUBLIC_URL" >/dev/null 2>&1; then
    ok "public endpoint: $PUBLIC_URL"
else
    warn "public endpoint: $PUBLIC_URL unreachable (local development unaffected)"
fi

# --- Disk -------------------------------------------------------------------
DISK_LINE="$(df -P "$DATA_ROOT" 2>/dev/null | awk 'NR==2 { gsub("%","",$5); print $5, $4 }')"
if [ -n "$DISK_LINE" ]; then
    USED_PCT="$(printf '%s' "$DISK_LINE" | cut -d' ' -f1)"
    AVAIL_GB="$(( $(printf '%s' "$DISK_LINE" | cut -d' ' -f2) / 1024 / 1024 ))"
    if [ "$USED_PCT" -ge "$DISK_WARN_PCT" ]; then
        warn "disk: ${USED_PCT}% used, ${AVAIL_GB}G free (warn threshold ${DISK_WARN_PCT}%)"
    else
        ok "disk: ${USED_PCT}% used, ${AVAIL_GB}G free"
    fi
else
    warn "disk: unable to read usage for $DATA_ROOT"
fi

# --- Verdict ----------------------------------------------------------------
echo
if [ "$FAILED" -ne 0 ]; then
    echo "NOT READY FOR DEVELOPMENT"
    exit 1
fi
if [ "$WARNED" -ne 0 ]; then
    echo "READY FOR DEVELOPMENT (with warnings)"
else
    echo "READY FOR DEVELOPMENT"
fi
exit 0
