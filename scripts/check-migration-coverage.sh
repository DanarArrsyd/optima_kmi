#!/usr/bin/env bash
#
# Gerbang: tiap file migrasi harus punya rumah yang jelas.
#
#   - didaftarkan sebagai --path= di .github/workflows/deploy.yml  → dijalankan saat deploy
#   - ATAU tercatat di database/migrations/.deploy-baseline        → schema sudah ada di prod
#
# Kenapa ada: step "Migrate (targeted)" di deploy.yml cuma menjalankan migrasi yang
# di-hardcode per --path. Lupa mendaftarkan = deploy tetap HIJAU dan mencetak
# "Nothing to migrate", padahal kode sudah live di prod tanpa kolom/tabelnya.
# Sudah tiga kali kejadian: durasi_menit (2026-07-17), enum role guest (2026-07-27),
# checksheet_path (2026-07-23, baru ketahuan 2026-07-27 — prod rusak 4 hari).
#
# Jalankan lokal: ./scripts/check-migration-coverage.sh

set -euo pipefail

cd "$(dirname "$0")/.."

DEPLOY_YML=".github/workflows/deploy.yml"
BASELINE="database/migrations/.deploy-baseline"
MIGRATION_DIR="database/migrations"

for f in "$DEPLOY_YML" "$BASELINE"; do
    [ -f "$f" ] || { echo "FATAL: $f tidak ditemukan"; exit 2; }
done

# Nama file yang terdaftar --path= di deploy.yml
allowlist="$(grep -oE 'database/migrations/[A-Za-z0-9_]+\.php' "$DEPLOY_YML" \
    | sed 's|database/migrations/||' | sort -u)"

# Baseline, buang komentar & baris kosong
baseline="$(grep -vE '^\s*#|^\s*$' "$BASELINE" | tr -d ' \t' | sort -u)"

covered="$(printf '%s\n%s\n' "$allowlist" "$baseline" | grep -v '^$' | sort -u)"
actual="$(ls "$MIGRATION_DIR"/*.php 2>/dev/null | sed "s|$MIGRATION_DIR/||" | sort)"

status=0

# 1. Migrasi yang tak punya rumah — ini penyebab bug senyap itu
orphans="$(comm -23 <(echo "$actual") <(echo "$covered") || true)"
if [ -n "$orphans" ]; then
    status=1
    echo "✗ Migrasi berikut tidak terdaftar di mana pun:"
    echo "$orphans" | sed 's/^/    /'
    echo
    echo "  Migrasi baru HARUS dijalankan di prod. Tambahkan barisnya ke step"
    echo "  \"Migrate (targeted) di server\" pada $DEPLOY_YML:"
    echo
    echo "$orphans" | sed 's|^|              --path=database/migrations/|; s|$| \\|'
    echo
    echo "  (Jangan menaruhnya di $BASELINE — itu hanya untuk migrasi lama"
    echo "   yang schema-nya sudah ada di prod sebelum deploy otomatis.)"
    echo
fi

# 2. Entri basi — menunjuk file yang sudah tidak ada
ghosts="$(comm -13 <(echo "$actual") <(echo "$covered") || true)"
if [ -n "$ghosts" ]; then
    status=1
    echo "✗ Terdaftar tapi filenya tidak ada (entri basi):"
    echo "$ghosts" | sed 's/^/    /'
    echo "  Hapus dari $DEPLOY_YML atau $BASELINE."
    echo
fi

# 3. Migrasi tak boleh nongkrong di dua tempat sekaligus
dupes="$(comm -12 <(echo "$allowlist") <(echo "$baseline") || true)"
if [ -n "$dupes" ]; then
    status=1
    echo "✗ Ada di allowlist deploy DAN baseline sekaligus:"
    echo "$dupes" | sed 's/^/    /'
    echo "  Pilih salah satu — baseline berarti 'jangan dijalankan'."
    echo
fi

if [ "$status" -eq 0 ]; then
    echo "✓ Cakupan migrasi lengkap: $(echo "$actual" | wc -l | tr -d ' ') file — $(echo "$allowlist" | wc -l | tr -d ' ') dijalankan saat deploy, $(echo "$baseline" | wc -l | tr -d ' ') baseline."
fi

exit "$status"
