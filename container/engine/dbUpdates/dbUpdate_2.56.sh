#!/bin/bash

source /opt/stateless/engine/includes/phvalheim-static.conf

addColumn() {
	table="$1"
	column="$2"
	definition="$3"

	sql "DESCRIBE $table"|awk '{print $1}'|grep -qx "$column" > /dev/null 2>&1
	if [ ! $? = 0 ]; then
		echo "`date` [NOTICE : phvalheim] Adding $table.$column"
		sql "ALTER TABLE $table ADD COLUMN $column $definition;"
	fi
}

## BEGIN UPDATE ##
#
# 2.56
#
# Object-by-object idempotent rather than one top-level guard, for the same reason as every
# migration since 2.40: this ships as an RC first, and later revisions of THIS script must run
# on servers that already ran an earlier revision.

echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.56"


# --- worlds.join_code: the crossplay join code, remembered --------------------------------
#
# THE BUG (production, 2026-10-18): a modded crossplay world was up and joinable on code
# 417250 for 35 hours while the dashboard said "waiting for its join code". Its startup was
# verbose enough to pass settings.maxLogSize within five minutes, logRotater.sh (cp + truncate,
# ONE generation kept) moved every mention of the code out of the live log, and Valheim only
# re-prints it when a player joins or leaves. The code was stored nowhere, so once the log
# lost it nothing could find it again.
#
# Written by engine/tools/playerMonitor, which already reads every running world's log every
# two minutes. Read by getWorldJoinCode() in db_gets.php, and ONLY when neither log generation
# still says anything about the running session -- a visible session start with no code after
# it means "not issued yet", whatever this column holds. The safety argument for never serving
# a previous session's code lives with the writer, in playerMonitor.
#
# NULL is the default and means "nothing remembered", which the reader treats exactly like a
# log with no code in it.
addColumn worlds join_code "VARCHAR(16) NULL"
