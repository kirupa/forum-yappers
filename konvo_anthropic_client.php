<?php

/*
 * Shared LLM transport. Currently routed to OpenAI.
 *
 * The filename is historical: every worker already requires this file and calls
 * konvo_anthropic_chat_json(), so the name is kept to avoid touching 30+ call
 * sites. Treat it as "the LLM client".
 *
 * Call sites pass OpenAI chat-completions-shaped payloads (model, messages,
 * temperature, max_tokens) and read choices[0].message.content, so this is
 * mostly a passthrough. It is not a pure passthrough, because the gpt-5 family
 * rejects payloads that older models accepted:
 *
 *   - max_tokens is refused outright; it must be max_completion_tokens.
 *   - Some models refuse any temperature other than the default. Those same
 *     models spend "reasoning tokens" out of the completion budget, so a small
 *     budget returns an empty string with finish_reason=length rather than an
 *     error. Silently posting that empty string would be the worst outcome, so
 *     it is converted into a failure the callers' existing retry paths handle.
 *
 * Verified against the live API on 2026-08-11:
 *   gpt-5.4, gpt-5.4-mini, gpt-5.4-nano, gpt-5.2  accept temperature, 0 reasoning tokens
 *   gpt-5, gpt-5-mini, gpt-5-nano                 reject temperature, burn reasoning tokens
 */

declare(strict_types=1);

if (!defined('KONVO_LLM_API_KEY')) {
    define('KONVO_LLM_API_KEY', trim((string)getenv('OPENAI_API_KEY')));
}
// Back-compat: workers guard on this constant before running.
if (!defined('KONVO_ANTHROPIC_API_KEY')) {
    define('KONVO_ANTHROPIC_API_KEY', KONVO_LLM_API_KEY);
}
if (!defined('KONVO_LLM_ENDPOINT')) {
    define('KONVO_LLM_ENDPOINT', 'https://api.openai.com/v1/chat/completions');
}

if (!function_exists('konvo_llm_map_model')) {
    function konvo_llm_map_model(string $model): string
    {
        $m = trim($model);
        // Anything left over from the Claude period maps onto the equivalent tier.
        switch ($m) {
            case 'claude-haiku-4-5':  return 'gpt-5.4-nano';
            case 'claude-sonnet-5':   return 'gpt-5.4-mini';
            case 'claude-opus-5':
            case 'claude-fable-5':    return 'gpt-5.4';
        }
        if ($m === '') return 'gpt-5.4-mini';
        return $m;
    }
}

/**
 * Models that refuse a non-default temperature. These are also the ones that
 * consume completion budget on hidden reasoning tokens.
 */
if (!function_exists('konvo_llm_is_strict_model')) {
    function konvo_llm_is_strict_model(string $model): bool
    {
        $m = strtolower(trim($model));
        if (preg_match('/^(o1|o3|o4)\b/', $m)) return true;
        // gpt-5, gpt-5-mini, gpt-5-nano and dated variants of those, but NOT
        // gpt-5.1 / 5.2 / 5.4 / 5.5, which behave like normal chat models.
        return (bool)preg_match('/^gpt-5(?:-(?:mini|nano|chat-latest))?(?:-\d{4}-\d{2}-\d{2})?$/', $m);
    }
}

if (!function_exists('konvo_llm_chat_json')) {
    function konvo_llm_chat_json(array $payload, int $timeoutSeconds = 60): array
    {
        if (KONVO_LLM_API_KEY === '') {
            return array('ok' => false, 'error' => 'OPENAI_API_KEY missing.');
        }
        if (!function_exists('curl_init')) {
            return array('ok' => false, 'error' => 'curl_init unavailable.');
        }

        $model = konvo_llm_map_model((string)($payload['model'] ?? ''));
        $strict = konvo_llm_is_strict_model($model);

        $messages = array();
        foreach ((array)($payload['messages'] ?? array()) as $m) {
            if (!is_array($m)) continue;
            $role = (string)($m['role'] ?? '');
            if (!in_array($role, array('system', 'user', 'assistant'), true)) continue;
            $messages[] = array('role' => $role, 'content' => (string)($m['content'] ?? ''));
        }
        if ($messages === array()) {
            return array('ok' => false, 'error' => 'No messages to send.');
        }

        $budget = (int)($payload['max_completion_tokens'] ?? ($payload['max_tokens'] ?? 2048));
        if ($budget < 1) $budget = 2048;
        // Reasoning models spend the budget before writing anything, so give
        // them room or the reply comes back empty.
        if ($strict) $budget = max($budget + 512, 1024);

        $body = array(
            'model' => $model,
            'messages' => $messages,
            'max_completion_tokens' => $budget,
        );
        if (!$strict && isset($payload['temperature'])) {
            $body['temperature'] = (float)$payload['temperature'];
        }
        foreach (array('response_format', 'top_p', 'presence_penalty', 'frequency_penalty') as $k) {
            if (!$strict && isset($payload[$k])) $body[$k] = $payload[$k];
        }

        $ch = curl_init(KONVO_LLM_ENDPOINT);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . KONVO_LLM_API_KEY,
            ),
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
        ));
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);

        if ($raw === false || $err !== '') {
            return array('ok' => false, 'error' => ($err !== '' ? $err : 'LLM request failed.'), 'status' => $status);
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return array('ok' => false, 'error' => 'LLM JSON decode failed.', 'raw' => substr((string)$raw, 0, 300), 'status' => $status);
        }
        if ($status < 200 || $status >= 300) {
            $msg = isset($decoded['error']['message']) ? (string)$decoded['error']['message'] : ('LLM returned status ' . $status);
            return array('ok' => false, 'error' => $msg, 'body' => $decoded, 'status' => $status);
        }

        $text = (string)($decoded['choices'][0]['message']['content'] ?? '');
        $finish = (string)($decoded['choices'][0]['finish_reason'] ?? '');
        if (trim($text) === '') {
            // Empty because the budget was spent on reasoning, or a refusal.
            // Report it as a failure so the caller retries instead of posting
            // an empty body.
            return array(
                'ok' => false,
                'error' => 'LLM returned empty content (finish_reason=' . ($finish !== '' ? $finish : 'unknown') . ').',
                'status' => $status,
                'body' => $decoded,
            );
        }

        return array('ok' => true, 'body' => $decoded, 'status' => $status);
    }
}

// Every worker calls this name.
if (!function_exists('konvo_anthropic_chat_json')) {
    function konvo_anthropic_chat_json(array $payload, int $timeoutSeconds = 60): array
    {
        return konvo_llm_chat_json($payload, $timeoutSeconds);
    }
}
if (!function_exists('konvo_anthropic_map_model')) {
    function konvo_anthropic_map_model(string $model): string
    {
        return konvo_llm_map_model($model);
    }
}
