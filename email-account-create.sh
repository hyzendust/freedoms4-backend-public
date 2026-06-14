#!/bin/bash
# /usr/local/bin/email-account-create
# Called by www-data via sudo to add a virtual mail user to /var/dovecot/users
# Usage: sudo /usr/local/bin/email-account-create <username> <password>

set -e

USERNAME="$1"
PASSWORD="$2"
USERS_FILE="/var/dovecot/users"
VMAIL_UID=$(id -u vmail)
VMAIL_GID=$(id -g vmail)
MAILDIR="/var/vmail/${USERNAME}@freedoms4.org/maildir"

if [[ -z "$USERNAME" || -z "$PASSWORD" ]]; then
    echo "Usage: $0 <username> <password>" >&2
    exit 1
fi

# Validate username
if ! [[ "$USERNAME" =~ ^[a-zA-Z0-9_-]{1,32}$ ]]; then
    echo "Invalid username" >&2
    exit 2
fi

# Check if already exists in passwd-file
if grep -q "^${USERNAME}:" "${USERS_FILE}" 2>/dev/null; then
    echo "exists"
    exit 0
fi

# Skip if this is an existing system user — they have their own mailbox
if id "${USERNAME}" &>/dev/null; then
    echo "system-user"
    exit 0
fi

# Hash the password using SHA512-CRYPT (Dovecot compatible)
HASHED=$(doveadm pw -s SHA512-CRYPT -p "$PASSWORD")

# Append to users file
echo "${USERNAME}:${HASHED}:${VMAIL_UID}:${VMAIL_GID}::${MAILDIR}::" >> "${USERS_FILE}"

# Create maildir structure
mkdir -p "${MAILDIR}"
chown -R vmail:mail "/var/vmail/${USERNAME}@freedoms4.org"
chmod -R 700 "/var/vmail/${USERNAME}@freedoms4.org"

# Register this user in the per-user transport map so Postfix routes
# inbound mail to the Dovecot LDA (system users are not in this map
# and continue to receive via normal local delivery).
VTRANSPORT_FILE="/etc/postfix/virtual_transport"
if ! grep -q "^${USERNAME}@freedoms4.org" "${VTRANSPORT_FILE}" 2>/dev/null; then
    echo "${USERNAME}@freedoms4.org    dovecot" >> "${VTRANSPORT_FILE}"
    postmap "${VTRANSPORT_FILE}"
fi

echo "created"
