#!/bin/bash
# =============================================================================
# production-deploy.sh
# Deploy script untuk chatbot AI ke production
#
# Penggunaan:
#   bash deploy/production-deploy.sh
#
# Pastikan sebelum menjalankan script ini:
#   1. Backup database sudah dibuat
#   2. Anda berada di direktori project yang benar
#   3. User yang menjalankan memiliki akses ke sudo untuk Supervisor
# =============================================================================

set -euo pipefail

# -----------------------------------------------------------------------------
# Konfigurasi — sesuaikan dengan environment Anda
# -----------------------------------------------------------------------------
PROJECT_DIR="/var/www/chatbot_ai"
PHP_BIN="php"                          # Ganti dengan path absolut jika perlu, mis: /usr/bin/php8.2
COMPOSER_BIN="composer"                # Ganti dengan path absolut jika perlu
SUPERVISOR_WORKER="chatbot-worker"     # Nama program Supervisor
GIT_BRANCH="main"                      # Branch yang di-deploy

# Warna untuk output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# -----------------------------------------------------------------------------
# Helper functions
# -----------------------------------------------------------------------------
log_info()    { echo -e "${BLUE}[INFO]${NC}  $1"; }
log_ok()      { echo -e "${GREEN}[OK]${NC}    $1"; }
log_warn()    { echo -e "${YELLOW}[WARN]${NC}  $1"; }
log_error()   { echo -e "${RED}[ERROR]${NC} $1"; }

confirm() {
    read -r -p "$1 [y/N] " response
    case "$response" in
        [yY][eE][sS]|[yY]) return 0 ;;
        *) return 1 ;;
    esac
}

# -----------------------------------------------------------------------------
# Mulai deploy
# -----------------------------------------------------------------------------
echo ""
echo "=============================================="
echo "  Chatbot AI — Production Deploy"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "=============================================="
echo ""

# Pindah ke direktori project
log_info "Masuk ke direktori project: $PROJECT_DIR"
cd "$PROJECT_DIR" || { log_error "Direktori $PROJECT_DIR tidak ditemukan."; exit 1; }

# -----------------------------------------------------------------------------
# Step 0: Konfirmasi backup
# -----------------------------------------------------------------------------
log_warn "PENTING: Pastikan backup database sudah dibuat sebelum melanjutkan!"
if ! confirm "Backup database sudah ada? Lanjutkan deploy?"; then
    log_warn "Deploy dibatalkan. Buat backup dulu."
    exit 0
fi

# -----------------------------------------------------------------------------
# Step 1: Maintenance mode ON
# -----------------------------------------------------------------------------
log_info "Mengaktifkan maintenance mode..."
$PHP_BIN artisan down --message="Sistem sedang diperbarui. Silakan coba lagi dalam beberapa menit." --retry=60
log_ok "Maintenance mode aktif."

# Fungsi trap untuk matikan maintenance mode jika script gagal di tengah jalan
cleanup_on_error() {
    log_error "Deploy gagal! Mematikan maintenance mode..."
    $PHP_BIN artisan up || true
    log_warn "Maintenance mode dinonaktifkan karena error. Periksa kondisi sistem."
}
trap cleanup_on_error ERR

# -----------------------------------------------------------------------------
# Step 2: Pull kode terbaru
# -----------------------------------------------------------------------------
log_info "Mengambil kode terbaru dari git ($GIT_BRANCH)..."
git fetch origin
git pull origin "$GIT_BRANCH"
log_ok "Kode berhasil diperbarui."

# -----------------------------------------------------------------------------
# Step 3: Install/update Composer dependencies
# -----------------------------------------------------------------------------
log_info "Menginstall Composer dependencies (production)..."
$COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction
log_ok "Composer dependencies selesai."

# -----------------------------------------------------------------------------
# Step 4: Jalankan migration
# -----------------------------------------------------------------------------
log_info "Menjalankan database migration..."
log_warn "Ini akan mengubah skema database. Pastikan backup sudah ada!"
$PHP_BIN artisan migrate --force
log_ok "Migration selesai."

# -----------------------------------------------------------------------------
# Step 5: Clear cache lama (urutan penting)
# -----------------------------------------------------------------------------
log_info "Membersihkan cache lama..."
$PHP_BIN artisan config:clear
$PHP_BIN artisan cache:clear
$PHP_BIN artisan route:clear
$PHP_BIN artisan view:clear
log_ok "Cache lama dibersihkan."

# -----------------------------------------------------------------------------
# Step 6: Rebuild cache baru
# -----------------------------------------------------------------------------
log_info "Membangun cache baru..."
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache
log_ok "Cache baru berhasil dibuat."

# -----------------------------------------------------------------------------
# Step 7: Set permission storage & bootstrap/cache
# -----------------------------------------------------------------------------
log_info "Memperbaiki permission folder..."
chmod -R 775 storage bootstrap/cache
# Jika perlu ubah owner (uncomment dan sesuaikan):
# sudo chown -R www-data:www-data storage bootstrap/cache
log_ok "Permission selesai."

# -----------------------------------------------------------------------------
# Step 8: Restart queue worker
# -----------------------------------------------------------------------------
log_info "Me-restart queue worker..."
$PHP_BIN artisan queue:restart
# Beri sinyal ke Supervisor untuk restart worker
if command -v supervisorctl &> /dev/null; then
    sudo supervisorctl restart "$SUPERVISOR_WORKER":*
    log_ok "Queue worker di-restart via Supervisor."
else
    log_warn "supervisorctl tidak ditemukan. Restart worker secara manual."
fi

# -----------------------------------------------------------------------------
# Step 9: Maintenance mode OFF
# -----------------------------------------------------------------------------
log_info "Mematikan maintenance mode..."
$PHP_BIN artisan up
log_ok "Maintenance mode dinonaktifkan. Aplikasi kembali online."

# Hapus trap error karena sudah selesai
trap - ERR

# -----------------------------------------------------------------------------
# Step 10: Post-deploy check ringan
# -----------------------------------------------------------------------------
log_info "Menjalankan post-deploy check..."
echo ""

if [[ -f "$PROJECT_DIR/deploy/post-deploy-check.sh" ]]; then
    bash "$PROJECT_DIR/deploy/post-deploy-check.sh"
else
    # Fallback: check manual minimal
    $PHP_BIN artisan about --only=Environment
    $PHP_BIN artisan chatbot:health-check || log_warn "Health check melaporkan masalah. Periksa dashboard."
fi

echo ""
echo "=============================================="
log_ok "Deploy selesai: $(date '+%Y-%m-%d %H:%M:%S')"
echo "=============================================="
echo ""
log_info "Langkah selanjutnya:"
echo "  1. Pantau log: tail -f storage/logs/laravel-\$(date +%Y-%m-%d).log"
echo "  2. Cek worker: sudo supervisorctl status"
echo "  3. Cek failed jobs: $PHP_BIN artisan queue:failed"
echo "  4. Test kirim pesan WhatsApp ke bot"
echo ""
