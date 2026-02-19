#!/bin/bash
# PhValheim Analytics Pusher
# Collects installation metrics and world data, then POSTs to the analytics service.
# Runs on startup and every 24 hours via cron.

source /opt/stateless/engine/includes/phvalheim-static.conf

# ── Check if analytics is enabled ────────────────────────────────
analyticsEnabled=$(SQL "SELECT analyticsEnabled FROM settings" 2>/dev/null)
if [ "$analyticsEnabled" != "1" ]; then
	exit 0
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
worlds_json="[]"
world_ids=$(SQL "SELECT id FROM worlds" 2>/dev/null)

for wid in $world_ids; do
	[ -z "$wid" ] && continue

	wname=$(SQL "SELECT name FROM worlds WHERE id='$wid'"  2>/dev/null)
	wmode=$(SQL "SELECT mode FROM worlds WHERE id='$wid'"  2>/dev/null)
	wupdated=$(SQL "SELECT updated FROM worlds WHERE id='$wid'" 2>/dev/null || echo "")
	wmods=$(SQL "SELECT thunderstore_mods FROM worlds WHERE id='$wid'" 2>/dev/null)

	# Build mods array with jq for safe JSON encoding
	mods_json="[]"
	for muuid in $wmods; do
		[ -z "$muuid" ] && continue
		mod_name=$(SQL    "SELECT name    FROM tsmods WHERE uuid='$muuid'" 2>/dev/null)
		mod_version=$(SQL "SELECT version FROM tsmods WHERE uuid='$muuid'" 2>/dev/null)
		mod_owner=$(SQL   "SELECT owner   FROM tsmods WHERE uuid='$muuid'" 2>/dev/null)
		[ -z "$mod_name" ] && continue

		ts_url="https://thunderstore.io/c/valheim/p/${mod_owner}/${mod_name}/"
		mods_json=$(echo "$mods_json" | jq \
			--arg n "$mod_name" \
			--arg v "${mod_version:-unknown}" \
			--arg o "${mod_owner:-unknown}" \
			--arg u "$ts_url" \
			'. += [{"name":$n,"version":$v,"owner":$o,"thunderstore_url":$u}]')
	done

	worlds_json=$(echo "$worlds_json" | jq \
		--arg n "${wname:-unknown}" \
		--arg m "${wmode:-unknown}" \
		--arg u "${wupdated:-}" \
		--argjson mods "$mods_json" \
		'. += [{"name":$n,"mode":$m,"last_updated":$u,"mods":$mods}]')
done

# ── Build payload ─────────────────────────────────────────────────
payload=$(jq -n \
	--arg  uuid     "$analyticsUUID" \
	--arg  hostname "$pv_hostname" \
	--arg  version  "$pv_version" \
	--arg  kernel   "$pv_kernel" \
	--arg  cpu      "$pv_cpu" \
	--argjson mem_total  "$mem_total_mb" \
	--argjson mem_used   "$mem_used_mb" \
	--argjson disk_total "$disk_total_gb" \
	--argjson disk_used  "$disk_used_gb" \
	--argjson ai_enabled "$ai_enabled" \
	--argjson ai_providers "$ai_providers" \
	--argjson worlds       "$worlds_json" \
	'{
		uuid:            $uuid,
		hostname:        $hostname,
		version:         $version,
		kernel:          $kernel,
		cpu_type:        $cpu,
		memory_total_mb: $mem_total,
		memory_used_mb:  $mem_used,
		disk_total_gb:   $disk_total,
		disk_used_gb:    $disk_used,
		ai_enabled:      $ai_enabled,
		ai_providers:    $ai_providers,
		worlds:          $worlds
	}')

if [ -z "$payload" ]; then
	echo "$(date) [WARN : phvalheim] Failed to build analytics payload"
	exit 1
fi

# ── POST ──────────────────────────────────────────────────────────
http_code=$(curl -s -o /tmp/phvalheim_analytics.tmp -w "%{http_code}" \
	-X POST \
	-H "Content-Type: application/json" \
	-d "$payload" \
	--max-time 30 \
	--connect-timeout 10 \
	"https://analytics.phvalheim.com/api/ingest" 2>/dev/null)

if [ "$http_code" = "200" ]; then
	echo "$(date) [NOTICE : phvalheim] Analytics data pushed successfully"
else
	response_body=$(cat /tmp/phvalheim_analytics.tmp 2>/dev/null)
	echo "$(date) [WARN : phvalheim] Analytics push failed (HTTP ${http_code:-000}): ${response_body}"
fi

rm -f /tmp/phvalheim_analytics.tmp
