#!/bin/bash
# /usr/local/bin/email-delete
# Permanently delete a virtual mail user and their mailbox.
# Usage: sudo /usr/local/bin/email-delete <username>

set -e

USERNAME="$1"
USERS_FILE="/var/dovecot/users"
BACKUP_FILE="/var/dovecot/users.blocked"
MAIL_ROOT="/var/vmail/${USERNAME}@freedoms4.org"
VTRANSPORT_FILE="/etc/postfix/virtual_transport"

if [[ -z "$USERNAME" ]]; then
    echo "Usage: $0 <username>" >&2
    exit 1
fi

if ! [[ "$USERNAME" =~ ^[a-zA-Z0-9_-]{1,32}$ ]]; then
    echo "Invalid username" >&2
    exit 2
fi

# Skip system users — they authenticate via PAM, not passwd-file
if id "${USERNAME}" &>/dev/null; then
    echo "system-user"
    exit 0
fi

# Remove active/blocked Dovecot passwd-file entries
sed -i "/^${USERNAME}:/d" "${USERS_FILE}" 2>/dev/null || true
sed -i "/^${USERNAME}:/d" "${BACKUP_FILE}" 2>/dev/null || true

# Remove per-user Postfix transport route
if [[ -f "${VTRANSPORT_FILE}" ]]; then
    sed -i "/^${USERNAME}@freedoms4\.org[[:space:]]/d" "${VTRANSPORT_FILE}"
    postmap "${VTRANSPORT_FILE}" 2>/dev/null || true
fi

# Delete existing messages using the same Maildir cleanup as the block action
if [[ -d "${MAIL_ROOT}" ]]; then
    find "${MAIL_ROOT}" -type d \( -name cur -o -name new -o -name tmp \) -print0 |
        while IFS= read -r -d '' maildir_part; do
            find "${maildir_part}" -mindepth 1 -maxdepth 1 -type f -delete
        done
fi

# Permanently remove the virtual mailbox directory
rm -rf "${MAIL_ROOT}"

echo "deleted"
