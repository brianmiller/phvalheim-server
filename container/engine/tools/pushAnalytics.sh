#!/bin/bash
# PhValheim Analytics Pusher
# Collects installation metrics and world data, then POSTs to the analytics service.
# Runs on startup and every 24 hours via cron.
#
# Usage: pushAnalytics.sh [--disabled]
#   --disabled  Send one final payload marking analytics as disabled, then exit.
#               Used automatically when the user turns off analytics in settings.

source /opt/stateless/engine/includes/phvalheim-static.conf

# ── Check for --disabled flag ─────────────────────────────────────
SEND_DISABLED_NOTICE=0
if [ "$1" = "--disabled" ]; then
	SEND_DISABLED_NOTICE=1
fi

# ── Check if analytics is enabled (skip when sending disabled notice) ──
if [ "$SEND_DISABLED_NOTICE" != "1" ]; then
	analyticsEnabled=$(SQL "SELECT analyticsEnabled FROM settings" 2>/dev/null)
	if [ "$analyticsEnabled" != "1" ]; then
		exit 0
	fi
fi

# ── Validate UUID ─────────────────────────────────────────────────
analyticsUUID=$(SQL "SELECT analyticsUUID FROM settings" 2>/dev/null)
if [ -z "$analyticsUUID" ]; then
	echo "$(date) [WARN : phvalheim] Analytics UUID not found. Has the engine finished starting?"
	exit 1
fi

# ── System info ───────────────────────────────────────────────────
pv_hostname=$(SQL "SELECT gameDNS FROM settings" 2>/dev/null)
[ -z "$pv_hostname" ] && pv_hostname=$(hostname -f 2>/dev/null || echo "unknown")

pv_version="${phvalheimVersion:-unknown}"
pv_kernel=$(uname -r 2>/dev/null || echo "unknown")
pv_cpu=$(grep "model name" /proc/cpuinfo 2>/dev/null | head -1 | sed 's/.*: //')

mem_total_kb=$(grep "MemTotal:"     /proc/meminfo 2>/dev/null | awk '{print $2}')
mem_avail_kb=$(grep "MemAvailable:" /proc/meminfo 2>/dev/null | awk '{print $2}')
mem_total_mb=$(( ${mem_total_kb:-0} / 1024 ))
mem_used_mb=$(( ( ${mem_total_kb:-0} - ${mem_avail_kb:-0} ) / 1024 ))

disk_row=$(df -BG /opt/stateful 2>/dev/null | tail -1)
disk_total_gb=$(echo "$disk_row" | awk '{gsub(/G/,"",$2); print int($2+0)}')
disk_used_gb=$(echo "$disk_row"  | awk '{gsub(/G/,"",$3); print int($3+0)}')
[ -z "$disk_total_gb" ] && disk_total_gb=0
[ -z "$disk_used_gb"  ] && disk_used_gb=0

# ── AI providers ──────────────────────────────────────────────────
openaiKey=$(SQL "SELECT openaiApiKey FROM settings" 2>/dev/null)
geminiKey=$(SQL "SELECT geminiApiKey FROM settings" 2>/dev/null)
claudeKey=$(SQL "SELECT claudeApiKey FROM settings" 2>/dev/null)
ollamaUrl=$(SQL "SELECT ollamaUrl    FROM settings" 2>/dev/null)

provider_csv=""
[ -n "$openaiKey" ] && provider_csv="${provider_csv}\"openai\","
[ -n "$geminiKey" ] && provider_csv="${provider_csv}\"gemini\","
[ -n "$claudeKey" ] && provider_csv="${provider_csv}\"claude\","
[ -n "$ollamaUrl" ] && provider_csv="${provider_csv}\"ollama\","

if [ -n "$provider_csv" ]; then
	ai_enabled="true"
	ai_providers="[${provider_csv%,}]"
else
	ai_enabled="false"
	ai_providers="[]"
fi

# ── Worlds ────────────────────────────────────────────────────────
# Accumulated in FILES, never in shell variables passed on a command line.
#
# This used to build worlds_json in a variable and hand it to jq as
# --argjson worlds "$worlds_json". Linux caps a SINGLE argv entry at 128 KiB
# (MAX_ARG_STRLEN), independently of the much larger total ARG_MAX, so once a
# server had enough worlds x mods the payload crossed that line and jq died with
# "Argument list too long" -- every analytics push failed from then on, silently
# apart from one WARN line. Measured in-container: --argjson accepts 129,025
# bytes and fails at 201,601; the same data via --slurpfile is fine at 512 KB.
# Keep large JSON off argv. Scalars below are safe; a world name cannot approach
# 128 KiB.
worlds_file=$(mktemp /tmp/phvalheim_analytics_worlds.XXXXXX)
mods_file=$(mktemp /tmp/phvalheim_analytics_mods.XXXXXX)
work_file=$(mktemp /tmp/phvalheim_analytics_work.XXXXXX)
trap 'rm -f "$worlds_file" "$mods_file" "$work_file"' EXIT INT TERM
echo "[]" > "$worlds_file"

world_ids=$(SQL "SELECT id FROM worlds" 2>/dev/null)

for wid in $world_ids; do
	[ -z "$wid" ] && continue

	wname=$(SQL "SELECT name FROM worlds WHERE id='$wid'"  2>/dev/null)
	wmode=$(SQL "SELECT mode FROM worlds WHERE id='$wid'"  2>/dev/null)
	wupdated=$(SQL "SELECT updated FROM worlds WHERE id='$wid'" 2>/dev/null || echo "")
	# One query instead of three per mod against an unindexed table, and it reports the
	# version the world will actually run (the pin if pinned, else newest) rather than
	# assuming latest. `source` is new in 2.43 -- a world's mods can now come from more
	# than one catalogue, and analytics that said "thunderstore" for all of them would be
	# reporting something untrue.
	#
	# Tab-separated and read with IFS set to a literal tab: the default IFS would split an
	# owner containing a space into the wrong field.
	wmodrows=$(SQL "SELECT m.source, m.owner, m.name,
	                       COALESCE(pin.version, latest.version, 'unknown'),
	                       COALESCE(m.package_url, '')
	                  FROM world_mods wm
	                  JOIN mods m ON m.id = wm.mod_id
	                  LEFT JOIN mod_versions pin ON pin.id = wm.pin_version_id
	                  LEFT JOIN mod_versions latest ON latest.mod_id = m.id
	                                              AND latest.source_rank = 0
	                 WHERE wm.world_id='$wid'" 2>/dev/null)

	# Build mods array with jq for safe JSON encoding
	echo "[]" > "$mods_file"
	while IFS=$'\t' read -r mod_source mod_owner mod_name mod_version mod_url; do
		[ -z "$mod_name" ] && continue
		[ -z "$mod_url" ] && mod_url="https://thunderstore.io/c/valheim/p/${mod_owner}/${mod_name}/"

		if jq \
			--arg n "$mod_name" \
			--arg v "${mod_version:-unknown}" \
			--arg o "${mod_owner:-unknown}" \
			--arg s "${mod_source:-unknown}" \
			--arg u "$mod_url" \
			'. += [{"name":$n,"version":$v,"owner":$o,"source":$s,"thunderstore_url":$u}]' \
			"$mods_file" > "$work_file"; then
			mv "$work_file" "$mods_file"
		else
			echo "$(date) [WARN : phvalheim] analytics: could not add mod $mod_name, skipping"
		fi
	done <<< "$wmodrows"

	# $mods[0] because --slurpfile wraps the file's value in an array.
	if jq \
		--arg n "${wname:-unknown}" \
		--arg m "${wmode:-unknown}" \
		--arg u "${wupdated:-}" \
		--slurpfile mods "$mods_file" \
		'. += [{"name":$n,"mode":$m,"last_updated":$u,"mods":$mods[0]}]' \
		"$worlds_file" > "$work_file"; then
		mv "$work_file" "$worlds_file"
	else
		echo "$(date) [WARN : phvalheim] analytics: could not add world ${wname:-unknown}, skipping"
	fi
done

# ── Analytics disabled flag ───────────────────────────────────────
if [ "$SEND_DISABLED_NOTICE" = "1" ]; then
	analytics_disabled_val="true"
else
	analytics_disabled_val="false"
fi

# ── Build payload ─────────────────────────────────────────────────
payload=$(jq -n \
	--arg  uuid     "$analyticsUUID" \
	--arg  hostname "$pv_hostname" \
	--arg  version  "$pv_version" \
	--arg  kernel   "$pv_kernel" \
	--arg  cpu      "$pv_cpu" \
	--argjson mem_total          "$mem_total_mb" \
	--argjson mem_used           "$mem_used_mb" \
	--argjson disk_total         "$disk_total_gb" \
	--argjson disk_used          "$disk_used_gb" \
	--argjson ai_enabled         "$ai_enabled" \
	--argjson ai_providers       "$ai_providers" \
	--slurpfile worlds           "$worlds_file" \
	--argjson analytics_disabled "$analytics_disabled_val" \
	'{
		uuid:                $uuid,
		hostname:            $hostname,
		version:             $version,
		kernel:              $kernel,
		cpu_type:            $cpu,
		memory_total_mb:     $mem_total,
		memory_used_mb:      $mem_used,
		disk_total_gb:       $disk_total,
		disk_used_gb:        $disk_used,
		ai_enabled:          $ai_enabled,
		ai_providers:        $ai_providers,
		worlds:              $worlds[0],
		analytics_disabled:  $analytics_disabled
	}')

if [ -z "$payload" ]; then
	echo "$(date) [WARN : phvalheim] Failed to build analytics payload"
	exit 1
fi

# Write payload to a temp file so curl reads it with --data-binary.
# This avoids bash's null-byte truncation when expanding "$payload" inline.
payload_file="/tmp/phvalheim_analytics_payload.json"
printf '%s' "$payload" > "$payload_file"

# ── POST ──────────────────────────────────────────────────────────
# Primary: public HTTPS endpoint (requires analytics.phvalheim.com DNS → this node)
# Fallback: node-local HTTP via nginx proxy on port 80 (always works when co-deployed)
primary_url="https://analytics.phvalheim.com/api/ingest"
fallback_url="http://localhost/api/ingest"

http_code=$(curl -s -o /tmp/phvalheim_analytics.tmp -w "%{http_code}" \
	-X POST \
	-H "Content-Type: application/json" \
	--data-binary "@${payload_file}" \
	--max-time 30 \
	--connect-timeout 10 \
	"$primary_url" 2>/dev/null)

# If HTTPS endpoint fails or returns non-200, try the local fallback
response_body=$(cat /tmp/phvalheim_analytics.tmp 2>/dev/null)
if [ "$http_code" != "200" ] || ! echo "$response_body" | grep -q '"success":true'; then
	http_code=$(curl -s -o /tmp/phvalheim_analytics.tmp -w "%{http_code}" \
		-X POST \
		-H "Content-Type: application/json" \
		--data-binary "@${payload_file}" \
		--max-time 10 \
		--connect-timeout 5 \
		"$fallback_url" 2>/dev/null)
	response_body=$(cat /tmp/phvalheim_analytics.tmp 2>/dev/null)
fi

if [ "$http_code" != "200" ]; then
	response_body=$(cat /tmp/phvalheim_analytics.tmp 2>/dev/null)
	echo "$(date) [WARN : phvalheim] Analytics push failed (HTTP ${http_code:-000}): ${response_body}"
fi

rm -f /tmp/phvalheim_analytics.tmp "$payload_file"
