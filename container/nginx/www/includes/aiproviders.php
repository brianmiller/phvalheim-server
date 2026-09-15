<?php
/**
 * AI provider registry, live model discovery, and the unified chat transport.
 *
 * ------------------------------------------------------------------------------------
 * THE RULE: a model id is NEVER a constant in this file, or in any file.
 * ------------------------------------------------------------------------------------
 *
 * Issue #83 arrived as "gemini-2.0-flash is retired". Bumping it to whatever is current
 * would have been a fix with a shelf life measured in weeks. The actual defect is that
 * 2.44 held three hardcoded model lists, and aiHelperDispatch() used one of them to
 * SILENTLY rewrite any model it did not recognise:
 *
 *     if (!in_array($model, $allowedModels[$provider])) {
 *         $model = $allowedModels[$provider][0];     // operator asked for X, got Y
 *     }
 *
 * So the failure mode was not only "our default is stale" but "a correct model the
 * operator typed gets replaced by a stale one, with no error". Every provider below
 * publishes its catalogue over HTTP. We ask. We do not remember.
 *
 * The one place a provider-specific string still appears is aiProviderKinds()'s
 * `base_url` -- a pre-filled, operator-editable hostname, not a capability claim.
 */

if (!defined('AI_HTTP_TIMEOUT'))        define('AI_HTTP_TIMEOUT', 120);
if (!defined('AI_DISCOVERY_TIMEOUT'))   define('AI_DISCOVERY_TIMEOUT', 12);
if (!defined('AI_MODEL_CACHE_SECONDS')) define('AI_MODEL_CACHE_SECONDS', 21600); // 6h

/**
 * The kinds we know how to speak to.
 *
 * `openai_compatible` is the catch-all and covers far more than OpenAI: vLLM, LM Studio,
 * llama.cpp-server, OpenRouter, Groq, Together, DeepSeek, Mistral, xAI and any future
 * endpoint that implements /chat/completions. That is why base_url is a per-row value
 * rather than being derived from the kind -- one adapter, N endpoints.
 */
function aiProviderKinds() {
    return [
        'openai_compatible' => [
            'label'       => 'OpenAI-compatible',
            'blurb'       => 'OpenAI, vLLM, LM Studio, llama.cpp, OpenRouter, Groq, Together, DeepSeek, Mistral, xAI — anything serving /chat/completions.',
            'base_url'    => 'https://api.openai.com/v1',
            'key_label'   => 'API key',
            'key_hint'    => 'Sent as "Authorization: Bearer". Leave blank for an unauthenticated local server.',
            'key_required'=> false,
            'base_hint'   => 'Must be the root that exposes /models and /chat/completions (usually ends in /v1).',
            'tools'       => true,
            // Presets, not separate provider kinds. Every one of these speaks the same wire
            // protocol, so giving each its own adapter would be four hundred lines of
            // duplication to save the operator typing a URL. They only prefill the form —
            // nothing downstream branches on which preset was used, and an operator can
            // still type any URL they like.
            //
            // Ollama is a preset here and NOT a kind of its own. It serves an
            // OpenAI-compatible API at /v1, so the dedicated native adapter it used to have
            // was ~70 lines duplicating what aiChatOpenAI already does. dbUpdate_2.45.sh
            // converts any existing kind='ollama' row to this shape on upgrade.
            'presets'     => [
                ['label' => 'OpenAI',      'base_url' => 'https://api.openai.com/v1'],
                ['label' => 'vLLM',        'base_url' => 'http://127.0.0.1:8000/v1',
                 'hint'  => 'vLLM serves an OpenAI-compatible API. Only needs a key if started with --api-key.'],
                ['label' => 'LM Studio',   'base_url' => 'http://127.0.0.1:1234/v1'],
                ['label' => 'llama.cpp',   'base_url' => 'http://127.0.0.1:8080/v1'],
                ['label' => 'Ollama',      'base_url' => 'http://127.0.0.1:11434/v1',
                 'hint'  => 'Ollama, through its OpenAI-compatible endpoint. Note the /v1 — the bare port serves the native API, which this does not speak. No key needed by default.'],
                ['label' => 'OpenRouter',  'base_url' => 'https://openrouter.ai/api/v1'],
                ['label' => 'Groq',        'base_url' => 'https://api.groq.com/openai/v1'],
                ['label' => 'Together',    'base_url' => 'https://api.together.xyz/v1'],
                ['label' => 'DeepSeek',    'base_url' => 'https://api.deepseek.com/v1'],
                ['label' => 'Mistral',     'base_url' => 'https://api.mistral.ai/v1'],
                ['label' => 'xAI',         'base_url' => 'https://api.x.ai/v1'],
            ],
        ],
        'anthropic' => [
            'label'       => 'Anthropic Claude',
            'blurb'       => 'Claude models via the native Messages API.',
            'base_url'    => 'https://api.anthropic.com',
            'key_label'   => 'API key',
            'key_hint'    => 'From console.anthropic.com. Sent as "x-api-key".',
            'key_required'=> true,
            'base_hint'   => 'Root host only — /v1/models and /v1/messages are appended.',
            'tools'       => true,
        ],
        'gemini' => [
            'label'       => 'Google Gemini',
            'blurb'       => 'Gemini models via the Generative Language API.',
            'base_url'    => 'https://generativelanguage.googleapis.com',
            'key_label'   => 'API key',
            'key_hint'    => 'From aistudio.google.com. Sent as "x-goog-api-key".',
            'key_required'=> true,
            'base_hint'   => 'Root host only — /v1beta/models is appended.',
            'tools'       => true,
        ],
    ];
}

function aiKnownKind($kind) {
    return array_key_exists($kind, aiProviderKinds());
}

/* ====================================================================================
 * Registry CRUD
 * ==================================================================================== */

function aiProviders($pdo, $onlyEnabled = true) {
    $sql = "SELECT id, kind, label, base_url, api_key, model, extra_headers, enabled, is_default, sort_order
            FROM ai_providers";
    if ($onlyEnabled) $sql .= " WHERE enabled = 1";
    $sql .= " ORDER BY sort_order ASC, id ASC";

    try {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table missing means the 2.45 migration has not run yet. An empty registry is
        // the correct answer -- the UI renders "no providers configured" and offers the
        // wizard, rather than throwing a fatal into a JSON response.
        return [];
    }
    foreach ($rows as &$r) {
        $r['id']         = (int)$r['id'];
        $r['enabled']    = (int)$r['enabled'];
        $r['is_default'] = (int)$r['is_default'];
        $r['headers']    = aiDecodeHeaders($r['extra_headers']);
    }
    return $rows;
}

function aiProvider($pdo, $id) {
    foreach (aiProviders($pdo, false) as $p) {
        if ($p['id'] === (int)$id) return $p;
    }
    return null;
}

function aiDefaultProvider($pdo) {
    $enabled = aiProviders($pdo, true);
    if (!$enabled) return null;
    foreach ($enabled as $p) {
        if ($p['is_default']) return $p;
    }
    return $enabled[0];
}

function aiDecodeHeaders($json) {
    if (!$json) return [];
    $h = json_decode($json, true);
    if (!is_array($h)) return [];
    $out = [];
    foreach ($h as $k => $v) {
        // A newline in a header value is header injection. Strip rather than reject, so
        // a pasted value with a stray trailing newline still works.
        $k = trim(str_replace(["\r", "\n"], '', (string)$k));
        $v = trim(str_replace(["\r", "\n"], '', (string)$v));
        if ($k !== '') $out[$k] = $v;
    }
    return $out;
}

function aiSaveProvider($pdo, $data) {
    $kind = $data['kind'] ?? '';
    if (!aiKnownKind($kind)) {
        return ['success' => false, 'error' => "Unknown provider kind '$kind'"];
    }

    $label = trim($data['label'] ?? '');
    if ($label === '') $label = aiProviderKinds()[$kind]['label'];

    $base = rtrim(trim($data['base_url'] ?? ''), '/');
    if ($base === '') $base = rtrim(aiProviderKinds()[$kind]['base_url'], '/');
    if (!preg_match('#^https?://#i', $base)) {
        return ['success' => false, 'error' => 'Base URL must start with http:// or https://'];
    }

    $headers = $data['extra_headers'] ?? [];
    if (is_string($headers)) {
        $decoded = json_decode($headers, true);
        $headers = is_array($decoded) ? $decoded : [];
    }

    $fields = [
        'kind'          => $kind,
        'label'         => $label,
        'base_url'      => $base,
        // The model is stored EXACTLY as the operator chose it. No allowlist check, no
        // silent substitution. If discovery has never heard of it, that is discovery's
        // problem to report -- not ours to override. See the header comment.
        'model'         => trim($data['model'] ?? ''),
        'extra_headers' => $headers ? json_encode($headers) : null,
        'enabled'       => !empty($data['enabled']) ? 1 : 0,
    ];

    $id = (int)($data['id'] ?? 0);

    // An empty key on update means "unchanged", so the UI can render a masked field
    // without round-tripping the secret to the browser and back.
    $newKey = $data['api_key'] ?? null;

    try {
        if ($id > 0) {
            $set = [];
            foreach ($fields as $k => $v) $set[] = "$k = :$k";
            if ($newKey !== null && $newKey !== '') $set[] = "api_key = :api_key";
            $stmt = $pdo->prepare("UPDATE ai_providers SET " . implode(', ', $set) . " WHERE id = :id");
            foreach ($fields as $k => $v) $stmt->bindValue(":$k", $v);
            if ($newKey !== null && $newKey !== '') $stmt->bindValue(':api_key', $newKey);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $next = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM ai_providers")->fetchColumn();
            $cols = array_keys($fields);
            $stmt = $pdo->prepare(
                "INSERT INTO ai_providers (" . implode(',', $cols) . ", api_key, sort_order) VALUES (:"
                . implode(', :', $cols) . ", :api_key, :sort_order)"
            );
            foreach ($fields as $k => $v) $stmt->bindValue(":$k", $v);
            $stmt->bindValue(':api_key', (string)($newKey ?? ''));
            $stmt->bindValue(':sort_order', $next, PDO::PARAM_INT);
            $stmt->execute();
            $id = (int)$pdo->lastInsertId();
        }

        if (!empty($data['is_default'])) {
            $pdo->exec("UPDATE ai_providers SET is_default = 0");
            $stmt = $pdo->prepare("UPDATE ai_providers SET is_default = 1 WHERE id = ?");
            $stmt->execute([$id]);
        } elseif (!$pdo->query("SELECT COUNT(*) FROM ai_providers WHERE is_default = 1")->fetchColumn()) {
            // Never leave the registry with no default -- the panel would open with no
            // provider selected and every send would fail on an empty provider id.
            $stmt = $pdo->prepare("UPDATE ai_providers SET is_default = 1 WHERE id = ?");
            $stmt->execute([$id]);
        }

        // Endpoint or credentials may have moved; the cached catalogue is about the OLD
        // endpoint and must not be shown against the new one.
        $stmt = $pdo->prepare("DELETE FROM ai_model_cache WHERE provider_id = ?");
        $stmt->execute([$id]);

        return ['success' => true, 'id' => $id];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function aiDeleteProvider($pdo, $id) {
    $id = (int)$id;
    try {
        $wasDefault = (int)$pdo->query("SELECT COALESCE(is_default,0) FROM ai_providers WHERE id = $id")->fetchColumn();
        $pdo->prepare("DELETE FROM ai_providers WHERE id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM ai_model_cache WHERE provider_id = ?")->execute([$id]);
        if ($wasDefault) {
            $next = $pdo->query("SELECT id FROM ai_providers ORDER BY sort_order ASC, id ASC LIMIT 1")->fetchColumn();
            if ($next) $pdo->prepare("UPDATE ai_providers SET is_default = 1 WHERE id = ?")->execute([$next]);
        }
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Make one provider the default, in one statement pair.
 *
 * Its own function rather than routing through aiSaveProvider($pdo, ['id'=>..,
 * 'is_default'=>1]): that builds a full row from the draft it is given, so a partial
 * payload would blank the label, endpoint and model of the provider it was meant to
 * promote. A one-field change gets a one-field endpoint.
 *
 * Refuses an id that does not exist, because "UPDATE ... SET is_default = 0" followed by a
 * no-op UPDATE would leave the registry with NO default at all -- the state the panel
 * cannot open in.
 */
function aiProviderSetDefault($pdo, $id) {
    $id = (int)$id;
    try {
        $exists = (int)$pdo->query("SELECT COUNT(*) FROM ai_providers WHERE id = $id")->fetchColumn();
        if (!$exists) return ['success' => false, 'error' => 'No such provider.'];

        $pdo->exec("UPDATE ai_providers SET is_default = 0");
        $pdo->prepare("UPDATE ai_providers SET is_default = 1 WHERE id = ?")->execute([$id]);
        return ['success' => true, 'id' => $id];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/* ====================================================================================
 * HTTP
 * ==================================================================================== */

/**
 * One curl call. $onChunk non-null switches to streaming: curl hands us bytes as they
 * arrive and we never buffer the body.
 */
function aiHttp($method, $url, $headers, $body = null, $timeout = AI_HTTP_TIMEOUT, $onChunk = null) {
    $ch = curl_init($url);
    $hdr = [];
    foreach ($headers as $k => $v) $hdr[] = "$k: $v";

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(15, $timeout));
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    // On the streaming path the body used to be discarded entirely ('body' => ''), because
    // curl hands every byte to the write callback instead of returning it. That made EVERY
    // streamed failure mute: the panel said only "Request failed (HTTP 400)" while the
    // upstream had sent a perfectly good explanation, and both of the adapter-level retries
    // (max_completion_tokens, systemInstruction) inspect $res['body'] and so could never
    // fire on a streamed request -- they worked in the wizard's non-streaming probe and
    // nowhere else. That is exactly how "Provider test: OK" sat one line above
    // "Chat failed: Request failed (HTTP 400)" in ai.log.
    //
    // So: when the status line says this is an error, buffer the body instead of feeding it
    // to the SSE parser. An error body is not event-stream framed anyway, so forwarding it
    // was never right.
    $errBody = '';
    if ($onChunk) {
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use ($onChunk, &$errBody) {
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($code >= 400) {
                // Bounded: a hostile or broken endpoint must not be able to grow this
                // without limit, and no useful error message is anywhere near 64 KB.
                if (strlen($errBody) < 65536) $errBody .= $chunk;
                return strlen($chunk);
            }
            $onChunk($chunk);
            return strlen($chunk);
        });
    } else {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    }

    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [
        'ok'     => ($err === '' && $code >= 200 && $code < 300),
        'code'   => $code,
        'body'   => $onChunk ? $errBody : (string)$res,
        'error'  => $err,
    ];
}

/**
 * Pull a human-usable message out of whatever shape the provider returned.
 *
 * This matters more than it looks: #83 was diagnosed from the provider's own error text
 * ("This model ... is no longer available"). A generic "request failed" would have cost
 * the reporter and us a round trip, so every error path here works to surface the
 * upstream string verbatim.
 */
function aiErrorText($res, $fallback = 'Request failed') {
    if (!empty($res['error'])) return $res['error'];
    $j = json_decode($res['body'] ?? '', true);
    if (is_array($j)) {
        foreach ([['error', 'message'], ['error'], ['message'], ['detail']] as $path) {
            $v = $j;
            foreach ($path as $seg) {
                if (!is_array($v) || !isset($v[$seg])) { $v = null; break; }
                $v = $v[$seg];
            }
            if (is_string($v) && $v !== '') return $v;
        }
    }
    $body = trim((string)($res['body'] ?? ''));
    if ($body !== '') return substr($body, 0, 400);
    return $fallback . ($res['code'] ? ' (HTTP ' . $res['code'] . ')' : '');
}

function aiAuthHeaders($provider) {
    $kind = $provider['kind'];
    $key  = $provider['api_key'] ?? '';
    $h    = ['Content-Type' => 'application/json'];

    if ($kind === 'anthropic') {
        if ($key !== '') $h['x-api-key'] = $key;
        $h['anthropic-version'] = '2023-06-01';
    } elseif ($kind === 'gemini') {
        // Header form rather than ?key= so the credential never lands in an access log.
        if ($key !== '') $h['x-goog-api-key'] = $key;
    } else {
        // openai_compatible: Bearer covers OpenAI, vLLM --api-key, LM Studio, OpenRouter,
        // Groq, and an authenticating proxy in front of Ollama's /v1 endpoint.
        if ($key !== '') $h['Authorization'] = 'Bearer ' . $key;
    }

    foreach (($provider['headers'] ?? []) as $k => $v) $h[$k] = $v;
    return $h;
}

/* ====================================================================================
 * Live model discovery
 * ==================================================================================== */

/**
 * Ask the provider what it actually has. Returns
 *   ['success'=>bool, 'models'=>[['id','label','context','notes']], 'error'=>string]
 *
 * Normalised across four wire formats so the picker renders one way.
 */
function aiDiscoverModels($provider) {
    $base = rtrim($provider['base_url'], '/');
    $kind = $provider['kind'];
    $h    = aiAuthHeaders($provider);

    switch ($kind) {
        case 'anthropic':  $url = "$base/v1/models?limit=1000"; break;
        case 'gemini':     $url = "$base/v1beta/models?pageSize=1000"; break;
        default:           $url = "$base/models"; break;
    }

    $res = aiHttp('GET', $url, $h, null, AI_DISCOVERY_TIMEOUT);
    if (!$res['ok']) {
        return ['success' => false, 'models' => [], 'error' => aiErrorText($res, 'Could not reach the provider')];
    }

    $j = json_decode($res['body'], true);
    if (!is_array($j)) {
        return ['success' => false, 'models' => [], 'error' => 'Provider returned a non-JSON model list'];
    }

    $models = [];

    if ($kind === 'gemini') {
        foreach (($j['models'] ?? []) as $m) {
            // A Gemini "model" may be an embedder or a tuner. Only things that can
            // actually answer generateContent belong in a chat picker.
            $methods = $m['supportedGenerationMethods'] ?? [];
            if ($methods && !in_array('generateContent', $methods, true)) continue;
            $id = preg_replace('#^models/#', '', $m['name'] ?? '');
            if ($id === '') continue;
            $models[] = [
                'id'      => $id,
                'label'   => $m['displayName'] ?? $id,
                'context' => isset($m['inputTokenLimit']) ? (int)$m['inputTokenLimit'] : null,
                'notes'   => !empty($m['thinking']) ? 'thinking' : '',
            ];
        }
    } elseif ($kind === 'anthropic') {
        foreach (($j['data'] ?? []) as $m) {
            if (empty($m['id'])) continue;
            $models[] = [
                'id'      => $m['id'],
                'label'   => $m['display_name'] ?? $m['id'],
                'context' => isset($m['max_input_tokens']) ? (int)$m['max_input_tokens'] : null,
                'notes'   => '',
            ];
        }
    } else {
        // OpenAI-compatible. OpenAI itself returns {data:[{id,...}]}; some gateways
        // (OpenRouter) return richer rows; a few local servers return a bare array.
        $list = $j['data'] ?? (isset($j[0]) ? $j : []);
        foreach ($list as $m) {
            $id = is_array($m) ? ($m['id'] ?? '') : (string)$m;
            if ($id === '') continue;
            $ctx = null;
            foreach (['context_length', 'max_model_len', 'context_window'] as $k) {
                if (isset($m[$k])) { $ctx = (int)$m[$k]; break; }
            }
            $models[] = [
                'id'      => $id,
                'label'   => (is_array($m) && !empty($m['name'])) ? $m['name'] : $id,
                'context' => $ctx,
                'notes'   => '',
            ];
        }
    }

    usort($models, function ($a, $b) { return strcasecmp($a['id'], $b['id']); });

    if (!$models) {
        return ['success' => false, 'models' => [], 'error' => 'The provider reported no usable chat models'];
    }
    return ['success' => true, 'models' => $models, 'error' => ''];
}

/**
 * Discovery with a DB cache. $force bypasses it.
 *
 * A failure here is reported but never fatal: the caller still gets the pinned model
 * so the operator can keep chatting while, say, OpenAI has a bad afternoon.
 */
function aiCachedModels($pdo, $provider, $force = false) {
    $id = (int)$provider['id'];

    if (!$force) {
        try {
            $stmt = $pdo->prepare("SELECT models_json, error, UNIX_TIMESTAMP(fetched_at) AS ts
                                   FROM ai_model_cache WHERE provider_id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && (time() - (int)$row['ts']) < AI_MODEL_CACHE_SECONDS) {
                $models = json_decode((string)$row['models_json'], true);
                if (is_array($models) && $models) {
                    return ['success' => true, 'models' => $models, 'error' => '', 'cached' => true];
                }
            }
        } catch (Exception $e) { /* fall through to a live fetch */ }
    }

    $res = aiDiscoverModels($provider);

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO ai_model_cache (provider_id, models_json, error, fetched_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE models_json = VALUES(models_json),
                                     error       = VALUES(error),
                                     fetched_at  = VALUES(fetched_at)"
        );
        $stmt->execute([$id, $res['success'] ? json_encode($res['models']) : null, substr($res['error'], 0, 500)]);
    } catch (Exception $e) { /* cache write is best effort */ }

    $res['cached'] = false;
    return $res;
}

/**
 * Wizard step 4. Proves the endpoint, the credential AND the chosen model, separately,
 * so a failure names which of the three is wrong instead of "connection failed".
 */
/**
 * Append one line to /opt/stateful/logs/ai.log.
 *
 * Until this existed the AI Helper was the only subsystem in the product that wrote to no
 * log at all: every failure -- bad key, refused model, discovery error -- was returned as
 * JSON to the browser and left nothing behind. When an operator reported "I tried to set up
 * Gemini and it failed", the server could not say what had happened, and the three POSTs in
 * php.log were all HTTP 200 because a rejection is a successful request carrying
 * success:false. Diagnosis had to wait for a screenshot.
 *
 * Never log the key, the endpoint's credentials, or the body of a conversation.
 */
function aiLog($event, $detail = '') {
    $dir = defined('AI_LOG_DIR') ? AI_LOG_DIR : '/opt/stateful/logs';
    if (!is_dir($dir)) return;
    $line = sprintf("%s [%s] %s%s\n", date('D M j H:i:s T Y'), $event, $detail, '');
    @file_put_contents($dir . '/ai.log', $line, FILE_APPEND | LOCK_EX);
}

function aiTestProvider($provider) {
    $steps = [];

    $disc = aiDiscoverModels($provider);
    aiLog($disc['success'] ? 'NOTICE' : 'ERROR', sprintf(
        'Provider test: %s at %s — discovery %s',
        $provider['kind'] ?? '?', $provider['base_url'] ?? '?',
        $disc['success'] ? count($disc['models']) . ' models' : 'FAILED: ' . $disc['error']
    ));
    $steps[] = [
        'name'   => 'Model discovery',
        'ok'     => $disc['success'],
        'detail' => $disc['success'] ? count($disc['models']) . ' models available' : $disc['error'],
    ];

    $model = trim($provider['model'] ?? '');
    if ($model === '' && $disc['success']) {
        // NEVER auto-pick. This used to probe with $disc['models'][0]['id'] -- "whatever
        // the provider listed first" -- which is issue #83 in a new costume: code choosing
        // a model on the operator's behalf and then reporting the arbitrary choice's
        // failure as if the account were broken.
        //
        // Google's /v1beta/models is not ordered by preference. On a real paid account the
        // first entry was `antigravity-preview-05-2026`, an internal preview that rejects
        // systemInstruction outright -- so a perfectly good key produced a red
        // "Developer instruction is not enabled for models/antigravity-preview-05-2026"
        // and no way to tell that the key was fine. The wizard now asks for the model
        // BEFORE this step, so reaching here with none is a flow error, not a guess to make.
        $steps[] = [
            'name'   => 'Model',
            'ok'     => false,
            'detail' => 'No model selected — go Back and choose one from the list.',
        ];
        aiLog('ERROR', 'Provider test reached the chat step with no model selected');
        return ['success' => false, 'steps' => $steps, 'models' => $disc['models']];
    } elseif ($model !== '' && $disc['success']) {
        $ids = array_column($disc['models'], 'id');
        if (in_array($model, $ids, true)) {
            $steps[] = ['name' => 'Model', 'ok' => true, 'detail' => "$model is available"];
        } else {
            // A WARNING, not a rewrite. Some gateways under-report their catalogue, and
            // the operator may legitimately know better than /models does. 2.44 silently
            // substituted here; that substitution is what issue #83 actually experienced.
            $steps[] = [
                'name'   => 'Model',
                'ok'     => true,
                'warn'   => true,
                'detail' => "$model was not in the provider's list — it will still be used as entered",
            ];
        }
    }

    if ($model === '') {
        $steps[] = ['name' => 'Chat round trip', 'ok' => false, 'detail' => 'No model to test with'];
        return ['success' => false, 'steps' => $steps];
    }

    $probe = $provider;
    $probe['model'] = $model;

    // 512, not 16.
    //
    // On a reasoning model the output budget covers THINKING as well as the reply, so a
    // 16-token probe is spent before a single visible character is produced: the call
    // succeeds, the content is empty, and the wizard shows a passing step reading
    // "Replied:" with nothing after it. Measured against live Gemini 3 models, which
    // burned ~150 thinking tokens answering a one-word question.
    $chat = aiChat($probe, [['role' => 'user', 'content' => 'Reply with the single word: OK']],
                   'You are a connectivity probe. Reply with one word.', [], null, 512);

    $reply = trim($chat['content'] ?? '');
    if (!$chat['success']) {
        $steps[] = ['name' => 'Chat round trip', 'ok' => false, 'detail' => $chat['error']];
    } elseif ($reply === '') {
        // Reached the model and got a well-formed empty answer. Usable, but say so
        // plainly rather than rendering a blank success.
        $steps[] = [
            'name'   => 'Chat round trip',
            'ok'     => true,
            'warn'   => true,
            'detail' => 'Connected, but the model returned no text. This is usually a reasoning model spending its budget on thinking; it should still work normally in the panel.',
        ];
    } else {
        $steps[] = ['name' => 'Chat round trip', 'ok' => true, 'detail' => 'Replied: ' . substr($reply, 0, 60)];
    }

    $ok = true;
    foreach ($steps as $s) { if (!$s['ok']) $ok = false; }
    aiLog($ok ? 'NOTICE' : 'ERROR', sprintf(
        'Provider test: %s with model %s — %s',
        $provider['kind'] ?? '?', $model,
        $ok ? 'OK' : 'FAILED: ' . ($chat['error'] ?? 'see steps')
    ));
    return ['success' => $ok, 'steps' => $steps, 'models' => $disc['models']];
}

/* ====================================================================================
 * Unified chat
 * ==================================================================================== */

/**
 * Internal message shape (OpenAI-ish, converted per adapter):
 *   ['role' => 'user'|'assistant'|'tool', 'content' => string,
 *    'tool_calls' => [['id','name','arguments'(array)]],   // assistant turns
 *    'tool_call_id' => string, 'name' => string]           // tool result turns
 *
 * Returns ['success', 'content', 'tool_calls', 'usage', 'error', 'model'].
 * $onDelta, when given, is called with each text fragment as it streams.
 */
function aiChat($provider, $messages, $system, $tools = [], $onDelta = null, $maxTokens = 4096) {
    $model = trim($provider['model'] ?? '');
    if ($model === '') {
        return ['success' => false, 'error' => 'No model selected for this provider', 'content' => '', 'tool_calls' => []];
    }

    switch ($provider['kind']) {
        case 'anthropic': $r = aiChatAnthropic($provider, $model, $messages, $system, $tools, $onDelta, $maxTokens); break;
        case 'gemini':    $r = aiChatGemini($provider, $model, $messages, $system, $tools, $onDelta, $maxTokens); break;
        default:          $r = aiChatOpenAI($provider, $model, $messages, $system, $tools, $onDelta, $maxTokens);
    }

    // Single choke point for every upstream call, so no adapter can fail quietly. Only the
    // failure is recorded -- logging successful turns would put conversation content on disk.
    if (empty($r['success'])) {
        aiLog('ERROR', sprintf('Chat failed: %s/%s — %s',
            $provider['kind'] ?? '?', $model, $r['error'] ?? 'unknown error'));
    }
    return $r;
}

/** Accumulates SSE bytes and hands whole `data:` payloads to a callback. */
function aiSseReader($onEvent) {
    $buf = '';
    return function ($chunk) use (&$buf, $onEvent) {
        $buf .= $chunk;
        // Providers terminate events with \n\n; some proxies normalise to \r\n\r\n.
        $buf = str_replace("\r\n", "\n", $buf);
        while (($pos = strpos($buf, "\n\n")) !== false) {
            $block = substr($buf, 0, $pos);
            $buf   = substr($buf, $pos + 2);
            foreach (explode("\n", $block) as $line) {
                if (strpos($line, 'data:') !== 0) continue;
                $payload = trim(substr($line, 5));
                if ($payload === '' || $payload === '[DONE]') continue;
                $onEvent($payload);
            }
        }
    };
}

// ---- OpenAI-compatible ---------------------------------------------------------------

function aiChatOpenAI($provider, $model, $messages, $system, $tools, $onDelta, $maxTokens) {
    $msgs = [];
    if ($system !== '') $msgs[] = ['role' => 'system', 'content' => $system];

    foreach ($messages as $m) {
        if ($m['role'] === 'tool') {
            $msgs[] = ['role' => 'tool', 'tool_call_id' => $m['tool_call_id'], 'content' => $m['content']];
        } elseif (!empty($m['tool_calls'])) {
            $calls = [];
            foreach ($m['tool_calls'] as $c) {
                $calls[] = [
                    'id'       => $c['id'],
                    'type'     => 'function',
                    // (object) CAST IS LOAD-BEARING.
                    //
                    // A no-argument tool call carries an empty PHP array, and json_encode([])
                    // is "[]" -- a JSON *list*. The spec says `arguments` is an object, and a
                    // strict gateway enforces it: LiteLLM parses the string and calls .items()
                    // on the result, so replaying get_system_health() produced
                    //
                    //   litellm.InternalServerError: 'list' object has no attribute 'items'
                    //
                    // right after the tool ran, on every question. OpenAI itself accepts "[]",
                    // which is why this survived testing against api.openai.com and only
                    // showed up through a gateway. Same trap as the Gemini `args` fix below;
                    // this path was missed when that one was made.
                    'function' => ['name' => $c['name'], 'arguments' => json_encode((object)$c['arguments'])],
                ];
            }
            $msgs[] = ['role' => 'assistant', 'content' => $m['content'] ?: null, 'tool_calls' => $calls];
        } else {
            $msgs[] = ['role' => $m['role'], 'content' => $m['content']];
        }
    }

    // ---- capability negotiation -------------------------------------------------------
    //
    // The /chat/completions dialect is not one dialect. The same endpoint answers for many
    // models and each can refuse a different part of the request:
    //
    //   completion_tokens  "Unsupported parameter: 'max_tokens' is not supported with this
    //                       model. Use 'max_completion_tokens' instead."
    //   no_reasoning       "Function tools with reasoning_effort are not supported for
    //                       <model> in /v1/chat/completions. To use function tools, use
    //                       /v1/responses or set reasoning_effort to 'none'."
    //
    // None of this is discoverable from /models, and we are not allowed to keep a table of
    // model ids — that IS issue #83. So the request negotiates: send the plain form, read
    // what the endpoint objects to, apply that one adjustment, try again. Bounded by the
    // number of known quirks, and each may be applied at most once, so a refusal we do not
    // recognise ends the loop immediately rather than spinning.
    //
    // This was shipped twice as a pair of one-off retries and grew a third case within the
    // day; a loop is the honest shape for it. It is also not OpenAI-only — every gateway
    // behind openai_compatible mirrors whichever quirks its upstream has.
    // no_tools is the last resort and behaves differently from the others: instead of
    // adjusting a parameter it DROPS the whole tool surface. An operator running a small
    // local model on an endpoint that has never implemented function calling would
    // otherwise get a hard 400 and no assistant at all. Answering without tools is worse
    // than answering with them, and far better than not answering.
    $quirks = ['completion_tokens' => false, 'no_reasoning' => false, 'no_tools' => false];

    $mkPayload = function ($q) use ($model, $msgs, $tools, $maxTokens, $onDelta) {
        $p = ['model' => $model, 'messages' => $msgs];
        $p[$q['completion_tokens'] ? 'max_completion_tokens' : 'max_tokens'] = $maxTokens;
        if ($tools && !$q['no_tools']) {
            $p['tools'] = array_map(function ($t) {
                return ['type' => 'function', 'function' => $t];
            }, $tools);
            // Only ever sent as a REMEDY. Sending reasoning_effort unprompted would itself
            // be rejected by every model that has never heard of it.
            if ($q['no_reasoning']) $p['reasoning_effort'] = 'none';
        }
        if ($onDelta) { $p['stream'] = true; $p['stream_options'] = ['include_usage' => true]; }
        return $p;
    };

    // Match on the parameter NAME the endpoint complains about, not the prose around it:
    // the wording differs between OpenAI proper and the gateways that proxy it, but every
    // one of them names the field.
    $diagnose = function ($res) {
        $b = $res['body'] ?? '';
        if ($b === '') return null;
        if (stripos($b, 'max_completion_tokens') !== false && stripos($b, 'max_tokens') !== false) {
            return 'completion_tokens';
        }
        if (stripos($b, 'reasoning_effort') !== false) return 'no_reasoning';

        // The endpoint does not do function calling at all. Checked LAST, and only when
        // the complaint names tools/functions, so a 400 about something else never
        // silently strips the entire tool surface -- that would turn a fixable parameter
        // problem into a permanently crippled assistant.
        if (stripos($b, 'tool') !== false || stripos($b, 'function call') !== false
            || stripos($b, 'function_call') !== false) {
            if (preg_match('/(not support|unsupported|unrecognized|unrecognised|unknown|invalid|do(es)? not accept|no such)/i', $b)) {
                return 'no_tools';
            }
        }
        return null;
    };

    $url = rtrim($provider['base_url'], '/') . '/chat/completions';
    $h   = aiAuthHeaders($provider);

    if (!$onDelta) {
        $res = null;
        for ($try = 0; $try <= count($quirks); $try++) {
            $res = aiHttp('POST', $url, $h, json_encode($mkPayload($quirks)));
            if ($res['ok']) break;
            $q = $diagnose($res);
            if ($q === null || $quirks[$q]) break;   // unrecognised, or already tried
            $quirks[$q] = true;
        }
        if (!$res['ok']) return ['success' => false, 'error' => aiErrorText($res), 'content' => '', 'tool_calls' => []];
        $j   = json_decode($res['body'], true);
        $msg = $j['choices'][0]['message'] ?? [];
        $calls = [];
        foreach (($msg['tool_calls'] ?? []) as $c) {
            $calls[] = [
                'id'        => $c['id'] ?? uniqid('call_'),
                'name'      => $c['function']['name'] ?? '',
                'arguments' => json_decode($c['function']['arguments'] ?? '{}', true) ?: [],
            ];
        }
        return [
            'success'    => true,
            'content'    => (string)($msg['content'] ?? ''),
            'tool_calls' => $calls,
            'usage'      => $j['usage'] ?? null,
            'model'      => $j['model'] ?? $model,
            // The caller needs to know the answer was produced WITHOUT tools, so it can
            // say so rather than presenting a guess as an investigation.
            'tools_dropped' => $tools && $quirks['no_tools'],
        ];
    }

    $text = ''; $calls = []; $usage = null; $err = null;
    $reader = aiSseReader(function ($payload) use (&$text, &$calls, &$usage, &$err, $onDelta) {
        $d = json_decode($payload, true);
        if (!is_array($d)) return;
        if (isset($d['error'])) { $err = $d['error']['message'] ?? 'stream error'; return; }
        if (isset($d['usage'])) $usage = $d['usage'];
        $delta = $d['choices'][0]['delta'] ?? [];
        if (!empty($delta['content'])) { $text .= $delta['content']; $onDelta($delta['content']); }
        // Tool calls stream as fragments indexed by position; arguments arrive as a
        // partial JSON string that must be concatenated before it can be parsed.
        foreach (($delta['tool_calls'] ?? []) as $tc) {
            $i = (int)($tc['index'] ?? 0);
            if (!isset($calls[$i])) $calls[$i] = ['id' => '', 'name' => '', 'arguments' => ''];
            if (!empty($tc['id']))                  $calls[$i]['id']         = $tc['id'];
            if (!empty($tc['function']['name']))    $calls[$i]['name']      .= $tc['function']['name'];
            if (isset($tc['function']['arguments'])) $calls[$i]['arguments'] .= $tc['function']['arguments'];
        }
    });

    // Same negotiation as the non-streaming branch. These refusals arrive as a 400 before a
    // single token is generated, so $text is still empty and nothing has reached the browser
    // — the retry is invisible downstream. The accumulators are reset each attempt anyway,
    // so a partial first response could not bleed into the second.
    $res = null;
    for ($try = 0; $try <= count($quirks); $try++) {
        $text = ''; $calls = []; $usage = null; $err = null;
        $res = aiHttp('POST', $url, $h, json_encode($mkPayload($quirks)), AI_HTTP_TIMEOUT, $reader);
        if ($res['ok'] || $text !== '') break;
        $q = $diagnose($res);
        if ($q === null || $quirks[$q]) break;
        $quirks[$q] = true;
    }
    if (!$res['ok'] && $text === '') {
        return ['success' => false, 'error' => $err ?: aiErrorText($res), 'content' => '', 'tool_calls' => []];
    }

    $out = [];
    foreach ($calls as $c) {
        $out[] = [
            'id'        => $c['id'] ?: uniqid('call_'),
            'name'      => $c['name'],
            'arguments' => json_decode($c['arguments'] ?: '{}', true) ?: [],
        ];
    }
    return ['success' => true, 'content' => $text, 'tool_calls' => $out, 'usage' => $usage,
            'model' => $model, 'tools_dropped' => $tools && $quirks['no_tools']];
}

// ---- Anthropic -----------------------------------------------------------------------

function aiChatAnthropic($provider, $model, $messages, $system, $tools, $onDelta, $maxTokens) {
    $msgs = [];
    foreach ($messages as $m) {
        if ($m['role'] === 'tool') {
            $msgs[] = ['role' => 'user', 'content' => [[
                'type'        => 'tool_result',
                'tool_use_id' => $m['tool_call_id'],
                'content'     => $m['content'],
            ]]];
        } elseif (!empty($m['tool_calls'])) {
            $blocks = [];
            if (!empty($m['content'])) $blocks[] = ['type' => 'text', 'text' => $m['content']];
            foreach ($m['tool_calls'] as $c) {
                $blocks[] = ['type' => 'tool_use', 'id' => $c['id'], 'name' => $c['name'], 'input' => (object)$c['arguments']];
            }
            $msgs[] = ['role' => 'assistant', 'content' => $blocks];
        } else {
            $msgs[] = ['role' => $m['role'], 'content' => $m['content']];
        }
    }

    $payload = ['model' => $model, 'max_tokens' => $maxTokens, 'messages' => $msgs];
    if ($system !== '') $payload['system'] = $system;
    if ($tools) {
        $payload['tools'] = array_map(function ($t) {
            return ['name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['parameters']];
        }, $tools);
    }
    if ($onDelta) $payload['stream'] = true;

    $url = rtrim($provider['base_url'], '/') . '/v1/messages';
    $h   = aiAuthHeaders($provider);

    if (!$onDelta) {
        $res = aiHttp('POST', $url, $h, json_encode($payload));
        if (!$res['ok']) return ['success' => false, 'error' => aiErrorText($res), 'content' => '', 'tool_calls' => []];
        $j = json_decode($res['body'], true);
        $text = ''; $calls = [];
        foreach (($j['content'] ?? []) as $b) {
            if (($b['type'] ?? '') === 'text')     $text .= $b['text'];
            if (($b['type'] ?? '') === 'tool_use') $calls[] = ['id' => $b['id'], 'name' => $b['name'], 'arguments' => (array)($b['input'] ?? [])];
        }
        return ['success' => true, 'content' => $text, 'tool_calls' => $calls, 'usage' => $j['usage'] ?? null, 'model' => $j['model'] ?? $model];
    }

    $text = ''; $calls = []; $usage = null; $err = null; $cur = null;
    $reader = aiSseReader(function ($payload) use (&$text, &$calls, &$usage, &$err, &$cur, $onDelta) {
        $d = json_decode($payload, true);
        if (!is_array($d)) return;
        $t = $d['type'] ?? '';
        if ($t === 'error') { $err = $d['error']['message'] ?? 'stream error'; return; }
        if ($t === 'content_block_start') {
            $b = $d['content_block'] ?? [];
            if (($b['type'] ?? '') === 'tool_use') {
                $cur = ['id' => $b['id'] ?? uniqid('call_'), 'name' => $b['name'] ?? '', 'json' => ''];
            }
        } elseif ($t === 'content_block_delta') {
            $delta = $d['delta'] ?? [];
            if (($delta['type'] ?? '') === 'text_delta') {
                $text .= $delta['text']; $onDelta($delta['text']);
            } elseif (($delta['type'] ?? '') === 'input_json_delta' && $cur !== null) {
                $cur['json'] .= $delta['partial_json'] ?? '';
            }
        } elseif ($t === 'content_block_stop') {
            if ($cur !== null) {
                $calls[] = ['id' => $cur['id'], 'name' => $cur['name'], 'arguments' => json_decode($cur['json'] ?: '{}', true) ?: []];
                $cur = null;
            }
        } elseif ($t === 'message_delta' && isset($d['usage'])) {
            $usage = $d['usage'];
        }
    });

    $res = aiHttp('POST', $url, $h, json_encode($payload), AI_HTTP_TIMEOUT, $reader);
    if (!$res['ok'] && $text === '') {
        return ['success' => false, 'error' => $err ?: aiErrorText($res), 'content' => '', 'tool_calls' => []];
    }
    return ['success' => true, 'content' => $text, 'tool_calls' => $calls, 'usage' => $usage, 'model' => $model];
}

// ---- Gemini --------------------------------------------------------------------------

function aiChatGemini($provider, $model, $messages, $system, $tools, $onDelta, $maxTokens) {
    $contents = [];
    foreach ($messages as $m) {
        if ($m['role'] === 'tool') {
            $contents[] = ['role' => 'user', 'parts' => [[
                'functionResponse' => [
                    'name'     => $m['name'] ?? 'tool',
                    // Gemini wants an object here; wrap a plain string result.
                    'response' => ['result' => $m['content']],
                ],
            ]]];
        } elseif (!empty($m['provider_raw'])) {
            // REPLAY THE MODEL TURN VERBATIM.
            //
            // Gemini 3 attaches a `thoughtSignature` to parts -- an encrypted handle on
            // its internal reasoning -- and REQUIRES it back on functionCall parts for a
            // tool loop to continue. Reconstructing the part from name+args drops it, and
            // the second round trip dies with:
            //
            //   Function call is missing a thought_signature in functionCall parts.
            //
            // So tool calling was broken on every current Gemini model. It is invisible
            // against an OpenAI-shaped mock, because no other provider has this field.
            //
            // Google's own guidance is to return the entire response unmodified, which is
            // what this does -- and it stays correct if Google adds further part types or
            // attaches signatures to more of them.
            $contents[] = ['role' => 'model', 'parts' => $m['provider_raw']];
        } elseif (!empty($m['tool_calls'])) {
            // No raw parts captured (a non-Gemini turn replayed onto Gemini, or history
            // rebuilt from the browser). Reconstruct; fine for 2.5-era models, and the
            // only thing we can do.
            $parts = [];
            if (!empty($m['content'])) $parts[] = ['text' => $m['content']];
            foreach ($m['tool_calls'] as $c) {
                $parts[] = ['functionCall' => ['name' => $c['name'], 'args' => (object)$c['arguments']]];
            }
            $contents[] = ['role' => 'model', 'parts' => $parts];
        } else {
            $contents[] = [
                'role'  => ($m['role'] === 'assistant') ? 'model' : 'user',
                'parts' => [['text' => $m['content']]],
            ];
        }
    }

    // Not every model Google lists accepts a systemInstruction. The preview tiers reject it
    // with "Developer instruction is not enabled for models/<id>", a 400 -- so the grounding
    // prompt, which is the entire reason this helper is worth anything, would take the whole
    // request down. $foldSystem replays the same call with the system text prepended to the
    // first user turn instead, which every generateContent model accepts. Tried only after
    // the proper field has been refused, never speculatively.
    $mkPayload = function ($foldSystem) use ($contents, $system, $tools, $maxTokens) {
        $c = $contents;
        if ($system !== '' && $foldSystem) {
            foreach ($c as $i => $turn) {
                if (($turn['role'] ?? '') === 'user' && isset($turn['parts'][0]['text'])) {
                    $c[$i]['parts'][0]['text'] = $system . "\n\n" . $turn['parts'][0]['text'];
                    break;
                }
            }
        }
        $p = ['contents' => $c, 'generationConfig' => ['maxOutputTokens' => $maxTokens]];
        if ($system !== '' && !$foldSystem) $p['systemInstruction'] = ['parts' => [['text' => $system]]];
        if ($tools) {
            $p['tools'] = [['functionDeclarations' => array_map(function ($t) {
                return ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['parameters']];
            }, $tools)]];
        }
        return $p;
    };
    $noSystemInstruction = function ($res) {
        return stripos($res['body'] ?? '', 'Developer instruction is not enabled') !== false;
    };

    $base = rtrim($provider['base_url'], '/');
    // The model id is passed through EXACTLY as discovery reported it. No normalising,
    // no "models/" prefix guessing beyond stripping a duplicate, no fallback constant.
    $mid  = preg_replace('#^models/#', '', $model);
    $verb = $onDelta ? 'streamGenerateContent?alt=sse' : 'generateContent';
    $url  = "$base/v1beta/models/" . rawurlencode($mid) . ":$verb";
    $h    = aiAuthHeaders($provider);

    if (!$onDelta) {
        $res = aiHttp('POST', $url, $h, json_encode($mkPayload(false)));
        if (!$res['ok'] && $noSystemInstruction($res)) $res = aiHttp('POST', $url, $h, json_encode($mkPayload(true)));
        if (!$res['ok']) return ['success' => false, 'error' => aiErrorText($res), 'content' => '', 'tool_calls' => []];
        $j = json_decode($res['body'], true);
        $text = ''; $calls = [];
        // Kept verbatim so the turn can be replayed with its thoughtSignatures intact.
        //
        // Decoded a SECOND time WITHOUT assoc, as stdClass. This is not redundancy: an
        // empty JSON object decodes to an empty PHP array under assoc=true, which is
        // indistinguishable from an empty list, and json_encode then emits `[]`. A
        // no-argument tool call has `"args": {}`, so replaying it produced `"args": []`
        // and Gemini rejected the turn with:
        //
        //   Unknown name "args" ... Proto field is not repeating, cannot start list.
        //
        // Objects round-trip through json_encode exactly; associative arrays do not.
        $rawObj = json_decode($res['body']);
        $raw    = $rawObj->candidates[0]->content->parts ?? [];
        foreach (($j['candidates'][0]['content']['parts'] ?? []) as $p) {
            if (isset($p['text']))         $text .= $p['text'];
            if (isset($p['functionCall'])) $calls[] = ['id' => uniqid('call_'), 'name' => $p['functionCall']['name'], 'arguments' => (array)($p['functionCall']['args'] ?? [])];
        }
        return ['success' => true, 'content' => $text, 'tool_calls' => $calls, 'usage' => $j['usageMetadata'] ?? null, 'model' => $model, 'provider_raw' => $raw];
    }

    $text = ''; $calls = []; $usage = null; $raw = [];
    $reader = aiSseReader(function ($payload) use (&$text, &$calls, &$usage, &$raw, $onDelta) {
        $d = json_decode($payload, true);
        if (!is_array($d)) return;
        if (isset($d['usageMetadata'])) $usage = $d['usageMetadata'];

        // Accumulate every part as received, as stdClass -- see the long note on the
        // non-streaming path for why the object form is load-bearing rather than fussy.
        // Streamed text arrives as many small parts; concatenating them on replay is
        // equivalent, and a signature attached to any one of them survives.
        $dObj = json_decode($payload);
        foreach (($dObj->candidates[0]->content->parts ?? []) as $pObj) $raw[] = $pObj;

        foreach (($d['candidates'][0]['content']['parts'] ?? []) as $p) {
            if (isset($p['text']))         { $text .= $p['text']; $onDelta($p['text']); }
            if (isset($p['functionCall'])) { $calls[] = ['id' => uniqid('call_'), 'name' => $p['functionCall']['name'], 'arguments' => (array)($p['functionCall']['args'] ?? [])]; }
        }
    });

    $res = aiHttp('POST', $url, $h, json_encode($mkPayload(false)), AI_HTTP_TIMEOUT, $reader);
    // Nothing has been emitted to the browser yet when the refusal is a 400, so the retry
    // is invisible downstream -- there is no half-streamed answer to reconcile.
    if (!$res['ok'] && $text === '' && $noSystemInstruction($res)) {
        $res = aiHttp('POST', $url, $h, json_encode($mkPayload(true)), AI_HTTP_TIMEOUT, $reader);
    }
    if (!$res['ok'] && $text === '') {
        return ['success' => false, 'error' => aiErrorText($res), 'content' => '', 'tool_calls' => []];
    }
    return ['success' => true, 'content' => $text, 'tool_calls' => $calls, 'usage' => $usage, 'model' => $model, 'provider_raw' => $raw];
}

