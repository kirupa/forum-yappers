<?php

/*
 * Shared LLM transport. Currently routed to Google Gemini.
 *
 * The filename and konvo_anthropic_chat_json() are historical: 30+ workers
 * already require this file and call that function, so both names are kept.
 * Treat this as "the LLM client".
 *
 * Unlike the OpenAI swap, this is a real translation layer. Call sites speak
 * OpenAI chat-completions and read choices[0].message.content, while Gemini
 * wants a different request and returns a different response:
 *
 *   messages[]                 -> contents[] with role user|model
 *   role "system"              -> systemInstruction (Gemini rejects it inline)
 *   role "assistant"           -> "model"
 *   temperature / max_tokens   -> generationConfig.{temperature,maxOutputTokens}
 *   choices[0].message.content <- candidates[0].content.parts[].text
 *
 * Cost note: Gemini bills thinking tokens at the output rate. gemini-2.5-flash
 * thinks by default, so thinkingConfig.thinkingBudget=0 is sent for the models
 * that accept it. The 3.x lite models do not think and reject the parameter, so
 * it is only sent where it is known to work.
 *
 * Verified against the live API on 2026-08-14:
 *   gemini-3.1-flash-lite, gemini-3.5-flash-lite, gemini-flash-lite-latest  0 thinking tokens
 *   gemini-2.5-flash        14 thinking tokens by default, 0 with thinkingBudget=0
 *   gemini-2.5-flash-lite   404, retired for new users
 */

declare(strict_types=1);

if (!defined('KONVO_LLM_API_KEY')) {
    $k = trim((string)getenv('GEMINI_API_KEY'));
    if ($k === '') $k = trim((string)getenv('GOOGLE_API_KEY'));
    define('KONVO_LLM_API_KEY', $k);
}
// Back-compat: workers guard on this constant before doing any work.
if (!defined('KONVO_ANTHROPIC_API_KEY')) {
    define('KONVO_ANTHROPIC_API_KEY', KONVO_LLM_API_KEY);
}
if (!defined('KONVO_LLM_BASE')) {
    define('KONVO_LLM_BASE', 'https://generativelanguage.googleapis.com/v1beta/models/');
}

if (!function_exists('konvo_llm_map_model')) {
    function konvo_llm_map_model(string $model): string
    {
        $m = trim($model);
        // Anything left over from the OpenAI or Claude periods lands on the
        // equivalent Gemini tier rather than 404ing.
        switch ($m) {
            case 'gpt-5.4-nano':
            case 'claude-haiku-4-5':
                return 'gemini-3.1-flash-lite';
            case 'gpt-5.4-mini':
            case 'gpt-5.2':
            case 'claude-sonnet-5':
                return 'gemini-2.5-flash';
            case 'gpt-5.4':
            case 'claude-opus-5':
            case 'claude-fable-5':
                return 'gemini-2.5-flash';
        }
        if ($m === '') return 'gemini-2.5-flash';
        return $m;
    }
}

/** Only these accept thinkingConfig; the lite models reject it with a 400. */
if (!function_exists('konvo_llm_supports_thinking_budget')) {
    function konvo_llm_supports_thinking_budget(string $model): bool
    {
        return (bool)preg_match('/^gemini-2\.5-(flash|pro)/i', trim($model));
    }
}

if (!function_exists('konvo_llm_chat_json')) {
    function konvo_llm_chat_json(array $payload, int $timeoutSeconds = 60): array
    {
        if (KONVO_LLM_API_KEY === '') {
            return array('ok' => false, 'error' => 'GEMINI_API_KEY missing.');
        }
        if (!function_exists('curl_init')) {
            return array('ok' => false, 'error' => 'curl_init unavailable.');
        }

        $model = konvo_llm_map_model((string)($payload['model'] ?? ''));

        // System turns are collected separately; Gemini takes them as a
        // dedicated systemInstruction rather than as a role inside contents.
        $system = '';
        $contents = array();
        foreach ((array)($payload['messages'] ?? array()) as $m) {
            if (!is_array($m)) continue;
            $role = (string)($m['role'] ?? '');
            $text = (string)($m['content'] ?? '');
            if ($text === '') continue;
            if ($role === 'system') {
                $system = ($system !== '' ? $system . "\n\n" : '') . $text;
                continue;
            }
            if ($role !== 'user' && $role !== 'assistant') continue;
            $contents[] = array(
                'role' => ($role === 'assistant') ? 'model' : 'user',
                'parts' => array(array('text' => $text)),
            );
        }
        if ($contents === array()) {
            return array('ok' => false, 'error' => 'No user/assistant messages to send.');
        }

        $budget = (int)($payload['max_tokens'] ?? ($payload['max_completion_tokens'] ?? 2048));
        if ($budget < 1) $budget = 2048;

        $genCfg = array('maxOutputTokens' => $budget);
        if (isset($payload['temperature'])) {
            $genCfg['temperature'] = (float)$payload['temperature'];
        }
        // Thinking is billed at the output rate, and nothing here needs it.
        if (konvo_llm_supports_thinking_budget($model)) {
            $genCfg['thinkingConfig'] = array('thinkingBudget' => 0);
        }

        $body = array('contents' => $contents, 'generationConfig' => $genCfg);
        if ($system !== '') {
            $body['systemInstruction'] = array('parts' => array(array('text' => $system)));
        }

        $ch = curl_init(KONVO_LLM_BASE . rawurlencode($model) . ':generateContent');
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'x-goog-api-key: ' . KONVO_LLM_API_KEY,
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

        $cand = isset($decoded['candidates'][0]) && is_array($decoded['candidates'][0])
            ? $decoded['candidates'][0]
            : array();
        $text = '';
        foreach ((array)($cand['content']['parts'] ?? array()) as $part) {
            if (is_array($part) && isset($part['text'])) $text .= (string)$part['text'];
        }
        $finish = (string)($cand['finishReason'] ?? '');

        if (trim($text) === '') {
            // Safety block, recitation block, or the budget was exhausted.
            // Surface as a failure so the caller retries rather than posting
            // an empty body.
            $why = $finish !== '' ? $finish : 'unknown';
            if (isset($decoded['promptFeedback']['blockReason'])) {
                $why = 'prompt blocked: ' . (string)$decoded['promptFeedback']['blockReason'];
            }
            return array(
                'ok' => false,
                'error' => 'LLM returned empty content (' . $why . ').',
                'status' => $status,
                'body' => $decoded,
            );
        }

        // Re-shape into what every call site expects.
        $usage = isset($decoded['usageMetadata']) && is_array($decoded['usageMetadata'])
            ? $decoded['usageMetadata']
            : array();
        $compat = array(
            'choices' => array(
                array(
                    'message' => array('role' => 'assistant', 'content' => $text),
                    'finish_reason' => strtolower($finish),
                ),
            ),
            'model' => $model,
            'usage' => array(
                'prompt_tokens' => (int)($usage['promptTokenCount'] ?? 0),
                'completion_tokens' => (int)($usage['candidatesTokenCount'] ?? 0),
                'total_tokens' => (int)($usage['totalTokenCount'] ?? 0),
                'thinking_tokens' => (int)($usage['thoughtsTokenCount'] ?? 0),
            ),
        );
        return array('ok' => true, 'body' => $compat, 'status' => $status);
    }
}

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
