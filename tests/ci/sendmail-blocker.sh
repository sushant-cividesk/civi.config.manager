#!/bin/sh

# Never retain headers or message content. Record only that an attempt occurred.
cat >/dev/null
printf '%s\n' 'Blocked outbound PHP mail attempt.' >> /qa-artifacts/mail-attempts.log
exit 75
