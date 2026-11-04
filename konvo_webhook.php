<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function serverHeader(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$key] ?? ''));
}

function getWebhookSecret(): string
{
    return trim((string)getenv('DISCOURSE_WEBHOOK_SECRET'));
}

function verifyDiscourseSignature(string $rawBody, string $secret): bool
{
    if ($secret === '') {
        return false;
    }

    $sig = serverHeader('X-Discourse-Event-Signature');
    if ($sig === '') {
        $sig = serverHeader('X-Discourse-Event-Signature-256');
    }
    if ($sig === '') {
        return false;
    }

    $sig = strtolower($sig);
    if (str_starts_with($sig, 'sha256=')) {
        $sig = substr($sig, 7);
    }

    $calc = hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($calc, $sig);
}

function normalizeMention(string $username): string
{
    return strtolower(trim($username));
}

function extractMentions(array $payload, string $raw): array
{
    $mentions = [];
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];

    $mentionedUsers = $post['mentioned_users'] ?? ($payload['mentioned_users'] ?? []);
    if (is_array($mentionedUsers)) {
        foreach ($mentionedUsers as $m) {
            if (is_array($m) && isset($m['username'])) {
                $u = normalizeMention((string)$m['username']);
                if ($u !== '') {
                    $mentions[$u] = true;
                }
            } elseif (is_string($m)) {
                $u = normalizeMention($m);
                if ($u !== '') {
                    $mentions[$u] = true;
                }
            }
        }
    }

    if ($raw !== '' && preg_match_all('/@([a-z0-9_]+)/i', $raw, $matches)) {
        foreach (($matches[1] ?? []) as $u) {
            $n = normalizeMention((string)$u);
            if ($n !== '') {
                $mentions[$n] = true;
            }
        }
    }

    return array_keys($mentions);
}

function isQuestionLikeText(string $text): bool
{
    $t = trim($text);
    if ($t === '') {
        return false;
    }
    if (str_contains($t, '?')) {
        return true;
    }
    return (bool)preg_match(
        '/\b(what|why|how|when|where|who|which|can you|could you|would you|do you|did you|is there|are there|thoughts on|any tips|any advice|i wonder|i[\'’]m curious|curious)\b/i',
        $t
    );
}

function isGratitudeLikeText(string $text): bool
{
    $t = trim($text);
    if ($t === '') {
        return false;
    }
    $plain = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = trim((string)(preg_replace('/\s+/', ' ', $plain) ?? $plain));
    if ($plain === '') {
        return false;
    }
    if (strlen($plain) > 320) {
        return false;
    }
    return (bool)preg_match('/\b(thanks|thank you|thx|ty|appreciate it|appreciated|helpful|that helped|super helpful|cheers)\b/i', $plain);
}

function looksTechnicalText(string $text): bool
{
    $t = strtolower(trim($text));
    if ($t === '') {
        return false;
    }
    if (preg_match('/```|`[^`]+`/', $t)) {
        return true;
    }
    return (bool)preg_match('/\b(js|javascript|typescript|html|css|api|cache|react|vue|node|sql|queryselectorall|htmlcollection|nodelist|dom|function|class|array|object|promise|async|await|bug|error|exception|stack)\b/i', $t);
}

function baseUrlForLocalCalls(): string
{
    $fromEnv = trim((string)(getenv('KONVO_LOCAL_BASE_URL') ?: ''));
    if ($fromEnv !== '') {
        return rtrim($fromEnv, '/');
    }

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $isHttps ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    if ($scriptDir === '.' || $scriptDir === '/') {
        $scriptDir = '';
    }
    return $scheme . '://' . $host . $scriptDir;
}

function postForm(string $url, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        // A reply run makes several sequential LLM calls, each allowed 60s.
        // 30s here used to cut most runs off while they were still working.
        CURLOPT_TIMEOUT => 150,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    $decoded = is_string($body) ? json_decode($body, true) : null;
    return [
        'ok' => $error === '' && $status >= 200 && $status < 300 && is_array($decoded) && !empty($decoded['ok']),
        'status' => $status,
        'error' => $error,
        // The endpoint was reached and may still be running, so it may yet post.
        'timed_out' => $errno === CURLE_OPERATION_TIMEDOUT,
        'raw' => is_string($body) ? $body : '',
        'body' => is_array($decoded) ? $decoded : null,
    ];
}

function postFormWithRetry(string $url, array $fields, int $attempts = 3, int $delayMicros = 350000): array
{
    $attempts = max(1, $attempts);
    $last = ['ok' => false, 'status' => 0, 'error' => 'unattempted', 'raw' => '', 'body' => null, 'attempts' => 0, 'timed_out' => false];
    for ($i = 1; $i <= $attempts; $i++) {
        $res = postForm($url, $fields);
        $res['attempts'] = $i;
        $last = $res;
        if (!empty($res['ok'])) {
            return $res;
        }
        // Re-sending after a timeout is how one post collected duplicate
        // replies: the first run was still generating and posted anyway.
        if (!empty($res['timed_out'])) {
            return $res;
        }
        $status = (int)($res['status'] ?? 0);
        $err = trim((string)($res['error'] ?? ''));
        $retryable = ($status === 0 || $status === 429 || $status >= 500 || $err !== '');
        if (!$retryable || $i >= $attempts) {
            return $res;
        }
        if ($delayMicros > 0) {
            usleep($delayMicros);
        }
    }
    return $last;
}

function fetchJson(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '' || $status < 200 || $status >= 300) {
        return null;
    }

    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

function repliedToBotUsername(array $payload, array $post, int $topicId, array $botEndpointMap): string
{
    // Prefer direct fields from payload when available.
    $candidate = '';
    if (isset($post['reply_to_user']) && is_array($post['reply_to_user'])) {
        $candidate = normalizeMention((string)($post['reply_to_user']['username'] ?? ''));
    }
    if ($candidate === '' && isset($post['reply_to_username'])) {
        $candidate = normalizeMention((string)$post['reply_to_username']);
    }
    if ($candidate === '' && isset($payload['reply_to_user']) && is_array($payload['reply_to_user'])) {
        $candidate = normalizeMention((string)($payload['reply_to_user']['username'] ?? ''));
    }
    if ($candidate !== '' && isset($botEndpointMap[$candidate])) {
        return $candidate;
    }

    // Fallback: resolve replied post from topic stream using post_number.
    $replyToPostNumber = (int)($post['reply_to_post_number'] ?? ($payload['reply_to_post_number'] ?? 0));
    if ($replyToPostNumber <= 0 || $topicId <= 0) {
        return '';
    }

    $topic = fetchJson('https://forum.kirupa.com/t/' . $topicId . '.json');
    if (!is_array($topic)) {
        return '';
    }
    $posts = $topic['post_stream']['posts'] ?? [];
    if (!is_array($posts)) {
        return '';
    }
    foreach ($posts as $p) {
        if (!is_array($p)) {
            continue;
        }
        if ((int)($p['post_number'] ?? 0) !== $replyToPostNumber) {
            continue;
        }
        $u = normalizeMention((string)($p['username'] ?? ''));
        if ($u !== '' && isset($botEndpointMap[$u])) {
            return $u;
        }
        break;
    }

    return '';
}

function topicOpBotUsername(int $topicId, array $botEndpointMap): string
{
    if ($topicId <= 0) {
        return '';
    }

    $topic = fetchJson('https://forum.kirupa.com/t/' . $topicId . '.json');
    if (!is_array($topic)) {
        return '';
    }
    $posts = $topic['post_stream']['posts'] ?? [];
    if (!is_array($posts) || $posts === []) {
        return '';
    }

    $op = null;
    foreach ($posts as $p) {
        if (!is_array($p)) {
            continue;
        }
        $num = (int)($p['post_number'] ?? 0);
        if ($num === 1) {
            $op = $p;
            break;
        }
        if ($op === null) {
            $op = $p;
        }
    }
    if (!is_array($op)) {
        return '';
    }

    $u = normalizeMention((string)($op['username'] ?? ''));
    if ($u !== '' && isset($botEndpointMap[$u])) {
        return $u;
    }
    return '';
}

function stateFilePath(): string
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/webhook_seen_posts.json';
}

function alreadyProcessedKey(string $dedupeKey): bool
{
    $path = stateFilePath();
    $now = time();
    $maxAge = 7 * 24 * 60 * 60;
    $maxEntries = 2000;

    $fp = @fopen($path, 'c+');
    if ($fp === false) {
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }

    $raw = stream_get_contents($fp);
    $state = [];
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $state = $decoded;
        }
    }

    foreach ($state as $k => $ts) {
        $age = $now - (int)$ts;
        if ($age > $maxAge) {
            unset($state[$k]);
        }
    }

    $key = trim($dedupeKey);
    if ($key === '') {
        $key = 'unknown:' . (string)$now;
    }

    $seen = isset($state[$key]);
    if (!$seen) {
        $state[$key] = $now;
        if (count($state) > $maxEntries) {
            asort($state);
            $state = array_slice($state, -$maxEntries, null, true);
        }
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state, JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $seen;
}

function appendEventLog(string $line): void
{
    // Logging intentionally disabled to avoid writing log files.
    if ($line === '') {
        return;
    }
}

function sendExternalNotification(array $payload): void
{
    $notifyUrl = trim((string)(getenv('KONVO_NOTIFY_WEBHOOK') ?: ''));
    if ($notifyUrl === '') {
        return;
    }

    $ch = curl_init($notifyUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || $rawBody === '') {
    jsonOut(['ok' => false, 'error' => 'Empty body.'], 400);
}

$secret = getWebhookSecret();
if (!verifyDiscourseSignature($rawBody, $secret)) {
    jsonOut(['ok' => false, 'error' => 'Invalid webhook signature.'], 401);
}

$event = serverHeader('X-Discourse-Event');
if ($event !== '' && $event !== 'post_created' && $event !== 'post_edited') {
    jsonOut(['ok' => true, 'ignored' => true, 'reason' => 'Unsupported event: ' . $event]);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    jsonOut(['ok' => false, 'error' => 'Invalid JSON payload.'], 400);
}

$post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
$postId = (int)($post['id'] ?? ($payload['id'] ?? 0));
$topicId = (int)($post['topic_id'] ?? ($payload['topic_id'] ?? 0));
$postNumber = (int)($post['post_number'] ?? ($payload['post_number'] ?? 0));
$author = strtolower(trim((string)($post['username'] ?? ($payload['username'] ?? ''))));
$raw = trim((string)($post['raw'] ?? ($payload['raw'] ?? '')));
if ($raw === '' && isset($post['cooked']) && is_string($post['cooked'])) {
    $raw = trim(html_entity_decode(strip_tags($post['cooked']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

if ($postId <= 0 || $topicId <= 0) {
    jsonOut(['ok' => false, 'error' => 'Missing post/topic id.'], 400);
}

$trackedBots = ['baymax', 'kirupabot', 'vaultboy', 'mechaprime', 'yoshiii', 'bobamilk', 'wafflefries', 'quelly', 'sora', 'sarah_connor', 'ellen1979', 'arthurdent', 'hariseldon', 'kirupabotx', 'coding_agent_bot'];
$isTrackedBotAuthor = in_array($author, $trackedBots, true);
if ($isTrackedBotAuthor && $author === 'kirupabot') {
    jsonOut(['ok' => true, 'ignored' => true, 'reason' => 'Author is a bot.']);
}

// Dedupe by event + post + version, so edited posts can trigger once per new revision.
$postVersion = (int)($post['version'] ?? ($payload['version'] ?? 0));
$dedupeKey = ($event !== '' ? $event : 'unknown') . ':' . $postId . ':' . $postVersion;
if (alreadyProcessedKey($dedupeKey)) {
    jsonOut(['ok' => true, 'ignored' => true, 'reason' => 'Already processed key ' . $dedupeKey . '.']);
}

$mentions = extractMentions($payload, $raw);
$botEndpointMap = [
    'baymax' => 'konvo_baymax_reply.php',
    'kirupabot' => 'konvo_kirupabot_reply.php',
    'vaultboy' => 'konvo_vaultboy_reply.php',
    'mechaprime' => 'konvo_mpr_reply.php',
    'yoshiii' => 'konvo_yoshiii_reply.php',
    'bobamilk' => 'konvo_bobamilk_reply.php',
    'wafflefries' => 'konvo_wafflefries_reply.php',
    'quelly' => 'konvo_quelly_reply.php',
    'sora' => 'konvo_sora_reply.php',
    'sarah_connor' => 'konvo_sarah_connor_reply.php',
    'ellen1979' => 'konvo_ellen1979_reply.php',
    'arthurdent' => 'konvo_arthurdent_reply.php',
    'hariseldon' => 'konvo_hariseldon_reply.php',
];

if ($isTrackedBotAuthor && $author !== 'kirupabot') {
    $categoryId = (int)($post['category_id'] ?? ($payload['category_id'] ?? 0));
    $topicTitle = trim((string)($post['topic_title'] ?? ($payload['topic_title'] ?? '')));
    $topicText = $topicTitle . "\n" . $raw;
    $looksTechnical = ($categoryId === 42) || looksTechnicalText($topicText);
    $alreadyHasKirupaLink = stripos($raw, 'kirupa.com') !== false;
    if ($looksTechnical && !$alreadyHasKirupaLink) {
        $baseUrl = baseUrlForLocalCalls();
        $url = $baseUrl . '/konvo_kirupabot_reply.php';
        $res = postFormWithRetry($url, [
            'topic_id' => (string)$topicId,
            'reply_target' => 'latest',
            'force_reply_to_bot' => '1',
            'force_kirupa_link' => '1',
        ]);
        if (empty($res['ok']) && empty($res['timed_out'])) {
            $res = postFormWithRetry($url, [
                'topic_id' => (string)$topicId,
                'reply_target' => 'latest',
                'force_reply_to_bot' => '1',
                'force_kirupa_link' => '1',
                'safe_mode' => '1',
            ], 2, 250000);
        }
        jsonOut([
            'ok' => true,
            'event' => $event !== '' ? $event : 'unknown',
            'post_id' => $postId,
            'topic_id' => $topicId,
            'author' => $author,
            'auto_kirupabot_link' => true,
            'triggered' => [[
                'bot' => 'kirupabot',
                'endpoint' => 'konvo_kirupabot_reply.php',
                'ok' => $res['ok'],
                'status' => $res['status'],
                'attempts' => (int)($res['attempts'] ?? 1),
                'error' => $res['error'],
                'endpoint_error' => is_array($res['body']) ? (string)($res['body']['error'] ?? '') : '',
                'post_url' => is_array($res['body']) ? (string)($res['body']['post_url'] ?? '') : '',
            ]],
        ]);
    }
    jsonOut([
        'ok' => true,
        'ignored' => true,
        'reason' => 'Author is a bot; no kirupaBot link action required.',
        'author' => $author,
        'topic_id' => $topicId,
        'post_id' => $postId,
    ]);
}

$toTrigger = [];
foreach ($mentions as $mention) {
    if (isset($botEndpointMap[$mention])) {
        $toTrigger[$mention] = $botEndpointMap[$mention];
    }
}

// If user directly replies to a bot post (without mention), trigger that bot.
$replyBot = repliedToBotUsername($payload, $post, $topicId, $botEndpointMap);
$questionLike = isQuestionLikeText($raw);
$gratitudeLike = isGratitudeLikeText($raw);
$triggerMeta = [];
if ($gratitudeLike && !$questionLike) {
    foreach (array_keys($toTrigger) as $mentionedBot) {
        $triggerMeta[$mentionedBot] = ['response_mode' => 'thanks_ack'];
    }
}
if ($replyBot !== '') {
    $toTrigger[$replyBot] = $botEndpointMap[$replyBot];
    if ($gratitudeLike && !$questionLike) {
        $triggerMeta[$replyBot] = ['response_mode' => 'thanks_ack'];
    }
}

// Fallback: if topic was started by a bot and user asks a question in-topic,
// trigger the OP bot even when Discourse didn't set reply_to_post_number.
$topicOpBot = '';
if ($toTrigger === [] && $questionLike && $postNumber > 1) {
    $topicOpBot = topicOpBotUsername($topicId, $botEndpointMap);
    if ($topicOpBot !== '' && $topicOpBot !== $author) {
        $toTrigger[$topicOpBot] = $botEndpointMap[$topicOpBot];
    }
}

if ($toTrigger === []) {
    $reason = 'No tracked bot mention found.';
    jsonOut([
        'ok' => true,
        'ignored' => true,
        'reason' => $reason,
        'mentions' => $mentions,
        'reply_to_bot' => $replyBot,
        'topic_op_bot' => $topicOpBot,
        'question_like' => $questionLike,
        'gratitude_like' => $gratitudeLike,
    ]);
}

$baseUrl = baseUrlForLocalCalls();
$results = [];
foreach ($toTrigger as $bot => $script) {
    $url = $baseUrl . '/' . $script;
    $fields = [
        'topic_id' => (string)$topicId,
        'reply_target' => 'latest',
        'target_post_number' => (string)$postNumber,
        'target_username' => (string)($post['username'] ?? ''),
        'target_raw' => (string)$raw,
    ];
    if ($replyBot !== '' && $bot === $replyBot) {
        $fields['direct_reply_to_bot'] = '1';
    }
    if (isset($triggerMeta[$bot]['response_mode']) && is_string($triggerMeta[$bot]['response_mode'])) {
        $fields['response_mode'] = (string)$triggerMeta[$bot]['response_mode'];
    }
    if (isset($triggerMeta[$bot]['force_reply_to_bot']) && $triggerMeta[$bot]['force_reply_to_bot'] === true) {
        $fields['force_reply_to_bot'] = '1';
    }
    if (isset($triggerMeta[$bot]['force_kirupa_link']) && $triggerMeta[$bot]['force_kirupa_link'] === true) {
        $fields['force_kirupa_link'] = '1';
    }
    $res = postFormWithRetry($url, $fields);
    $usedSafeMode = false;
    if (empty($res['ok']) && empty($res['timed_out'])) {
        $safeFields = $fields;
        $safeFields['safe_mode'] = '1';
        $res = postFormWithRetry($url, $safeFields, 2, 250000);
        $usedSafeMode = !empty($res['ok']);
    }
    $results[] = [
        'bot' => $bot,
        'endpoint' => $script,
        'response_mode' => (string)($fields['response_mode'] ?? ''),
        'ok' => $res['ok'],
        'status' => $res['status'],
        'attempts' => (int)($res['attempts'] ?? 1),
        'safe_mode_used' => $usedSafeMode,
        'error' => $res['error'],
        'endpoint_error' => is_array($res['body']) ? (string)($res['body']['error'] ?? '') : '',
        'endpoint_raw' => is_string($res['raw']) ? substr($res['raw'], 0, 220) : '',
        'post_url' => is_array($res['body']) ? (string)($res['body']['post_url'] ?? '') : '',
    ];
}

$okCount = 0;
foreach ($results as $r) {
    if (!empty($r['ok'])) {
        $okCount++;
    }
}

sendExternalNotification([
    'event' => 'konvo_bot_mention',
    'post_id' => $postId,
    'topic_id' => $topicId,
    'author' => $author,
    'mentions' => array_keys($toTrigger),
    'triggered' => $results,
]);

jsonOut([
    'ok' => true,
    'event' => $event !== '' ? $event : 'unknown',
    'post_id' => $postId,
    'topic_id' => $topicId,
    'mentions' => array_keys($toTrigger),
    'triggered' => $results,
]);
