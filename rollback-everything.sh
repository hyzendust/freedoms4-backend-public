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
    echo -e "${RED}[ERROR]${NC} Please run as root: sudo bash rollback-everything.sh"
    exit 1
fi

echo ""
echo -e "${RED}WARNING:${NC} This will remove the database, email accounts, mailboxes, and all config."
echo -n "Are you sure you want to proceed? (yes/no) [no]: "
read -r CONFIRM
if [[ "${CONFIRM}" != "yes" ]]; then
    echo "Aborted."
    exit 0
fi
echo ""
info "Stopping php8.2-fpm..."
systemctl stop php8.2-fpm

# ── 2. Terminate any remaining freedoms4 DB connections ──
info "Terminating freedoms4 DB connections..."
(cd /tmp && sudo -u postgres psql -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='freedoms4';" 2>/dev/null || true)

# ── 3. Revoke freedoms4_user access to prosody DB ──
info "Revoking freedoms4_user from prosody DB..."
(cd /tmp && sudo -u postgres psql -d prosody -c "REVOKE ALL ON TABLE prosody FROM freedoms4_user;" 2>/dev/null || true)
(cd /tmp && sudo -u postgres psql -c "REVOKE CONNECT ON DATABASE prosody FROM freedoms4_user;" 2>/dev/null || true)

# ── 4. Drop the freedoms4 database and user ──
info "Dropping freedoms4 database and user..."
(cd /tmp && sudo -u postgres psql -c "DROP DATABASE IF EXISTS freedoms4;")
(cd /tmp && sudo -u postgres psql -c "DROP USER IF EXISTS freedoms4_user;")
success "Database and user dropped."

# ── 5. Remove deployed API dir and env file ──
info "Removing API dir and env file..."
rm -rf /var/www/freedoms4
rm -rf /etc/freedoms4
success "API dir and env file removed."

# ── 6. Remove nginx site ──
info "Removing nginx site config..."
rm -f /etc/nginx/sites-enabled/backend.freedoms4.org
rm -f /etc/nginx/sites-available/backend.freedoms4.org
systemctl restart nginx
success "Nginx site removed and restarted."

# ── 7. Remove email account wrapper and sudoers rule ──
info "Removing email-account-create script and sudoers rule..."
rm -f /usr/local/bin/email-account-create
rm -f /etc/sudoers.d/email-account-create
rm -f /usr/local/bin/email-block
rm -f /etc/sudoers.d/email-block
rm -f /usr/local/bin/email-delete
rm -f /etc/sudoers.d/email-delete
success "email-account-create removed."

# ── 8. Remove ONLY site-created virtual users from /var/dovecot/users ──
# System users (like hyzen) are NOT in /var/dovecot/users — they authenticate
# via PAM/system auth — so we can safely remove the entire file.
# Their mailboxes in /var/mail/<user> or ~/Maildir are untouched.
info "Removing virtual users file (/var/dovecot/users)..."
rm -f /var/dovecot/users
rm -f /var/dovecot/users.blocked
success "Virtual users file removed (system users unaffected)."

# Remove vmail mailbox data (only virtual users live here; system users use /var/mail)
info "Removing /var/vmail (virtual mailboxes only)..."
rm -rf /var/vmail
success "/var/vmail removed."

# Remove vmail system user (not a login account, safe to remove)
if id vmail &>/dev/null; then
    userdel vmail 2>/dev/null || true
    success "vmail system user removed."
fi

# ── 9. Undo Dovecot passwd-file auth config ──
info "Reverting Dovecot auth config..."
sed -i '/auth-passwdfile/d' /etc/dovecot/conf.d/10-auth.conf
cat > /etc/dovecot/conf.d/auth-passwdfile.conf.ext << 'DOVECOT'
# passdb and userdb for virtual users — managed by full-setup.sh
# (currently inactive; run full-setup.sh to re-enable)
DOVECOT
# Remove the Postfix-auth drop-in (do NOT touch 10-master.conf itself)
rm -f /etc/dovecot/conf.d/99-postfix-auth.conf
systemctl reload dovecot
success "Dovecot config reverted."

# ── 10. Undo Postfix SASL and virtual mailbox config ──
info "Reverting Postfix SASL and virtual mailbox config..."

# mydestination was never changed by full-setup, so nothing to restore there.

# Use postconf -X to fully REMOVE parameters (not set them empty).
# postconf -e "param =" writes "param =" to main.cf which Postfix rejects
# for parameters like virtual_transport that cannot be blank.
postconf -X transport_maps
postconf -X dovecot_destination_recipient_limit
postconf -X local_recipient_maps
postconf -X smtpd_sasl_type
postconf -X smtpd_sasl_path
postconf -e "smtpd_sasl_auth_enable = no"
postconf -X smtpd_sasl_security_options
postconf -X smtpd_sasl_local_domain
postconf -e "broken_sasl_auth_clients = no"

# Restore default recipient restrictions
postconf -e "smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination"

# Remove the dovecot pipe transport block from master.cf
sed -i '/^dovecot[[:space:]]*unix.*pipe/,/argv=\/usr\/lib\/dovecot\//d' /etc/postfix/master.cf 2>/dev/null || true

# Remove transport map files
rm -f /etc/postfix/virtual_transport /etc/postfix/virtual_transport.db

systemctl reload postfix
success "Postfix config reverted — system users (hyzen etc.) restored."

# ── 11. Restart php-fpm ──
info "Restarting php8.2-fpm..."
systemctl start php8.2-fpm
success "php8.2-fpm restarted."

echo ""
echo -e "${GREEN}================================================${NC}"
echo -e "${GREEN}  Rollback complete.${NC}"
echo -e "${GREEN}================================================${NC}"
echo ""
echo "  Run full-setup.sh again to restore the backend."
echo ""
