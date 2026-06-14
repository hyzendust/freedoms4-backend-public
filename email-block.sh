#!/bin/bash
# /usr/local/bin/email-block
# Block or unblock a virtual mail user by moving their entry in/out of the users file.
# Usage: sudo /usr/local/bin/email-block <block|unblock> <username>

ACTION="$1"
USERNAME="$2"
USERS_FILE="/var/dovecot/users"
BACKUP_FILE="/var/dovecot/users.blocked"
MAIL_ROOT="/var/vmail/${USERNAME}@freedoms4.org"
VTRANSPORT_FILE="/etc/postfix/virtual_transport"
ADDRESS="${USERNAME}@freedoms4.org"

if [[ -z "$ACTION" || -z "$USERNAME" ]]; then
    echo "Usage: $0 <block|unblock> <username>" >&2
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

if [[ "$ACTION" == "block" ]]; then
    # Check if entry exists in users file
    if ! grep -q "^${USERNAME}:" "${USERS_FILE}" 2>/dev/null; then
        echo "not-found"
        exit 0
    fi
    # Move entry to backup file
    grep "^${USERNAME}:" "${USERS_FILE}" >> "${BACKUP_FILE}"
    sed -i "/^${USERNAME}:/d" "${USERS_FILE}"
    # Remove Postfix transport entry so new mail is no longer delivered
    if [[ -f "${VTRANSPORT_FILE}" ]]; then
        sed -i "/^${ADDRESS}[[:space:]]/d" "${VTRANSPORT_FILE}"
        postmap "${VTRANSPORT_FILE}" 2>/dev/null || true
    fi
    # Delete existing messages while keeping mailbox folders intact
    if [[ -d "${MAIL_ROOT}" ]]; then
        find "${MAIL_ROOT}" -type d \( -name cur -o -name new -o -name tmp \) -print0 |
            while IFS= read -r -d '' maildir_part; do
                find "${maildir_part}" -mindepth 1 -maxdepth 1 -type f -delete
            done
    fi
    echo "blocked"

elif [[ "$ACTION" == "unblock" ]]; then
    # Check if entry exists in backup file
    if ! grep -q "^${USERNAME}:" "${BACKUP_FILE}" 2>/dev/null; then
        echo "not-found"
        exit 0
    fi
    # Restore entry to users file
    grep "^${USERNAME}:" "${BACKUP_FILE}" >> "${USERS_FILE}"
    sed -i "/^${USERNAME}:/d" "${BACKUP_FILE}"
    # Restore Postfix transport entry so new mail is delivered again
    if [[ -f "${VTRANSPORT_FILE}" ]] && ! grep -q "^${ADDRESS}[[:space:]]" "${VTRANSPORT_FILE}" 2>/dev/null; then
        echo "${ADDRESS}    dovecot" >> "${VTRANSPORT_FILE}"
        postmap "${VTRANSPORT_FILE}" 2>/dev/null || true
    fi
    echo "unblocked"

else
    echo "Invalid action: $ACTION" >&2
    exit 3
fi
