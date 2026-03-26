#!/bin/bash
# =============================================================================
# post-deploy-check.sh
# Verifikasi cepat setelah deploy production chatbot AI
#
# Penggunaan:
#   bash deploy/post-deploy-check.sh
#
# Script ini hanya memeriksa — tidak mengubah apapun.
# Exit code 0 = semua OK, Exit code 1 = ada masalah
# =============================================================================

set -uo pipefail

# -----------------------------------------------------------------------------
# Konfigurasi
# -----------------------------------------------------------------------------
PROJECT_DIR="/var/www/chatbot_ai"
PHP_BIN="php"
SUPERVISOR_WORKER="chatbot-worker"

# Warna
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

PASS=0
FAIL=0

# -----------------------------------------------------------------------------
# Helper
# -----------------------------------------------------------------------------
check_pass() { echo -e "  ${GREEN}[PASS]${NC} $1"; ((PASS++)) || true; }
check_fail() { echo -e "  ${RED}[FAIL]${NC} $1"; ((FAIL++)) || true; }
check_warn() { echo -e "  ${YELLOW}[WARN]${NC} $1"; }
section()    { echo -e "\n${BLUE}=== $1 ===${NC}"; }

# Pindah ke direktori project
cd "$PROJECT_DIR" || { echo -e "${RED}ERROR: Direktori $PROJECT_DIR tidak ditemukan.${NC}"; exit 1; }

echo ""
echo "================================================"
echo "  Chatbot AI — Post-Deploy Check"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "================================================"

# -----------------------------------------------------------------------------
# 1. Artisan bisa dijalankan
# -----------------------------------------------------------------------------
section "Artisan & Framework"

if $PHP_BIN artisan --version &>/dev/null; then
    VERSION=$($PHP_BIN artisan --version)
    check_pass "Artisan berjalan: $VERSION"
else
    check_fail "Artisan tidak bisa dijalankan"
fi

# Cek environment
ENV_VALUE=$($PHP_BIN artisan env 2>/dev/null | grep -oP '(?<=Current application environment: ).*' || echo "unknown")
if [[ "$ENV_VALUE" == "production" ]]; then
    check_pass "APP_ENV = production"
else
    check_warn "APP_ENV = $ENV_VALUE (bukan production)"
fi

# Cek APP_DEBUG
DEBUG_VALUE=$($PHP_BIN artisan tinker --execute="echo config('app.debug') ? 'true' : 'false';" 2>/dev/null || echo "unknown")
if [[ "$DEBUG_VALUE" == "false" ]]; then
    check_pass "APP_DEBUG = false"
else
    check_fail "APP_DEBUG = $DEBUG_VALUE (seharusnya false di production)"
fi

# -----------------------------------------------------------------------------
# 2. Config cache valid
# -----------------------------------------------------------------------------
section "Config Cache"

if [[ -f "bootstrap/cache/config.php" ]]; then
    check_pass "bootstrap/cache/config.php ada"
else
    check_fail "bootstrap/cache/config.php tidak ditemukan — jalankan: php artisan config:cache"
fi

if [[ -f "bootstrap/cache/routes-v7.php" ]] || ls bootstrap/cache/routes*.php &>/dev/null 2>&1; then
    check_pass "Route cache ada"
else
    check_warn "Route cache tidak ditemukan — jalankan: php artisan route:cache"
fi

# -----------------------------------------------------------------------------
# 3. Database koneksi
# -----------------------------------------------------------------------------
section "Database"

if $PHP_BIN artisan db:monitor 2>/dev/null; then
    check_pass "Koneksi database berhasil"
else
    # Coba cara alternatif
    if $PHP_BIN artisan tinker --execute="\DB::connection()->getPdo(); echo 'ok';" 2>/dev/null | grep -q "ok"; then
        check_pass "Koneksi database berhasil"
    else
        check_fail "Koneksi database gagal — cek DB_HOST, DB_USERNAME, DB_PASSWORD"
    fi
fi

# Cek migration status
PENDING=$($PHP_BIN artisan migrate:status 2>/dev/null | grep -c "Pending" || echo "0")
if [[ "$PENDING" == "0" ]]; then
    check_pass "Tidak ada migration pending"
else
    check_fail "$PENDING migration pending — jalankan: php artisan migrate --force"
fi

# -----------------------------------------------------------------------------
# 4. Queue worker
# -----------------------------------------------------------------------------
section "Queue Worker"

if command -v supervisorctl &>/dev/null; then
    SUPERVISOR_STATUS=$(sudo supervisorctl status "${SUPERVISOR_WORKER}:*" 2>/dev/null || echo "ERROR")
    if echo "$SUPERVISOR_STATUS" | grep -q "RUNNING"; then
        RUNNING_COUNT=$(echo "$SUPERVISOR_STATUS" | grep -c "RUNNING" || echo "0")
        check_pass "Supervisor worker RUNNING ($RUNNING_COUNT proses)"
    else
        check_fail "Supervisor worker tidak RUNNING:\n$SUPERVISOR_STATUS"
    fi
else
    # Cek via ps
    WORKER_COUNT=$(ps aux | grep "queue:work" | grep -v grep | wc -l || echo "0")
    if [[ "$WORKER_COUNT" -gt 0 ]]; then
        check_pass "Queue worker berjalan ($WORKER_COUNT proses)"
    else
        check_warn "supervisorctl tidak ditemukan dan tidak ada proses queue:work terdeteksi"
    fi
fi

# Cek backlog queue
JOB_COUNT=$($PHP_BIN artisan tinker --execute="echo \DB::table('jobs')->count();" 2>/dev/null || echo "unknown")
if [[ "$JOB_COUNT" == "unknown" ]]; then
    check_warn "Tidak bisa membaca jumlah job dalam antrian"
elif [[ "$JOB_COUNT" -gt 100 ]]; then
    check_warn "Backlog queue tinggi: $JOB_COUNT job (worker mungkin lambat)"
else
    check_pass "Queue backlog normal: $JOB_COUNT job"
fi

# Cek failed jobs
FAILED_COUNT=$($PHP_BIN artisan tinker --execute="echo \DB::table('failed_jobs')->count();" 2>/dev/null || echo "unknown")
if [[ "$FAILED_COUNT" == "unknown" ]]; then
    check_warn "Tidak bisa membaca jumlah failed jobs"
elif [[ "$FAILED_COUNT" -gt 0 ]]; then
    check_warn "$FAILED_COUNT failed job — cek: php artisan queue:failed"
else
    check_pass "Tidak ada failed jobs"
fi

# -----------------------------------------------------------------------------
# 5. Health check command
# -----------------------------------------------------------------------------
section "Chatbot Health Check"

if $PHP_BIN artisan chatbot:health-check 2>/dev/null; then
    check_pass "Health check berhasil dijalankan"
else
    HEALTH_EXIT=$?
    if [[ "$HEALTH_EXIT" -eq 1 ]]; then
        check_warn "Health check melaporkan masalah (exit 1) — cek dashboard notifikasi"
    else
        check_fail "Health check gagal dijalankan (exit $HEALTH_EXIT)"
    fi
fi

# -----------------------------------------------------------------------------
# 6. Permission storage
# -----------------------------------------------------------------------------
section "Permission"

if [[ -w "storage/logs" ]]; then
    check_pass "storage/logs dapat ditulis"
else
    check_fail "storage/logs tidak dapat ditulis — jalankan: chmod -R 775 storage"
fi

if [[ -w "bootstrap/cache" ]]; then
    check_pass "bootstrap/cache dapat ditulis"
else
    check_fail "bootstrap/cache tidak dapat ditulis — jalankan: chmod -R 775 bootstrap/cache"
fi

# -----------------------------------------------------------------------------
# Ringkasan
# -----------------------------------------------------------------------------
echo ""
echo "================================================"
echo -e "  Hasil: ${GREEN}$PASS PASS${NC} | ${RED}$FAIL FAIL${NC}"
echo "================================================"
echo ""

if [[ "$FAIL" -gt 0 ]]; then
    echo -e "${RED}Ada $FAIL masalah yang perlu diselesaikan sebelum sistem dinyatakan siap.${NC}"
    echo ""
    exit 1
else
    echo -e "${GREEN}Semua check lulus. Sistem siap beroperasi.${NC}"
    echo ""
    exit 0
fi
