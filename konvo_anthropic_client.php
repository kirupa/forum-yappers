<?php

declare(strict_types=1);

if (!defined('KONVO_ANTHROPIC_API_KEY')) {
    define('KONVO_ANTHROPIC_API_KEY', trim((string)getenv('ANTHROPIC_API_KEY')));
}

/**
 * Records the outcome of the most recent API call so workers can tell an
 * unavailable API apart from a merely unusable answer, and skip posting rather
 * than falling back to canned content.
 */
if (!function_exists('konvo_anthropic_last_failure')) {
    function konvo_anthropic_last_failure(?array $set = null): array
    {
        static $last = array('failed' => false, 'status' => 0, 'error' => '');
        if ($set !== null) $last = $set;
        return $last;
    }
}

if (!function_exists('konvo_anthropic_unavailable')) {
    function konvo_anthropic_unavailable(): bool
    {
        $f = konvo_anthropic_last_failure();
        if (empty($f['failed'])) return false;
        $status = (int)($f['status'] ?? 0);
        // 0 covers network/curl failures; 4xx auth, disabled org and quota; 5xx outages.
        if ($status === 0 || $status === 401 || $status === 403 || $status === 429 || $status >= 500) return true;
        $err = strtolower((string)($f['error'] ?? ''));
        foreach (array('disabled', 'credit', 'quota', 'billing', 'suspend', 'rate limit') as $needle) {
            if (strpos($err, $needle) !== false) return true;
        }
        return false;
    }
}

if (!function_exists('konvo_anthropic_map_model')) {
    function konvo_anthropic_map_model(string $model): string
    {
        $m = trim($model);
        switch ($m) {
            case 'gpt-5.4-mini':
                return 'claude-haiku-4-5';
            case 'gpt-5.2':
                return 'claude-sonnet-5';
            case 'gpt-5.4':
                return 'claude-opus-5';
        }
        if (strpos($m, 'claude-') === 0) {
            return $m;
        }
        return 'claude-sonnet-5';
    }
}

/**
 * Accepts an OpenAI chat-completions-shaped payload (model, messages,
 * optional temperature/max_tokens/max_completion_tokens), calls Anthropic's
 * Messages API, and returns an OpenAI-chat-completions-shaped result so
 * every existing call site (`$res['body']['choices'][0]['message']['content']`)
 * keeps working unchanged.
 */
if (!function_exists('konvo_anthropic_chat_json')) {
    function konvo_anthropic_chat_json(array $payload, int $timeoutSeconds = 60): array
    {
        if (KONVO_ANTHROPIC_API_KEY === '') {
            konvo_anthropic_last_failure(array('failed' => true, 'status' => 0, 'error' => 'ANTHROPIC_API_KEY missing.'));
            return array('ok' => false, 'error' => 'ANTHROPIC_API_KEY missing.');
        }
        if (!function_exists('curl_init')) {
            return array('ok' => false, 'error' => 'curl_init unavailable.');
        }

        $model = konvo_anthropic_map_model((string)($payload['model'] ?? ''));

        $system = '';
        $messages = array();
        foreach ((array)($payload['messages'] ?? array()) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = (string)($m['role'] ?? '');
            $content = (string)($m['content'] ?? '');
            if ($role === 'system') {
                $system = ($system !== '' ? $system . "\n\n" : '') . $content;
                continue;
            }
            if ($role !== 'user' && $role !== 'assistant') {
                continue;
            }
            $messages[] = array('role' => $role, 'content' => $content);
        }
        if (empty($messages)) {
            return array('ok' => false, 'error' => 'No user/assistant messages to send.');
        }

        // Default generously: several workers omit max_tokens, and a truncated reply
        // surfaces downstream as "model returned non-JSON content" rather than as an
        // obvious length error.
        $maxTokens = (int)($payload['max_tokens'] ?? ($payload['max_completion_tokens'] ?? 2048));
        if ($maxTokens < 1) {
            $maxTokens = 2048;
        }

        $anthropicPayload = array(
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $messages,
        );
        if ($system !== '') {
            $anthropicPayload['system'] = $system;
        }
        // Opus 5 and Sonnet 5 think adaptively by default; disable it for
        // these short, deterministic-style completions so max_tokens isn't
        // consumed by reasoning instead of the reply text. Haiku doesn't
        // think unless asked, so leave it alone.
        if ($model === 'claude-opus-5' || $model === 'claude-sonnet-5') {
            $anthropicPayload['thinking'] = array('type' => 'disabled');
        }

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'x-api-key: ' . KONVO_ANTHROPIC_API_KEY,
                'anthropic-version: 2023-06-01',
            ),
            CURLOPT_POSTFIELDS => json_encode($anthropicPayload, JSON_UNESCAPED_SLASHES),
        ));
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            konvo_anthropic_last_failure(array('failed' => true, 'status' => 0, 'error' => ($err !== '' ? $err : 'Anthropic request failed.')));
            return array('ok' => false, 'error' => ($err !== '' ? $err : 'Anthropic request failed.'), 'status' => $status);
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return array('ok' => false, 'error' => 'Anthropic JSON decode failed.', 'raw' => (string)$raw, 'status' => $status);
        }

        if ($status < 200 || $status >= 300) {
            $msg = isset($decoded['error']['message']) ? (string)$decoded['error']['message'] : ('Anthropic returned status ' . $status);
            konvo_anthropic_last_failure(array('failed' => true, 'status' => $status, 'error' => $msg));
            return array('ok' => false, 'error' => $msg, 'body' => $decoded, 'status' => $status);
        }

        if (($decoded['stop_reason'] ?? '') === 'refusal') {
            return array('ok' => false, 'error' => 'Anthropic declined the request (refusal).', 'body' => $decoded, 'status' => $status);
        }

        $text = '';
        foreach ((array)($decoded['content'] ?? array()) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= (string)($block['text'] ?? '');
            }
        }

        // Claude wraps JSON answers in a ```json fence where OpenAI returned them bare.
        // Unwrap only when the whole reply is one fenced block that parses as JSON, so a
        // forum post that legitimately contains a code block is never touched.
        $trimmed = trim($text);
        if (preg_match('/\A```[a-zA-Z0-9_-]*\s*\n(.*)\n?```\z/s', $trimmed, $m)) {
            $inner = trim((string)$m[1]);
            if ($inner !== '' && is_array(json_decode($inner, true))) {
                $text = $inner;
            }
        }

        $compatBody = array(
            'choices' => array(
                array('message' => array('role' => 'assistant', 'content' => $text)),
            ),
            'model' => $model,
            'usage' => $decoded['usage'] ?? array(),
        );

        konvo_anthropic_last_failure(array('failed' => false, 'status' => $status, 'error' => ''));
        return array('ok' => true, 'body' => $compatBody, 'status' => $status);
    }
}
