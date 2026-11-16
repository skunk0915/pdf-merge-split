#!/usr/bin/env bash
# Deploy Invoice Sorter to xserver-vps-ow
set -e

VPS_HOST="162.43.39.238"
VPS_USER="root"
SSH_KEY="$HOME/.ssh/mac-mini.pem"
REMOTE_DIR="/var/www/pdf-invoice-sorter"

echo "=== Deploying PDF Invoice Sorter to ${VPS_HOST} ==="

# SCSS Build
echo "Building SCSS..."
npx sass "$(dirname "$0")/scss/style.scss" "$(dirname "$0")/public/css/style.css"

# Sync files
echo "Syncing files to VPS..."
rsync -avz -e "ssh -i ${SSH_KEY}" \
    --exclude "storage" \
    --exclude "worker" \
    --exclude ".env" \
    --exclude ".git" \
    "$(dirname "$0")/config" \
    "$(dirname "$0")/public" \
    "${VPS_USER}@${VPS_HOST}:${REMOTE_DIR}/"

# Fix permissions on VPS
echo "Fixing permissions on VPS..."
ssh -i "${SSH_KEY}" "${VPS_USER}@${VPS_HOST}" \
    "chown -R www-data:www-data ${REMOTE_DIR} && chmod -R 775 ${REMOTE_DIR}/storage"

echo "=== Deployment Completed Successfully! ==="
