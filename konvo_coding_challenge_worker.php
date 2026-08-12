<?php

/*
 * Browser-callable coding challenge poster.
 *
 * Posts a small, self-contained CSS or JavaScript task that someone can solve
 * and answer directly in a forum reply. No build step, no npm, no CodePen or
 * JSFiddle: if a challenge cannot be answered by pasting a short snippet into
 * a reply, it is the wrong challenge.
 *
 * The answer is revealed ~24h later by konvo_js_quiz_answer_worker.php, which
 * also grades everyone who replied and keeps the shared first-answer
 * leaderboard.
 *
 * Example:
 * https://www.kirupa.com/konvo_coding_challenge_worker.php?key=YOUR_SECRET&dry_run=1
 * https://www.kirupa.com/konvo_coding_challenge_worker.php?key=YOUR_SECRET
 *
 * Suggested cron (a couple of times a week, offset from Spot the Bug):
 * 0 15 * * 2,5 /usr/bin/curl -fsS "https://www.kirupa.com/konvo_coding_challenge_worker.php?key=YOUR_SECRET"
 */

declare(strict_types=1);

require_once __DIR__ . '/konvo_anthropic_client.php';
require_once __DIR__ . '/konvo_code_format_helper.php';

header('Content-Type: application/json; charset=utf-8');

$signatureHelper = __DIR__ . '/konvo_signature_helper.php';
if (is_file($signatureHelper)) {
    require_once $signatureHelper;
}
$konvoModelRouter = __DIR__ . '/konvo_model_router.php';
if (is_file($konvoModelRouter)) {
    require_once $konvoModelRouter;
}
if (!function_exists('konvo_model_for_task')) {
    function konvo_model_for_task(string $task, array $ctx = array()): string
    {
        return 'claude-sonnet-5';
    }
}

if (!defined('KONVO_BASE_URL')) define('KONVO_BASE_URL', 'https://forum.kirupa.com');
if (!defined('KONVO_API_KEY')) define('KONVO_API_KEY', trim((string)getenv('DISCOURSE_API_KEY')));
if (!defined('KONVO_SECRET')) define('KONVO_SECRET', trim((string)getenv('DISCOURSE_WEBHOOK_SECRET')));
if (!defined('KONVO_WEBDEV_CATEGORY_ID')) define('KONVO_WEBDEV_CATEGORY_ID', 42);
if (!defined('KONVO_TZ')) define('KONVO_TZ', trim((string)(getenv('KONVO_TIMEZONE') ?: 'America/Los_Angeles')));

@date_default_timezone_set(KONVO_TZ);

$bots = array(
    array('username' => 'BayMax', 'name' => 'BayMax'),
    array('username' => 'vaultboy', 'name' => 'VaultBoy'),
    array('username' => 'MechaPrime', 'name' => 'MechaPrime'),
    array('username' => 'yoshiii', 'name' => 'Yoshiii'),
    array('username' => 'bobamilk', 'name' => 'BobaMilk'),
    array('username' => 'wafflefries', 'name' => 'WaffleFries'),
    array('username' => 'quelly', 'name' => 'Quelly'),
    array('username' => 'sora', 'name' => 'Sora'),
    array('username' => 'sarah_connor', 'name' => 'Sarah'),
    array('username' => 'ellen1979', 'name' => 'Ellen'),
    array('username' => 'arthurdent', 'name' => 'Arthur'),
    array('username' => 'hariseldon', 'name' => 'Hari'),
);

function cc_out(int $code, array $data): void
{
    if (function_exists('http_response_code')) http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function cc_safe_hash_equals(string $a, string $b): bool
{
    if (function_exists('hash_equals')) return hash_equals($a, $b);
    if (strlen($a) !== strlen($b)) return false;
    $res = 0;
    for ($i = 0, $len = strlen($a); $i < $len; $i++) $res |= ord($a[$i]) ^ ord($b[$i]);
    return $res === 0;
}

function cc_state_path(): string
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir . '/coding_challenge_state.json';
}

function cc_load_state(): array
{
    $p = cc_state_path();
    if (!is_file($p)) return array();
    $raw = @file_get_contents($p);
    if (!is_string($raw) || trim($raw) === '') return array();
    $d = json_decode($raw, true);
    return is_array($d) ? $d : array();
}

function cc_save_state(array $state): void
{
    // Atomic write, same reasoning as the leaderboard: a half-written state file
    // would lose the challenge numbering and the recency history.
    $path = cc_state_path();
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) return;
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return;
    if (!@rename($tmp, $path)) @unlink($tmp);
}

function cc_pick_bot(array $bots): array
{
    if ($bots === array()) return array('username' => 'BayMax', 'name' => 'BayMax');
    return $bots[array_rand($bots)];
}

function cc_recent_vals(array $state, string $key, int $max = 14): array
{
    $vals = isset($state[$key]) && is_array($state[$key]) ? $state[$key] : array();
    $out = array();
    foreach ($vals as $v) {
        $s = trim((string)$v);
        if ($s !== '' && !in_array($s, $out, true)) $out[] = $s;
        if (count($out) >= $max) break;
    }
    return $out;
}

function cc_norm_lang(string $lang): string
{
    $l = strtolower(trim($lang));
    if ($l === 'javascript' || $l === 'js') return 'js';
    if ($l === 'css') return 'css';
    if ($l === 'html') return 'html';
    return 'js';
}

/**
 * Used when the model call fails, so a cron run still produces something sane.
 * Each is answerable in a reply with a short snippet and no tooling.
 */
function cc_fallback_challenges(): array
{
    return array(
        array(
            'language' => 'css',
            'title_summary' => 'Center A Card',
            'task' => 'Center the `.card` both horizontally and vertically inside `.stage`, which is 400px tall. Use flexbox and no fixed pixel offsets.',
            'starter' => ".stage {\n  height: 400px;\n  border: 1px solid #ccc;\n}\n\n.card {\n  width: 200px;\n  height: 100px;\n  background: #4f46e5;\n}",
            'constraints' => array('Flexbox only', 'No absolute positioning', 'No margin: auto with fixed values'),
            'theme' => 'layout centering',
        ),
        array(
            'language' => 'js',
            'title_summary' => 'Group By Key',
            'task' => 'Write `groupBy(items, key)` that returns an object grouping the array of objects by the given property. `groupBy([{t:"a",v:1},{t:"b",v:2},{t:"a",v:3}], "t")` should give `{a:[{t:"a",v:1},{t:"a",v:3}], b:[{t:"b",v:2}]}`.',
            'starter' => "function groupBy(items, key) {\n  // your code here\n}",
            'constraints' => array('Plain JavaScript, no libraries', 'Do not mutate the input array'),
            'theme' => 'array grouping',
        ),
        array(
            'language' => 'js',
            'title_summary' => 'Debounce Function',
            'task' => 'Write `debounce(fn, wait)` that returns a function which only runs `fn` after `wait` milliseconds have passed with no further calls. Calling it repeatedly should reset the timer.',
            'starter' => "function debounce(fn, wait) {\n  // your code here\n}",
            'constraints' => array('Plain JavaScript, no libraries', 'Preserve the arguments passed to the returned function'),
            'theme' => 'timing utility',
        ),
        array(
            'language' => 'css',
            'title_summary' => 'Truncate With Ellipsis',
            'task' => 'Make `.title` show a single line that truncates with an ellipsis when the text is too long for its 240px container.',
            'starter' => ".title {\n  width: 240px;\n  font-size: 18px;\n}",
            'constraints' => array('CSS only', 'Single line, no wrapping'),
            'theme' => 'text overflow',
        ),
    );
}

function cc_extract_json_object(string $content): ?array
{
    $content = trim($content);
    if ($content === '') return null;
    $decoded = json_decode($content, true);
    if (is_array($decoded)) return $decoded;
    $start = strpos($content, '{');
    $end = strrpos($content, '}');
    if ($start === false || $end === false || $end <= $start) return null;
    $decoded = json_decode((string)substr($content, (int)$start, (int)($end - $start + 1)), true);
    return is_array($decoded) ? $decoded : null;
}

function cc_generate_challenge(array $state): array
{
    $recentThemes = cc_recent_vals($state, 'recent_themes', 14);
    $recentSummaries = cc_recent_vals($state, 'recent_summaries', 14);
    $avoid = '';
    if ($recentThemes !== array()) $avoid .= "Recently used themes to avoid repeating:\n- " . implode("\n- ", $recentThemes) . "\n";
    if ($recentSummaries !== array()) $avoid .= "Recently used titles to avoid repeating:\n- " . implode("\n- ", $recentSummaries) . "\n";

    $system = "You write small coding challenges for a friendly web development forum.\n\n"
        . "HARD CONSTRAINTS on what you may ask for:\n"
        . "- Solvable with plain CSS or plain JavaScript only. No frameworks, no libraries, no TypeScript, no npm, no build step, no bundler.\n"
        . "- Answerable by pasting a short snippet into a forum reply. Never ask for a CodePen, JSFiddle, repo, screenshot, or any external link.\n"
        . "- No file system, no network requests, no server, no canvas pixel work, no browser APIs that need permissions.\n"
        . "- The whole solution should be roughly 3 to 15 lines. If it needs more, it is too big.\n"
        . "- There must be a clear, checkable correct answer. Avoid open-ended 'design something nice' prompts.\n\n"
        . "Write one challenge that is interesting but genuinely small. Favour everyday things people actually hit: layout, text overflow, array and string handling, event timing, sorting, formatting.\n"
        . "The starter block should give just enough context to answer, and must not contain the solution.\n"
        . "When you mention code in the task text (a function signature, a sample call, an expected return value), wrap it in backticks so it renders as code.\n"
        . "Do not use an em dash anywhere.\n\n"
        . "Return ONLY JSON:\n"
        . '{"language":"css|js","title_summary":"2 to 3 words, Title Case","task":"what to build, 1 to 3 sentences, with a concrete example of expected behaviour where useful","starter":"short starter snippet or empty string","constraints":["short rule",".."],"theme":"2 to 4 word topic label"}';

    $user = trim($avoid) !== ''
        ? ($avoid . "\nWrite the next challenge.")
        : "Write the challenge.";

    $res = konvo_anthropic_chat_json(array(
        'model' => konvo_model_for_task('deep_question', array('technical' => true)),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'max_tokens' => 1400,
        'temperature' => 0.85,
    ), 90);

    if (!($res['ok'] ?? false)) {
        return array('ok' => false, 'error' => (string)($res['error'] ?? 'model call failed'));
    }
    $content = trim((string)($res['body']['choices'][0]['message']['content'] ?? ''));
    $obj = cc_extract_json_object($content);
    if (!is_array($obj)) {
        return array('ok' => false, 'error' => 'model returned non-JSON');
    }

    $task = trim((string)($obj['task'] ?? ''));
    $summary = trim((string)($obj['title_summary'] ?? ''));
    if ($task === '' || $summary === '') {
        return array('ok' => false, 'error' => 'model JSON missing task/title_summary');
    }

    $constraints = array();
    if (isset($obj['constraints']) && is_array($obj['constraints'])) {
        foreach ($obj['constraints'] as $c) {
            $c = trim((string)$c);
            if ($c !== '') $constraints[] = $c;
        }
    }

    return array(
        'ok' => true,
        'challenge' => array(
            'language' => cc_norm_lang((string)($obj['language'] ?? 'js')),
            'title_summary' => $summary,
            'task' => $task,
            'starter' => rtrim((string)($obj['starter'] ?? '')),
            'constraints' => $constraints,
            'theme' => trim((string)($obj['theme'] ?? '')),
            '_origin' => 'llm',
        ),
    );
}

function cc_pick_challenge(array $state): array
{
    $gen = cc_generate_challenge($state);
    if (!empty($gen['ok']) && is_array($gen['challenge'] ?? null)) {
        return $gen['challenge'];
    }
    $pool = cc_fallback_challenges();
    $recent = cc_recent_vals($state, 'recent_summaries', 14);
    shuffle($pool);
    foreach ($pool as $c) {
        if (!in_array((string)$c['title_summary'], $recent, true)) {
            $c['_origin'] = 'fallback';
            return $c;
        }
    }
    $c = $pool[0];
    $c['_origin'] = 'fallback';
    return $c;
}

function cc_title_case_word(string $w): string
{
    $w = trim($w);
    if ($w === '') return '';
    $upper = strtoupper($w);
    if (in_array($upper, array('CSS', 'JS', 'DOM', 'API', 'HTML', 'JSON', 'SQL', 'UI', 'UX', 'URL'), true)) return $upper;
    return strtoupper(substr($w, 0, 1)) . substr($w, 1);
}

function cc_clean_title_summary(string $summary): string
{
    $summary = trim(preg_replace('/[^A-Za-z0-9 \-]/', ' ', $summary) ?? $summary);
    $summary = trim(preg_replace('/\s+/', ' ', $summary) ?? $summary);
    if ($summary === '') return '';
    $words = array_slice(explode(' ', $summary), 0, 3);
    $out = array();
    foreach ($words as $w) {
        $t = cc_title_case_word($w);
        if ($t !== '') $out[] = $t;
    }
    return implode(' ', $out);
}

function cc_build_raw(array $challenge): string
{
    $task = trim((string)($challenge['task'] ?? ''));
    $starter = rtrim((string)($challenge['starter'] ?? ''));
    $lang = cc_norm_lang((string)($challenge['language'] ?? 'js'));
    $constraints = isset($challenge['constraints']) && is_array($challenge['constraints'])
        ? $challenge['constraints']
        : array();

    $lines = array();
    // Code mentioned in the task text must read as code, not prose.
    $lines[] = function_exists('konvo_format_inline_code') ? konvo_format_inline_code($task) : $task;

    if ($starter !== '') {
        $lines[] = '';
        $lines[] = '```' . $lang;
        $lines[] = $starter;
        $lines[] = '```';
    }

    if ($constraints !== array()) {
        $lines[] = '';
        $lines[] = '**Rules:**';
        foreach ($constraints as $c) {
            $lines[] = '- ' . trim((string)$c);
        }
    }

    $lines[] = '';
    $lines[] = 'Post your solution as a reply. Answer goes up in about a day.';

    return trim(implode("\n", $lines));
}

function cc_post_topic(string $botUsername, string $title, string $raw): array
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'status' => 0, 'error' => 'curl_init unavailable', 'body' => null, 'raw' => '');
    }
    $payload = array(
        'title' => $title,
        'raw' => $raw,
        'category' => (int)KONVO_WEBDEV_CATEGORY_ID,
    );
    $ch = curl_init(rtrim(KONVO_BASE_URL, '/') . '/posts.json');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Api-Key: ' . KONVO_API_KEY,
            'Api-Username: ' . $botUsername,
        ),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ));
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $decoded = json_decode((string)$body, true);
    return array(
        'ok' => ($err === '' && $status >= 200 && $status < 300 && is_array($decoded)),
        'status' => $status,
        'error' => $err,
        'body' => is_array($decoded) ? $decoded : null,
        'raw' => (string)$body,
    );
}

// ---------------------------------------------------------------- entry point

$providedKey = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (KONVO_SECRET === '') {
    cc_out(500, array('ok' => false, 'error' => 'DISCOURSE_WEBHOOK_SECRET is not configured on the server.'));
}
if ($providedKey === '' || !cc_safe_hash_equals(KONVO_SECRET, $providedKey)) {
    cc_out(403, array('ok' => false, 'error' => 'Forbidden', 'hint' => 'Pass ?key=YOUR_SECRET'));
}
if (KONVO_API_KEY === '') {
    cc_out(500, array('ok' => false, 'error' => 'DISCOURSE_API_KEY is not configured on the server.'));
}
if (KONVO_ANTHROPIC_API_KEY === '') {
    cc_out(500, array('ok' => false, 'error' => 'ANTHROPIC_API_KEY is not configured on the server.'));
}

$dryRun = isset($_GET['dry_run']) && (string)$_GET['dry_run'] === '1';
$force = isset($_GET['force']) && (string)$_GET['force'] === '1';

$allowNewTopicsEnv = strtolower(trim((string)getenv('KONVO_ALLOW_NEW_TOPICS')));
$allowNewTopics = in_array($allowNewTopicsEnv, array('1', 'true', 'yes', 'on'), true);
if (!$dryRun && !$allowNewTopics && !$force) {
    cc_out(200, array(
        'ok' => true,
        'posted' => false,
        'reason' => 'new_topic_creation_disabled',
        'hint' => 'Set KONVO_ALLOW_NEW_TOPICS=1 or pass force=1 to override.',
    ));
}

$state = cc_load_state();
$lastNumber = (int)($state['last_number'] ?? 0);
$nextNumber = max(1, $lastNumber + 1);

$bot = cc_pick_bot($bots);
$challenge = cc_pick_challenge($state);

// Posting a canned challenge while the API is down produces repeats (the
// fallback pool is only four items) and burns a challenge number for content
// nobody wrote. Stay quiet instead and let the next cron slot try again.
if ((string)($challenge['_origin'] ?? '') === 'fallback'
    && function_exists('konvo_anthropic_unavailable') && konvo_anthropic_unavailable()) {
    $lastFailure = function_exists('konvo_anthropic_last_failure') ? konvo_anthropic_last_failure() : array();
    cc_out(200, array(
        'ok' => true,
        'posted' => false,
        'reason' => 'model_unavailable',
        'detail' => (string)($lastFailure['error'] ?? ''),
        'status' => (int)($lastFailure['status'] ?? 0),
        'hint' => 'Skipped rather than posting a canned challenge. Will retry on the next scheduled run.',
    ));
}

$summary = cc_clean_title_summary((string)($challenge['title_summary'] ?? ''));
$title = 'Coding Challenge - #' . $nextNumber . ($summary !== '' ? (': ' . $summary) : '');
$raw = cc_build_raw($challenge);

if ($dryRun) {
    cc_out(200, array(
        'ok' => true,
        'dry_run' => true,
        'action' => 'would_post_coding_challenge',
        'bot' => $bot,
        'topic' => array(
            'title' => $title,
            'category_id' => (int)KONVO_WEBDEV_CATEGORY_ID,
            'language' => (string)($challenge['language'] ?? ''),
            'theme' => (string)($challenge['theme'] ?? ''),
            'origin' => (string)($challenge['_origin'] ?? ''),
            'raw_preview' => $raw,
        ),
    ));
}

$postRes = cc_post_topic((string)$bot['username'], $title, $raw);
if (!$postRes['ok']) {
    cc_out(500, array(
        'ok' => false,
        'error' => 'Failed to post coding challenge topic.',
        'status' => (int)($postRes['status'] ?? 0),
        'curl_error' => (string)($postRes['error'] ?? ''),
        'response' => $postRes['body'],
        'raw' => (string)($postRes['raw'] ?? ''),
    ));
}

$topicId = (int)($postRes['body']['topic_id'] ?? 0);
$postNumber = (int)($postRes['body']['post_number'] ?? 1);

// Only advance the counter after a successful post, so a failed run does not
// burn a challenge number.
$recentSummaries = cc_recent_vals($state, 'recent_summaries', 40);
array_unshift($recentSummaries, $summary);
$recentThemes = cc_recent_vals($state, 'recent_themes', 40);
array_unshift($recentThemes, trim((string)($challenge['theme'] ?? '')));

$state['last_number'] = $nextNumber;
$state['recent_summaries'] = array_slice(array_values(array_unique(array_filter($recentSummaries))), 0, 40);
$state['recent_themes'] = array_slice(array_values(array_unique(array_filter($recentThemes))), 0, 40);
$state['last_posted_at'] = time();
$state['last_topic_id'] = $topicId;
cc_save_state($state);

cc_out(200, array(
    'ok' => true,
    'posted' => true,
    'action' => 'posted_coding_challenge',
    'topic_url' => rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '/' . $postNumber,
    'topic_id' => $topicId,
    'number' => $nextNumber,
    'title' => $title,
    'bot' => $bot,
    'language' => (string)($challenge['language'] ?? ''),
    'origin' => (string)($challenge['_origin'] ?? ''),
));
