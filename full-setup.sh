#!/bin/bash
# full-setup.sh — freedoms4 backend automation script

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
error()   { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

# Config (edit these before running)
DB_NAME=""
DB_USER=""
DB_PASS=""
PROSODY_DB_USER=""
PROSODY_DB_PASS=""  # must match /etc/prosody/prosody.cfg.lua
DOMAIN=""
CERTBOT_EMAIL=""
API_DIR=""
ENV_FILE=""
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
OTP_FROM="" # for example: no-reply@freedoms4.org

# Must run as root
if [[ $EUID -ne 0 ]]; then
    error "Please run as root: sudo bash full-setup.sh"
fi

echo ""
echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}  freedoms4 backend setup${NC}"
echo -e "${BLUE}================================================${NC}"
echo ""

# ── STEP 1  PostgreSQL ──
info "Checking PostgreSQL..."

if command -v psql &>/dev/null; then
    success "PostgreSQL is already installed."
else
    info "Installing PostgreSQL..."
    apt update -qq
    apt install -y postgresql postgresql-contrib
    systemctl enable --now postgresql
    success "PostgreSQL installed and started."
fi

if ! systemctl is-active --quiet postgresql; then
    systemctl start postgresql
fi
success "PostgreSQL is running."

# ── STEP 2  Database & user ──
info "Setting up database and user..."

if (cd /tmp && sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'" | grep -q 1); then
    warn "DB user '${DB_USER}' already exists — resetting password to ensure it matches."
    (cd /tmp && sudo -u postgres psql -c "ALTER USER ${DB_USER} WITH PASSWORD '${DB_PASS}';")
else
    (cd /tmp && sudo -u postgres psql -c "CREATE USER ${DB_USER} WITH PASSWORD '${DB_PASS}';")
    success "Created DB user '${DB_USER}'."
fi

if (cd /tmp && sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'" | grep -q 1); then
    warn "Database '${DB_NAME}' already exists, skipping."
else
    (cd /tmp && sudo -u postgres psql -c "CREATE DATABASE ${DB_NAME} OWNER ${DB_USER};")
    success "Created database '${DB_NAME}'."
fi

(cd /tmp && sudo -u postgres psql -d "${DB_NAME}") << SQL
-- Users table
CREATE TABLE IF NOT EXISTS users (
    id            BIGSERIAL     PRIMARY KEY,
    username      VARCHAR(32)   NOT NULL UNIQUE,
    email         VARCHAR(254)  NOT NULL UNIQUE,
    password_hash VARCHAR(255)  NOT NULL,
    blocked       BOOLEAN       NOT NULL DEFAULT FALSE,
    terms_agreed  BOOLEAN       NOT NULL DEFAULT FALSE,
    created_at    TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

-- Migration: add terms_agreed for existing installs
ALTER TABLE users ADD COLUMN IF NOT EXISTS terms_agreed BOOLEAN NOT NULL DEFAULT FALSE;

CREATE INDEX IF NOT EXISTS idx_users_username ON users (username);
CREATE INDEX IF NOT EXISTS idx_users_email    ON users (email);

REVOKE ALL ON TABLE users FROM PUBLIC;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE users TO ${DB_USER};
GRANT USAGE, SELECT ON SEQUENCE users_id_seq TO ${DB_USER};

-- OTP table for email verification during sign-up
CREATE TABLE IF NOT EXISTS email_otps (
    id               BIGSERIAL     PRIMARY KEY,
    email            VARCHAR(254)  NOT NULL,
    otp_hash         VARCHAR(255)  NOT NULL,
    expires_at       TIMESTAMPTZ   NOT NULL,
    used             BOOLEAN       NOT NULL DEFAULT FALSE,
    otp_token        VARCHAR(64),
    token_expires_at TIMESTAMPTZ,
    created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_email_otps_email ON email_otps (email);
CREATE INDEX IF NOT EXISTS idx_email_otps_token ON email_otps (otp_token);

REVOKE ALL ON TABLE email_otps FROM PUBLIC;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE email_otps TO ${DB_USER};
GRANT USAGE, SELECT ON SEQUENCE email_otps_id_seq TO ${DB_USER};

-- Session tracking table
CREATE TABLE IF NOT EXISTS user_sessions (
    id            BIGSERIAL     PRIMARY KEY,
    user_id       BIGINT        NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    session_id    VARCHAR(128)  NOT NULL UNIQUE,
    logged_in_at  TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    last_seen_at  TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    logged_out_at TIMESTAMPTZ,
    ip_address    VARCHAR(45),
    user_agent    VARCHAR(512)
);

CREATE INDEX IF NOT EXISTS idx_user_sessions_user_id    ON user_sessions (user_id);
CREATE INDEX IF NOT EXISTS idx_user_sessions_session_id ON user_sessions (session_id);

REVOKE ALL ON TABLE user_sessions FROM PUBLIC;
GRANT SELECT, INSERT, UPDATE ON TABLE user_sessions TO ${DB_USER};
GRANT USAGE, SELECT ON SEQUENCE user_sessions_id_seq TO ${DB_USER};

-- Comments table
CREATE TABLE IF NOT EXISTS comments (
    id         BIGSERIAL     PRIMARY KEY,
    post_id    VARCHAR(512)  NOT NULL,
    parent_id  BIGINT        REFERENCES comments(id) ON DELETE SET NULL,
    user_id    BIGINT        REFERENCES users(id) ON DELETE SET NULL,
    username   VARCHAR(32),
    body       TEXT,
    deleted_by VARCHAR(16),
    created_at TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    deleted    BOOLEAN       NOT NULL DEFAULT FALSE
);

ALTER TABLE comments ADD COLUMN IF NOT EXISTS username VARCHAR(32);
UPDATE comments c
SET username = u.username
FROM users u
WHERE c.user_id = u.id AND c.username IS NULL;

CREATE INDEX IF NOT EXISTS idx_comments_post_id   ON comments (post_id);
CREATE INDEX IF NOT EXISTS idx_comments_parent_id ON comments (parent_id);

REVOKE ALL ON TABLE comments FROM PUBLIC;
GRANT SELECT, INSERT, UPDATE ON TABLE comments TO ${DB_USER};
GRANT USAGE, SELECT ON SEQUENCE comments_id_seq TO ${DB_USER};
SQL


# ── STEP 2b  Grant freedoms4_user access to Prosody DB ──
info "Granting freedoms4_user access to prosody database..."

(cd /tmp && sudo -u postgres psql -d prosody -c "GRANT CONNECT ON DATABASE prosody TO ${DB_USER};")
(cd /tmp && sudo -u postgres psql -d prosody -c "GRANT SELECT, INSERT, UPDATE ON TABLE prosody TO ${DB_USER};")
success "freedoms4_user can read/write prosody table."


# ── STEP 3  Block port 5432 via ufw ──
info "Blocking port 5432 externally via ufw..."

if command -v ufw &>/dev/null; then
    if ufw status | head -1 | grep -q "active"; then
        ufw deny 5432/tcp
        success "ufw: port 5432 blocked — PostgreSQL is localhost-only."
    else
        ufw --force enable
        ufw deny 5432/tcp
        success "ufw enabled and port 5432 blocked."
    fi
else
    warn "ufw not found — please manually ensure port 5432 is not publicly exposed."
fi

# ── STEP 4  PHP 8.2 fpm + pgsql ──
info "Installing PHP 8.2 fpm and pgsql extension..."

apt install -y php8.2-fpm php8.2-pgsql php8.2-apcu

phpenmod -v 8.2 pgsql     2>/dev/null || true
phpenmod -v 8.2 pdo_pgsql 2>/dev/null || true
phpenmod -v 8.2 apcu      2>/dev/null || true

systemctl enable --now php8.2-fpm
systemctl restart php8.2-fpm

if [[ ! -S /run/php/php8.2-fpm.sock ]]; then
    error "fpm socket not found at /run/php/php8.2-fpm.sock"
fi
success "php8.2-fpm ready."

# ── STEP 5  Mail server check ──
info "Checking mail server (Postfix) for OTP delivery..."

if ! command -v postfix &>/dev/null; then
    warn "Postfix binary not found. Installing..."
    apt install -y postfix
    # Ensure it is configured as 'Internet Site' for freedoms4.org
    debconf-set-selections <<< "postfix postfix/main_mailer_type select Internet Site"
    debconf-set-selections <<< "postfix postfix/mailname string freedoms4.org"
    dpkg-reconfigure -f noninteractive postfix
    success "Postfix installed."
fi

if ! systemctl is-active --quiet postfix; then
    systemctl start postfix
    success "Postfix started."
else
    success "Postfix is running."
fi

# Ensure PHP's sendmail_path points to Postfix's sendmail binary
PHP_INI_FPM="/etc/php/8.2/fpm/php.ini"
SENDMAIL_PATH=$(php8.2 -r "echo ini_get('sendmail_path');" 2>/dev/null || true)
if [[ "$SENDMAIL_PATH" != *"sendmail"* ]]; then
    warn "sendmail_path may not be set — adding to ${PHP_INI_FPM}"
    if ! grep -q "^sendmail_path" "${PHP_INI_FPM}" 2>/dev/null; then
        echo 'sendmail_path = "/usr/sbin/sendmail -t -i"' >> "${PHP_INI_FPM}"
        systemctl restart php8.2-fpm
        success "sendmail_path set and php8.2-fpm restarted."
    fi
else
    success "sendmail_path is configured: ${SENDMAIL_PATH}"
fi

# Configure Postfix myhostname / myorigin if not already set
POSTFIX_MAIN="/etc/postfix/main.cf"
if ! grep -q "^myhostname\s*=\s*freedoms4.org" "${POSTFIX_MAIN}" 2>/dev/null; then
    info "Setting Postfix myhostname = freedoms4.org ..."
    postconf -e "myhostname = freedoms4.org"
    postconf -e "myorigin = freedoms4.org"
    systemctl reload postfix
    success "Postfix myhostname/myorigin updated."
fi

# ── Configure Postfix to use Dovecot SASL for SMTP AUTH (so virtual users can send) ──
info "Configuring Postfix to use Dovecot SASL for SMTP submission..."

# Install libsasl2 if needed
apt install -y libsasl2-modules 2>/dev/null || true

postconf -e "smtpd_sasl_type = dovecot"
postconf -e "smtpd_sasl_path = private/auth"
postconf -e "smtpd_sasl_auth_enable = yes"
postconf -e "smtpd_sasl_security_options = noanonymous"
postconf -e "smtpd_sasl_local_domain = \$myhostname"
postconf -e "broken_sasl_auth_clients = yes"
postconf -e "smtpd_recipient_restrictions = permit_sasl_authenticated, permit_mynetworks, reject_unauth_destination"

# ── Configure Postfix virtual mailbox delivery (Dovecot LDA) ──
info "Configuring Postfix virtual mailbox delivery via transport_maps..."

# freedoms4.org stays in mydestination so system users (hyzen etc.) keep
# working via local delivery. For site-created virtual users, we use
# transport_maps on a per-address basis to route them to Dovecot LDA.
#
# The critical piece: by default, Postfix checks local_recipient_maps for
# any domain in mydestination and rejects unknown recipients before ever
# consulting transport_maps. We override local_recipient_maps to only list
# actual Unix system accounts (passwd file). Virtual users are not in passwd,
# so Postfix finds no match, skips the reject, and falls through to
# transport_maps where their dovecot entry routes them correctly.
postconf -e "local_recipient_maps ="

postconf -e "dovecot_destination_recipient_limit = 1"

# Initialise the per-user transport map (entries added by email-account-create)
VTRANSPORT_FILE="/etc/postfix/virtual_transport"
if [[ ! -f "${VTRANSPORT_FILE}" ]]; then
    touch "${VTRANSPORT_FILE}"
    success "Created empty ${VTRANSPORT_FILE}."
fi
postmap "${VTRANSPORT_FILE}"
postconf -e "transport_maps = hash:${VTRANSPORT_FILE}"
success "transport_maps initialised."

# Add dovecot LDA transport to master.cf if not already there.
# Use dovecot-lda (the correct binary name on Debian/Ubuntu).
MASTER_CF="/etc/postfix/master.cf"
if ! grep -q "^dovecot" "${MASTER_CF}"; then
    cat >> "${MASTER_CF}" << 'MASTER'
dovecot   unix  -       n       n       -       -       pipe
  flags=DRhu user=vmail:mail argv=/usr/lib/dovecot/dovecot-lda -f ${sender} -d ${recipient}
MASTER
    success "Dovecot LDA transport added to master.cf."
fi

# Enable Dovecot auth-userdb socket for Postfix SASL.
# Use a separate drop-in file instead of injecting into 10-master.conf
# to avoid sed portability issues and keep changes isolated.
DOVECOT_POSTFIX_CONF="/etc/dovecot/conf.d/99-postfix-auth.conf"
if [[ ! -f "${DOVECOT_POSTFIX_CONF}" ]]; then
    cat > "${DOVECOT_POSTFIX_CONF}" << 'DOVECOTCONF'
# Dovecot SASL socket for Postfix SMTP AUTH
# Added by full-setup.sh — remove this file to undo
service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode = 0660
    user = postfix
    group = postfix
  }
}
DOVECOTCONF
    success "Created Dovecot Postfix-auth drop-in at ${DOVECOT_POSTFIX_CONF}."
else
    success "Dovecot Postfix-auth drop-in already exists."
fi

systemctl reload postfix
systemctl reload dovecot
success "Postfix SASL + virtual mailbox delivery configured."

# Quick test: send a mail from no-reply@freedoms4.org to itself (appears in /var/mail or mail queue)
info "Sending a Postfix self-test email..."
echo "Postfix OTP relay self-test from full-setup.sh" \
    | mail -s "freedoms4 mail test" \
           -a "From: ${OTP_FROM}" \
           root 2>/dev/null \
    && success "Test email queued (check /var/mail/root or 'mailq')." \
    || warn "mail command not available for self-test — install mailutils if needed."

# ── STEP 6  Generate env file + deploy auth.php ──
info "Creating credentials env file at ${ENV_FILE}..."

# Write env file
mkdir -p "$(dirname ${ENV_FILE})"
cat > "${ENV_FILE}" << ENV
# /etc/freedoms4/auth.env — generated by full-setup.sh on $(date)
# Do NOT commit to version control.
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
PROSODY_DB_NAME=prosody
PROSODY_DB_USER=${PROSODY_DB_USER}
PROSODY_DB_PASS=${PROSODY_DB_PASS}
PROSODY_HOST=freedoms4.org
ENV

chown root:www-data "${ENV_FILE}"
chmod 640 "${ENV_FILE}"
success "Env file written and secured (root:www-data 640)."

info "Deploying auth.php to ${API_DIR}..."

if [[ ! -f "${SCRIPT_DIR}/auth.php" ]]; then
    error "auth.php not found in ${SCRIPT_DIR}."
fi

mkdir -p "${API_DIR}"
cp "${SCRIPT_DIR}/auth.php" "${API_DIR}/auth.php"
chown -R www-data:www-data "${API_DIR}"
chmod 640 "${API_DIR}/auth.php"
success "auth.php deployed."

if [[ ! -f "${SCRIPT_DIR}/comments.php" ]]; then
    error "comments.php not found in ${SCRIPT_DIR}."
fi
cp "${SCRIPT_DIR}/comments.php" "${API_DIR}/comments.php"
chown www-data:www-data "${API_DIR}/comments.php"
chmod 640 "${API_DIR}/comments.php"
success "comments.php deployed."

if [[ ! -f "${SCRIPT_DIR}/admin.php" ]]; then
    error "admin.php not found in ${SCRIPT_DIR}."
fi
cp "${SCRIPT_DIR}/admin.php" "${API_DIR}/admin.php"
chown www-data:www-data "${API_DIR}/admin.php"
chmod 640 "${API_DIR}/admin.php"
success "admin.php deployed."



# ── STEP 6b  Virtual mail setup (Dovecot + vmail) ──
info "Setting up virtual mail user and Dovecot passwd-file..."

# Create vmail system user if not exists
if ! id vmail &>/dev/null; then
    useradd -r -u 5000 -g mail -d /var/vmail -s /sbin/nologin vmail
    success "Created vmail system user (uid 5000)."
else
    success "vmail user already exists."
fi

# Create mailbox base directory
mkdir -p /var/vmail
mkdir -p /var/dovecot
chown vmail:mail /var/vmail
chmod 770 /var/vmail
success "/var/vmail ready."

# Create /var/dovecot/users if not exists
if [[ ! -f /var/dovecot/users ]]; then
    touch /var/dovecot/users
    chown root:root /var/dovecot/users
    chmod 644 /var/dovecot/users
    success "Created /var/dovecot/users."
else
    success "/var/dovecot/users already exists."
fi

# Enable auth-passwdfile in 10-auth.conf (add include if not already there)
if ! grep -q "auth-passwdfile" /etc/dovecot/conf.d/10-auth.conf; then
    echo '!include auth-passwdfile.conf.ext' >> /etc/dovecot/conf.d/10-auth.conf
    success "Enabled auth-passwdfile.conf.ext in 10-auth.conf."
fi

# Enable auth-passwdfile.conf.ext — uncomment the passdb/userdb blocks
PASSWDFILE="/etc/dovecot/conf.d/auth-passwdfile.conf.ext"
if grep -q "^#passdb" "${PASSWDFILE}" 2>/dev/null || ! grep -q "^passdb" "${PASSWDFILE}" 2>/dev/null; then
    cat > "${PASSWDFILE}" << 'DOVECOT'
passdb {
  driver = passwd-file
  args = scheme=SHA512-CRYPT username_format=%n /var/dovecot/users
}
userdb {
  driver = passwd-file
  args = username_format=%n /var/dovecot/users
  default_fields = uid=vmail gid=mail home=/var/vmail/%n@freedoms4.org/maildir
}
DOVECOT
    success "auth-passwdfile.conf.ext configured."
fi

systemctl reload dovecot
success "Dovecot reloaded."

# ── STEP 6c  Deploy email-account-create wrapper + sudoers ──
info "Deploying email-account-create wrapper script..."

if [[ ! -f "${SCRIPT_DIR}/email-account-create.sh" ]]; then
    error "email-account-create.sh not found in ${SCRIPT_DIR}."
fi

cp "${SCRIPT_DIR}/email-account-create.sh" /usr/local/bin/email-account-create
chown root:root /usr/local/bin/email-account-create
chmod 755 /usr/local/bin/email-account-create
success "email-account-create deployed to /usr/local/bin/"

SUDOERS_EMAIL="/etc/sudoers.d/email-account-create"
echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/email-account-create" > "${SUDOERS_EMAIL}"
chmod 440 "${SUDOERS_EMAIL}"
visudo -cf "${SUDOERS_EMAIL}" && success "sudoers rule for email-account-create installed." \
    || error "sudoers syntax check failed — check ${SUDOERS_EMAIL}"


# ── STEP 6d  Deploy email-block wrapper + sudoers ──
info "Deploying email-block wrapper script..."

if [[ ! -f "${SCRIPT_DIR}/email-block.sh" ]]; then
    error "email-block.sh not found in ${SCRIPT_DIR}."
fi

cp "${SCRIPT_DIR}/email-block.sh" /usr/local/bin/email-block
chown root:root /usr/local/bin/email-block
chmod 755 /usr/local/bin/email-block
success "email-block deployed to /usr/local/bin/"

# Create backup file if not exists
touch /var/dovecot/users.blocked
chown root:root /var/dovecot/users.blocked
chmod 644 /var/dovecot/users.blocked
success "/var/dovecot/users.blocked ready."

SUDOERS_BLOCK="/etc/sudoers.d/email-block"
echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/email-block" > "${SUDOERS_BLOCK}"
chmod 440 "${SUDOERS_BLOCK}"
visudo -cf "${SUDOERS_BLOCK}" && success "sudoers rule for email-block installed." \
    || error "sudoers syntax check failed — check ${SUDOERS_BLOCK}"


# ── STEP 6e  Deploy email-delete wrapper + sudoers ──
info "Deploying email-delete wrapper script..."

if [[ ! -f "${SCRIPT_DIR}/email-delete.sh" ]]; then
    error "email-delete.sh not found in ${SCRIPT_DIR}."
fi

cp "${SCRIPT_DIR}/email-delete.sh" /usr/local/bin/email-delete
chown root:root /usr/local/bin/email-delete
chmod 755 /usr/local/bin/email-delete
success "email-delete deployed to /usr/local/bin/"

SUDOERS_DELETE="/etc/sudoers.d/email-delete"
echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/email-delete" > "${SUDOERS_DELETE}"
chmod 440 "${SUDOERS_DELETE}"
visudo -cf "${SUDOERS_DELETE}" && success "sudoers rule for email-delete installed." \
    || error "sudoers syntax check failed — check ${SUDOERS_DELETE}"


# ── STEP 7  Nginx config ──
info "Deploying nginx config for ${DOMAIN}..."

if [[ ! -f "${SCRIPT_DIR}/backend.freedoms4.org" ]]; then
    error "backend.freedoms4.org not found in ${SCRIPT_DIR}."
fi

cp "${SCRIPT_DIR}/backend.freedoms4.org" "/etc/nginx/sites-available/${DOMAIN}"
ln -sf "/etc/nginx/sites-available/${DOMAIN}" "/etc/nginx/sites-enabled/${DOMAIN}"

nginx -t || error "Nginx config test failed."
systemctl restart nginx
success "Nginx restarted."

# ── STEP 8  Certbot / SSL ──
info "Checking SSL certificate..."

if ! command -v certbot &>/dev/null; then
    apt install -y certbot python3-certbot-nginx
    success "Certbot installed."
fi

if [[ -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]]; then
    warn "Certificate for ${DOMAIN} already exists, skipping issuance."
else
    info "Obtaining SSL certificate for ${DOMAIN}..."
    certbot --nginx -d "${DOMAIN}" --non-interactive --agree-tos -m "${CERTBOT_EMAIL}"
    success "Certificate obtained."
fi

if systemctl list-timers 2>/dev/null | grep -q certbot; then
    success "Certbot auto-renewal timer is active."
else
    systemctl enable --now certbot.timer 2>/dev/null || true
    if ! crontab -l 2>/dev/null | grep -q certbot; then
        (crontab -l 2>/dev/null; echo "0 3 * * * certbot renew --quiet --post-hook 'systemctl reload nginx'") | crontab -
        success "Added certbot renewal cron job."
    fi
fi

# ── STEP 9  OTP cleanup cron ──
info "Installing OTP cleanup cron (purges rows older than 24 h)..."

OTP_CRON="0 4 * * * psql postgresql://${DB_USER}:${DB_PASS}@127.0.0.1/${DB_NAME} -c \"DELETE FROM email_otps WHERE created_at < NOW() - INTERVAL '24 hours';\" >/dev/null 2>&1"

if ! crontab -l 2>/dev/null | grep -q "email_otps"; then
    (crontab -l 2>/dev/null; echo "${OTP_CRON}") | crontab -
    success "OTP cleanup cron installed (runs daily at 04:00)."
else
    success "OTP cleanup cron already present."
fi

# ── STEP 10  Smoke test ──
info "Running smoke test..."

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST \
    "https://${DOMAIN}/auth.php" \
    -H "Content-Type: application/json" \
    -H "Origin: https://freedoms4.org" \
    -d '{"action":"login","username":"__probe__","password":"__probe__"}' \
    --max-time 10 || true)

if [[ "$HTTP_CODE" == "200" ]]; then
    success "Smoke test passed (HTTP 200)."
elif [[ "$HTTP_CODE" == "000" ]]; then
    error "Smoke test failed (000) — endpoint unreachable. Run: curl -sv https://${DOMAIN}/auth.php"
else
    success "Smoke test: HTTP ${HTTP_CODE} — endpoint is reachable."
fi

# ── Done ──
echo ""
echo -e "${GREEN}================================================${NC}"
echo -e "${GREEN}  Setup complete!${NC}"
echo -e "${GREEN}================================================${NC}"
echo ""
echo -e "  API endpoint  : ${BLUE}https://${DOMAIN}/auth.php${NC}"
echo -e "  Auth file     : ${BLUE}${API_DIR}/auth.php${NC}"
echo -e "  OTP sender    : ${BLUE}${OTP_FROM}${NC}"
echo ""
echo ""
