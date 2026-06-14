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
echo -e "${BLUE}  freedoms4 uninstall (keeps DB + email accounts)${NC}"
echo -e "${BLUE}================================================${NC}"
echo ""
warn "This removes deployed files and config only."
warn "Database, email accounts, and mailboxes are preserved."
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

# ── 5. Undo Dovecot passwd-file auth config ──
# NOTE: /var/dovecot/users and /var/vmail are intentionally preserved.
info "Reverting Dovecot auth config (preserving user accounts and mailboxes)..."
sed -i '/auth-passwdfile/d' /etc/dovecot/conf.d/10-auth.conf
cat > /etc/dovecot/conf.d/auth-passwdfile.conf.ext << 'DOVECOT'
# passdb and userdb for virtual users — managed by full-setup.sh
# (currently inactive; run full-setup.sh to re-enable)
DOVECOT
rm -f /etc/dovecot/conf.d/99-postfix-auth.conf
systemctl reload dovecot
success "Dovecot config reverted (accounts and mailboxes untouched)."

# ── 6. Undo Postfix SASL and virtual mailbox config ──
info "Reverting Postfix config..."

# Use postconf -X to fully remove parameters rather than set them empty.
postconf -X transport_maps
postconf -X dovecot_destination_recipient_limit
postconf -X local_recipient_maps
postconf -X smtpd_sasl_type
postconf -X smtpd_sasl_path
postconf -e "smtpd_sasl_auth_enable = no"
postconf -X smtpd_sasl_security_options
postconf -X smtpd_sasl_local_domain
postconf -e "broken_sasl_auth_clients = no"
postconf -e "smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination"

# Remove the dovecot pipe transport block from master.cf
sed -i '/^dovecot[[:space:]]*unix.*pipe/,/argv=\/usr\/lib\/dovecot\//d' /etc/postfix/master.cf 2>/dev/null || true

# NOTE: /etc/postfix/virtual_transport and its .db are preserved so that
# existing site-created email accounts retain their routing entries when
# full-setup.sh re-enables transport_maps.
success "Postfix config reverted — system users (hyzen etc.) unaffected."

systemctl reload postfix

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
echo ""
echo "  Run full-setup.sh again to redeploy without losing any data."
echo ""
