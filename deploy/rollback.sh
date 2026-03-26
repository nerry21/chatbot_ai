#!/bin/bash
# =============================================================================
# rollback.sh
# Script rollback chatbot AI ke commit/release sebelumnya
#
# Penggunaan:
#   bash deploy/rollback.sh <git_commit_hash>
#
# Contoh:
#   bash deploy/rollback.sh abc1234
#
# PERINGATAN:
#   - Script ini tidak me-restore database secara otomatis
#   - Restore database HARUS dilakukan manual jika migration sempat jalan
#   - Selalu baca ROLLBACK_PLAN.md sebelum menjalankan script ini
# =============================================================================

set -uo pipefail

# -----------------------------------------------------------------------------
# Konfigurasi
# -----------------------------------------------------------------------------
PROJECT_DIR="/var/www/chatbot_ai"
PHP_BIN="php"
COMPOSER_BIN="composer"
SUPERVISOR_WORKER="chatbot-worker"

# Warna
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_info()  { echo -e "${BLUE}[INFO]${NC}  $1"; }
log_ok()    { echo -e "${GREEN}[OK]${NC}    $1"; }
log_warn()  { echo -e "${YELLOW}[WARN]${NC}  $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

confirm() {
    read -r -p "$1 [y/N] " response
    case "$response" in
        [yY][eE][sS]|[yY]) return 0 ;;
        *) return 1 ;;
    esac
}

# -----------------------------------------------------------------------------
# Validasi argumen
# -----------------------------------------------------------------------------
if [[ $# -lt 1 ]]; then
    log_error "Gunakan: bash deploy/rollback.sh <git_commit_hash>"
    echo ""
    echo "Untuk melihat daftar commit:"
    echo "  git log --oneline -10"
    echo ""
    exit 1
fi

TARGET_COMMIT="$1"

echo ""
echo "=============================================="
echo "  Chatbot AI — Rollback"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "=============================================="
echo ""

# Pindah ke direktori project
cd "$PROJECT_DIR" || { log_error "Direktori $PROJECT_DIR tidak ditemukan."; exit 1; }

# Tampilkan info commit target
log_info "Target rollback: $TARGET_COMMIT"
git show "$TARGET_COMMIT" --stat --no-patch 2>/dev/null || { log_error "Commit hash '$TARGET_COMMIT' tidak ditemukan."; exit 1; }
echo ""

# Konfirmasi
log_warn "PERHATIAN:"
log_warn "  - Script ini akan mengembalikan kode ke commit: $TARGET_COMMIT"
log_warn "  - Jika migration sempat jalan, Anda HARUS restore database secara manual"
log_warn "  - Script ini TIDAK me-restore database"
echo ""
if ! confirm "Yakin ingin melakukan rollback ke $TARGET_COMMIT?"; then
    log_warn "Rollback dibatalkan."
    exit 0
fi

# Konfirmasi database
echo ""
log_warn "Apakah migration sempat berjalan saat deploy yang gagal?"
if confirm "Ya, migration sempat jalan — saya akan restore database manual setelah ini?"; then
    log_warn "Ingat: Anda harus restore database manual setelah script ini selesai."
    log_warn "Lihat: docs/deployment/ROLLBACK_PLAN.md bagian 'Restore Database'"
    NEED_DB_RESTORE=true
else
    NEED_DB_RESTORE=false
fi

echo ""

# -----------------------------------------------------------------------------
# Step 1: Maintenance mode ON
# -----------------------------------------------------------------------------
log_info "Mengaktifkan maintenance mode..."
$PHP_BIN artisan down --message="Sistem sedang dipulihkan." --retry=60 || log_warn "Gagal set maintenance mode (mungkin sudah aktif)"
log_ok "Maintenance mode aktif."

# -----------------------------------------------------------------------------
# Step 2: Stop worker
# -----------------------------------------------------------------------------
log_info "Menghentikan queue worker..."
if command -v supervisorctl &>/dev/null; then
    sudo supervisorctl stop "${SUPERVISOR_WORKER}:*" || log_warn "Gagal stop worker via Supervisor"
    log_ok "Worker dihentikan."
else
    log_warn "supervisorctl tidak ditemukan — hentikan worker secara manual jika perlu"
fi

# -----------------------------------------------------------------------------
# Step 3: Rollback kode
# -----------------------------------------------------------------------------
log_info "Mengembalikan kode ke commit: $TARGET_COMMIT"
git checkout "$TARGET_COMMIT"
log_ok "Kode berhasil dikembalikan ke $TARGET_COMMIT"

# -----------------------------------------------------------------------------
# Step 4: Restore Composer dependencies ke versi lama
# -----------------------------------------------------------------------------
log_info "Menginstall Composer dependencies sesuai commit ini..."
$COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction
log_ok "Composer dependencies selesai."

# -----------------------------------------------------------------------------
# Step 5: Pengingat restore database
# -----------------------------------------------------------------------------
if [[ "$NEED_DB_RESTORE" == "true" ]]; then
    echo ""
    log_warn "========================================================"
    log_warn " AKSI MANUAL DIPERLUKAN: Restore Database"
    log_warn "========================================================"
    log_warn " Jalankan perintah berikut untuk restore:"
    echo ""
    echo "   gunzip < /backup/chatbot_ai/pre-deploy/FILE_BACKUP.sql.gz | \\"
    echo "     mysql -u chatbot_user -p chatbot_ai"
    echo ""
    log_warn " Ganti FILE_BACKUP.sql.gz dengan nama file backup sebelum deploy."
    log_warn " Setelah restore selesai, tekan Enter untuk melanjutkan."
    echo ""
    read -r -p "Tekan Enter setelah restore database selesai (atau Ctrl+C untuk batalkan)..."
fi

# -----------------------------------------------------------------------------
# Step 6: Clear cache
# -----------------------------------------------------------------------------
log_info "Membersihkan cache..."
$PHP_BIN artisan config:clear
$PHP_BIN artisan cache:clear
$PHP_BIN artisan route:clear
$PHP_BIN artisan view:clear
log_ok "Cache dibersihkan."

# Rebuild cache
log_info "Membangun ulang cache..."
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache
log_ok "Cache dibangun ulang."

# -----------------------------------------------------------------------------
# Step 7: Set permission
# -----------------------------------------------------------------------------
log_info "Memperbaiki permission..."
chmod -R 775 storage bootstrap/cache
log_ok "Permission selesai."

# -----------------------------------------------------------------------------
# Step 8: Start worker
# -----------------------------------------------------------------------------
log_info "Menjalankan ulang queue worker..."
if command -v supervisorctl &>/dev/null; then
    sudo supervisorctl start "${SUPERVISOR_WORKER}:*"
    log_ok "Worker dijalankan ulang via Supervisor."
else
    log_warn "supervisorctl tidak ditemukan — jalankan worker secara manual"
fi

# -----------------------------------------------------------------------------
# Step 9: Maintenance mode OFF
# -----------------------------------------------------------------------------
log_info "Mematikan maintenance mode..."
$PHP_BIN artisan up
log_ok "Maintenance mode dinonaktifkan. Aplikasi kembali online."

# -----------------------------------------------------------------------------
# Step 10: Verifikasi
# -----------------------------------------------------------------------------
echo ""
log_info "Verifikasi setelah rollback..."
echo ""

$PHP_BIN artisan about --only=Environment 2>/dev/null || true

echo ""
$PHP_BIN artisan chatbot:health-check 2>/dev/null || log_warn "Health check melaporkan masalah — periksa dashboard"

echo ""
if command -v supervisorctl &>/dev/null; then
    sudo supervisorctl status "${SUPERVISOR_WORKER}:*" || true
fi

echo ""
echo "=============================================="
log_ok "Rollback selesai: $(date '+%Y-%m-%d %H:%M:%S')"
echo "=============================================="
echo ""
log_info "Langkah verifikasi manual:"
echo "  1. Test kirim pesan WhatsApp ke bot"
echo "  2. Pantau log: tail -f storage/logs/laravel-\$(date +%Y-%m-%d).log"
echo "  3. Cek failed jobs: $PHP_BIN artisan queue:failed"
echo "  4. Setelah stabil, investigasi penyebab gagal sebelum deploy ulang"
echo ""
