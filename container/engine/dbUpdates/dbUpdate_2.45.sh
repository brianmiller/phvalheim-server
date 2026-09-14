#!/bin/bash

source /opt/stateless/engine/includes/phvalheim-static.conf

## BEGIN UPDATE ##
#
# 2.45: the AI Helper becomes provider-agnostic.
#
# Object-by-object idempotent rather than one top-level guard, for the same reason as
# 2.40 and 2.43: this ships as an RC first, and later revisions of THIS script must run
# on servers that already ran an earlier revision.

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

tableExists() {
	sql "SHOW TABLES LIKE '$1'" | grep -qx "$1" > /dev/null 2>&1
}

echo "`date` [NOTICE : phvalheim] Applying database schema update for phvalheim-server >=v2.45"


# --- ai_providers --------------------------------------------------------------------
#
# A provider is a ROW, not a hardcoded case in PHP. That is the whole point of 2.45.
#
# Issue #83 was reported as "the Gemini model we hardcoded got retired". The retirement
# is not the bug -- models get retired constantly and always will. The bug is that a
# model id was ever a constant in our source. 2.44 carried THREE such lists: one in
# getAiProvidersJson() for the picker, one in aiHelperDispatch() for validation, and the
# validator silently rewrote any model it did not recognise to element [0] of its list.
# So an operator who typed a valid current model got a different model than they asked
# for, with no error.
#
# Every provider we support publishes its own catalogue live:
#   openai_compatible  GET {base}/models              Authorization: Bearer
#   anthropic          GET {base}/v1/models           x-api-key + anthropic-version
#   gemini             GET {base}/v1beta/models       ?key=
# so there is no reason for us to hold an opinion about what exists.
#
# (A fourth `ollama` kind existed earlier in 2.45 and was folded into openai_compatible
# before release -- Ollama serves /v1, so it was a preset, not a protocol. The migration
# that converts those rows lives further down this file.) `model` below is
# whatever the operator picked out of that live list, stored verbatim and sent verbatim.
#
# `kind` is deliberately not an ENUM. A new kind must not require a schema migration,
# and an ENUM would make an unknown kind a write error at the DB layer instead of a
# handled "unsupported provider" at the PHP layer.
#
# base_url is per-row rather than derived from kind because openai_compatible is the
# catch-all: vLLM, LM Studio, llama.cpp-server, OpenRouter, Groq, Together, DeepSeek,
# Mistral and xAI are all the same wire protocol at different hostnames. One adapter,
# N rows. This is also what makes self-hosted-with-a-key work at all -- 2.44's ollamaUrl
# had no key field, so a vLLM behind --api-key was unusable.
if ! tableExists ai_providers; then
	echo "`date` [NOTICE : phvalheim] Creating table 'ai_providers'"
	sql "CREATE TABLE ai_providers (
		id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
		kind          VARCHAR(32)   NOT NULL,
		label         VARCHAR(64)   NOT NULL,
		base_url      VARCHAR(512)  NOT NULL DEFAULT '',
		api_key       VARCHAR(1024) NOT NULL DEFAULT '',
		model         VARCHAR(190)  NOT NULL DEFAULT '',
		extra_headers TEXT          NULL,
		enabled       TINYINT(1)    NOT NULL DEFAULT 1,
		is_default    TINYINT(1)    NOT NULL DEFAULT 0,
		sort_order    INT           NOT NULL DEFAULT 0,
		created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY idx_enabled (enabled, sort_order)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
fi

# Added after the initial 2.45 RC -- see the no-top-level-guard note above.
addColumn ai_providers extra_headers "TEXT NULL"
addColumn ai_providers is_default    "TINYINT(1) NOT NULL DEFAULT 0"
addColumn ai_providers sort_order    "INT NOT NULL DEFAULT 0"


# --- ai_model_cache ------------------------------------------------------------------
#
# Discovery is a network round trip to a third party. Doing it on every page load would
# put the admin UI's responsiveness at the mercy of api.openai.com, and 2.44 already
# taught us what that costs: getOllamaModels() ran inline inside getAiProviders and a
# dead Ollama host stalled the whole AI panel.
#
# A miss here must never block chat. It only means the picker shows the pinned model
# alone until the next refresh.
if ! tableExists ai_model_cache; then
	echo "`date` [NOTICE : phvalheim] Creating table 'ai_model_cache'"
	sql "CREATE TABLE ai_model_cache (
		provider_id INT UNSIGNED NOT NULL,
		models_json LONGTEXT      NULL,
		error       VARCHAR(512)  NOT NULL DEFAULT '',
		fetched_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (provider_id)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
fi


# --- migrate the four legacy settings columns into rows ------------------------------
#
# Guarded on ai_providers being EMPTY, not on the columns being non-empty: re-running
# this must not duplicate rows, and an operator who has already deleted a migrated
# provider must not have it resurrected on the next container restart.
#
# NOTE the deliberate omission: `model` is left ''. It is NOT carried across, because
# there is nothing worth carrying -- the only model ids 2.44 could have stored are the
# ones from its own hardcoded lists, and gemini-2.0-flash (issue #83) is exactly one of
# them. Leaving it empty forces resolution from live discovery on first use, which fixes
# #83 retroactively for upgraders rather than only for fresh installs.
providerCount="$(sql "SELECT COUNT(*) FROM ai_providers" | tail -n1)"
if [ "$providerCount" = "0" ]; then
	migrated=0

	legacyKey() {
		# settings is a single-row table; tail -n1 drops the column header mysql prints.
		sql "SELECT COALESCE($1,'') FROM settings LIMIT 1" 2>/dev/null | tail -n1
	}

	addProvider() {
		kind="$1"; label="$2"; base="$3"; key="$4"
		echo "`date` [NOTICE : phvalheim] Migrating legacy AI credential to provider '$label'"
		sql "INSERT INTO ai_providers (kind,label,base_url,api_key,model,enabled,sort_order)
		     VALUES ('$kind','$label','$base','$key','',1,$migrated);"
		migrated=$((migrated+1))
	}

	openaiKey="$(legacyKey openaiApiKey)"
	claudeKey="$(legacyKey claudeApiKey)"
	geminiKey="$(legacyKey geminiApiKey)"
	ollamaUrl="$(legacyKey ollamaUrl)"

	[ -n "$openaiKey" ] && [ "$openaiKey" != "NULL" ] && \
		addProvider openai_compatible "OpenAI" "https://api.openai.com/v1" "$openaiKey"

	[ -n "$claudeKey" ] && [ "$claudeKey" != "NULL" ] && \
		addProvider anthropic "Anthropic Claude" "https://api.anthropic.com" "$claudeKey"

	[ -n "$geminiKey" ] && [ "$geminiKey" != "NULL" ] && \
		addProvider gemini "Google Gemini" "https://generativelanguage.googleapis.com" "$geminiKey"

	# 2.44's ollamaUrl is a BARE host:port -- "http://ollama.example:11434" -- because 2.44 spoke
	# Ollama's native API. There is no native adapter any more: Ollama serves an
	# OpenAI-compatible API at /v1 and the dedicated kind was ~70 lines duplicating
	# aiChatOpenAI. So the URL gains /v1 and the row becomes openai_compatible.
	if [ -n "$ollamaUrl" ] && [ "$ollamaUrl" != "NULL" ]; then
		case "$ollamaUrl" in
			*/v1|*/v1/) ollamaBase="${ollamaUrl%/}" ;;
			*)          ollamaBase="${ollamaUrl%/}/v1" ;;
		esac
		addProvider openai_compatible "Ollama" "$ollamaBase" ""
	fi

	if [ "$migrated" -gt 0 ]; then
		sql "UPDATE ai_providers SET is_default=1 WHERE sort_order=0;"
		echo "`date` [NOTICE : phvalheim] Migrated $migrated legacy AI provider(s). Models resolve from live discovery."
	fi
fi

# --- convert any kind='ollama' row that already exists -------------------------------
#
# Runs OUTSIDE the "registry is empty" guard above, because this is not a legacy-settings
# import: it repairs rows a PREVIOUS revision of this very script created. The 2.45 RCs
# shipped a dedicated ollama kind and installs are already running with those rows; leaving
# them would point a live provider at a kind no code can dispatch, which is the 2.43
# world-card regression exactly -- a reader left behind pointing at something deleted.
#
# Idempotent by construction: after the second statement no row has kind='ollama', so a
# re-run matches nothing. The URL is fixed FIRST, while the rows are still identifiable.
#
# A converted provider is a CHANGE THE OPERATOR DID NOT MAKE: the base URL they typed now
# has /v1 on the end and the type they chose no longer exists. Doing that silently is the
# kind of thing that gets discovered weeks later as "the AI Helper is pointing somewhere
# odd", so the count is parked in settings and the admin UI raises a one-shot notice, the
# same shape as the 2.31 settings-migration dialog.
addColumn settings aiOllamaNotice "TINYINT NOT NULL DEFAULT 0"

ollamaRows="$(sql "SELECT COUNT(*) FROM ai_providers WHERE kind = 'ollama'" | tail -n1)"
if [ -n "$ollamaRows" ] && [ "$ollamaRows" != "0" ] && [ "$ollamaRows" != "COUNT(*)" ]; then
	echo "`date` [NOTICE : phvalheim] Converting $ollamaRows Ollama provider(s) to the OpenAI-compatible endpoint"
	# TRIM first so "host:11434/" does not become "host:11434//v1".
	sql "UPDATE ai_providers
	        SET base_url = CONCAT(TRIM(TRAILING '/' FROM base_url), '/v1')
	      WHERE kind = 'ollama'
	        AND base_url NOT LIKE '%/v1'
	        AND base_url NOT LIKE '%/v1/';"
	sql "UPDATE ai_providers SET kind = 'openai_compatible' WHERE kind = 'ollama';"
	# The cached model list came from /api/tags and is in the native shape. Drop it so the
	# next read rediscovers against /v1/models rather than showing stale, wrongly-parsed ids.
	sql "DELETE c FROM ai_model_cache c
	       LEFT JOIN ai_providers p ON p.id = c.provider_id
	      WHERE p.id IS NULL OR p.label = 'Ollama';"

	# Raise the notice. Written LAST, after the rewrites have actually happened, so a
	# migration that dies half way does not leave the admin UI announcing work it did not do.
	sql "UPDATE settings SET aiOllamaNotice = $ollamaRows;"
fi

# The legacy columns are intentionally NOT dropped -- they are kept as a rollback record,
# the same call 2.43 made for worlds.thunderstore_mods. Nothing reads them after this
# script runs. Code found reading openaiApiKey / claudeApiKey / geminiApiKey / ollamaUrl
# is a bug: it will see a value that the AI Helper no longer uses.


# ======================================================================================
# Hugin acts: proposals, usage counters and provider capability.
#
# Appended to THIS script rather than a new one, which is exactly what the object-by-object
# structure at the top of this file exists to allow: there is no top-level "already applied"
# gate, so every block below is re-evaluated on each boot and a block added after an RC has
# shipped still runs on a server that applied the earlier revision.
# ======================================================================================


# --- ai_proposals --------------------------------------------------------------------
#
# A consequential action is never executed straight off a tool call. The model's call is
# validated, and what it MEANT is written here as a server-authored plan; the browser is
# then handed nothing but an opaque token.
#
# That inversion is the whole safety story, and it exists because we do not control the
# model. An operator brings their own LLM -- it may be Opus, it may be a 7B quant that
# hallucinates a world name. If Apply posted the parameters back, the blast radius of a
# bad model (or a crafted client) would be "anything the admin API can do". Posting a
# token instead means the worst a bad proposal can achieve is a card the operator reads
# and dismisses.
#
# params_json holds the VALIDATED arguments, not the model's raw ones. summary is rendered
# server-side from those same parameters, so the card shows what will actually happen
# rather than what the model claimed it was doing -- when the two disagree, that is
# precisely the moment the operator needs to see the truth.
#
# consumed_at + expires_at make a proposal single-use and short-lived: no replay, and a
# card left open in a tab overnight is dead rather than dangerous.
if ! tableExists ai_proposals; then
	echo "`date` [NOTICE : phvalheim] Creating table 'ai_proposals'"
	sql "CREATE TABLE ai_proposals (
		id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
		token       CHAR(43)      NOT NULL,
		action      VARCHAR(64)   NOT NULL,
		world       VARCHAR(190)  NOT NULL DEFAULT '',
		params_json TEXT          NULL,
		summary     TEXT          NULL,
		typed_name  VARCHAR(190)  NOT NULL DEFAULT '',
		status      VARCHAR(16)   NOT NULL DEFAULT 'pending',
		result      TEXT          NULL,
		created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
		expires_at  DATETIME      NOT NULL,
		consumed_at DATETIME      NULL,
		PRIMARY KEY (id),
		UNIQUE KEY idx_token (token),
		KEY idx_expiry (status, expires_at)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
fi


# --- ai_usage ------------------------------------------------------------------------
#
# Counters only. Never text.
#
# Shaped as (metric, subkey, day) so the same table serves scalars, per-tool tallies and
# histograms without a schema change per question:
#
#   ('chats',    '',                 CURDATE())  a scalar
#   ('tool',     'get_diagnostics',  CURDATE())  a tally
#   ('rounds',   '3',                CURDATE())  a histogram bucket, so a median is
#                                                derivable without storing per-conversation
#                                                rows
#   ('error',    'http_400',         CURDATE())  a CLASS, never the message -- an error
#                                                string can contain a URL, a model id or
#                                                a world name
#
# Day granularity is the privacy floor as well as a storage one: per-event timestamps
# would make an operator's usage pattern reconstructable from what is meant to be an
# anonymous counter.
if ! tableExists ai_usage; then
	echo "`date` [NOTICE : phvalheim] Creating table 'ai_usage'"
	sql "CREATE TABLE ai_usage (
		metric VARCHAR(32)  NOT NULL,
		subkey VARCHAR(64)  NOT NULL DEFAULT '',
		day    DATE         NOT NULL,
		count  INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY (metric, subkey, day),
		KEY idx_day (day)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
fi


# --- provider capability -------------------------------------------------------------
#
# Whether a model can drive tools is a property of the ENDPOINT, discovered by asking it,
# and it is cached here so the answer survives a page load.
#
# It is NOT a lookup table of model names, and must never become one. 2.44's whole defect
# was source code holding an opinion about which models exist; a hardcoded
# "these models support tools" list would be the same mistake wearing a different hat, and
# would rot at the same speed.
#
# Three values, deliberately distinct:
#   'tools'  the endpoint accepted tools and the model emitted a well-formed call
#   'text'   the endpoint REFUSED the tools parameter -- fix is a different endpoint
#   'inert'  tools were accepted but the model answered in prose when a call was needed
#            -- fix is a bigger model
# '' means not yet established. The operator's remedy differs per value, which is why one
# "no tools" flag would not do.
addColumn ai_providers tool_capability    "VARCHAR(16) NOT NULL DEFAULT ''"
addColumn ai_providers capability_checked "DATETIME NULL"


# --- the one-shot "meet Hugin" notice -------------------------------------------------
#
# 0 = not yet shown. Defaults to 0 for everyone, including fresh installs, because the
# whole point is that an operator should meet the assistant once rather than discover it by
# clicking an unexplained bird. The admin UI gates it on setupComplete = 2, so on a fresh
# install it queues behind the setup wizard instead of stacking on top of it.
addColumn settings huginNoticeShown "TINYINT NOT NULL DEFAULT 0"


# --- housekeeping --------------------------------------------------------------------
#
# Proposals are transient by design. Sweep anything long dead so the table cannot grow
# without bound on a server where Hugin is used heavily and confirmed rarely.
sql "DELETE FROM ai_proposals WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY);" > /dev/null 2>&1
sql "DELETE FROM ai_usage    WHERE day        < DATE_SUB(CURDATE(), INTERVAL 30 DAY);" > /dev/null 2>&1

echo "`date` [NOTICE : phvalheim] Database schema update for phvalheim-server >=v2.45 complete"

## END UPDATE ##
