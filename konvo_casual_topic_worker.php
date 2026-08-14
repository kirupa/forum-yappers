<?php

/*
 * Browser-callable casual topic poster.
 *
 * Example:
 * https://www.kirupa.com/konvo_casual_topic_worker.php?key=YOUR_SECRET
 * https://www.kirupa.com/konvo_casual_topic_worker.php?key=YOUR_SECRET&dry_run=1
 */

declare(strict_types=1);

require_once __DIR__ . '/konvo_anthropic_client.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/konvo_soul_helper.php';
require_once __DIR__ . '/konvo_signature_helper.php';
require_once __DIR__ . '/konvo_link_helper.php';
$konvoForumPromptHelper = __DIR__ . '/konvo_forum_prompt_helper.php';
if (is_file($konvoForumPromptHelper)) {
    require_once $konvoForumPromptHelper;
}
$konvoModelRouter = __DIR__ . '/konvo_model_router.php';
if (is_file($konvoModelRouter)) {
    require_once $konvoModelRouter;
}
if (!function_exists('konvo_model_for_task')) {
    function konvo_model_for_task(string $task, array $ctx = array()): string
    {
        return 'claude-opus-5';
    }
}

if (!defined('KONVO_BASE_URL')) define('KONVO_BASE_URL', 'https://forum.kirupa.com');
if (!defined('KONVO_API_KEY')) define('KONVO_API_KEY', trim((string)getenv('DISCOURSE_API_KEY')));
if (!defined('KONVO_SECRET')) define('KONVO_SECRET', trim((string)getenv('DISCOURSE_WEBHOOK_SECRET')));
if (!defined('KONVO_ALLOW_CASUAL_TOPIC_POSTS')) define('KONVO_ALLOW_CASUAL_TOPIC_POSTS', trim((string)getenv('KONVO_ALLOW_CASUAL_TOPIC_POSTS')));
if (!defined('KONVO_CASUAL_DAY_TZ')) define('KONVO_CASUAL_DAY_TZ', trim((string)getenv('KONVO_CASUAL_DAY_TZ')) !== '' ? trim((string)getenv('KONVO_CASUAL_DAY_TZ')) : 'America/Los_Angeles');
if (!defined('KONVO_TALK_CATEGORY_ID')) define('KONVO_TALK_CATEGORY_ID', 34);
if (!defined('KONVO_WEBDEV_CATEGORY_ID')) define('KONVO_WEBDEV_CATEGORY_ID', 42);
if (!defined('KONVO_GAMING_CATEGORY_ID')) define('KONVO_GAMING_CATEGORY_ID', 115);
if (!defined('KONVO_DESIGN_CATEGORY_ID')) define('KONVO_DESIGN_CATEGORY_ID', 114);

$bots = array(
    array('username' => 'BayMax', 'name' => 'BayMax', 'soul_key' => 'baymax', 'soul_fallback' => 'You are BayMax. Write naturally, concise, and human.'),
    array('username' => 'vaultboy', 'name' => 'VaultBoy', 'soul_key' => 'vaultboy', 'soul_fallback' => 'You are VaultBoy. Casual, playful, and game-obsessed.'),
    array('username' => 'MechaPrime', 'name' => 'MechaPrime', 'soul_key' => 'mechaprime', 'soul_fallback' => 'You are MechaPrime. Write naturally, concise, and human.'),
    array('username' => 'yoshiii', 'name' => 'Yoshiii', 'soul_key' => 'yoshiii', 'soul_fallback' => 'You are Yoshiii. Write naturally, concise, and human.'),
    array('username' => 'bobamilk', 'name' => 'BobaMilk', 'soul_key' => 'bobamilk', 'soul_fallback' => 'You are BobaMilk. Write naturally, concise, and human.'),
    array('username' => 'wafflefries', 'name' => 'WaffleFries', 'soul_key' => 'wafflefries', 'soul_fallback' => 'You are WaffleFries. Write naturally, concise, and human.'),
    array('username' => 'quelly', 'name' => 'Quelly', 'soul_key' => 'quelly', 'soul_fallback' => 'You are Quelly. Write naturally, concise, and human.'),
    array('username' => 'sora', 'name' => 'Sora', 'soul_key' => 'sora', 'soul_fallback' => 'You are Sora. Write naturally, concise, and human.'),
    array('username' => 'sarah_connor', 'name' => 'Sarah', 'soul_key' => 'sarah_connor', 'soul_fallback' => 'You are Sarah Connor. Write naturally, concise, and human.'),
    array('username' => 'ellen1979', 'name' => 'Ellen', 'soul_key' => 'ellen1979', 'soul_fallback' => 'You are Ellen1979. Write naturally, concise, and human.'),
    array('username' => 'arthurdent', 'name' => 'Arthur', 'soul_key' => 'arthurdent', 'soul_fallback' => 'You are ArthurDent. Write naturally, concise, and human.'),
    array('username' => 'hariseldon', 'name' => 'Hari', 'soul_key' => 'hariseldon', 'soul_fallback' => 'You are HariSeldon. Write naturally, concise, and human.'),
);

function casual_out(int $status, array $data): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

// A misconfigured cron entry (minute field "*" instead of "0") can fire this worker every
// minute for a whole hour. Without a lock, overlapping runs each pick the same top-ranked
// seed article independently and all post before any of them marks that URL as seen. This
// keeps only one real (non-dry-run) invocation executing at a time.
function casual_acquire_run_lock(): bool
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $path = $dir . '/casual_topic_worker.lock';
    $handle = @fopen($path, 'c');
    if ($handle === false) {
        return true;
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return false;
    }
    return true;
}

set_exception_handler(static function (\Throwable $e): void {
    $where = basename((string)$e->getFile()) . ':' . (int)$e->getLine();
    $msg = trim((string)$e->getMessage());
    if ($msg === '') $msg = 'Unhandled exception';
    casual_out(500, array('ok' => false, 'error' => 'Casual worker exception: ' . $msg . ' [' . $where . ']'));
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!is_array($err)) return;
    $fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    if (!in_array((int)($err['type'] ?? 0), $fatal, true)) return;
    if (headers_sent()) return;

    $msg = trim((string)($err['message'] ?? 'Fatal error'));
    $file = basename((string)($err['file'] ?? 'unknown'));
    $line = (int)($err['line'] ?? 0);
    casual_out(500, array('ok' => false, 'error' => 'Casual worker fatal: ' . $msg . ' [' . $file . ':' . $line . ']'));
});

function safe_hash_equals(string $a, string $b): bool
{
    if (function_exists('hash_equals')) return hash_equals($a, $b);
    if (strlen($a) !== strlen($b)) return false;
    $res = 0;
    $len = strlen($a);
    for ($i = 0; $i < $len; $i++) {
        $res |= ord($a[$i]) ^ ord($b[$i]);
    }
    return $res === 0;
}

function casual_state_path(): string
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/casual_topic_recent.json';
}

function casual_daily_counts_path(): string
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/casual_topic_daily_counts.json';
}

function casual_daily_counts_load(): array
{
    $path = casual_daily_counts_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return array();
    $clean = array();
    foreach ($decoded as $day => $count) {
        $d = trim((string)$day);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
        $clean[$d] = max(0, (int)$count);
    }
    ksort($clean);
    return $clean;
}

function casual_daily_counts_save(array $state): void
{
    $today = casual_today_key();
    $cutoffTs = strtotime($today . ' 00:00:00 UTC');
    $minTs = ($cutoffTs === false ? time() : $cutoffTs) - (45 * 24 * 3600);
    $clean = array();
    foreach ($state as $day => $count) {
        $d = trim((string)$day);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
        $ts = strtotime($d . ' 00:00:00 UTC');
        if ($ts === false || $ts < $minTs) continue;
        $clean[$d] = max(0, (int)$count);
    }
    ksort($clean);
    @file_put_contents(casual_daily_counts_path(), json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function casual_today_key(): string
{
    try {
        $tz = new DateTimeZone((string)KONVO_CASUAL_DAY_TZ);
    } catch (\Throwable $e) {
        $tz = new DateTimeZone('America/Los_Angeles');
    }
    $now = new DateTimeImmutable('now', $tz);
    return $now->format('Y-m-d');
}

function casual_daily_count_for(string $day): int
{
    $state = casual_daily_counts_load();
    return max(0, (int)($state[$day] ?? 0));
}

function casual_daily_count_increment(string $day): int
{
    $state = casual_daily_counts_load();
    $state[$day] = max(0, (int)($state[$day] ?? 0)) + 1;
    casual_daily_counts_save($state);
    return (int)$state[$day];
}

function casual_seen_urls_path(): string
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/casual_topic_seen_urls.json';
}

function casual_load_seen_urls(): array
{
    $path = casual_seen_urls_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function casual_save_seen_urls(array $seen): void
{
    // No time-based expiry: reusing the exact same external article as a "new" seed
    // is never good regardless of how much time passed (a 30-day cutoff let this exact
    // recur every couple months once the source feed re-surfaced it). Matches
    // konvo_random_topic_worker.php's save_seen_urls(), which is already permanent and
    // only bounded by count.
    arsort($seen);
    $seen = array_slice($seen, 0, 600, true);
    @file_put_contents(casual_seen_urls_path(), json_encode($seen, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function casual_remember_seed_url(string $url): void
{
    $url = trim($url);
    if ($url === '' || !preg_match('/^https?:\/\/\S+$/i', $url)) return;
    $seen = casual_load_seen_urls();
    $seen[$url] = time();
    casual_save_seen_urls($seen);
}

function casual_load_recent_topics(): array
{
    $path = casual_state_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function casual_save_recent_topics(array $items): void
{
    $clean = array();
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $title = trim((string)($item['title'] ?? ''));
        $angle = trim((string)($item['plan_angle'] ?? ''));
        $lane = trim((string)($item['plan_lane'] ?? ''));
        $ts = (int)($item['ts'] ?? time());
        if ($title === '') continue;
        $clean[] = array(
            'title' => $title,
            'plan_angle' => $angle,
            'plan_lane' => $lane,
            'raw' => trim((string)($item['raw'] ?? '')),
            'ts' => $ts,
        );
    }

    usort($clean, static function ($a, $b) {
        return ((int)($b['ts'] ?? 0)) <=> ((int)($a['ts'] ?? 0));
    });

    $clean = array_slice($clean, 0, 24);
    @file_put_contents(casual_state_path(), json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function casual_remember_topic(string $title, string $planAngle, string $planLane = '', string $raw = ''): void
{
    $items = casual_load_recent_topics();
    array_unshift($items, array(
        'title' => trim($title),
        'plan_angle' => trim($planAngle),
        'plan_lane' => trim($planLane),
        'raw' => trim($raw),
        'ts' => time(),
    ));
    casual_save_recent_topics($items);
}

function casual_opening_stem(string $text): string
{
    $text = str_replace(array("\r\n", "\r"), "\n", (string)$text);
    $first = trim((string)strtok($text, "\n"));
    if ($first === '') return '';
    $first = preg_replace('/https?:\/\/\S+/i', '', $first) ?? $first;
    $first = strtolower($first);
    $first = preg_replace('/[^a-z0-9\s]/i', ' ', $first) ?? $first;
    $first = preg_replace('/\s+/', ' ', $first) ?? $first;
    $first = trim((string)$first);
    if ($first === '') return '';
    $parts = explode(' ', $first);
    if (count($parts) > 10) $parts = array_slice($parts, 0, 10);
    return trim((string)implode(' ', $parts));
}

function casual_recent_opening_stems(array $recent, int $limit = 14): string
{
    $stems = array();
    foreach ($recent as $item) {
        if (!is_array($item)) continue;
        $raw = trim((string)($item['raw'] ?? ''));
        if ($raw === '') continue;
        $stem = casual_opening_stem($raw);
        if ($stem === '' || isset($stems[$stem])) continue;
        $stems[$stem] = true;
        if (count($stems) >= max(6, $limit)) break;
    }
    if ($stems === array()) return '(none)';
    $lines = array();
    foreach (array_keys($stems) as $s) {
        $lines[] = '- ' . $s;
    }
    return implode("\n", $lines);
}

function casual_consensus_state_path(): string
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/casual_consensus_state.json';
}

function casual_consensus_load(): array
{
    $path = casual_consensus_state_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function casual_consensus_save(array $state): void
{
    $clean = array();
    $now = time();
    foreach ($state as $k => $row) {
        $id = (string)$k;
        if (!preg_match('/^\d+$/', $id)) continue;
        if (!is_array($row)) continue;
        $createdTs = isset($row['created_ts']) ? (int)$row['created_ts'] : $now;
        if (($now - $createdTs) > (14 * 24 * 3600)) continue;
        $clean[$id] = $row;
    }
    @file_put_contents(casual_consensus_state_path(), json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function casual_consensus_register_topic(int $topicId, array $bot, string $title, int $categoryId, array $plan): void
{
    if ($topicId <= 0) return;
    $state = casual_consensus_load();
    $key = (string)$topicId;
    $now = time();
    $state[$key] = array(
        'topic_id' => $topicId,
        'title' => trim($title),
        'category_id' => $categoryId,
        'op_bot' => strtolower(trim((string)($bot['username'] ?? ''))),
        'op_signature' => trim((string)($bot['name'] ?? '')),
        'phase' => 'open',
        'created_ts' => $now,
        'updated_ts' => $now,
        'discussion_reply_count' => 0,
        'participant_bots' => array(),
        'consensus_posted' => false,
        'consensus_post_id' => 0,
        'plan_mood' => trim((string)($plan['mood'] ?? '')),
        'plan_angle' => trim((string)($plan['angle'] ?? '')),
        'plan_intent' => trim((string)($plan['posting_intent'] ?? '')),
    );
    casual_consensus_save($state);
}

function casual_recent_hint_lines(array $recent): string
{
    $lines = array();
    $max = min(12, count($recent));
    for ($i = 0; $i < $max; $i++) {
        $item = $recent[$i] ?? null;
        if (!is_array($item)) continue;
        $title = trim((string)($item['title'] ?? ''));
        $angle = trim((string)($item['plan_angle'] ?? ''));
        $lane = trim((string)($item['plan_lane'] ?? ''));
        if ($title === '') continue;
        $line = '- ' . $title;
        if ($angle !== '') {
            $line .= ' (angle: ' . $angle . ')';
        }
        if ($lane !== '') {
            $line .= ' [lane: ' . $lane . ']';
        }
        $lines[] = $line;
    }
    return $lines === array() ? '(none)' : implode("\n", $lines);
}

function casual_interest_lanes(): array
{
    return array(
        'games' => array(
            'label' => 'video games and player experience',
            'guidance' => 'Focus on game design, player behavior, creativity, community, mechanics, balance, or discovery. Not patch/news reposts.',
        ),
        'sci_fi_ai' => array(
            'label' => 'science fiction lens on AI',
            'guidance' => 'Use a sci-fi framing to discuss practical AI/product behavior today. Keep it grounded in real teams and products.',
        ),
        'business' => array(
            'label' => 'business and market impact',
            'guidance' => 'Focus on incentives, margins, hiring, go-to-market pressure, or organizational tradeoffs from AI/tech shifts.',
        ),
        'design' => array(
            'label' => 'design and UX impact',
            'guidance' => 'Focus on UX quality, trust, intent, agency, creativity, and design-system/product tradeoffs.',
        ),
        'dev_culture' => array(
            'label' => 'developer life and craft',
            'guidance' => 'Focus on debugging habits, code ownership, review quality, team learning, and engineering culture tradeoffs.',
        ),
        'product_workflow' => array(
            'label' => 'product and workflow decisions',
            'guidance' => 'Focus on process, collaboration, decision speed, and where automation helps or harms product outcomes.',
        ),
    );
}

function casual_lane_tokens(string $laneKey): array
{
    $map = array(
        'games' => array('game', 'gaming', 'npc', 'player', 'gameplay', 'level', 'quest', 'rpg', 'indie'),
        'sci_fi_ai' => array('sci-fi', 'science fiction', 'hal', 'skynet', 'agent', 'autonomy', 'future'),
        'business' => array('business', 'market', 'pricing', 'margin', 'hiring', 'revenue', 'cost', 'roi'),
        'design' => array('design', 'ux', 'ui', 'interface', 'usability', 'creative', 'workflow'),
        'dev_culture' => array('developer', 'engineering', 'code review', 'debugging', 'ownership', 'team'),
        'product_workflow' => array('product', 'process', 'workflow', 'decision', 'collaboration', 'roadmap'),
    );
    return $map[$laneKey] ?? array();
}

function casual_infer_lane_from_item(array $item): string
{
    $lane = trim((string)($item['plan_lane'] ?? ''));
    if ($lane !== '') return $lane;

    $blob = strtolower(trim((string)($item['title'] ?? '') . "\n" . (string)($item['plan_angle'] ?? '')));
    if ($blob === '') return '';
    foreach (array_keys(casual_interest_lanes()) as $laneKey) {
        $tokens = casual_lane_tokens($laneKey);
        foreach ($tokens as $tok) {
            if ($tok !== '' && strpos($blob, strtolower($tok)) !== false) {
                return $laneKey;
            }
        }
    }
    return '';
}

function casual_pick_interest_lane(array $recent): array
{
    $lanes = casual_interest_lanes();
    $counts = array();
    foreach (array_keys($lanes) as $k) {
        $counts[$k] = 0;
    }
    $max = min(12, count($recent));
    for ($i = 0; $i < $max; $i++) {
        $item = $recent[$i] ?? null;
        if (!is_array($item)) continue;
        $lane = casual_infer_lane_from_item($item);
        if ($lane !== '' && isset($counts[$lane])) {
            $counts[$lane]++;
        }
    }

    $min = null;
    $choices = array();
    foreach ($counts as $k => $c) {
        if ($min === null || $c < $min) {
            $min = $c;
            $choices = array($k);
        } elseif ($c === $min) {
            $choices[] = $k;
        }
    }
    if ($choices === array()) {
        $choices = array_keys($lanes);
    }
    shuffle($choices);
    $pickedKey = (string)$choices[0];
    $picked = $lanes[$pickedKey] ?? array('label' => 'technology tradeoffs', 'guidance' => '');
    return array(
        'key' => $pickedKey,
        'label' => (string)($picked['label'] ?? 'technology tradeoffs'),
        'guidance' => (string)($picked['guidance'] ?? ''),
        'counts' => $counts,
    );
}

function casual_lane_from_key(string $key, array $recent = array()): ?array
{
    $k = strtolower(trim($key));
    if ($k === '') return null;
    $lanes = casual_interest_lanes();
    if (!isset($lanes[$k])) return null;
    $picked = $lanes[$k];
    $auto = casual_pick_interest_lane($recent);
    $counts = is_array($auto) && isset($auto['counts']) && is_array($auto['counts']) ? $auto['counts'] : array();
    return array(
        'key' => $k,
        'label' => (string)($picked['label'] ?? 'technology tradeoffs'),
        'guidance' => (string)($picked['guidance'] ?? ''),
        'counts' => $counts,
        'override' => true,
    );
}

function casual_fetch_latest_topic_titles(int $max = 120): array
{
    if (!function_exists('curl_init')) return array();
    $titles = array();
    $page = 0;
    $maxPages = 6;
    while ($page < $maxPages && count($titles) < $max) {
        $url = rtrim(KONVO_BASE_URL, '/') . '/latest.json?order=created&ascending=false&page=' . $page;
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => array(
                'Api-Key: ' . KONVO_API_KEY,
                'Api-Username: kirupa',
            ),
        ));
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err !== '' || $status < 200 || $status >= 300 || !is_string($body) || trim($body) === '') {
            break;
        }
        $json = json_decode($body, true);
        if (!is_array($json)) break;
        $topics = $json['topic_list']['topics'] ?? array();
        if (!is_array($topics) || $topics === array()) break;
        foreach ($topics as $topic) {
            $t = trim((string)($topic['title'] ?? ''));
            if ($t === '') continue;
            $titles[] = $t;
            if (count($titles) >= $max) break;
        }
        $page++;
    }
    return array_values(array_unique($titles));
}

function casual_feed_sources(): array
{
    // Single curated source: Techmeme's front page feed. It aggregates the day's
    // significant tech stories, so one small fetch replaces the previous 19-feed
    // sample (which pulled several megabytes of XML per run).
    return array(
        array('site' => 'Techmeme', 'feed' => 'https://www.techmeme.com/feed.xml', 'kind' => 'technology'),
    );
}

function casual_fetch_url(string $url): array
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'status' => 0, 'error' => 'curl_init unavailable', 'body' => '');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_USERAGENT => 'konvo-casual-topic-worker/2.0',
    ));
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $err !== '' || $status < 200 || $status >= 300) {
        return array('ok' => false, 'status' => $status, 'error' => $err, 'body' => '');
    }
    return array('ok' => true, 'status' => $status, 'error' => '', 'body' => (string)$body);
}

function casual_decode_xml_text(string $text): string
{
    $text = str_replace(array('<![CDATA[', ']]>'), ' ', $text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = strip_tags($text);
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return trim((string)$text);
}

function casual_normalize_feed_url(string $url): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
    if ($url === '') return '';
    if (str_starts_with($url, '//')) $url = 'https:' . $url;
    if (!preg_match('/^https?:\/\//i', $url)) return '';
    return $url;
}

function casual_extract_image_url_from_block(string $block): string
{
    if ($block === '') return '';
    $patterns = array(
        '/<media:content[^>]*url=["\']([^"\']+)["\'][^>]*>/i',
        '/<media:thumbnail[^>]*url=["\']([^"\']+)["\'][^>]*>/i',
        '/<enclosure[^>]*type=["\']image\/[^"\']+["\'][^>]*url=["\']([^"\']+)["\'][^>]*>/i',
        '/<enclosure[^>]*url=["\']([^"\']+)["\'][^>]*type=["\']image\/[^"\']+["\'][^>]*>/i',
        '/<img[^>]*src=["\']([^"\']+)["\'][^>]*>/i',
    );
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $block, $m) && isset($m[1])) {
            $url = casual_normalize_feed_url((string)$m[1]);
            if ($url !== '') return $url;
        }
    }
    return '';
}

function casual_text_is_english_like(string $text): bool
{
    $text = trim($text);
    if ($text === '') return false;
    $sample = substr($text, 0, 500);
    preg_match_all('/[a-z]/i', $sample, $latin);
    preg_match_all('/[\x{00C0}-\x{024F}\x{0400}-\x{04FF}\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}\x{0600}-\x{06FF}]/u', $sample, $nonLatin);
    $latinCount = count($latin[0] ?? array());
    $nonLatinCount = count($nonLatin[0] ?? array());
    return $latinCount >= max(8, $nonLatinCount * 2);
}

function casual_has_foreign_script(string $text): bool
{
    // The ratio test in casual_text_is_english_like() is for filtering seeds and
    // tolerates a stray character. Generated bodies get zero tolerance: one
    // foreign word dropped mid-sentence ("I'm not <arabic> shipping fast") is
    // enough to make a post look broken, and it happens intermittently.
    // Latin Extended is deliberately allowed so cafe/naive/resume survive, and
    // emoji live in symbol ranges that are not matched here.
    $stripped = preg_replace('/```[\s\S]*?```/', ' ', $text) ?? $text;
    $stripped = preg_replace('/`[^`\n]*`/', ' ', $stripped) ?? $stripped;
    return (bool)preg_match(
        '/[\x{0400}-\x{04FF}\x{0590}-\x{05FF}\x{0600}-\x{06FF}\x{0900}-\x{097F}\x{0E00}-\x{0E7F}\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}]/u',
        $stripped
    );
}

function casual_item_looks_shopping_deal(array $item): bool
{
    $blob = trim(
        (string)($item['title'] ?? '') . "\n"
        . (string)($item['summary'] ?? '') . "\n"
        . (string)($item['source'] ?? '')
    );
    $url = strtolower(trim((string)($item['url'] ?? '')));
    if ($blob === '' && $url === '') return false;
    if (preg_match('/\b(coupon|promo code|discount code|price drop|clearance|doorbuster|black friday|cyber monday|prime day|buy now|shop now|limited[- ]time offer|save\s*\$|%\s*off|for less)\b/i', $blob)) {
        return true;
    }
    $dealWord = (bool)preg_match('/\b(deal|deals|sale|on sale|discount|offer|offers)\b/i', $blob);
    $commerceWord = (bool)preg_match('/\b(shop|shopping|buy|price|priced|pricing|checkout|cart|amazon|walmart|best buy|target|costco|ebay)\b/i', $blob)
        || (bool)preg_match('/\$\s*\d+|\d+\s*usd/i', $blob);
    if ($dealWord && $commerceWord) return true;
    return (bool)preg_match('/\/deals?\b|[?&](deal|deals|coupon|promo|discount)=|black-friday|cyber-monday|prime-day|\/shopping\//i', $url);
}

function casual_item_looks_controversial_topic(array $item): bool
{
    $blob = strtolower(trim(
        (string)($item['title'] ?? '') . "\n"
        . (string)($item['summary'] ?? '') . "\n"
        . (string)($item['source'] ?? '')
    ));
    if ($blob === '') return false;
    return (bool)preg_match(
        '/\b(election|campaign|senate|congress|president|prime minister|gaza|ukraine|war|missile|bomb|killed|murder|shooting|sexual|sex|porn|nsfw|abortion|transgender|immigration|deport|riot|protest|police shooting)\b/i',
        $blob
    );
}

function casual_strip_tracking_params(string $url): string
{
    $url = trim($url);
    if ($url === '' || strpos($url, '?') === false) {
        return $url;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['query'])) {
        return $url;
    }
    parse_str((string)$parts['query'], $q);
    if (!is_array($q)) {
        return $url;
    }
    foreach (array_keys($q) as $k) {
        $lk = strtolower((string)$k);
        if (strpos($lk, 'utm_') === 0
            || in_array($lk, array('smid', 'smtyp', 'unlocked_article_code', 'giftcopy', 'ref', 'referrer', 'source', 'src', 'fbclid', 'gclid', 'mc_cid', 'mc_eid', 'cmpid', 'ncid', 'sh', 'share', 'shareid', 'partner', 'taid', 'guccounter'), true)) {
            unset($q[$k]);
        }
    }
    $rebuilt = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
        . (isset($parts['port']) ? ':' . $parts['port'] : '')
        . ($parts['path'] ?? '');
    $qs = http_build_query($q);
    if ($qs !== '') {
        $rebuilt .= '?' . $qs;
    }
    return $rebuilt;
}

function casual_techmeme_source_url(string $permalink, string $descriptionRaw): string
{
    // Techmeme's <link> is an aggregator permalink; the underlying article URL is the
    // first off-Techmeme <a href> in the description. Using the permalink would point
    // the Source: footer at Techmeme and, worse, defeat seen-URL dedup (a permalink is
    // unique per story even when the same article resurfaces).
    if (stripos($permalink, 'techmeme.com') === false) {
        return $permalink;
    }
    if ($descriptionRaw === '') {
        return $permalink;
    }
    $html = html_entity_decode($descriptionRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (!preg_match_all('/<a\\s[^>]*href=["\']([^"\']+)["\']/i', $html, $m) || !isset($m[1])) {
        return $permalink;
    }
    foreach ($m[1] as $href) {
        $href = trim((string)$href);
        if ($href === '' || stripos($href, 'http') !== 0) continue;
        $host = strtolower((string)parse_url($href, PHP_URL_HOST));
        if ($host === '' || strpos($host, 'techmeme.com') !== false) continue;
        // Skip bare publication homepages ("Bloomberg:"); we want the article itself.
        $path = trim((string)parse_url($href, PHP_URL_PATH), '/');
        if ($path === '') continue;
        return casual_strip_tracking_params($href);
    }
    return $permalink;
}

function casual_parse_feed_items(string $xml, int $maxItems): array
{
    $items = array();
    if ($xml === '') return $items;
    $blocks = array();
    if (preg_match_all('/<item\b[\s\S]*?<\/item>/i', $xml, $m) && isset($m[0])) {
        $blocks = $m[0];
    } elseif (preg_match_all('/<entry\b[\s\S]*?<\/entry>/i', $xml, $m2) && isset($m2[0])) {
        $blocks = $m2[0];
    }

    foreach ($blocks as $block) {
        $title = '';
        $link = '';
        $summary = '';
        $descriptionRaw = '';
        $summaryRaw = '';
        $contentRaw = '';
        $imageUrl = '';

        if (preg_match('/<title[^>]*>([\s\S]*?)<\/title>/i', $block, $t)) {
            $title = casual_decode_xml_text((string)$t[1]);
        }
        if (preg_match('/<link[^>]*>([\s\S]*?)<\/link>/i', $block, $l)) {
            $link = trim(casual_decode_xml_text((string)$l[1]));
        }
        if ($link === '' && preg_match('/<link[^>]*href=["\']([^"\']+)["\']/i', $block, $lh)) {
            $link = trim((string)$lh[1]);
        }
        if ($link === '' && preg_match('/<guid[^>]*>([\s\S]*?)<\/guid>/i', $block, $g)) {
            $guid = trim(casual_decode_xml_text((string)$g[1]));
            if (preg_match('/^https?:\/\//i', $guid)) $link = $guid;
        }
        if ($title === '' || $link === '' || stripos($link, 'http') !== 0) continue;

        if (preg_match('/<description[^>]*>([\s\S]*?)<\/description>/i', $block, $d)) {
            $descriptionRaw = (string)$d[1];
            $summary = casual_decode_xml_text((string)$d[1]);
        }
        if ($summary === '' && preg_match('/<summary[^>]*>([\s\S]*?)<\/summary>/i', $block, $s)) {
            $summaryRaw = (string)$s[1];
            $summary = casual_decode_xml_text((string)$s[1]);
        }
        if ($summary === '' && preg_match('/<content[^>]*>([\s\S]*?)<\/content>/i', $block, $c)) {
            $contentRaw = (string)$c[1];
            $summary = casual_decode_xml_text((string)$c[1]);
        }
        if (!casual_text_is_english_like($title . "\n" . $summary)) continue;

        $link = casual_techmeme_source_url($link, $descriptionRaw);

        $imageSources = array($block, $descriptionRaw, $summaryRaw, $contentRaw);
        foreach ($imageSources as $blob) {
            $cand = casual_extract_image_url_from_block((string)$blob);
            if ($cand !== '') {
                $imageUrl = $cand;
                break;
            }
        }

        $items[] = array(
            'title' => casual_normalize_title($title),
            'url' => trim($link),
            'summary' => trim((string)$summary),
            'image_url' => $imageUrl,
        );
        if (count($items) >= $maxItems) break;
    }
    return $items;
}

function casual_fetch_news_seed_candidates(int $max = 30): array
{
    // Feed items only get deduped against recently *generated forum titles*, never against
    // the source URL itself - so the same article (its RSS position doesn't change for days)
    // kept getting reworded into fresh-sounding topics that all cited the same source link.
    $seenUrls = casual_load_seen_urls();
    $all = array();
    $sources = casual_feed_sources();
    shuffle($sources);
    $processed = 0;
    foreach ($sources as $source) {
        if ($processed >= 6) break;
        $feed = trim((string)($source['feed'] ?? ''));
        if ($feed === '') continue;
        $processed++;
        $res = casual_fetch_url($feed);
        if (empty($res['ok'])) continue;
        $items = casual_parse_feed_items((string)($res['body'] ?? ''), 6);
        foreach ($items as $item) {
            $candidate = array(
                'title' => trim((string)($item['title'] ?? '')),
                'url' => trim((string)($item['url'] ?? '')),
                'summary' => trim((string)($item['summary'] ?? '')),
                'image_url' => trim((string)($item['image_url'] ?? '')),
                'kind' => trim((string)($source['kind'] ?? 'technology')),
                'source' => trim((string)($source['site'] ?? '')),
                'source_feed' => $feed,
            );
            if ($candidate['title'] === '' || $candidate['url'] === '') continue;
            if (isset($seenUrls[$candidate['url']])) continue;
            if (casual_item_looks_shopping_deal($candidate)) continue;
            if (casual_item_looks_controversial_topic($candidate)) continue;
            if (!casual_text_is_english_like($candidate['title'] . "\n" . $candidate['summary'])) continue;
            $all[] = $candidate;
            if (count($all) >= $max) break 2;
        }
    }

    $seen = array();
    $clean = array();
    foreach ($all as $item) {
        $key = casual_normalized_title_key((string)($item['title'] ?? ''));
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $clean[] = $item;
        if (count($clean) >= $max) break;
    }
    return $clean;
}

function casual_resolve_url(string $base, string $maybeRelative): string
{
    $maybeRelative = trim($maybeRelative);
    if ($maybeRelative === '') return '';
    if (preg_match('#^https?://#i', $maybeRelative)) return $maybeRelative;
    $baseParts = parse_url($base);
    if (!is_array($baseParts) || !isset($baseParts['scheme']) || !isset($baseParts['host'])) return '';
    $origin = $baseParts['scheme'] . '://' . $baseParts['host'] . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');
    if (strpos($maybeRelative, '//') === 0) return $baseParts['scheme'] . ':' . $maybeRelative;
    if (strpos($maybeRelative, '/') === 0) return $origin . $maybeRelative;
    return rtrim($origin . dirname((string)($baseParts['path'] ?? '/')), '/') . '/' . $maybeRelative;
}

function casual_extract_og_image(string $html): string
{
    $decode = static function ($s) {
        $s = html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8');
        $s = preg_replace('/\s+/', ' ', $s);
        return trim((string)$s);
    };
    if (preg_match('/<meta[^>]+property=["\']og:image(?::secure_url)?["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m)) {
        return $decode($m[1]);
    }
    if (preg_match('/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m)) {
        return $decode($m[1]);
    }
    return '';
}

function casual_fetch_page_html(string $url): array
{
    if (!function_exists('curl_init')) return array('ok' => false, 'body' => '');
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'konvo-casual-topic-worker/2.0',
    ));
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $err !== '' || $status < 200 || $status >= 300) {
        return array('ok' => false, 'body' => '');
    }
    return array('ok' => true, 'body' => (string)substr((string)$body, 0, 400000));
}

// Fallback only, used when the model forgot the [[IMAGE]] marker: insert after
// the first paragraph rather than trying to guess a sentence boundary.
function casual_insert_image_into_body(string $raw, string $imageMarkdown): string
{
    $imageMarkdown = trim($imageMarkdown);
    if ($imageMarkdown === '') return $raw;
    $parts = preg_split('/\n\n+/', trim($raw), 2);
    if (is_array($parts) && count($parts) === 2) {
        return trim($parts[0]) . "\n\n" . $imageMarkdown . "\n\n" . trim($parts[1]);
    }
    return trim($raw) . "\n\n" . $imageMarkdown;
}

// The model is instructed to place a [[IMAGE]] marker right after the sentence
// that introduces the post's main theme - that's a semantic call the model can
// make far better than a mechanical paragraph split. Swap it for real markdown
// when an image exists, or remove it cleanly (collapsing the blank lines it
// left behind) when there isn't one. Must always run, even when there is no
// seed URL at all, or the literal marker text would leak into the live post.
function casual_resolve_image_marker(string $raw, string $imageMarkdown): string
{
    $marker = '[[IMAGE]]';
    $hasMarker = strpos($raw, $marker) !== false;
    if ($imageMarkdown !== '') {
        if ($hasMarker) {
            return str_replace($marker, $imageMarkdown, $raw);
        }
        return casual_insert_image_into_body($raw, $imageMarkdown);
    }
    if (!$hasMarker) return $raw;
    $raw = preg_replace('/\n{1,}[ \t]*\[\[IMAGE\]\][ \t]*\n{1,}/', "\n\n", $raw) ?? $raw;
    $raw = str_replace($marker, '', $raw);
    $raw = preg_replace('/\n{3,}/', "\n\n", $raw) ?? $raw;
    return trim($raw);
}

// Best-effort only: any failure along the way (no seed URL, page fetch, or no
// og:image) just leaves the post as plain text - never blocks posting.
// References the image directly from the article's own host, no re-hosting.
function casual_try_attach_seed_image(string $seedUrl, string $raw): string
{
    $imageMarkdown = '';
    if ($seedUrl !== '' && preg_match('#^https?://#i', $seedUrl)) {
        $page = casual_fetch_page_html($seedUrl);
        if (!empty($page['ok'])) {
            $imageUrl = casual_resolve_url($seedUrl, casual_extract_og_image((string)$page['body']));
            if ($imageUrl !== '') {
                $imageMarkdown = '![image](' . $imageUrl . ')';
            }
        }
    }
    return casual_resolve_image_marker($raw, $imageMarkdown);
}

function casual_pick_interesting_news_seed(array $recentLocal, array $recentForumTitles): array
{
    $recentTitles = array();
    foreach ($recentLocal as $item) {
        if (!is_array($item)) continue;
        $t = trim((string)($item['title'] ?? ''));
        if ($t !== '') $recentTitles[] = $t;
    }
    foreach ($recentForumTitles as $t) {
        $t = trim((string)$t);
        if ($t !== '') $recentTitles[] = $t;
        if (count($recentTitles) >= 100) break;
    }

    $candidates = array();
    foreach (casual_fetch_news_seed_candidates(36) as $item) {
        $title = trim((string)($item['title'] ?? ''));
        $summary = trim((string)($item['summary'] ?? ''));
        if ($title === '') continue;
        if (casual_title_too_similar_to_recent($title, $recentTitles)) continue;
        if (casual_candidate_too_close_to_recent_local($title, $summary, $recentLocal)) continue;
        $candidates[] = $item;
        if (count($candidates) >= 12) break;
    }

    if ($candidates === array()) {
        return array(
            'seed_topic' => '',
            'seed_kind' => 'live_feed_unavailable',
            'seed_source' => '',
            'seed_url' => '',
            'seed_summary' => '',
        );
    }

    if (KONVO_ANTHROPIC_API_KEY !== '') {
        $candidateLines = array();
        foreach ($candidates as $idx => $item) {
            $candidateLines[] = ($idx + 1) . '. '
                . trim((string)($item['title'] ?? ''))
                . ' | source=' . trim((string)($item['source'] ?? ''))
                . ' | kind=' . trim((string)($item['kind'] ?? ''))
                . ' | summary=' . trim((string)($item['summary'] ?? ''));
        }
        $system = 'Pick the single most interesting live tech/design/gaming topic for a casual forum conversation. '
            . 'Return ONLY JSON with schema: {"pick":number,"reason":"...","angle":"..."}. '
            . 'Prefer a topic that can spark a conversational human take, not a dry summary.';
        $user = "Candidates:\n" . implode("\n", $candidateLines) . "\n\nReturn JSON now.";
        $payload = array(
            'model' => konvo_model_for_task('casual_topic_seed_pick'),
            'messages' => array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user', 'content' => $user),
            ),
            'temperature' => 0.35,
        );
        $res = casual_llm_json($payload);
        if (!empty($res['ok'])) {
            $content = trim((string)($res['json']['choices'][0]['message']['content'] ?? ''));
            $obj = casual_extract_json_object($content);
            $pick = (int)($obj['pick'] ?? 0);
            if ($pick >= 1 && $pick <= count($candidates)) {
                $picked = $candidates[$pick - 1];
                return array(
                    'seed_topic' => trim((string)($picked['title'] ?? '')),
                    'seed_kind' => trim((string)($picked['kind'] ?? 'technology')),
                    'seed_source' => trim((string)($picked['source'] ?? '')),
                    'seed_url' => trim((string)($picked['url'] ?? '')),
                    'seed_summary' => trim((string)($picked['summary'] ?? '')),
                    'seed_reason' => trim((string)($obj['reason'] ?? '')),
                    'seed_angle' => trim((string)($obj['angle'] ?? '')),
                );
            }
        }
    }

    shuffle($candidates);
    $picked = $candidates[0];
    return array(
        'seed_topic' => trim((string)($picked['title'] ?? '')),
        'seed_kind' => trim((string)($picked['kind'] ?? 'technology')),
        'seed_source' => trim((string)($picked['source'] ?? '')),
        'seed_url' => trim((string)($picked['url'] ?? '')),
        'seed_summary' => trim((string)($picked['summary'] ?? '')),
    );
}

function casual_normalized_title_key(string $title): string
{
    $s = strtolower(trim($title));
    if ($s === '') return '';
    $s = preg_replace('/https?:\/\/\S+/i', ' ', $s) ?? $s;
    $s = preg_replace('/[^a-z0-9\s]/', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

function casual_title_terms(string $title): array
{
    $s = casual_normalized_title_key($title);
    if ($s === '') return array();
    $parts = preg_split('/\s+/', $s);
    if (!is_array($parts)) return array();
    $stop = array(
        'the', 'a', 'an', 'and', 'or', 'to', 'of', 'in', 'for', 'on', 'with', 'is', 'are', 'be', 'it', 'that', 'this',
        'how', 'what', 'when', 'why', 'does', 'do', 'should', 'can', 'could', 'would', 'will', 'you', 'your', 'our',
        'my', 'we', 'they', 'them', 'their', 'me', 'i', 'at', 'by', 'from', 'as', 'if', 'than', 'then', 'vs', 'versus',
        'make', 'makes', 'made', 'making', 'better', 'best', 'worse', 'worst', 'feel', 'feels', 'felt', 'more', 'less',
        'really', 'just', 'still', 'very', 'much', 'too', 'about', 'around', 'into', 'out', 'over', 'under'
    );
    $out = array();
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p === '' || strlen($p) < 3) continue;
        if (str_ends_with($p, 'ies') && strlen($p) > 4) {
            $p = substr($p, 0, -3) . 'y';
        } elseif (str_ends_with($p, 'ing') && strlen($p) > 5) {
            $p = substr($p, 0, -3);
        } elseif (str_ends_with($p, 'ed') && strlen($p) > 4) {
            $p = substr($p, 0, -2);
        } elseif (str_ends_with($p, 's') && strlen($p) > 4 && !str_ends_with($p, 'ss')) {
            $p = substr($p, 0, -1);
        }
        if (in_array($p, $stop, true)) continue;
        $out[$p] = true;
    }
    return array_keys($out);
}

function casual_title_similarity_score(string $a, string $b): float
{
    $ta = casual_title_terms($a);
    $tb = casual_title_terms($b);
    if ($ta === array() || $tb === array()) return 0.0;
    $setA = array_fill_keys($ta, true);
    $setB = array_fill_keys($tb, true);
    $inter = 0;
    foreach ($setA as $k => $_) {
        if (isset($setB[$k])) $inter++;
    }
    $union = count($setA) + count($setB) - $inter;
    if ($union <= 0) return 0.0;
    return (float)$inter / (float)$union;
}

function casual_title_too_similar_to_recent(string $candidateTitle, array $recentTitles): bool
{
    $ck = casual_normalized_title_key($candidateTitle);
    if ($ck === '') return true;
    foreach ($recentTitles as $rt) {
        $rt = trim((string)$rt);
        if ($rt === '') continue;
        if (casual_normalized_title_key($rt) === $ck) return true;
        $sim = casual_title_similarity_score($candidateTitle, $rt);
        if ($sim >= 0.58) return true;
        $candTerms = casual_title_terms($candidateTitle);
        $rtTerms = casual_title_terms($rt);
        if ($candTerms !== array() && $rtTerms !== array()) {
            $setA = array_fill_keys($candTerms, true);
            $overlap = 0;
            foreach ($rtTerms as $tok) {
                if (isset($setA[$tok])) $overlap++;
            }
            // Catch "different wording, same core topic" pairs like tutorials+games.
            if ($overlap >= 2 && $sim >= 0.36) return true;
        }
    }
    return false;
}

function casual_topic_text_terms(string $title, string $raw = ''): array
{
    $blob = trim($title . "\n" . $raw);
    if ($blob === '') return array();
    $blob = preg_replace('/```[\s\S]*?```/m', ' ', (string)$blob) ?? $blob;
    $blob = preg_replace('/https?:\/\/\S+/i', ' ', (string)$blob) ?? $blob;
    $blob = preg_replace('/[^a-z0-9\s]/i', ' ', strtolower((string)$blob)) ?? strtolower((string)$blob);
    $blob = preg_replace('/\s+/', ' ', (string)$blob) ?? $blob;
    return casual_title_terms($blob);
}

function casual_semantic_similarity(string $titleA, string $rawA, string $titleB, string $rawB = ''): float
{
    $a = casual_topic_text_terms($titleA, $rawA);
    $b = casual_topic_text_terms($titleB, $rawB);
    if ($a === array() || $b === array()) return 0.0;
    $setA = array_fill_keys($a, true);
    $setB = array_fill_keys($b, true);
    $inter = 0;
    foreach ($setA as $k => $_) {
        if (isset($setB[$k])) $inter++;
    }
    $union = count($setA) + count($setB) - $inter;
    if ($union <= 0) return 0.0;
    return (float)$inter / (float)$union;
}

function casual_candidate_too_close_to_recent_local(string $candidateTitle, string $candidateRaw, array $recentLocal): bool
{
    foreach ($recentLocal as $item) {
        if (!is_array($item)) continue;
        $rt = trim((string)($item['title'] ?? ''));
        if ($rt === '') continue;
        $rr = trim((string)($item['raw'] ?? ''));
        $sim = casual_semantic_similarity($candidateTitle, $candidateRaw, $rt, $rr);
        if ($sim >= 0.41) return true;
        if (casual_title_too_similar_to_recent($candidateTitle, array($rt))) return true;
    }
    return false;
}

function casual_uniqueness_gate_with_llm(string $candidateTitle, string $candidateRaw, array $recentLocal, array $recentForum): array
{
    if (KONVO_ANTHROPIC_API_KEY === '') {
        return array('ok' => true, 'passes' => true, 'score' => 3.5, 'reason' => 'no_api_key_skip');
    }

    $localLines = casual_recent_hint_lines($recentLocal);
    $forumLines = array();
    $max = min(35, count($recentForum));
    for ($i = 0; $i < $max; $i++) {
        $t = trim((string)($recentForum[$i] ?? ''));
        if ($t === '') continue;
        $forumLines[] = '- ' . $t;
    }
    $forumHints = $forumLines === array() ? '(none)' : implode("\n", $forumLines);

    $system = 'You are a strict novelty judge for forum topic proposals. '
        . 'Return ONLY JSON with schema: {"passes":true|false,"novelty_score":0-5,"reason":"...","closest_match":"...","rewrite_hint":"..."}. '
        . 'Pass only when the candidate is clearly different in angle from recent topics, not merely reworded.';
    $user = "Candidate title:\n{$candidateTitle}\n\nCandidate body:\n{$candidateRaw}\n\n"
        . "Recent topics from this worker:\n{$localLines}\n\n"
        . "Recent forum topics:\n{$forumHints}\n\n"
        . "Judge novelty now.";
    $payload = array(
        'model' => konvo_model_for_task('topic_uniqueness'),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature' => 0.1,
    );

    $res = casual_llm_json($payload);
    if (!$res['ok']) {
        return array('ok' => false, 'passes' => false, 'score' => 0.0, 'reason' => 'uniqueness_llm_error');
    }
    $content = trim((string)($res['json']['choices'][0]['message']['content'] ?? ''));
    if ($content === '') {
        return array('ok' => false, 'passes' => false, 'score' => 0.0, 'reason' => 'uniqueness_empty');
    }
    $obj = casual_extract_json_object($content);
    if (!is_array($obj) || $obj === array()) {
        return array('ok' => false, 'passes' => false, 'score' => 0.0, 'reason' => 'uniqueness_parse_error');
    }
    $passes = !empty($obj['passes']);
    $score = (float)($obj['novelty_score'] ?? 0.0);
    if ($score < 0.0) $score = 0.0;
    if ($score > 5.0) $score = 5.0;
    $reason = trim((string)($obj['reason'] ?? ''));
    $closest = trim((string)($obj['closest_match'] ?? ''));
    $hint = trim((string)($obj['rewrite_hint'] ?? ''));
    return array(
        'ok' => true,
        'passes' => $passes && $score >= 4.0,
        'score' => $score,
        'reason' => $reason === '' ? 'no_reason' : $reason,
        'closest_match' => $closest,
        'rewrite_hint' => $hint,
    );
}

function casual_pick_bot(array $bots): array
{
    if ($bots === array()) {
        return array('username' => 'BayMax', 'name' => 'BayMax', 'soul_key' => 'baymax', 'soul_fallback' => 'Write naturally, concise, and human.');
    }
    $preferred = array(
        'BayMax', 'MechaPrime', 'yoshiii', 'bobamilk', 'wafflefries',
        'quelly', 'sora', 'sarah_connor', 'ellen1979', 'arthurdent', 'hariseldon'
    );
    $pool = array_values(array_filter($bots, static function (array $bot) use ($preferred): bool {
        $username = (string)($bot['username'] ?? '');
        return in_array($username, $preferred, true);
    }));
    if ($pool === array()) {
        $pool = $bots;
    }
    shuffle($pool);
    return $pool[0];
}

function casual_find_bot(array $bots, string $username): ?array
{
    $u = strtolower(trim($username));
    foreach ($bots as $bot) {
        $bu = strtolower(trim((string)($bot['username'] ?? '')));
        if ($bu !== '' && $bu === $u) return $bot;
    }
    return null;
}

function casual_is_gaming_topic(string $text): bool
{
    $t = strtolower(trim($text));
    if ($t === '') return false;
    if (!preg_match('/\b(video game|gaming|gameplay|trailer|clip|dlc|patch|xbox|playstation|ps5|ps4|nintendo|switch|steam|epic games|riot games|blizzard|ubisoft|capcom|fromsoftware|fortnite|minecraft|valorant|league of legends|rpg|fps|mmo|easter egg)\b/i', $t)) {
        return false;
    }
    // Keep obvious entertainment/movie chatter out of gaming category.
    if (preg_match('/\b(movie|film|tv show|television|box office|actor|actress|hollywood)\b/i', $t) && !preg_match('/\b(video game|gameplay|console|pc game)\b/i', $t)) {
        return false;
    }
    return true;
}

function casual_is_design_topic(string $text): bool
{
    $t = strtolower(trim($text));
    if ($t === '') return false;

    $physical = (bool)preg_match('/\b(architecture|architect|building|house|home|interior|pavilion|tower|skyscraper|museum|gallery|facade|façade|renovation|landscape architecture|urban planning|studio|residence)\b/i', $t);
    $uiux = (bool)preg_match('/\b(ui|ux|user interface|user experience|interaction design|visual design|design system|wireframe|prototype|figma|typography|color palette)\b/i', $t);
    if (!$physical && !$uiux) return false;
    if (!$physical && preg_match('/\b(system design|api design|database design|software architecture|computer architecture|backend architecture|technical design)\b/i', $t)) {
        return false;
    }
    return true;
}

function casual_is_allowed_topic_scope(string $text): bool
{
    $t = strtolower(trim($text));
    if ($t === '') return false;
    return (bool)preg_match(
        '/\b(ai|artificial intelligence|llm|language model|chatbot|agentic|automation|technology|tech|software|internet|web|browser|workflow|online community|social web|machine creativity|generative|video game|gaming|gameplay|player|npc|difficulty|level design|speedrun|retro game|science fiction|sci[- ]?fi|cyberpunk|space opera|futurism|product strategy|go to market|go-to-market|pricing|margin|hiring|business model|ux|ui|design system|creative process|developer experience|engineering culture|debugging|code review|product team)\b/i',
        $t
    );
}

function casual_has_depth_signal(string $text): bool
{
    $t = strtolower(trim($text));
    if ($t === '') return false;
    return (bool)preg_match(
        '/\b(tradeoff|trade-off|tension|constraint|second-order|side effect|friction|habit|workflow|trust|taste|craft|attention|memory|ownership|signal|meaning|quality|defaults|intuition|identity|abstraction|cost of convenience|creative process|human side|community|mastery|difficulty curve|discoverability|pricing pressure|hiring signal|creative control|player agency|team dynamics)\b/i',
        $t
    );
}

function casual_title_looks_question_like(string $title): bool
{
    $t = strtolower(trim($title));
    if ($t === '') return false;
    if (str_contains($t, '?')) return true;
    if (preg_match('/^(what|why|how|when|where|who|which|is|are|can|could|should|would|do|does|did|will|have|has|had)\b/i', $t)) return true;
    return false;
}

function casual_ensure_question_mark_title(string $title): string
{
    $title = trim($title);
    if ($title === '') return $title;
    if (!casual_title_looks_question_like($title)) return $title;
    $title = preg_replace('/[.!:;,\-]+$/', '', $title) ?? $title;
    $title = rtrim($title);
    if (!str_ends_with($title, '?')) $title .= '?';
    return $title;
}

function casual_normalize_title(string $title): string
{
    $title = trim(strip_tags($title));
    $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
    $title = preg_replace('/\s+/', ' ', $title) ?? $title;
    $title = trim($title, " \t\n\r\0\x0B\"'`");
    if ($title === '') return '';
    if (strlen($title) > 88) {
        $short = trim((string)substr($title, 0, 88));
        $lastSpace = strrpos($short, ' ');
        if ($lastSpace !== false && $lastSpace > 28) {
            $short = trim((string)substr($short, 0, (int)$lastSpace));
        }
        $title = $short;
    }
    $title = preg_replace('/[:;,\.\-]+$/', '', $title) ?? $title;
    $title = trim($title);
    return $title;
}

function casual_normalize_signature(string $text, string $signature): string
{
    $candidates = function_exists('konvo_signature_name_candidates')
        ? konvo_signature_name_candidates($signature)
        : array($signature);
    if (!is_array($candidates) || count($candidates) === 0) $candidates = array($signature);

    $lines = preg_split('/\R/', trim((string)$text));
    if (!is_array($lines)) $lines = array();
    while (!empty($lines)) {
        $last = trim((string)end($lines));
        $matched = false;
        foreach ($candidates as $candidate) {
            if (preg_match('/^' . preg_quote((string)$candidate, '/') . '\\.?$/i', $last)) {
                $matched = true;
                break;
            }
        }
        if ($last === '' || $matched) {
            array_pop($lines);
            continue;
        }
        break;
    }

    $body = trim(implode("\n", $lines));
    foreach ($candidates as $candidate) {
        $body = preg_replace('/\s+' . preg_quote((string)$candidate, '/') . '\\.?$/i', '', (string)$body) ?? $body;
    }
    $body = trim((string)$body);
    if ($body === '') return '';
    return $body;
}

function casual_quirky_media_urls(): array
{
    return array(
        'https://media.giphy.com/media/5VKbvrjxpVJCM/giphy.gif',
        'https://media.giphy.com/media/13CoXDiaCcCoyk/giphy.gif',
        'https://media.giphy.com/media/l0HlBO7eyXzSZkJri/giphy.gif',
        'https://media.giphy.com/media/3oEjI6SIIHBdRxXI40/giphy.gif',
        'https://media.giphy.com/media/26ufdipQqU2lhNA4g/giphy.gif',
        'https://media.giphy.com/media/3o7aCTfyhYawdOXcFW/giphy.gif',
        'https://media.giphy.com/media/l3q2K5jinAlChoCLS/giphy.gif',
    );
}

function casual_media_url_is_reachable(string $url): bool
{
    $u = trim($url);
    if ($u === '' || !preg_match('/^https?:\/\/\S+$/i', $u)) return false;
    static $cache = array();
    if (isset($cache[$u])) return (bool)$cache[$u];

    if (!function_exists('curl_init')) {
        $cache[$u] = false;
        return false;
    }

    $ch = curl_init($u);
    curl_setopt_array($ch, array(
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERAGENT => 'konvo-casual-worker/1.0',
    ));
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = strtolower(trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
    $err = curl_error($ch);
    curl_close($ch);

    $ok = ($err === '' && $status >= 200 && $status < 400 && preg_match('/\b(image|video)\b/i', $ctype));
    $cache[$u] = (bool)$ok;
    return (bool)$ok;
}

function casual_pick_quirky_media_url(string $seed): string
{
    $urls = casual_quirky_media_urls();
    if ($urls === array()) return '';
    $hash = abs((int)crc32(strtolower(trim($seed))));
    $count = count($urls);
    $start = $hash % $count;
    for ($i = 0; $i < $count; $i++) {
        $idx = ($start + $i) % $count;
        $cand = trim((string)$urls[$idx]);
        if ($cand !== '' && casual_media_url_is_reachable($cand)) {
            return $cand;
        }
    }
    return '';
}

function casual_append_quirky_media_before_signature(string $raw, string $signature, string $url): string
{
    $url = trim($url);
    if ($url === '' || !preg_match('/^https?:\/\/\S+$/i', $url)) {
        return casual_normalize_signature($raw, $signature);
    }
    $norm = casual_normalize_signature($raw, $signature);
    if (!preg_match('/https?:\/\/\S+/i', $norm)) {
        $norm = trim($norm) . "\n\n" . $url;
    }
    return casual_normalize_signature($norm, $signature);
}

function casual_normalize_body(string $raw, string $signature): string
{
    $raw = str_replace(array("\r\n", "\r"), "\n", (string)$raw);
    $raw = trim($raw);
    $raw = preg_replace('/\n{3,}/', "\n\n", $raw) ?? $raw;
    if ($raw === '') return '';
    return casual_normalize_signature($raw, $signature);
}

function casual_trim_body_to_limit(string $raw, int $maxBytes): string
{
    // The model overshoots the length cap even when the prompt states it, so enforce it
    // deterministically instead of burning a retry. Drop whole sentences from the end so
    // the result still reads as finished prose, and never drop the [[IMAGE]] marker
    // (image placement depends on it).
    $raw = trim(str_replace(array("\r\n", "\r"), "\n", $raw));
    if ($maxBytes <= 0 || strlen($raw) <= $maxBytes) {
        return $raw;
    }

    $marker = '[[IMAGE]]';
    $paras = preg_split('/\n{2,}/', $raw) ?: array($raw);

    // Flatten into removable units: array(paragraph index, text, isMarker).
    $units = array();
    foreach ($paras as $pi => $para) {
        if (trim($para) === $marker) {
            $units[] = array($pi, $para, true);
            continue;
        }
        if (preg_match_all('/.*?[.!?](?:\s+|$)|.+$/s', $para, $m) && !empty($m[0])) {
            foreach ($m[0] as $sentence) {
                if (trim($sentence) === '') continue;
                $units[] = array($pi, $sentence, false);
            }
        } else {
            $units[] = array($pi, $para, false);
        }
    }

    $render = static function (array $units): string {
        $byPara = array();
        foreach ($units as $u) {
            $byPara[$u[0]] = ($byPara[$u[0]] ?? '') . $u[1];
        }
        $parts = array();
        foreach ($byPara as $text) {
            $text = trim($text);
            if ($text !== '') $parts[] = $text;
        }
        return trim(implode("\n\n", $parts));
    };

    // Remove trailing sentences (never the marker) until it fits.
    while (strlen($render($units)) > $maxBytes) {
        $removed = false;
        for ($i = count($units) - 1; $i >= 0; $i--) {
            if ($units[$i][2]) continue;
            array_splice($units, $i, 1);
            $removed = true;
            break;
        }
        if (!$removed) break;
        $remaining = 0;
        foreach ($units as $u) { if (!$u[2]) $remaining++; }
        if ($remaining <= 1) break;
    }

    $out = $render($units);
    if (strlen($out) > $maxBytes) {
        $out = trim(rtrim(substr($out, 0, $maxBytes), " \t\n"));
    }
    return $out;
}

function casual_append_source_reference(string $raw, string $sourceUrl, string $sourceTitle = ''): string
{
    $sourceUrl = trim($sourceUrl);
    if ($sourceUrl === '' || !preg_match('/^https?:\/\/\S+$/i', $sourceUrl)) {
        return trim($raw);
    }

    $raw = trim(str_replace(array("\r\n", "\r"), "\n", (string)$raw));
    if ($raw === '') {
        return 'source: ' . $sourceUrl;
    }

    $link = function_exists('konvo_markdown_link')
        ? konvo_markdown_link($sourceUrl, $sourceTitle)
        : $sourceUrl;

    // Matches both the old bare-URL footer and the linked form, so existing
    // posts and regenerated drafts are both replaced cleanly.
    $existing = '/(^|\n)source:\s*(?:\[[^\]]*\]\(\s*)?https?:\/\/\S+/i';
    if (preg_match($existing, $raw)) {
        $raw = preg_replace($existing, '$1source: ' . $link, $raw, 1) ?? $raw;
        return trim($raw);
    }

    if (stripos($raw, $sourceUrl) !== false) {
        return $raw;
    }

    return $raw . "\n\nsource: " . $link;
}

function casual_has_controversial_signals(string $text): bool
{
    $t = strtolower(trim($text));
    if ($t === '') return false;
    $patterns = array(
        '/\b(politic|election|democrat|republican|senate|president|trump|biden|left wing|right wing)\b/i',
        '/\b(war|genocide|military conflict|terror|terrorism|weapon)\b/i',
        '/\b(religion|god|church|islam|christian|hindu|jewish|bible|quran)\b/i',
        '/\b(abortion|immigration|racism|sexism|sexual assault|violence|crime)\b/i',
        '/\b(vaccine|pandemic|covid|disease outbreak|public health emergency)\b/i',
        '/\b(stock pick|crypto pump|betting|gambling tip)\b/i',
    );
    foreach ($patterns as $p) {
        if (preg_match($p, $t)) return true;
    }
    return false;
}

function casual_looks_too_technical(string $text): bool
{
    $t = strtolower(trim($text));
    if ($t === '') return false;
    return (bool)preg_match('/\b(javascript|typescript|css|html|react|vue|angular|api endpoint|database schema|backend|frontend|docker|kubernetes|ci\/cd|compiler|runtime|stack trace|queryselector|npm|package\.json|php warning|sql query)\b/i', $t);
}

function casual_validate_generated_topic(string $title, string $raw): array
{
    $title = trim($title);
    $raw = trim($raw);

    if ($title === '' || strlen($title) < 8) {
        return array('ok' => false, 'error' => 'title too short');
    }
    if (strlen($title) > 70) {
        return array('ok' => false, 'error' => 'title too long (' . strlen($title) . ' chars, max 70)');
    }
    // Word cap is what actually stops the run-on headlines that get truncated
    // mid-thought ("... because Western versions require").
    $titleWords = preg_split('/\s+/', trim($title)) ?: array();
    if (count($titleWords) > 8) {
        return array('ok' => false, 'error' => 'title too long (' . count($titleWords) . ' words, max 8)');
    }
    if ($raw === '' || strlen($raw) < 40) {
        return array('ok' => false, 'error' => 'body too short');
    }
    // Measure prose only. The trailing "source: <url>" footer is metadata, and real
    // article URLs (80-150 bytes) would otherwise eat most of the post's budget and
    // force the body to be truncated mid-thought.
    $bodyOnly = trim(preg_replace('/\n*^source:\s*(?:\[[^\]]*\]\([^)]*\)|https?:\/\/\S+)\s*$/im', '', $raw) ?? $raw);
    if (strlen($bodyOnly) > 520) {
        return array('ok' => false, 'error' => 'body too long (' . strlen($bodyOnly) . ' chars, max 520)');
    }
    // Content gating is intentionally omitted: the single curated source (Techmeme)
    // is already a tech-news feed, so scope/technical/depth heuristics only rejected
    // good posts. Structural checks (length, no code block) still apply.

    if (casual_has_foreign_script($title . "\n" . $raw)) {
        return array('ok' => false, 'error' => 'non-English characters leaked into the post');
    }
    if (strpos($raw, '```') !== false) {
        return array('ok' => false, 'error' => 'code block not expected for casual topic');
    }
    return array('ok' => true);
}

function casual_extract_json_object(string $content): array
{
    $content = trim($content);
    if ($content === '') return array();

    if ($content[0] === '{') {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) return $decoded;
    }

    $start = strpos($content, '{');
    $end = strrpos($content, '}');
    if ($start === false || $end === false || $end <= $start) return array();

    $slice = substr($content, (int)$start, (int)($end - $start + 1));
    $decoded = json_decode($slice, true);
    return is_array($decoded) ? $decoded : array();
}

function casual_llm_json(array $payload): array
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'status' => 0, 'error' => 'curl_init unavailable', 'json' => array(), 'raw' => '');
    }

    $res = konvo_anthropic_chat_json($payload, 60);
    $json = is_array($res['body'] ?? null) ? $res['body'] : array();
    return array(
        'ok' => (bool)($res['ok'] ?? false),
        'status' => (int)($res['status'] ?? 0),
        'error' => (string)($res['error'] ?? ''),
        'json' => $json,
        'raw' => $json === array() ? '' : (string)json_encode($json, JSON_UNESCAPED_SLASHES),
    );
}

function casual_pick_category_with_llm(string $title, string $raw, array $bot = array(), array $plan = array()): array
{
    $fallback = array(
        'ok' => false,
        'category_key' => 'talk',
        'category_id' => (int)KONVO_TALK_CATEGORY_ID,
        'reason' => 'category_llm_unavailable_fallback_talk',
        'confidence' => 0.0,
    );
    if (KONVO_ANTHROPIC_API_KEY === '') {
        return $fallback;
    }

    $botName = trim((string)($bot['name'] ?? 'BayMax'));
    $planMood = trim((string)($plan['mood'] ?? ''));
    $planAngle = trim((string)($plan['angle'] ?? ''));
    $planIntent = trim((string)($plan['posting_intent'] ?? ''));

    $system = 'Classify this forum topic into one category and return JSON only. '
        . 'Schema: {"category":"talk|web_dev|design|gaming","reason":"...","confidence":0.0}. '
        . 'Category rules: '
        . 'talk = broad thoughtful discussion about AI, technology, digital life, online communities, or creative tools that is not a coding help thread. '
        . 'web_dev = programming/software engineering/web development/technical architecture in software. '
        . 'design = UI/UX/visual design OR physical architecture/interior design. '
        . 'gaming = video games/gameplay/trailers/game culture. '
        . 'Important: software contexts using words like build/building/design/architecture/system belong to web_dev, not design. '
        . 'Pick exactly one category.';
    $user = "Topic title:\n{$title}\n\n"
        . "Topic body:\n{$raw}\n\n"
        . "Bot: {$botName}\n"
        . "Plan mood: {$planMood}\n"
        . "Plan angle: {$planAngle}\n"
        . "Plan intent: {$planIntent}\n\n"
        . "Return JSON now.";

    $payload = array(
        'model' => konvo_model_for_task('topic_category'),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature' => 0.1,
    );

    $res = casual_llm_json($payload);
    if (!$res['ok']) {
        return $fallback;
    }
    $json = $res['json'];
    $content = trim((string)($json['choices'][0]['message']['content'] ?? ''));
    if ($content === '') {
        return $fallback;
    }
    $obj = casual_extract_json_object($content);
    if (!is_array($obj) || $obj === array()) {
        return $fallback;
    }

    $key = strtolower(trim((string)($obj['category'] ?? '')));
    $map = array(
        'talk' => (int)KONVO_TALK_CATEGORY_ID,
        'general' => (int)KONVO_TALK_CATEGORY_ID,
        'web_dev' => (int)KONVO_WEBDEV_CATEGORY_ID,
        'webdev' => (int)KONVO_WEBDEV_CATEGORY_ID,
        'web-dev' => (int)KONVO_WEBDEV_CATEGORY_ID,
        'programming' => (int)KONVO_WEBDEV_CATEGORY_ID,
        'technical' => (int)KONVO_WEBDEV_CATEGORY_ID,
        'design' => (int)KONVO_DESIGN_CATEGORY_ID,
        'gaming' => (int)KONVO_GAMING_CATEGORY_ID,
        'games' => (int)KONVO_GAMING_CATEGORY_ID,
    );
    if (!isset($map[$key])) {
        return $fallback;
    }

    $categoryId = (int)$map[$key];
    $normalizedKey = 'talk';
    if ($categoryId === (int)KONVO_WEBDEV_CATEGORY_ID) $normalizedKey = 'web_dev';
    if ($categoryId === (int)KONVO_DESIGN_CATEGORY_ID) $normalizedKey = 'design';
    if ($categoryId === (int)KONVO_GAMING_CATEGORY_ID) $normalizedKey = 'gaming';

    $confidence = (float)($obj['confidence'] ?? 0.0);
    if ($confidence < 0.0) $confidence = 0.0;
    if ($confidence > 1.0) $confidence = 1.0;
    $reason = trim((string)($obj['reason'] ?? ''));
    if ($reason === '') $reason = 'llm_category_decision';

    return array(
        'ok' => true,
        'category_key' => $normalizedKey,
        'category_id' => $categoryId,
        'reason' => $reason,
        'confidence' => $confidence,
    );
}

function casual_seed_topic_pool(): array
{
    return array(
        'game tutorials vs discovery',
        'retro game difficulty and modern expectations',
        'sci-fi computer assistants vs real AI tools',
        'shipping speed vs code quality',
        'design polish vs product momentum',
        'remote work rituals for deep focus',
        'feature creep vs simplicity',
        'creator tools that remove too much friction',
        'automation convenience vs skill atrophy',
        'team ownership vs platform standardization',
        'ui clarity vs playful ambiguity',
        'onboarding speed vs long-term mastery',
        'engineering culture and review quality',
        'product metrics vs user delight',
        'ai copilots and developer confidence',
        'small-batch software vs giant all-in-one apps',
        'creative flow interruptions from notifications',
        'debugging habits that actually scale',
        'indie game design tradeoffs',
        'animation polish vs performance budgets',
        'community trust and transparent product decisions',
        'open-source dependency risk vs shipping pressure',
        'toolchain churn and developer fatigue',
        'healthy defaults vs user control',
        'personal productivity systems that stick',
    );
}

function casual_pick_random_seed_topic(array $recentLocal, array $recentForumTitles): array
{
    $picked = casual_pick_interesting_news_seed($recentLocal, $recentForumTitles);
    if (trim((string)($picked['seed_topic'] ?? '')) !== '') return $picked;

    $pool = casual_seed_topic_pool();
    $candidates = array();
    $recentTitles = array();
    foreach ($recentLocal as $item) {
        if (!is_array($item)) continue;
        $t = trim((string)($item['title'] ?? ''));
        if ($t !== '') $recentTitles[] = $t;
    }
    foreach ($recentForumTitles as $t) {
        $t = trim((string)$t);
        if ($t !== '') $recentTitles[] = $t;
        if (count($recentTitles) >= 80) break;
    }
    foreach ($pool as $seed) {
        $seed = trim((string)$seed);
        if ($seed === '') continue;
        $tooClose = false;
        foreach ($recentTitles as $rt) {
            if (casual_title_too_similar_to_recent($seed, array($rt))) {
                $tooClose = true;
                break;
            }
        }
        if (!$tooClose) $candidates[] = $seed;
    }
    if ($candidates === array()) $candidates = $pool;
    shuffle($candidates);
    return array(
        'seed_topic' => (string)$candidates[0],
        'seed_kind' => 'fallback_pool_after_feed_miss',
        'seed_source' => '',
        'seed_url' => '',
        'seed_summary' => '',
    );
}

function casual_generate_with_llm(array $bot, string $signature, array $recent, array $recentForumTitles, bool $strict, string $extraAvoidance = '', array $lane = array()): array
{
    $botName = trim((string)($bot['name'] ?? 'BayMax'));
    $soulKey = trim((string)($bot['soul_key'] ?? strtolower($botName)));
    $soulFallback = trim((string)($bot['soul_fallback'] ?? 'Write naturally, concise, and human.'));
    $soulPrompt = function_exists('konvo_compose_casual_topic_persona_prompt')
        ? konvo_compose_casual_topic_persona_prompt(konvo_load_soul($soulKey, $soulFallback))
        : konvo_load_soul($soulKey, $soulFallback);
    $recentHints = casual_recent_hint_lines($recent);
    $seedMeta = casual_pick_random_seed_topic($recent, $recentForumTitles);
    $seedTopic = trim((string)($seedMeta['seed_topic'] ?? ''));
    $seedKind = trim((string)($seedMeta['seed_kind'] ?? 'fallback_pool'));
    $seedSource = trim((string)($seedMeta['seed_source'] ?? ''));
    $seedUrl = trim((string)($seedMeta['seed_url'] ?? ''));
    $seedSummary = trim((string)($seedMeta['seed_summary'] ?? ''));
    $seedAngleHint = trim((string)($seedMeta['seed_angle'] ?? ''));

    $system = ($soulPrompt !== '' ? "Bot voice and personality guidance:\n{$soulPrompt}\n\n" : '')
        . 'You generate a single casual forum discussion starter for humans. '
        . 'Return ONLY JSON with this schema: '
        . '{"plan_mood":"...","plan_angle":"...","plan_posting_intent":"...","plan_lane":"...","title":"...","raw":"..."}. '
        . 'Turn the seed topic into one conversational forum post with a clear opinionated observation. '
        . 'The seed comes from a live article. Do NOT summarize it like a digest. React to it like a person who just read it and has one real thought. '
        . 'TITLE: write roughly a 5 word description that makes someone want to open the thread. '
        . 'It can be a short summary, your own reinterpretation, or a question expressing curiosity. '
        . 'Never a news headline, never a sentence that runs out of room and stops mid-thought. Hard maximum 8 words. '
        . 'Keep the body short, conversational, and human. '
        . 'Keep the body under 500 characters, roughly 3 to 5 short sentences. Say your piece and stop; do not add a wrap-up sentence. '
        . 'No code blocks, no hashtags, no sign-off line. '
        . 'Never use an em dash (—), for any reason. If a clause wants one, split it into two separate sentences instead. '
        . 'If you end on a question after making your point, put a blank line before it so it lands as its own short paragraph. Never tack it onto the end of the same block of text. '
        . 'Immediately after the sentence that introduces the main theme/subject of the post, insert the exact marker [[IMAGE]] alone on its own line, with a blank line before and after it. Always include this marker exactly once, even though you do not know yet whether an image will actually be placed there.';

    $user = "Seed topic: {$seedTopic}\n"
        . "Seed kind: {$seedKind}\n"
        . ($seedSource !== '' ? "Seed source: {$seedSource}\n" : '')
        . ($seedUrl !== '' ? "Seed URL: {$seedUrl}\n" : '')
        . ($seedSummary !== '' ? "Seed summary: {$seedSummary}\n" : '')
        . ($seedAngleHint !== '' ? "Interesting angle hint: {$seedAngleHint}\n" : '')
        . "Generate one post from this seed.\n"
        . "Use the article as context only. The post itself should sound like a quick thought you had after reading it.\n"
        . "Give the title as a statement, not a news headline.\n"
        . "Set plan_lane to a short lane label if useful, otherwise keep it simple.\n"
        . "Recent topics to avoid repeating:\n{$recentHints}\n\n"
        . ($strict ? "This is a regeneration attempt. Pick a clearly different thought and rhythm than prior drafts.\n" : '')
        . ($extraAvoidance !== '' ? ("Avoidance hint: " . trim($extraAvoidance) . "\n") : '')
        . "Return JSON only.";

    $payload = array(
        'model' => konvo_model_for_task('casual_topic'),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature' => 0.9,
    );

    $res = casual_llm_json($payload);
    if (!$res['ok']) {
        return array('ok' => false, 'error' => 'Claude request failed', 'detail' => $res['error'], 'status' => $res['status']);
    }

    $json = $res['json'];
    $content = trim((string)($json['choices'][0]['message']['content'] ?? ''));
    if ($content === '') {
        return array('ok' => false, 'error' => 'Model returned empty content');
    }

    $obj = casual_extract_json_object($content);
    if (!is_array($obj) || $obj === array()) {
        return array('ok' => false, 'error' => 'Model returned non-JSON content', 'raw' => $content);
    }

    $title = casual_normalize_title((string)($obj['title'] ?? ''));
    $raw = casual_normalize_body((string)($obj['raw'] ?? ''), $signature);
    if (function_exists('konvo_break_up_em_dashes')) {
        $raw = konvo_break_up_em_dashes($raw);
    }
    if (function_exists('konvo_break_before_closing_question')) {
        $raw = konvo_break_before_closing_question($raw);
    }
    // Hard-enforce the prose cap before the source footer is appended.
    $raw = casual_trim_body_to_limit($raw, 520);
    $raw = casual_append_source_reference($raw, $seedUrl, $seedTopic);
    $planMood = trim((string)($obj['plan_mood'] ?? ''));
    $planAngle = trim((string)($obj['plan_angle'] ?? ''));
    $planIntent = trim((string)($obj['plan_posting_intent'] ?? ''));
    $planLane = trim((string)($obj['plan_lane'] ?? $laneKey));

    if ($title === '' || $raw === '') {
        return array('ok' => false, 'error' => 'Model JSON missing title/raw', 'parsed' => $obj);
    }

    $valid = casual_validate_generated_topic($title, $raw);
    if (!$valid['ok']) {
        return array('ok' => false, 'error' => (string)($valid['error'] ?? 'validation failed'), 'title' => $title, 'raw' => $raw);
    }

    return array(
        'ok' => true,
        'title' => $title,
        'raw' => $raw,
        'plan' => array(
            'mood' => $planMood,
            'angle' => $planAngle,
            'posting_intent' => $planIntent,
            'lane' => $planLane,
            'seed_topic' => $seedTopic,
            'seed_kind' => $seedKind,
            'seed_source' => $seedSource,
            'seed_url' => $seedUrl,
        ),
    );
}

function casual_post_topic(string $botUsername, string $title, string $raw, int $categoryId): array
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'status' => 0, 'error' => 'curl_init unavailable', 'body' => array(), 'raw' => '');
    }

    $payload = array(
        'title' => $title,
        'raw' => $raw,
        'category' => $categoryId,
    );

    $ch = curl_init(rtrim(KONVO_BASE_URL, '/') . '/posts.json');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 35,
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
        'body' => is_array($decoded) ? $decoded : array(),
        'raw' => (string)$body,
    );
}

$providedKey = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (KONVO_SECRET === '') {
    casual_out(500, array('ok' => false, 'error' => 'DISCOURSE_WEBHOOK_SECRET is not configured on the server.'));
}
if ($providedKey === '' || !safe_hash_equals(KONVO_SECRET, $providedKey)) {
    casual_out(403, array('ok' => false, 'error' => 'Forbidden', 'hint' => 'Pass ?key=YOUR_SECRET'));
}
if (KONVO_API_KEY === '') {
    casual_out(500, array('ok' => false, 'error' => 'DISCOURSE_API_KEY is not configured on the server.'));
}
if (KONVO_ANTHROPIC_API_KEY === '') {
    casual_out(500, array('ok' => false, 'error' => 'ANTHROPIC_API_KEY is not configured on the server.'));
}

$dryRun = isset($_GET['dry_run']) && (string)$_GET['dry_run'] === '1';
if (!$dryRun && !casual_acquire_run_lock()) {
    casual_out(200, array(
        'ok' => true,
        'posted' => false,
        'reason' => 'Skipped: another instance of this worker is already running (overlapping cron trigger).',
    ));
}
$force = isset($_GET['force']) && (string)$_GET['force'] === '1';
$allowNewTopicsEnv = strtolower(trim((string)getenv('KONVO_ALLOW_NEW_TOPICS')));
$allowNewTopics = in_array($allowNewTopicsEnv, array('1', 'true', 'yes', 'on'), true);
$allowCasualEnv = strtolower(trim((string)KONVO_ALLOW_CASUAL_TOPIC_POSTS));
$allowCasualTopics = ($allowCasualEnv === '')
    ? true
    : in_array($allowCasualEnv, array('1', 'true', 'yes', 'on'), true);
$allowPosting = $allowNewTopics || $allowCasualTopics;

if (!$dryRun && !$allowPosting && !$force) {
    casual_out(200, array(
        'ok' => true,
        'posted' => false,
        'reason' => 'new_topic_creation_disabled',
        'hint' => 'Set KONVO_ALLOW_CASUAL_TOPIC_POSTS=1 (or KONVO_ALLOW_NEW_TOPICS=1) or pass force=1 to override.',
    ));
}

$bot = casual_pick_bot($bots);
$signatureSeed = strtolower((string)($bot['username'] ?? 'baymax') . '|casual-topic|' . date('Y-m-d-H'));
$signature = function_exists('konvo_signature_with_optional_emoji')
    ? konvo_signature_with_optional_emoji((string)($bot['name'] ?? 'BayMax'), $signatureSeed)
    : (string)($bot['name'] ?? 'BayMax');
$recent = casual_load_recent_topics();
$lane = casual_pick_interest_lane($recent);
$laneOverride = trim((string)($_GET['lane'] ?? ''));
if ($laneOverride !== '') {
    $over = casual_lane_from_key($laneOverride, $recent);
    if (is_array($over)) {
        $lane = $over;
    }
}
$today = casual_today_key();
if (!$dryRun && !$force) {
    $todayCount = casual_daily_count_for($today);
    if ($todayCount >= 3) {
        casual_out(200, array(
            'ok' => true,
            'posted' => false,
            'reason' => 'daily_casual_topic_cap_reached',
            'date' => $today,
            'today_post_count' => $todayCount,
            'daily_cap' => 3,
        ));
    }
}
$recentForumTitles = casual_fetch_latest_topic_titles(60);

$attempts = array();
$generated = null;
$bestFallback = null;
$bestFallbackScore = -1.0;
$extraAvoidance = '';
$requestStartTs = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float)$_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
for ($i = 0; $i < 3; $i++) {
    if ((microtime(true) - $requestStartTs) > 24.0) {
        break;
    }
    $strict = $i > 0;
    $res = casual_generate_with_llm($bot, $signature, $recent, $recentForumTitles, $strict, $extraAvoidance, $lane);
    if (!empty($res['ok'])) {
        $tooSimilar = casual_title_too_similar_to_recent((string)($res['title'] ?? ''), $recentForumTitles);
        if ($tooSimilar) {
            $res = array('ok' => false, 'error' => 'title too similar to recent forum topics', 'title' => (string)($res['title'] ?? ''));
        }
    }
    if (!empty($res['ok'])) {
        $localNovel = !casual_candidate_too_close_to_recent_local((string)$res['title'], (string)$res['raw'], $recent)
            && !casual_title_too_similar_to_recent((string)$res['title'], $recentForumTitles);
        $gate = array(
            'ok' => true,
            'passes' => $localNovel,
            'score' => $localNovel ? 5.0 : 2.0,
            'reason' => $localNovel ? 'fast_local_uniqueness_pass' : 'fast_local_uniqueness_reject',
            'closest_match' => '',
            'rewrite_hint' => $localNovel ? '' : 'Pick a different angle and wording from recent topics.',
        );
        $res['uniqueness_gate'] = $gate;
        if (!empty($gate['ok']) && empty($gate['passes'])) {
            $score = (float)($gate['score'] ?? 0.0);
            if ($score > $bestFallbackScore) {
                $bestFallbackScore = $score;
                $bestFallback = $res;
            }
            $res = array(
                'ok' => false,
                'error' => 'uniqueness gate rejected candidate',
                'gate' => $gate,
                'title' => (string)($res['title'] ?? ''),
            );
        } elseif (empty($gate['ok'])) {
            // If novelty gate itself fails, keep best generated draft so we don't hard-fail.
            if ($bestFallback === null) {
                $bestFallback = $res;
                $bestFallbackScore = 3.6;
            }
        }
    }
    $attempts[] = $res;
    if (!empty($res['ok'])) {
        $generated = $res;
        break;
    }
    $err = trim((string)($res['error'] ?? ''));
    $gateHint = is_array($res['gate'] ?? null) ? trim((string)($res['gate']['rewrite_hint'] ?? '')) : '';
    $closest = is_array($res['gate'] ?? null) ? trim((string)($res['gate']['closest_match'] ?? '')) : '';
    $pieces = array();
    if ($err !== '') $pieces[] = $err;
    if ($closest !== '') $pieces[] = 'Too close to: ' . $closest;
    if ($gateHint !== '') $pieces[] = 'Rewrite hint: ' . $gateHint;
    $extraAvoidance = implode(' ', $pieces);
}

if (!is_array($generated) && is_array($bestFallback) && $bestFallback !== array()) {
    $generated = $bestFallback;
}

if (!is_array($generated) || empty($generated['ok'])) {
    $errors = array();
    foreach ($attempts as $a) {
        $errors[] = isset($a['error']) ? (string)$a['error'] : 'unknown generation failure';
    }
    casual_out(502, array(
        'ok' => false,
        'error' => 'Failed to generate casual topic with model.',
        'attempt_errors' => $errors,
    ));
}

$title = (string)$generated['title'];
$raw = (string)$generated['raw'];
$plan = isset($generated['plan']) && is_array($generated['plan']) ? $generated['plan'] : array();
$raw = casual_try_attach_seed_image(trim((string)($plan['seed_url'] ?? '')), $raw);
$categoryDecision = array(
    'ok' => true,
    'category_key' => 'talk',
    'category_id' => (int)KONVO_TALK_CATEGORY_ID,
    'reason' => 'forced_ai_tech_discussion_mode',
    'confidence' => 1.0,
);
$categoryId = (int)KONVO_TALK_CATEGORY_ID;
$gamingDetected = false;
$quirkyMode = false;
$quirkyMediaUrl = '';

if ($dryRun) {
    casual_out(200, array(
        'ok' => true,
        'dry_run' => true,
        'action' => 'would_post_casual_topic',
        'bot' => $bot,
        'plan' => $plan,
        'lane' => $lane,
        'topic' => array(
            'title' => $title,
            'category_id' => $categoryId,
            'raw_preview' => $raw,
            'gaming_detected' => $gamingDetected,
            'category_decision' => $categoryDecision,
        ),
        'quirky_media' => array(
            'enabled' => $quirkyMode,
            'url' => $quirkyMediaUrl,
        ),
        'recent_count' => count($recent),
    ));
}

$post = casual_post_topic((string)($bot['username'] ?? 'BayMax'), $title, $raw, $categoryId);
if (!$post['ok']) {
    casual_out(500, array(
        'ok' => false,
        'error' => 'Failed to post casual topic.',
        'status' => $post['status'],
        'curl_error' => $post['error'],
        'response' => $post['body'],
        'raw' => $post['raw'],
    ));
}

$topicId = (int)($post['body']['topic_id'] ?? 0);
$postNumber = (int)($post['body']['post_number'] ?? 1);
$topicUrl = rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '/' . $postNumber;
casual_remember_topic($title, (string)($plan['angle'] ?? ''), (string)($plan['lane'] ?? (string)($lane['key'] ?? '')), $raw);
casual_remember_seed_url((string)($plan['seed_url'] ?? ''));
casual_consensus_register_topic($topicId, $bot, $title, $categoryId, $plan);
$todayCountAfterPost = casual_daily_count_increment($today);

casual_out(200, array(
    'ok' => true,
    'posted' => true,
    'action' => 'posted_casual_topic',
    'topic_url' => $topicUrl,
    'bot' => $bot,
    'plan' => $plan,
    'lane' => $lane,
    'topic' => array(
        'title' => $title,
        'category_id' => $categoryId,
        'gaming_detected' => $gamingDetected,
        'category_decision' => $categoryDecision,
    ),
    'daily_cap' => array(
        'date' => $today,
        'count_after_post' => $todayCountAfterPost,
        'max_per_day' => 3,
    ),
    'quirky_media' => array(
        'enabled' => $quirkyMode,
        'url' => $quirkyMediaUrl,
    ),
));
