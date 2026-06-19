#!/bin/bash
set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

info()    { echo -e "${BLUE}[INFO]${NC}  $1"; }
success() { echo -e "${GREEN}[OK]${NC}    $1"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}  $1"; }

# Must run as root
if [[ $EUID -ne 0 ]]; then
    echo -e "${RED}[ERROR]${NC} Please run as root: sudo bash uninstall.sh"
    exit 1
fi

echo ""
echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}  freedoms4 uninstall (keeps DB + mail working)${NC}"
echo -e "${BLUE}================================================${NC}"
echo ""
warn "This removes deployed API/nginx files and config only."
warn "Database, email accounts, mailboxes, and mail client auth are preserved."
echo ""

# ── 1. Stop php-fpm to release DB connections ──
info "Stopping php8.2-fpm..."
systemctl stop php8.2-fpm

# ── 2. Remove deployed API dir and env file ──
info "Removing API dir and env file..."
rm -rf /var/www/freedoms4
rm -rf /etc/freedoms4
success "API dir and env file removed."

# ── 3. Remove nginx site ──
info "Removing nginx site config..."
rm -f /etc/nginx/sites-enabled/backend.freedoms4.org
rm -f /etc/nginx/sites-available/backend.freedoms4.org
systemctl restart nginx
success "Nginx site removed and restarted."

# ── 4. Remove email account wrapper and sudoers rule ──
info "Removing email-account-create script and sudoers rule..."
rm -f /usr/local/bin/email-account-create
rm -f /etc/sudoers.d/email-account-create
success "email-account-create removed."

# ── 5. Dovecot auth config: left untouched ──
# Mail clients must keep working after uninstall, so the auth-passwdfile
# config in /etc/dovecot/conf.d/10-auth.conf is intentionally NOT reverted.
info "Leaving Dovecot auth config untouched (mail clients keep working)..."
success "Dovecot config left as-is — existing accounts can still log in."

# ── 6. Postfix SASL / virtual mailbox config: left untouched ──
# Reverting this previously broke client SMTP auth and mail delivery for
# already-created accounts, so it's intentionally skipped here.
info "Leaving Postfix SASL and virtual transport config untouched..."
success "Postfix config left as-is — existing accounts keep sending/receiving mail."

# ── 7. Restart php-fpm ──
info "Restarting php8.2-fpm..."
systemctl start php8.2-fpm
success "php8.2-fpm restarted."

echo ""
echo -e "${GREEN}================================================${NC}"
echo -e "${GREEN}  Uninstall complete.${NC}"
echo -e "${GREEN}================================================${NC}"
echo ""
echo "  Preserved:"
echo "    - PostgreSQL database 'freedoms4' and all data"
echo "    - /var/dovecot/users (virtual email accounts)"
echo "    - /var/vmail (mailboxes)"
echo "    - /etc/postfix/virtual_transport (routing entries)"
echo "    - vmail system user"
echo "    - Dovecot auth-passwdfile config (clients can still log in)"
echo "    - Postfix SASL + virtual transport config (mail still sends/receives)"
echo ""
echo "  Removed:"
echo "    - API dir (/var/www/freedoms4), env file (/etc/freedoms4)"
echo "    - Nginx site for backend.freedoms4.org"
echo "    - email-account-create wrapper + sudoers rule (no new accounts via signup)"
echo ""
echo "  Run full-setup.sh again to redeploy the API/signup flow."
echo ""
