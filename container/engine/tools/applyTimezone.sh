#!/bin/bash
# Apply a timezone to the running system
# Usage: applyTimezone.sh <IANA timezone>
# Called by PHP when saving settings

TZ_NEW="$1"

if [ -z "$TZ_NEW" ]; then
	TZ_NEW="Etc/UTC"
fi

if [ ! -f "/usr/share/zoneinfo/$TZ_NEW" ]; then
	echo "Invalid timezone: $TZ_NEW"
	exit 1
fi

echo "$TZ_NEW" > /etc/timezone
ln -sf /usr/share/zoneinfo/$TZ_NEW /etc/localtime
export TZ="$TZ_NEW"

# Update /etc/environment so other processes pick it up
if grep -q "^TZ=" /etc/environment 2>/dev/null; then
	sed -i "s|^TZ=.*|TZ=$TZ_NEW|" /etc/environment
else
	echo "TZ=$TZ_NEW" >> /etc/environment
fi

echo "Timezone set to $TZ_NEW"
