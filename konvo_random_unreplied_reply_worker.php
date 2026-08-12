<?php

/*
 * Browser-callable random unreplied topic responder.
 *
 * Test (no posting):
 * https://www.kirupa.com/konvo_random_unreplied_reply_worker.php?key=YOUR_SECRET&dry_run=1
 *
 * Real run:
 * https://www.kirupa.com/konvo_random_unreplied_reply_worker.php?key=YOUR_SECRET
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/konvo_soul_helper.php';
require_once __DIR__ . '/konvo_signature_helper.php';
require_once __DIR__ . '/kirupa_article_helper.php';
require_once __DIR__ . '/konvo_link_helper.php';
require_once __DIR__ . '/konvo_anthropic_client.php';
require_once __DIR__ . '/konvo_code_format_helper.php';
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
if (!defined('KONVO_DISCOURSE_API_KEY')) define('KONVO_DISCOURSE_API_KEY', trim((string)getenv('DISCOURSE_API_KEY')));
if (!defined('KONVO_SECRET')) define('KONVO_SECRET', trim((string)getenv('DISCOURSE_WEBHOOK_SECRET')));

function worker_model_for_task($task, $ctx = array())
{
    return konvo_model_for_task((string)$task, is_array($ctx) ? $ctx : array());
}

$bots = array(
    array('username' => 'BayMax', 'signature' => 'BayMax', 'soul_key' => 'baymax'),
    array('username' => 'vaultboy', 'signature' => 'VaultBoy', 'soul_key' => 'vaultboy'),
    array('username' => 'MechaPrime', 'signature' => 'MechaPrime', 'soul_key' => 'mechaprime'),
    array('username' => 'yoshiii', 'signature' => 'Yoshiii', 'soul_key' => 'yoshiii'),
    array('username' => 'bobamilk', 'signature' => 'BobaMilk', 'soul_key' => 'bobamilk'),
    array('username' => 'wafflefries', 'signature' => 'WaffleFries', 'soul_key' => 'wafflefries'),
    array('username' => 'quelly', 'signature' => 'Quelly', 'soul_key' => 'quelly'),
    array('username' => 'sora', 'signature' => 'Sora', 'soul_key' => 'sora'),
    array('username' => 'sarah_connor', 'signature' => 'Sarah', 'soul_key' => 'sarah_connor'),
    array('username' => 'ellen1979', 'signature' => 'Ellen', 'soul_key' => 'ellen1979'),
    array('username' => 'arthurdent', 'signature' => 'Arthur', 'soul_key' => 'arthurdent'),
    array('username' => 'hariseldon', 'signature' => 'Hari', 'soul_key' => 'hariseldon'),
);

function out_json($code, $data)
{
    if (function_exists('http_response_code')) {
        http_response_code((int)$code);
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function safe_hash_equals($a, $b)
{
    $a = (string)$a;
    $b = (string)$b;
    if (function_exists('hash_equals')) return hash_equals($a, $b);
    if (strlen($a) !== strlen($b)) return false;
    $res = 0;
    $len = strlen($a);
    for ($i = 0; $i < $len; $i++) {
        $res |= ord($a[$i]) ^ ord($b[$i]);
    }
    return $res === 0;
}

function state_path()
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir . '/random_unreplied_seen_topics.json';
}

function load_seen_topics()
{
    $path = state_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function save_seen_topics($state)
{
    if (!is_array($state)) return;
    $now = time();
    foreach ($state as $k => $ts) {
        if (($now - (int)$ts) > 7 * 24 * 3600) {
            unset($state[$k]);
        }
    }
    arsort($state);
    $state = array_slice($state, 0, 1000, true);
    @file_put_contents(state_path(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Overlapping cron triggers (e.g. two schedule entries firing the same minute) can otherwise
// race to reply to the same unreplied post from different bots at nearly the same instant.
// This keeps only one real (non-dry-run) invocation of this worker executing at a time.
function worker_acquire_run_lock()
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $path = $dir . '/random_unreplied_reply_worker.lock';
    $handle = @fopen($path, 'c');
    if ($handle === false) {
        // If we can't even open a lock file, fail open rather than blocking all replies forever.
        return true;
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return false;
    }
    // Intentionally leaked for the life of the request: PHP releases the flock
    // automatically when the script exits or the handle is garbage collected.
    return true;
}

function worker_consensus_state_path()
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir . '/casual_consensus_state.json';
}

function worker_opening_memory_path()
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir . '/recent_reply_openings.json';
}

function worker_load_opening_memory()
{
    $path = worker_opening_memory_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function worker_save_opening_memory($rows)
{
    if (!is_array($rows)) $rows = array();
    $now = time();
    $clean = array();
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $stem = trim((string)($row['stem'] ?? ''));
        $ts = (int)($row['ts'] ?? 0);
        if ($stem === '' || $ts <= 0) continue;
        if (($now - $ts) > (7 * 24 * 3600)) continue;
        $clean[] = array(
            'stem' => $stem,
            'ts' => $ts,
            'topic_id' => (int)($row['topic_id'] ?? 0),
            'bot' => trim((string)($row['bot'] ?? '')),
        );
    }
    usort($clean, static function ($a, $b) {
        return ((int)($b['ts'] ?? 0)) <=> ((int)($a['ts'] ?? 0));
    });
    if (count($clean) > 250) $clean = array_slice($clean, 0, 250);
    @file_put_contents(worker_opening_memory_path(), json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function worker_opening_stem($text)
{
    $open = strtolower(trim((string)worker_extract_opening_line((string)$text)));
    if ($open === '') return '';
    $open = preg_replace('/https?:\/\/\S+/i', '', $open) ?? $open;
    $open = preg_replace('/[^a-z0-9\s]/i', ' ', $open) ?? $open;
    $open = preg_replace('/\s+/', ' ', $open) ?? $open;
    $open = trim((string)$open);
    if ($open === '') return '';
    $parts = explode(' ', $open);
    if (count($parts) > 10) $parts = array_slice($parts, 0, 10);
    return trim((string)implode(' ', $parts));
}

function worker_recent_opening_stems($memory, $hours = 72, $limit = 30)
{
    if (!is_array($memory) || $memory === array()) return array();
    $now = time();
    $cutoff = $now - max(1, (int)$hours) * 3600;
    $seen = array();
    $out = array();
    foreach ($memory as $row) {
        if (!is_array($row)) continue;
        $stem = trim((string)($row['stem'] ?? ''));
        $ts = (int)($row['ts'] ?? 0);
        if ($stem === '' || $ts < $cutoff) continue;
        if (isset($seen[$stem])) continue;
        $seen[$stem] = true;
        $out[] = $stem;
        if (count($out) >= max(5, (int)$limit)) break;
    }
    return $out;
}

function worker_opening_stem_reused($stem, $recentStems)
{
    $stem = trim((string)$stem);
    if ($stem === '' || !is_array($recentStems) || $recentStems === array()) return false;
    foreach ($recentStems as $s) {
        $s = trim((string)$s);
        if ($s === '') continue;
        if ($s === $stem) return true;
        if (strpos($s, $stem) === 0 || strpos($stem, $s) === 0) return true;
    }
    return false;
}

function worker_remember_opening_stem($stem, $topicId, $botUsername)
{
    $stem = trim((string)$stem);
    if ($stem === '') return;
    $rows = worker_load_opening_memory();
    $rows[] = array(
        'stem' => $stem,
        'ts' => time(),
        'topic_id' => (int)$topicId,
        'bot' => trim((string)$botUsername),
    );
    worker_save_opening_memory($rows);
}

function worker_consensus_load()
{
    $path = worker_consensus_state_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function worker_consensus_save($state)
{
    if (!is_array($state)) $state = array();
    $clean = array();
    $now = time();
    foreach ($state as $k => $row) {
        $id = (string)$k;
        if (!preg_match('/^\d+$/', $id)) continue;
        if (!is_array($row)) continue;
        $created = isset($row['created_ts']) ? (int)$row['created_ts'] : $now;
        if (($now - $created) > (14 * 24 * 3600)) continue;
        $clean[$id] = $row;
    }
    @file_put_contents(worker_consensus_state_path(), json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function worker_find_topic_stub_by_id($topics, $topicId)
{
    $id = (int)$topicId;
    if ($id <= 0 || !is_array($topics)) return null;
    foreach ($topics as $t) {
        if (!is_array($t)) continue;
        if ((int)($t['id'] ?? 0) === $id) return $t;
    }
    return null;
}

function worker_pick_consensus_topic($topics, $consensusState, $seenState)
{
    if (!is_array($topics) || !is_array($consensusState) || $consensusState === array()) {
        return null;
    }
    $now = time();
    $candidates = array();
    foreach ($consensusState as $idStr => $row) {
        if (!is_array($row)) continue;
        $topicId = (int)$idStr;
        if ($topicId <= 0) continue;
        $phase = strtolower(trim((string)($row['phase'] ?? 'open')));
        $consensusPosted = !empty($row['consensus_posted']);
        if ($consensusPosted || $phase === 'closed') continue;

        $createdTs = isset($row['created_ts']) ? (int)$row['created_ts'] : 0;
        $updatedTs = isset($row['updated_ts']) ? (int)$row['updated_ts'] : $createdTs;
        $age = ($createdTs > 0) ? ($now - $createdTs) : 0;
        if ($age < 8 * 60 || $age > 72 * 3600) continue;

        $discussionCount = (int)($row['discussion_reply_count'] ?? 0);
        if ($discussionCount >= 5) continue;

        $topic = worker_find_topic_stub_by_id($topics, $topicId);
        if (!is_array($topic)) continue;
        $visible = isset($topic['visible']) ? (bool)$topic['visible'] : true;
        $closed = isset($topic['closed']) ? (bool)$topic['closed'] : false;
        $archived = isset($topic['archived']) ? (bool)$topic['archived'] : false;
        if (!$visible || $closed || $archived) continue;

        $seenBoost = isset($seenState[(string)$topicId]) ? 1 : 0;
        $score = 0;
        $score += max(0, 4 - $discussionCount) * 10;
        $score += ($phase === 'open') ? 6 : 2;
        $score -= $seenBoost * 4;
        $score += ($age < 6 * 3600) ? 6 : 0;
        $score -= ($now - max($updatedTs, $createdTs) < 6 * 60) ? 8 : 0;

        $candidates[] = array(
            'score' => $score,
            'topic' => $topic,
            'state' => $row,
            'topic_id' => $topicId,
        );
    }
    if ($candidates === array()) return null;
    usort($candidates, function ($a, $b) {
        $sa = (int)($a['score'] ?? 0);
        $sb = (int)($b['score'] ?? 0);
        if ($sa === $sb) return 0;
        return ($sa > $sb) ? -1 : 1;
    });
    return $candidates[0];
}

function worker_pick_consensus_bot($bots, $latestUsername, $stateRow)
{
    if (!is_array($bots) || $bots === array()) return null;
    $excludeLatest = strtolower(trim((string)$latestUsername));
    $opBot = strtolower(trim((string)($stateRow['op_bot'] ?? '')));
    $participants = isset($stateRow['participant_bots']) && is_array($stateRow['participant_bots']) ? $stateRow['participant_bots'] : array();
    $participantSet = array();
    foreach ($participants as $p) {
        $k = strtolower(trim((string)$p));
        if ($k !== '') $participantSet[$k] = true;
    }

    $fresh = array();
    $fallback = array();
    foreach ($bots as $bot) {
        if (!is_array($bot)) continue;
        $u = strtolower(trim((string)($bot['username'] ?? '')));
        if ($u === '' || $u === $excludeLatest || $u === $opBot) continue;
        $fallback[] = $bot;
        if (!isset($participantSet[$u])) {
            $fresh[] = $bot;
        }
    }
    if ($fresh !== array()) {
        shuffle($fresh);
        return $fresh[0];
    }
    if ($fallback !== array()) {
        shuffle($fallback);
        return $fallback[0];
    }
    return pick_bot($bots, $latestUsername);
}

function worker_consensus_mark_posted_reply(&$consensusState, $topicId, $botUsername, $postNumber = 0)
{
    if (!is_array($consensusState)) return;
    $key = (string)((int)$topicId);
    if ($key === '0' || !isset($consensusState[$key]) || !is_array($consensusState[$key])) return;
    $row = $consensusState[$key];
    $participants = isset($row['participant_bots']) && is_array($row['participant_bots']) ? $row['participant_bots'] : array();
    $u = strtolower(trim((string)$botUsername));
    if ($u !== '' && !in_array($u, array_map('strtolower', array_map('strval', $participants)), true)) {
        $participants[] = $u;
    }
    $row['participant_bots'] = array_values(array_unique(array_map('strval', $participants)));
    $row['discussion_reply_count'] = max(0, (int)($row['discussion_reply_count'] ?? 0)) + 1;
    $row['updated_ts'] = time();
    if ((int)$row['discussion_reply_count'] >= 4) {
        $row['phase'] = 'ready_for_consensus';
    } else {
        $row['phase'] = 'open';
    }
    $row['last_reply_post_number'] = (int)$postNumber;
    $consensusState[$key] = $row;
}

function worker_all_bot_signature_aliases()
{
    return array(
        'baymax', 'kirupabot', 'kirupaBot', 'vaultboy', 'VaultBoy', 'mechaprime', 'MechaPrime',
        'yoshiii', 'Yoshiii', 'bobamilk', 'BobaMilk', 'wafflefries', 'WaffleFries',
        'quelly', 'Quelly', 'sora', 'Sora', 'sarah_connor', 'Sarah', 'ellen1979', 'Ellen',
        'arthurdent', 'Arthur', 'hariseldon', 'Hari',
    );
}

function worker_question_cadence_state_path()
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir . '/reply_question_cadence.json';
}

function worker_question_cadence_load()
{
    $path = worker_question_cadence_state_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function worker_question_cadence_save($state)
{
    if (!is_array($state)) $state = array();
    @file_put_contents(worker_question_cadence_state_path(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function worker_question_cadence_should_force_question($botUsername)
{
    $u = strtolower(trim((string)$botUsername));
    $now = time();
    $cutoff = $now - 86400;
    $state = worker_question_cadence_load();
    $rows = (isset($state[$u]) && is_array($state[$u])) ? $state[$u] : array();
    $rows = array_values(array_filter($rows, static function ($ts) use ($cutoff) {
        $n = (int)$ts;
        return $n > $cutoff && $n <= time() + 120;
    }));
    $state[$u] = $rows;
    worker_question_cadence_save($state);
    $count = count($rows);
    $nextIndex = $count + 1;
    return array(
        'count_24h' => $count,
        'next_index' => $nextIndex,
        'force_question' => (($nextIndex % 5) === 0),
    );
}

function worker_question_cadence_record_post($botUsername)
{
    $u = strtolower(trim((string)$botUsername));
    if ($u === '') return;
    $now = time();
    $cutoff = $now - 86400;
    $state = worker_question_cadence_load();
    $rows = (isset($state[$u]) && is_array($state[$u])) ? $state[$u] : array();
    $rows[] = $now;
    $rows = array_values(array_filter($rows, static function ($ts) use ($cutoff) {
        return ((int)$ts) > $cutoff;
    }));
    $state[$u] = array_slice($rows, -80);
    worker_question_cadence_save($state);
}

function fetch_json($url, $headers = array())
{
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    $baseHeaders = array('Accept: application/json');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => array_merge($baseHeaders, $headers),
        CURLOPT_USERAGENT => 'konvo-random-unreplied-worker/1.0',
    ));
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $err !== '' || $status < 200 || $status >= 300) return null;
    $decoded = json_decode((string)$body, true);
    return is_array($decoded) ? $decoded : null;
}

function post_json($url, $payload, $headers = array())
{
    // LLM traffic goes to Claude; Discourse traffic falls through untouched.
    if ((string)$url === 'llm:chat' && is_array($payload)) {
        return konvo_anthropic_chat_json($payload, 60);
    }
    if (!function_exists('curl_init')) return array('ok' => false, 'status' => 0, 'error' => 'curl unavailable', 'body' => array(), 'raw' => '');
    $ch = curl_init($url);
    $baseHeaders = array('Content-Type: application/json', 'Accept: application/json');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_merge($baseHeaders, $headers),
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

function worker_extract_json_object($raw)
{
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) return $decoded;
    if (preg_match('/\{[\s\S]*\}/', $raw, $m)) {
        $decoded = json_decode((string)$m[0], true);
        if (is_array($decoded)) return $decoded;
    }
    return null;
}

function worker_quality_gate_evaluate_reply($topicTitle, $targetRaw, $draft, $isTechnicalQuestion, $isSimpleClarification, $isQuestionLike)
{
    $modeRule = 'General mode: concise, human, on-target, complete thought.';
    if ($isSimpleClarification) {
        $modeRule = 'Simple clarification mode: 1-2 short sentences, answer-first, max 35 words, no bullets/headings.';
    } elseif ($isTechnicalQuestion) {
        $modeRule = 'Technical mode: precise, conversational, complete, and formatting-safe. No robotic section-heading style. Prefer short sentences and blank-line separation between distinct ideas.';
    } elseif (!$isQuestionLike) {
        $modeRule = 'Non-question reply mode: brief acknowledgement or one concrete add-on only.';
    }

    $payload = array(
        'model' => worker_model_for_task('quality_eval', array('technical' => $isTechnicalQuestion)),
        'messages' => array(
            array(
                'role' => 'system',
                'content' => 'You are a strict quality rater for forum replies. '
                    . 'Return JSON only with keys: score, pass, issues, reason, rewrite_brief. '
                    . 'score must be integer 1-5. pass is true only when score >= 4. '
                    . 'issues are short machine-like tags. No markdown. '
                    . 'Hard rule: if the draft sounds like abstract commentary or polished analysis instead of casual human conversation, score must be <=3.',
            ),
            array(
                'role' => 'user',
                'content' => "Quality bar:\n"
                    . "- sound human and casual\n"
                    . "- directly answer target intent\n"
                    . "- avoid fluff and robotic phrasing\n"
                    . "- complete thought (no dangling fragment)\n"
                    . "- avoid abstract meta phrasing like: useful constraint, framing, mental model, key takeaway, useful bit\n"
                    . "- if it agrees with someone, it must add one concrete detail; generic agreement must score 3 or lower\n"
                    . "- avoid long run-on sentences and semicolon/comma chains\n"
                    . "- use blank lines to separate distinct ideas when there is more than one idea\n"
                    . "- when listing 3 or more items, use markdown bullet points (one item per line)\n"
                    . "- follow mode constraints\n\n"
                    . "Mode rule: {$modeRule}\n\n"
                    . "Topic title:\n{$topicTitle}\n\n"
                    . "Target post:\n{$targetRaw}\n\n"
                    . "Draft reply:\n{$draft}",
            ),
        ),
        'temperature' => 0.1,
    );

    $res = post_json(
        'llm:chat',
        $payload,
        array('Authorization: Bearer ' . KONVO_ANTHROPIC_API_KEY)
    );
    if (!$res['ok'] || !isset($res['body']['choices'][0]['message']['content'])) {
        return array('ok' => false, 'error' => 'quality_eval_failed');
    }
    $obj = worker_extract_json_object((string)$res['body']['choices'][0]['message']['content']);
    if (!is_array($obj)) {
        return array('ok' => false, 'error' => 'quality_eval_parse_failed');
    }
    $score = (int)($obj['score'] ?? 0);
    if ($score < 1 || $score > 5) $score = 0;
    $issues = array();
    if (isset($obj['issues']) && is_array($obj['issues'])) {
        foreach ($obj['issues'] as $it) {
            $tag = strtolower(trim((string)$it));
            if ($tag !== '') $issues[] = $tag;
            if (count($issues) >= 6) break;
        }
    }
    return array(
        'ok' => true,
        'score' => $score,
        'pass' => ($score >= 4),
        'issues' => $issues,
        'reason' => trim((string)($obj['reason'] ?? '')),
        'rewrite_brief' => trim((string)($obj['rewrite_brief'] ?? '')),
    );
}

function worker_quality_gate_rewrite_reply($bot, $topicTitle, $targetRaw, $draft, $issues, $rewriteBrief, $isTechnicalQuestion, $isSimpleClarification)
{
    $signature = isset($bot['signature']) ? (string)$bot['signature'] : '';
    $soulKey = isset($bot['soul_key']) ? (string)$bot['soul_key'] : strtolower(trim($signature));
    $soul = konvo_compose_forum_persona_system_prompt(
        konvo_load_soul($soulKey, 'Write naturally, concise, and human.')
    );
    $modeRule = 'Keep this short, direct, human, and complete.';
    $openingRule = worker_opening_diversity_rule(isset($bot['username']) ? (string)$bot['username'] : '');
    if ($isSimpleClarification) {
        $modeRule = 'Simple clarification mode: 1-2 short sentences, answer-first, max 35 words, no bullets/headings.';
    } elseif ($isTechnicalQuestion) {
        $modeRule = 'Technical mode: precise and conversational, no robotic heading labels. Prefer short sentences and blank-line separation between distinct ideas.';
    }
    $issueText = is_array($issues) && $issues !== array() ? implode(', ', $issues) : 'general_quality';

    $payload = array(
        'model' => worker_model_for_task('quality_rewrite', array('technical' => $isTechnicalQuestion)),
        'messages' => array(
            array(
                'role' => 'system',
                'content' => $soul . ' Rewrite this forum reply so it passes a strict quality bar (4/5). '
                    . $modeRule
                    . ' Remove fluff, avoid robotic phrasing, stay on target intent, and keep natural cadence. '
                    . $openingRule . ' '
                    . 'Use short sentence cadence (no long run-ons) and add a blank line between unrelated ideas. '
                    . 'When listing 3 or more items, format as markdown bullet points with one item per line. '
                    . 'Avoid abstract analyst wording ("useful constraint", "framing", "mental model", "key takeaway"). '
                    . 'If this is an agreement, make it sound casual and include one concrete detail. '
                    . 'Good style example: "@WaffleFries, the fencepost warning is useful. One extra edge case is non-unique ids can break cursor paging." '
                    . 'Do not sign your post; the forum already shows your username.',
            ),
            array(
                'role' => 'user',
                'content' => "Topic title:\n{$topicTitle}\n\n"
                    . "Target post:\n{$targetRaw}\n\n"
                    . "Current draft:\n{$draft}\n\n"
                    . "Detected issues: {$issueText}\n"
                    . "Rewrite guidance: {$rewriteBrief}\n\n"
                    . "Rewrite now.",
            ),
        ),
        'temperature' => 0.45,
    );
    $res = post_json(
        'llm:chat',
        $payload,
        array('Authorization: Bearer ' . KONVO_ANTHROPIC_API_KEY)
    );
    if (!$res['ok'] || !isset($res['body']['choices'][0]['message']['content'])) {
        return '';
    }
    return trim((string)$res['body']['choices'][0]['message']['content']);
}

function worker_quality_gate_hard_rewrite_reply($bot, $topicTitle, $targetRaw, $draft)
{
    $signature = isset($bot['signature']) ? (string)$bot['signature'] : '';
    $soulKey = isset($bot['soul_key']) ? (string)$bot['soul_key'] : strtolower(trim($signature));
    $soul = konvo_compose_forum_persona_system_prompt(
        konvo_load_soul($soulKey, 'Write naturally, concise, and human.')
    );
    $openingRule = worker_opening_diversity_rule(isset($bot['username']) ? (string)$bot['username'] : '');
    $payload = array(
        'model' => worker_model_for_task('quality_hard', array('technical' => true)),
        'messages' => array(
            array(
                'role' => 'system',
                'content' => $soul
                    . ' Hard rewrite mode: make this sound unmistakably human and casual. '
                    . 'Write 1-2 short sentences only. '
                    . 'No long run-ons. Use a blank line if the second sentence is a different idea. '
                    . 'Directly address the target and include one concrete detail. '
                    . $openingRule . ' '
                    . 'No abstract/meta wording ("framing", "mental model", "key takeaway", "useful constraint"). '
                    . 'No generic filler, no question mark, no links, no code block unless absolutely required by the target. '
                    . 'Do not sign your post; the forum already shows your username.',
            ),
            array(
                'role' => 'user',
                'content' => "Topic title:\n{$topicTitle}\n\nTarget post:\n{$targetRaw}\n\nCurrent draft:\n{$draft}\n\nRewrite now.",
            ),
        ),
        'temperature' => 0.35,
    );
    $res = post_json(
        'llm:chat',
        $payload,
        array('Authorization: Bearer ' . KONVO_ANTHROPIC_API_KEY)
    );
    if (!$res['ok'] || !isset($res['body']['choices'][0]['message']['content'])) {
        return '';
    }
    return trim((string)$res['body']['choices'][0]['message']['content']);
}

function worker_quality_gate_forced_human_candidate($bot, $seed = 0)
{
    $variants = array(
        'A rule that takes 10 seconds to apply in review usually survives.',
        'If a guideline needs a long explanation, it is too fuzzy to enforce.',
        'One concrete rule with an example beats a long doc in practice.',
        'If reviewers cannot check it in one pass, rewrite the principle.',
        'Short and concrete wins because teams use it under pressure.',
        'If it does not prevent a common mistake, it is just noise.',
        'Practical rules stick when first-day teammates can apply them fast.',
        'If a handoff cannot use it quickly, it still needs simplification.',
    );
    $idx = abs((int)$seed) % count($variants);
    return trim((string)$variants[$idx]);
}

function worker_enforce_reply_quality_gate($bot, $topicTitle, $targetRaw, $draft, $isTechnicalQuestion, $isSimpleClarification, $isQuestionLike)
{
    $threshold = 4;
    $maxRounds = $isTechnicalQuestion ? 2 : 1;
    $minRoundsBeforeForcedBestPost = 5;
    $current = trim((string)$draft);
    $history = array();
    $score = 0;
    $issues = array();
    $bestScore = -1;
    $bestReply = $current;
    $bestIssues = array();
    $phase = 'normal';
    $hardRounds = 0;
    $maxHardRounds = 2;
    $forcedCandidateTries = $isTechnicalQuestion ? 2 : 1;

    for ($round = 1; ; $round++) {
        $eval = worker_quality_gate_evaluate_reply($topicTitle, $targetRaw, $current, $isTechnicalQuestion, $isSimpleClarification, $isQuestionLike);
        if (empty($eval['ok'])) {
            return array(
                'enabled' => true,
                'available' => false,
                'passed' => true,
                'threshold' => $threshold,
                'score' => 4,
                'rounds' => $round - 1,
                'issues' => array(),
                'history' => $history,
                'reply' => $current,
                'error' => isset($eval['error']) ? (string)$eval['error'] : 'quality_unavailable',
            );
        }

        $score = (int)($eval['score'] ?? 0);
        $issues = isset($eval['issues']) && is_array($eval['issues']) ? $eval['issues'] : array();
        $history[] = array(
            'round' => $round,
            'score' => $score,
            'issues' => $issues,
            'reason' => isset($eval['reason']) ? (string)$eval['reason'] : '',
        );
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestReply = $current;
            $bestIssues = $issues;
        }
        if ($score >= $threshold) {
            return array(
                'enabled' => true,
                'available' => true,
                'passed' => true,
                'threshold' => $threshold,
                'score' => $score,
                'rounds' => $round,
                'issues' => $issues,
                'history' => $history,
                'reply' => $current,
            );
        }

        if ($phase === 'normal' && $round >= $maxRounds) {
            $hard = worker_quality_gate_hard_rewrite_reply($bot, $topicTitle, $targetRaw, $current);
            if ($hard !== '') {
                $current = trim((string)$hard);
                $signature = isset($bot['signature']) ? (string)$bot['signature'] : '';
                $current = worker_normalize_technical_sentences($current);
                $current = worker_markdown_code_integrity_pass($current);
                $current = worker_normalize_code_fence_spacing($current);
                $current = normalize_signature($current, $signature);
            }
            $phase = 'hard';
            continue;
        }
        if ($phase === 'hard' && $hardRounds >= $maxHardRounds) break;

        $rewritten = '';
        if ($phase === 'hard') {
            $hardRounds++;
            $rewritten = worker_quality_gate_hard_rewrite_reply($bot, $topicTitle, $targetRaw, $current);
        } else {
            $rewritten = worker_quality_gate_rewrite_reply(
                $bot,
                $topicTitle,
                $targetRaw,
                $current,
                $issues,
                isset($eval['rewrite_brief']) ? (string)$eval['rewrite_brief'] : '',
                $isTechnicalQuestion,
                $isSimpleClarification
            );
        }
        if ($rewritten === '') break;
        $current = trim((string)$rewritten);
        $signature = isset($bot['signature']) ? (string)$bot['signature'] : '';
        $current = worker_normalize_technical_sentences($current);
        $current = worker_markdown_code_integrity_pass($current);
        $current = worker_normalize_code_fence_spacing($current);
        $current = normalize_signature($current, $signature);
    }

    if ($score < $threshold) {
        $bestCandidate = $bestReply;
        $bestCandidateScore = max($bestScore, $score);
        $forcedCandidateTries = max($forcedCandidateTries, $minRoundsBeforeForcedBestPost - count($history));
        for ($i = 0; $i < $forcedCandidateTries; $i++) {
            $candidate = worker_quality_gate_forced_human_candidate($bot, $i + count($history));
            $eval = worker_quality_gate_evaluate_reply($topicTitle, $targetRaw, $candidate, $isTechnicalQuestion, $isSimpleClarification, $isQuestionLike);
            if (empty($eval['ok'])) continue;
            $candScore = (int)($eval['score'] ?? 0);
            $candIssues = isset($eval['issues']) && is_array($eval['issues']) ? $eval['issues'] : array();
            $history[] = array(
                'round' => count($history) + 1,
                'score' => $candScore,
                'issues' => $candIssues,
                'reason' => isset($eval['reason']) ? (string)$eval['reason'] : '',
            );
            if ($candScore > $bestCandidateScore) {
                $bestCandidateScore = $candScore;
                $bestCandidate = $candidate;
                $bestIssues = $candIssues;
            }
            if ($candScore >= $threshold) {
                return array(
                    'enabled' => true,
                    'available' => true,
                    'passed' => true,
                    'threshold' => $threshold,
                    'score' => $candScore,
                    'rounds' => count($history),
                    'issues' => $candIssues,
                    'history' => $history,
                    'reply' => $candidate,
                );
            }
        }
        $current = $bestCandidate;
        $score = $bestCandidateScore;
        $issues = $bestIssues;
    }

    if ($score < $threshold && count($history) >= $minRoundsBeforeForcedBestPost) {
        return array(
            'enabled' => true,
            'available' => true,
            'passed' => true,
            'threshold' => $threshold,
            'score' => $score,
            'rounds' => count($history),
            'issues' => $issues,
            'history' => $history,
            'reply' => $current,
            'forced_best_post' => true,
            'forced_reason' => 'Reached 5 rounds below threshold; posting best available draft.',
        );
    }

    return array(
        'enabled' => true,
        'available' => true,
        'passed' => false,
        'threshold' => $threshold,
        'score' => $score,
        'rounds' => count($history),
        'issues' => $issues,
        'history' => $history,
        'reply' => $current,
    );
}

function worker_topic_is_solved($topic)
{
    if (!is_array($topic)) return false;
    if (!empty($topic['accepted_answer']) || !empty($topic['has_accepted_answer'])) return true;
    if (isset($topic['accepted_answer_post_id']) && (int)$topic['accepted_answer_post_id'] > 0) return true;
    if (isset($topic['topic_accepted_answer']) && !empty($topic['topic_accepted_answer'])) return true;
    $posts = isset($topic['post_stream']['posts']) && is_array($topic['post_stream']['posts']) ? $topic['post_stream']['posts'] : array();
    foreach ($posts as $post) {
        if (!is_array($post)) continue;
        if (!empty($post['accepted_answer']) || !empty($post['can_unaccept_answer'])) return true;
    }
    return false;
}

function worker_topic_is_technical($topic)
{
    if (!is_array($topic)) return false;
    $text = (string)($topic['title'] ?? '') . "\n";
    $posts = isset($topic['post_stream']['posts']) && is_array($topic['post_stream']['posts']) ? $topic['post_stream']['posts'] : array();
    $limit = min(8, count($posts));
    for ($i = 0; $i < $limit; $i++) {
        if (!is_array($posts[$i])) continue;
        $text .= post_content_text($posts[$i]) . "\n";
    }
    if (function_exists('kirupa_is_technical_text') && kirupa_is_technical_text($text)) return true;
    return (bool)preg_match('/(```|`|javascript|typescript|css|html|php|api|state|render|cache|query|frontend|backend|debug|bug|error)/i', $text);
}

function worker_answer_like_score($post, $opUsernameLower)
{
    if (!is_array($post)) return -100;
    if (!empty($post['hidden']) || !empty($post['user_deleted']) || !empty($post['deleted_at'])) return -100;
    $username = strtolower(trim((string)($post['username'] ?? '')));
    if ($username === '' || $username === $opUsernameLower) return -100;

    $text = trim(post_content_text($post));
    if ($text === '') return -100;

    $score = 0;
    $len = strlen($text);
    if ($len >= 70) $score++;
    if ($len >= 130) $score++;
    if (strpos($text, '```') !== false) $score += 2;
    if (preg_match('/\b(because|means|prints?|output|returns?|fix|use|instead|happens|caused|therefore|so that|root cause|tradeoff)\b/i', $text)) $score++;
    if (preg_match('/\b(javascript|typescript|css|html|php|api|state|render|cache|promise|async|await|closure|event loop|component|query|bundle|performance|debug)\b/i', $text)) $score++;
    if (preg_match('/\b(thanks|agree|same|nice|cool)\b/i', $text) && $len < 110) $score--;

    return $score;
}

function worker_pick_solution_candidate($posts, $opUsername)
{
    if (!is_array($posts)) return array('ok' => false, 'reason' => 'invalid_posts', 'strong_count' => 0);
    $opLower = strtolower(trim((string)$opUsername));
    $strong = array();
    foreach ($posts as $post) {
        if (!is_array($post)) continue;
        $score = worker_answer_like_score($post, $opLower);
        if ($score < 2) continue;
        $postId = (int)($post['id'] ?? 0);
        $postNumber = (int)($post['post_number'] ?? 0);
        $username = trim((string)($post['username'] ?? ''));
        if ($postId <= 0 || $postNumber <= 0 || $username === '') continue;
        $strong[] = array(
            'id' => $postId,
            'post_number' => $postNumber,
            'username' => $username,
            'score' => $score,
            'is_bot' => worker_is_known_bot_username($username),
        );
    }

    if (count($strong) < 3) {
        return array('ok' => false, 'reason' => 'not_enough_strong_replies', 'strong_count' => count($strong));
    }

    usort($strong, function ($a, $b) {
        if ((bool)$a['is_bot'] !== (bool)$b['is_bot']) {
            return $a['is_bot'] ? 1 : -1;
        }
        if ((int)$a['score'] !== (int)$b['score']) {
            return ((int)$b['score']) <=> ((int)$a['score']);
        }
        return ((int)$a['post_number']) <=> ((int)$b['post_number']);
    });

    return array('ok' => true, 'strong_count' => count($strong), 'candidate' => $strong[0]);
}

function worker_nontechnical_thread_answered_direction($posts, $opUsername, $excludeUsername = '', $minSignals = 2)
{
    if (!is_array($posts) || count($posts) < 3) return false;
    $opLower = strtolower(trim((string)$opUsername));
    $excludeLower = strtolower(trim((string)$excludeUsername));
    $signals = 0;
    $users = array();
    foreach ($posts as $post) {
        if (!is_array($post)) continue;
        $postNumber = (int)($post['post_number'] ?? 0);
        if ($postNumber <= 1) continue;
        $username = trim((string)($post['username'] ?? ''));
        $usernameLower = strtolower($username);
        if ($username === '' || $usernameLower === $opLower || ($excludeLower !== '' && $usernameLower === $excludeLower)) continue;
        $raw = trim((string)post_content_text($post));
        if ($raw === '' || worker_is_short_thank_you_ack($raw)) continue;

        $isSignal = false;
        $score = worker_answer_like_score($post, $opLower);
        if ($score >= 2) {
            $isSignal = true;
        } elseif (
            strlen($raw) >= 95
            && preg_match('/\b(i think|i feel|for me|my take|personally|depends|better|worse|tradeoff|because|instead|prefer|works for)\b/i', (string)$raw)
        ) {
            $isSignal = true;
        }

        if ($isSignal) {
            $signals++;
            $users[$usernameLower] = true;
            if ($signals >= max(1, (int)$minSignals) && count($users) >= 2) return true;
        }
    }

    return (count($posts) >= 7 && $signals >= 1 && count($users) >= 2);
}

function worker_try_auto_accept_solution($topicId, $topicDetail)
{
    $meta = array(
        'attempted' => false,
        'ok' => false,
        'reason' => '',
        'candidate_post_id' => 0,
        'candidate_post_number' => 0,
        'candidate_username' => '',
        'strong_reply_count' => 0,
        'status' => 0,
        'error' => '',
    );
    if ($topicId <= 0 || !is_array($topicDetail)) {
        $meta['reason'] = 'missing_topic';
        return $meta;
    }
    if (worker_topic_is_solved($topicDetail)) {
        $meta['reason'] = 'already_solved';
        return $meta;
    }
    if (!worker_topic_is_technical($topicDetail)) {
        $meta['reason'] = 'not_technical_topic';
        return $meta;
    }

    $posts = isset($topicDetail['post_stream']['posts']) && is_array($topicDetail['post_stream']['posts']) ? $topicDetail['post_stream']['posts'] : array();
    if (count($posts) < 4) {
        $meta['reason'] = 'not_enough_posts';
        return $meta;
    }
    $opPost = $posts[0] ?? null;
    if (!is_array($opPost)) {
        $meta['reason'] = 'missing_op_post';
        return $meta;
    }
    $opUsername = trim((string)($opPost['username'] ?? ''));
    if ($opUsername === '') {
        $meta['reason'] = 'missing_op_username';
        return $meta;
    }
    if (!worker_is_known_bot_username($opUsername)) {
        $meta['reason'] = 'op_not_bot';
        return $meta;
    }
    if (isset($opPost['can_accept_answer']) && $opPost['can_accept_answer'] === false) {
        $meta['reason'] = 'op_cannot_accept_answer';
        return $meta;
    }

    $pick = worker_pick_solution_candidate($posts, $opUsername);
    $meta['strong_reply_count'] = (int)($pick['strong_count'] ?? 0);
    if (empty($pick['ok']) || !isset($pick['candidate']) || !is_array($pick['candidate'])) {
        $meta['reason'] = (string)($pick['reason'] ?? 'no_candidate');
        return $meta;
    }

    $candidate = $pick['candidate'];
    $meta['candidate_post_id'] = (int)($candidate['id'] ?? 0);
    $meta['candidate_post_number'] = (int)($candidate['post_number'] ?? 0);
    $meta['candidate_username'] = (string)($candidate['username'] ?? '');
    if ($meta['candidate_post_id'] <= 0) {
        $meta['reason'] = 'candidate_missing_post_id';
        return $meta;
    }

    $headers = array(
        'Api-Key: ' . KONVO_DISCOURSE_API_KEY,
        'Api-Username: ' . $opUsername,
    );
    $payload = array(
        'id' => $meta['candidate_post_id'],
        'post_id' => $meta['candidate_post_id'],
        'topic_id' => (int)$topicId,
    );
    $meta['attempted'] = true;
    $acceptRes = post_json(rtrim(KONVO_BASE_URL, '/') . '/solution/accept.json', $payload, $headers);
    if (empty($acceptRes['ok'])) {
        $acceptRes = post_json(rtrim(KONVO_BASE_URL, '/') . '/solution/accept', $payload, $headers);
    }
    $meta['ok'] = !empty($acceptRes['ok']);
    $meta['status'] = (int)($acceptRes['status'] ?? 0);
    if (!$meta['ok']) {
        $err = trim((string)($acceptRes['error'] ?? ''));
        if ($err === '' && isset($acceptRes['body']) && is_array($acceptRes['body'])) {
            if (isset($acceptRes['body']['error'])) {
                $err = trim((string)$acceptRes['body']['error']);
            } elseif (isset($acceptRes['body']['errors']) && is_array($acceptRes['body']['errors'])) {
                $err = trim(implode(' ', array_map('strval', $acceptRes['body']['errors'])));
            }
        }
        if ($err === '') $err = trim((string)($acceptRes['raw'] ?? ''));
        $meta['error'] = $err;
        $meta['reason'] = 'accept_failed';
        return $meta;
    }

    $meta['reason'] = 'accepted';
    return $meta;
}

function worker_find_post_by_number($posts, $postNumber)
{
    $target = (int)$postNumber;
    if (!is_array($posts) || $target <= 0) return null;
    foreach ($posts as $post) {
        if (!is_array($post)) continue;
        if ((int)($post['post_number'] ?? 0) === $target) return $post;
    }
    return null;
}

function worker_bot_profile_for_username($username)
{
    $u = strtolower(trim((string)$username));
    $map = array(
        'baymax' => array('soul_key' => 'baymax', 'signature' => 'BayMax'),
        'kirupabot' => array('soul_key' => 'kirupabot', 'signature' => 'kirupaBot'),
        'vaultboy' => array('soul_key' => 'vaultboy', 'signature' => 'VaultBoy'),
        'mechaprime' => array('soul_key' => 'mechaprime', 'signature' => 'MechaPrime'),
        'yoshiii' => array('soul_key' => 'yoshiii', 'signature' => 'Yoshiii'),
        'bobamilk' => array('soul_key' => 'bobamilk', 'signature' => 'BobaMilk'),
        'wafflefries' => array('soul_key' => 'wafflefries', 'signature' => 'WaffleFries'),
        'quelly' => array('soul_key' => 'quelly', 'signature' => 'Quelly'),
        'sora' => array('soul_key' => 'sora', 'signature' => 'Sora'),
        'sarah_connor' => array('soul_key' => 'sarah_connor', 'signature' => 'Sarah'),
        'ellen1979' => array('soul_key' => 'ellen1979', 'signature' => 'Ellen'),
        'arthurdent' => array('soul_key' => 'arthurdent', 'signature' => 'Arthur'),
        'hariseldon' => array('soul_key' => 'hariseldon', 'signature' => 'Hari'),
    );
    if (isset($map[$u])) return $map[$u];
    $sig = trim((string)$username);
    if ($sig === '') $sig = 'Bot';
    return array('soul_key' => $u, 'signature' => $sig);
}

function worker_topic_has_op_thank_you($topicDetail, $opUsername, $candidatePostNumber = 0)
{
    $posts = isset($topicDetail['post_stream']['posts']) && is_array($topicDetail['post_stream']['posts']) ? $topicDetail['post_stream']['posts'] : array();
    if ($posts === array() || trim((string)$opUsername) === '') return false;
    $op = strtolower(trim((string)$opUsername));
    $minPost = max(1, (int)$candidatePostNumber);
    foreach ($posts as $post) {
        if (!is_array($post)) continue;
        $u = strtolower(trim((string)($post['username'] ?? '')));
        if ($u !== $op) continue;
        $pn = (int)($post['post_number'] ?? 0);
        if ($pn <= $minPost) continue;
        $raw = strtolower(trim(post_content_text($post)));
        if ($raw === '' || strlen($raw) > 320) continue;
        if (preg_match('/\b(thanks|thank you|appreciate|super helpful|that helped|solved it|fixed it)\b/i', $raw)) {
            return true;
        }
    }
    return false;
}

function worker_generate_op_thank_you_text($topicTitle, $candidateUsername, $candidateRaw, $signature, $soulPrompt)
{
    if (KONVO_ANTHROPIC_API_KEY === '') return '';
    $payload = array(
        'model' => worker_model_for_task('reply_ack'),
        'messages' => array(
            array(
                'role' => 'system',
                'content' => trim((string)$soulPrompt)
                    . ' Write a brief casual thank-you follow-up after the thread reached a solution. '
                    . 'Sound human and concise. '
                    . 'Use one sentence (or two short sentences max), no recap paragraph, no question mark, no links, no code blocks, no fluff. '
                    . 'Do not sign your post; the forum already shows your username.',
            ),
            array(
                'role' => 'user',
                'content' => "Topic title: {$topicTitle}\n"
                    . "Accepted/helpful reply by @{$candidateUsername}:\n"
                    . substr((string)$candidateRaw, 0, 700)
                    . "\n\nWrite the thank-you post now.",
            ),
        ),
        'temperature' => 0.5,
    );
    $res = post_json(
        'llm:chat',
        $payload,
        array('Authorization: Bearer ' . KONVO_ANTHROPIC_API_KEY)
    );
    if (!$res['ok'] || !isset($res['body']['choices'][0]['message']['content'])) return '';
    $txt = trim((string)$res['body']['choices'][0]['message']['content']);
    if ($txt === '') return '';
    $txt = preg_replace('/```[\s\S]*?```/m', '', (string)$txt);
    $txt = preg_replace('/https?:\/\/\S+/i', '', (string)$txt);
    $txt = force_no_questions((string)$txt);
    $txt = force_no_trailing_question((string)$txt);
    $txt = normalize_signature((string)$txt, (string)$signature);
    return trim((string)$txt);
}

function worker_try_post_op_thank_you($topicId, $topicDetail, $solvedMeta)
{
    $meta = array(
        'attempted' => false,
        'ok' => false,
        'reason' => '',
        'op_username' => '',
        'reply_to_post_number' => 0,
        'status' => 0,
        'error' => '',
        'post_url' => '',
    );
    if ((int)$topicId <= 0 || !is_array($topicDetail) || KONVO_DISCOURSE_API_KEY === '') {
        $meta['reason'] = 'missing_topic_or_api_key';
        return $meta;
    }
    if (empty($solvedMeta['ok']) || (string)($solvedMeta['reason'] ?? '') !== 'accepted') {
        $meta['reason'] = 'not_newly_accepted';
        return $meta;
    }

    $posts = isset($topicDetail['post_stream']['posts']) && is_array($topicDetail['post_stream']['posts']) ? $topicDetail['post_stream']['posts'] : array();
    if ($posts === array()) {
        $meta['reason'] = 'missing_posts';
        return $meta;
    }
    $opPost = $posts[0] ?? null;
    if (!is_array($opPost)) {
        $meta['reason'] = 'missing_op_post';
        return $meta;
    }
    $opUsername = trim((string)($opPost['username'] ?? ''));
    $meta['op_username'] = $opUsername;
    if ($opUsername === '' || !worker_is_known_bot_username($opUsername)) {
        $meta['reason'] = 'op_not_bot';
        return $meta;
    }

    $candidatePostNumber = (int)($solvedMeta['candidate_post_number'] ?? 0);
    $meta['reply_to_post_number'] = $candidatePostNumber;
    if (worker_topic_has_op_thank_you($topicDetail, $opUsername, $candidatePostNumber)) {
        $meta['reason'] = 'already_thanked';
        return $meta;
    }

    $candidatePost = worker_find_post_by_number($posts, $candidatePostNumber);
    $candidateRaw = is_array($candidatePost) ? post_content_text($candidatePost) : '';
    $candidateUsername = trim((string)($solvedMeta['candidate_username'] ?? ''));
    if ($candidateUsername === '' && is_array($candidatePost)) {
        $candidateUsername = trim((string)($candidatePost['username'] ?? ''));
    }

    $profile = worker_bot_profile_for_username($opUsername);
    $signatureRaw = (string)($profile['signature'] ?? $opUsername);
    $seed = strtolower('thanks|' . $opUsername . '|' . (int)$topicId . '|' . (int)$candidatePostNumber);
    $signature = function_exists('konvo_signature_with_optional_emoji')
        ? konvo_signature_with_optional_emoji($signatureRaw, $seed)
        : $signatureRaw;
    $soul = konvo_compose_forum_persona_system_prompt(
        konvo_load_soul((string)($profile['soul_key'] ?? strtolower($opUsername)), 'Write concise, casual, human forum posts.')
    );
    $topicTitle = trim((string)($topicDetail['title'] ?? 'Untitled topic'));

    $thankYou = worker_generate_op_thank_you_text($topicTitle, $candidateUsername, $candidateRaw, $signature, $soul);
    if ($thankYou === '') {
        $thankYou = "Thanks, this was super helpful.";
    }
    $thankYou = force_no_questions((string)$thankYou);
    $thankYou = force_no_trailing_question((string)$thankYou);
    $thankYou = normalize_signature((string)$thankYou, (string)$signature);

    $payload = array(
        'topic_id' => (int)$topicId,
        'raw' => (string)$thankYou,
    );
    if ($candidatePostNumber > 0) {
        $payload['reply_to_post_number'] = $candidatePostNumber;
    }
    $meta['attempted'] = true;
    $postRes = post_json(
        rtrim(KONVO_BASE_URL, '/') . '/posts.json',
        $payload,
        array(
            'Api-Key: ' . KONVO_DISCOURSE_API_KEY,
            'Api-Username: ' . $opUsername,
        )
    );
    $meta['ok'] = !empty($postRes['ok']);
    $meta['status'] = (int)($postRes['status'] ?? 0);
    if (!$meta['ok']) {
        $err = trim((string)($postRes['error'] ?? ''));
        if ($err === '' && isset($postRes['body']) && is_array($postRes['body'])) {
            if (isset($postRes['body']['error'])) {
                $err = trim((string)$postRes['body']['error']);
            } elseif (isset($postRes['body']['errors']) && is_array($postRes['body']['errors'])) {
                $err = trim(implode(' ', array_map('strval', $postRes['body']['errors'])));
            }
        }
        if ($err === '') $err = trim((string)($postRes['raw'] ?? ''));
        $meta['error'] = $err;
        $meta['reason'] = 'post_failed';
        return $meta;
    }
    $postNumber = isset($postRes['body']['post_number']) ? (int)$postRes['body']['post_number'] : 0;
    $meta['ok'] = true;
    $meta['reason'] = 'posted';
    if ($postNumber > 0) {
        $meta['post_url'] = rtrim(KONVO_BASE_URL, '/') . '/t/' . (int)$topicId . '/' . $postNumber;
    }
    return $meta;
}

function normalize_signature($text, $name)
{
    $candidates = function_exists('konvo_signature_name_candidates')
        ? konvo_signature_name_candidates((string)$name)
        : array((string)$name);
    foreach (worker_all_bot_signature_aliases() as $alias) {
        $alias = trim((string)$alias);
        if ($alias !== '') $candidates[] = $alias;
    }
    $candidates = array_values(array_unique(array_map('strval', $candidates)));
    if (!is_array($candidates) || count($candidates) === 0) {
        $candidates = array((string)$name);
    }

    foreach ($candidates as $candidate) {
        $text = preg_replace(
            '/(https?:\/\/\S+)(?=' . preg_quote((string)$candidate, '/') . '\b)/iu',
            '$1' . "\n\n",
            (string)$text
        );
        if (!is_string($text)) $text = '';
    }

    $line_matches_candidate = static function ($line, $candidate): bool {
        $line = trim((string)$line);
        $candidate = trim((string)$candidate);
        if ($line === '' || $candidate === '') return false;
        $lineNorm = strtolower(trim((string)(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $line) ?? $line)));
        $candNorm = strtolower(trim((string)(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $candidate) ?? $candidate)));
        if ($lineNorm !== '' && $candNorm !== '') {
            $parts = preg_split('/\s+/', $lineNorm);
            if (is_array($parts) && $parts !== array()) {
                $allCand = true;
                foreach ($parts as $p) {
                    $p = trim((string)$p);
                    if ($p === '') continue;
                    if ($p !== $candNorm) {
                        $allCand = false;
                        break;
                    }
                }
                if ($allCand) return true;
            }
        }
        $prefixPattern = '/^' . preg_quote((string)$candidate, '/') . '\b/iu';
        if (!preg_match($prefixPattern, (string)$line)) return false;
        $tail = preg_replace($prefixPattern, '', (string)$line, 1);
        if (!is_string($tail)) $tail = '';
        $tail = trim((string)$tail);
        if ($tail === '' || $tail === '.') return true;
        return preg_match('/[\p{L}\p{N}]/u', (string)$tail) !== 1;
    };

    $lines = preg_split('/\R/', trim((string)$text));
    if (!is_array($lines)) $lines = array();
    $filtered = array();
    foreach ($lines as $line) {
        $trimmed = trim((string)$line);
        if ($trimmed === '') {
            $filtered[] = (string)$line;
            continue;
        }
        $isSigOnly = false;
        foreach ($candidates as $candidate) {
            if ($line_matches_candidate($trimmed, (string)$candidate)) {
                $isSigOnly = true;
                break;
            }
        }
        if (!$isSigOnly) {
            $filtered[] = (string)$line;
        }
    }
    $lines = $filtered;
    while (!empty($lines)) {
        $last = trim((string)end($lines));
        $matched = false;
        foreach ($candidates as $candidate) {
            if ($line_matches_candidate($last, (string)$candidate)) {
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
    $changed = true;
    while ($changed && $body !== '') {
        $changed = false;
        foreach ($candidates as $candidate) {
            $pat = '/(?:\s+)' . preg_quote((string)$candidate, '/') . '(?:\s+' . preg_quote((string)$candidate, '/') . ')*(?:\b[^\p{L}\p{N}]*)?$/iu';
            $next = preg_replace($pat, '', (string)$body, -1, $count);
            if (is_string($next) && (int)$count > 0) {
                $body = trim((string)$next);
                $changed = true;
            }
        }
    }
    $body = trim((string)$body);
    if ($body === '') return '';
    return $body;
}

function force_standalone_urls($text)
{
    $text = trim((string)$text);
    if ($text === '') return $text;

    $text = preg_replace_callback('/\[[^\]]+\]\((https?:\/\/[^\s)]+)\)/i', function ($m) {
        $u = trim((string)($m[1] ?? ''));
        return $u !== '' ? "\n\n" . $u . "\n\n" : (string)$m[0];
    }, $text);
    if (!is_string($text)) $text = '';

    $text = preg_replace_callback('/(?<![\w\/])(https?:\/\/[^\s<>()]+)(?![\w\/])/i', function ($m) {
        $u = trim((string)($m[1] ?? ''));
        return $u !== '' ? "\n\n" . $u . "\n\n" : (string)$m[0];
    }, $text);
    if (!is_string($text)) $text = '';

    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim((string)$text);
}

function worker_repair_url_artifacts($text)
{
    $text = str_replace(array("\r\n", "\r"), "\n", (string)$text);
    if (trim((string)$text) === '') return '';

    $text = preg_replace('/https?:\/\/\s*www\.\s*kirupa\.\s*com\s*\/\s*/i', 'https://www.kirupa.com/', (string)$text);
    if (!is_string($text)) $text = '';
    $text = preg_replace_callback('/https?:\/\/[^\n]+/i', function ($m) {
        $u = isset($m[0]) ? (string)$m[0] : '';
        $u = preg_replace('/\s+/', '', $u);
        return is_string($u) ? $u : (isset($m[0]) ? (string)$m[0] : '');
    }, (string)$text);
    if (!is_string($text)) $text = '';
    return trim((string)$text);
}

function worker_quirky_media_urls()
{
    return array(
        'https://media.giphy.com/media/ICOgUNjpvO0PC/giphy.gif',
        'https://media.giphy.com/media/5VKbvrjxpVJCM/giphy.gif',
        'https://media.giphy.com/media/13CoXDiaCcCoyk/giphy.gif',
        'https://media.giphy.com/media/l0HlBO7eyXzSZkJri/giphy.gif',
        'https://media.giphy.com/media/3oEjI6SIIHBdRxXI40/giphy.gif',
        'https://media.giphy.com/media/26ufdipQqU2lhNA4g/giphy.gif',
        'https://media.giphy.com/media/3o7aCTfyhYawdOXcFW/giphy.gif',
        'https://media.giphy.com/media/l3q2K5jinAlChoCLS/giphy.gif',
    );
}

function worker_pick_quirky_media_url($seed)
{
    $urls = worker_quirky_media_urls();
    if (!is_array($urls) || $urls === array()) return '';
    $hash = abs((int)crc32(strtolower(trim((string)$seed))));
    return (string)$urls[$hash % count($urls)];
}

function force_no_trailing_question($text)
{
    $text = trim((string)$text);
    if ($text === '') return $text;

    $lines = preg_split('/\R/', $text);
    if (!is_array($lines)) $lines = array($text);
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = trim((string)$lines[$i]);
        if ($line === '' || preg_match('/^https?:\/\/\S+$/i', $line)) continue;
        if (preg_match('/\?\s*$/', $line)) {
            $line = preg_replace('/\?\s*$/', '.', $line);
            $lines[$i] = trim((string)$line);
        }
        break;
    }
    return trim(implode("\n", $lines));
}

function force_no_questions($text)
{
    $text = trim((string)$text);
    if ($text === '') return $text;
    $segments = preg_split('/(```[\s\S]*?```)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($segments) || count($segments) === 0) $segments = array($text);
    foreach ($segments as $i => $segment) {
        if ($segment === '' || str_starts_with((string)$segment, '```')) continue;
        $lines = preg_split('/\R/', (string)$segment);
        if (!is_array($lines)) $lines = array((string)$segment);
        foreach ($lines as $j => $line) {
            if (preg_match('/^\s*https?:\/\/\S+\s*$/i', (string)$line)) continue;
            $lines[$j] = str_replace('?', '.', (string)$line);
        }
        $segments[$i] = implode("\n", $lines);
    }
    $text = implode('', $segments);
    $text = preg_replace('/\.{2,}/', '.', $text);
    return trim((string)$text);
}

function worker_apply_micro_grammar_fixes($text)
{
    $text = trim((string)$text);
    if ($text === '') return $text;

    $segments = preg_split('/(```[\s\S]*?```)/', (string)$text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($segments) || $segments === array()) $segments = array((string)$text);
    foreach ($segments as $i => $segment) {
        if ($i % 2 === 1) continue;
        $segment = (string)$segment;
        $segment = preg_replace('/[ \t]{2,}/', ' ', $segment);
        $segment = preg_replace('/^(@[A-Za-z0-9_]+)\s+(?=[A-Za-z])/m', '$1, ', (string)$segment);
        // Normalize awkward spaced compound hyphenation (e.g. "re - ran" -> "re-ran")
        // while keeping sentence dashes that start with uppercase words (e.g. "Yes - that").
        $segment = preg_replace('/(?<=\p{Ll})\s+-\s+(?=\p{Ll})/u', '-', (string)$segment);
        $segment = preg_replace('/\s+([,.;!?])/', '$1', (string)$segment);
        // Add space after punctuation, but preserve thousands separators like 6,000.
        $segment = preg_replace('/,(?=\S)(?!\d)/', ', ', (string)$segment);
        $segment = preg_replace('/([.;!?])(?=\S)/', '$1 ', (string)$segment);
        $segment = preg_replace('/(\d),\s+(\d{3}\b)/', '$1,$2', (string)$segment);
        $segment = preg_replace('/\s{2,}/', ' ', (string)$segment);
        if (!is_string($segment)) $segment = '';

        $lines = preg_split('/\R/', $segment);
        if (!is_array($lines)) $lines = array($segment);
        foreach ($lines as $j => $line) {
            $line = (string)$line;
            $trimmed = trim((string)$line);
            if ($trimmed === '' || preg_match('/^https?:\/\/\S+$/i', $trimmed) || preg_match('/^([-*]|\d+\.)\s+/', $trimmed)) {
                $lines[$j] = $line;
                continue;
            }
            if (preg_match('/^(@[A-Za-z0-9_]+,\s+)([a-z])/', $line, $m)) {
                $line = preg_replace('/^(@[A-Za-z0-9_]+,\s+)[a-z]/', $m[1] . strtoupper($m[2]), $line, 1);
            } elseif (preg_match('/^[a-z]/', ltrim($line), $m)) {
                $line = preg_replace('/^(\s*)[a-z]/', '$1' . strtoupper($m[0]), $line, 1);
            }
            $lines[$j] = is_string($line) ? $line : (string)$lines[$j];
        }
        $segments[$i] = implode("\n", $lines);
    }

    $out = trim(implode('', $segments));
    $out = preg_replace('/\n{3,}/', "\n\n", (string)$out);
    return trim((string)$out);
}

function worker_grammar_cleanup_with_llm($soul, $signature, $topicTitle, $targetRaw, $draft, $isTechnicalQuestion)
{
    $draft = trim((string)$draft);
    if ($draft === '' || KONVO_ANTHROPIC_API_KEY === '') return $draft;
    $model = worker_model_for_task('reply_rewrite', array('technical' => (bool)$isTechnicalQuestion));
    if (!is_string($model) || trim($model) === '') return $draft;

    $payload = array(
        'model' => $model,
        'messages' => array(
            array(
                'role' => 'system',
                'content' => trim((string)$soul)
                    . ' Perform a grammar-only cleanup pass for a forum reply. '
                    . 'Keep original intent, tone, persona, and brevity. '
                    . 'Fix punctuation, capitalization, and sentence flow only. '
                    . 'Do not add new claims or remove important details. '
                    . 'Do not formalize or sanitize voice texture; preserve natural rough edges and human rhythm. '
                    . 'Preserve URLs exactly as-is and keep them standalone. '
                    . 'Preserve fenced code blocks exactly as-is. '
                    . 'Do not add headings. '
                    . ((bool)$isTechnicalQuestion ? 'Keep technical structure and examples intact. ' : '')
                    . 'Do not sign your post; the forum already shows your username. Return only the final reply text.',
            ),
            array(
                'role' => 'user',
                'content' => "Topic title: {$topicTitle}\n\nTarget content:\n{$targetRaw}\n\nDraft:\n{$draft}\n\nReturn grammar-polished text only.",
            ),
        ),
        'temperature' => 0.15,
    );
    $res = post_json(
        'llm:chat',
        $payload,
        array('Authorization: Bearer ' . KONVO_ANTHROPIC_API_KEY)
    );
    if (!$res['ok'] || !isset($res['body']['choices'][0]['message']['content'])) return $draft;
    $clean = trim((string)$res['body']['choices'][0]['message']['content']);
    return $clean !== '' ? $clean : $draft;
}

function worker_normalize_code_language($lang)
{
    $lang = strtolower(trim((string)$lang));
    if ($lang === 'javascript') return 'js';
    if ($lang === 'typescript') return 'ts';
    if ($lang === '') return 'js';
    return $lang;
}

function worker_prettify_inline_code($code)
{
    $code = html_entity_decode((string)$code, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $code = str_replace(array("\r\n", "\r"), "\n", (string)$code);
    $code = trim((string)$code);
    if ($code === '') return $code;
    $code = preg_replace('/;\s+(?=\S)/', ";\n", (string)$code);
    $code = preg_replace('/\{\s+(?=\S)/', "{\n  ", (string)$code);
    $code = preg_replace('/\s+\}/', "\n}", (string)$code);
    $code = preg_replace('/\n{3,}/', "\n\n", (string)$code);
    return trim((string)$code);
}

function worker_inline_code_looks_programmatic($code)
{
    $code = html_entity_decode((string)$code, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $code = trim((string)$code);
    if ($code === '') return false;

    $hasNewline = strpos((string)$code, "\n") !== false;
    $operatorHits = preg_match_all('/(=>|===|!==|==|!=|<=|>=|\|\||&&|::|->)/', (string)$code, $m1);
    $syntaxHits = preg_match_all('/[{}\[\]();=<>:+\-*\/%]/', (string)$code, $m2);
    $keywordHits = preg_match_all('/\b(function|class|const|let|var|return|switch|case|try|catch|finally|await|async|import|export|console\.log|document\.|window\.|select|insert|update|delete|join|group by|order by|limit)\b/i', (string)$code, $m3);
    $wordHits = preg_match_all('/\b[a-zA-Z_][a-zA-Z0-9_]*\b/', (string)$code, $m4);
    $proseHits = preg_match_all('/\b(is|are|was|were|this|that|and|but|because|only|happens|same|make|call|about|right|small|caveat)\b/i', (string)$code, $m5);

    if (is_int($operatorHits) && $operatorHits >= 1 && is_int($syntaxHits) && $syntaxHits >= 2) return true;
    if (is_int($keywordHits) && $keywordHits >= 2) return true;
    if ($hasNewline && is_int($keywordHits) && $keywordHits >= 1 && is_int($syntaxHits) && $syntaxHits >= 2) return true;
    if ($hasNewline && is_int($syntaxHits) && $syntaxHits >= 6) return true;
    if (is_int($syntaxHits) && $syntaxHits >= 8 && is_int($wordHits) && $wordHits <= 60) return true;
    if (is_int($proseHits) && $proseHits >= 3 && is_int($keywordHits) && $keywordHits === 0 && is_int($operatorHits) && $operatorHits === 0) return false;
    return false;
}

function worker_markdown_code_integrity_pass($text)
{
    $text = str_replace(array("\r\n", "\r"), "\n", (string)$text);
    if (trim((string)$text) === '') return '';

    $segments = preg_split('/(```[\s\S]*?```)/', (string)$text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($segments) || count($segments) === 0) $segments = array((string)$text);
    foreach ($segments as $i => $segment) {
        if ($i % 2 === 1) continue;
        $segment = (string)$segment;
        if ($segment === '') continue;
        $tickCount = substr_count($segment, '`');
        if (($tickCount % 2) !== 0) {
            $segment = preg_replace('/(^|[\s(\[{])`(?=[\s)\]}.,;:!?]|$)/', '$1', $segment);
            $segment = is_string($segment) ? $segment : '';
            if ((substr_count($segment, '`') % 2) !== 0) {
                $segment = str_replace('`', '', $segment);
            }
        }
        $segments[$i] = $segment;
    }
    $out = trim((string)implode('', $segments));
    $fenceCount = substr_count($out, '```');
    if (($fenceCount % 2) !== 0) {
        if ($fenceCount === 1) {
            $out = str_replace('```', '', $out);
        } else {
            $out .= "\n```";
        }
    }
    $out = preg_replace('/\n{3,}/', "\n\n", (string)$out);
    return trim((string)$out);
}

function worker_strip_technical_section_labels($text)
{
    $text = str_replace(array("\r\n", "\r"), "\n", (string)$text);
    if (trim((string)$text) === '') return '';
    $labelPattern = '/^\s*(?:#{1,6}\s*)?(?:\d+[\).\:-]?\s*)?(Diagnosis|Conceptual Explanation|Minimal Fix|Why This Works|Sanity Check|Quick Check|Optional Practical Tip)\s*:?\s*$/im';
    $text = preg_replace($labelPattern, '', (string)$text);
    $text = preg_replace('/\n{3,}/', "\n\n", (string)$text);
    return trim((string)$text);
}

function worker_normalize_technical_sentences($text)
{
    $text = trim((string)$text);
    if ($text === '') return '';
    $text = preg_replace('/\b(Minimal Fix|Sanity Check|Quick Check)\b\s*:?\s*/i', '', (string)$text);
    $text = worker_restructure_technical_bullets((string)$text);
    $text = preg_replace('/\n{3,}/', "\n\n", (string)$text);
    return trim((string)$text);
}

function worker_force_visual_breaks_for_technical_reply($text)
{
    $text = str_replace(array("\r\n", "\r"), "\n", (string)$text);
    $text = trim((string)$text);
    if ($text === '') return '';
    if (strpos((string)$text, "\n\n") !== false) return $text;

    $segments = preg_split('/(```[\s\S]*?```)/', (string)$text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($segments) || $segments === array()) $segments = array((string)$text);

    foreach ($segments as $i => $segment) {
        if ($i % 2 === 1) continue;
        $segment = trim((string)$segment);
        if ($segment === '') continue;
        if (preg_match('/^\s*(https?:\/\/\S+|[-*]\s+|\d+\.\s+)/m', (string)$segment)) {
            $segments[$i] = $segment;
            continue;
        }

        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9@])/u', (string)$segment) ?: array();
        $sentences = array_values(array_filter(array_map(static function ($s) {
            return trim((string)$s);
        }, $sentences), static function ($s) {
            return $s !== '';
        }));

        if (count($sentences) >= 2) {
            $first = (string)$sentences[0];
            $rest = trim((string)implode(' ', array_slice($sentences, 1)));
            if (strlen($first) >= 40 || strlen($segment) >= 180) {
                $segment = $first . "\n\n" . $rest;
            }
        }

        $segments[$i] = trim((string)$segment);
    }

    $out = trim((string)implode('', $segments));
    $out = preg_replace('/\n{3,}/', "\n\n", (string)$out);
    return trim((string)$out);
}

function worker_restructure_technical_bullets($text)
{
    $text = str_replace(array("\r\n", "\r"), "\n", (string)$text);
    if (trim((string)$text) === '') return '';

    $lines = preg_split('/\n/', (string)$text);
    if (!is_array($lines) || $lines === array()) return trim((string)$text);

    $bulletIdx = array();
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*-\s+/', (string)$line)) {
            $bulletIdx[] = (int)$i;
        }
    }
    if (count($bulletIdx) <= 2) return trim((string)$text);

    $extra = array();
    for ($k = 2; $k < count($bulletIdx); $k++) {
        $idx = (int)$bulletIdx[$k];
        $content = preg_replace('/^\s*-\s+/', '', (string)$lines[$idx]);
        $content = trim((string)$content);
        if ($content === '') {
            $lines[$idx] = '';
            continue;
        }
        if (!preg_match('/[.!?]$/', $content)) $content .= '.';
        $extra[] = $content;
        $lines[$idx] = '';
    }

    if ($extra !== array()) {
        $insertAt = (int)$bulletIdx[1] + 1;
        $extraLine = implode(' ', $extra);
        array_splice($lines, $insertAt, 0, array('', $extraLine));
    }

    $out = trim((string)implode("\n", $lines));
    $out = preg_replace('/\n{3,}/', "\n\n", (string)$out);
    return trim((string)$out);
}

function worker_convert_markdown_fences_to_html($text)
{
    $text = str_replace(array("\r\n", "\r"), "\n", (string)$text);
    if (trim((string)$text) === '' || strpos((string)$text, '```') === false) return trim((string)$text);

    $known = array('js', 'javascript', 'ts', 'typescript', 'css', 'html', 'php', 'python', 'sql', 'bash', 'json', 'text');
    $out = preg_replace_callback('/```([a-zA-Z0-9_-]*)\n([\s\S]*?)```/m', function ($m) use ($known) {
        $code = (string)($m[2] ?? '');
        $code = ltrim((string)$code, "\n");
        $code = rtrim((string)$code);
        $lines = preg_split('/\n/', (string)$code);
        if (!is_array($lines)) $lines = array();

        // Strip stray first-line language tokens so code starts with actual code.
        $firstNonEmptyIdx = -1;
        $firstToken = '';
        foreach ($lines as $idx => $line) {
            $trim = strtolower(trim((string)$line));
            if ($trim === '') continue;
            $firstNonEmptyIdx = (int)$idx;
            $firstToken = $trim;
            break;
        }
        if ($firstNonEmptyIdx >= 0 && in_array($firstToken, $known, true)) {
            array_splice($lines, $firstNonEmptyIdx, 1);
        }

        $code = trim((string)implode("\n", $lines));
        $escaped = htmlspecialchars((string)$code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<pre><code>' . $escaped . '</code></pre>';
    }, (string)$text);

    $out = preg_replace('/\n{3,}/', "\n\n", (string)$out);
    return trim((string)$out);
}

function worker_normalize_code_fence_spacing($text)
{
    $text = str_replace(array("\r\n", "\r"), "\n", (string)$text);
    if (trim((string)$text) === '') return trim((string)$text);
    $hasFences = strpos((string)$text, '```') !== false;
    $hasHtmlCode = (stripos((string)$text, '<pre') !== false && stripos((string)$text, '<code') !== false);
    if (!$hasFences && !$hasHtmlCode) return trim((string)$text);

    if ($hasFences) {
        // Strip language labels from opening fences.
        $text = preg_replace('/```[ \t]*(?:js|javascript|ts|typescript|css|html|php|python|sql|bash|json|text)[ \t]*\n/i', "```\n", (string)$text);
        // Strip standalone first-line language tokens inside a fence.
        $text = preg_replace('/```\n(?:[ \t]*\n)*[ \t]*(?:js|javascript|ts|typescript|css|html|php|python|sql|bash|json|text)[ \t]*\n/i', "```\n", (string)$text);
        $text = preg_replace('/([^\n])\s*```(?:[a-zA-Z0-9_-]*)?\n/', "$1\n\n```\n", (string)$text);
        $text = preg_replace('/\n```([^\n])/', "\n```\n\n$1", (string)$text);
    }

    if ($hasHtmlCode) {
        $text = preg_replace_callback('/<pre\b([^>]*)>\s*<code\b([^>]*)>([\s\S]*?)<\/code>\s*<\/pre>/i', function ($m) {
            $preAttrs = isset($m[1]) ? (string)$m[1] : '';
            $codeAttrs = isset($m[2]) ? (string)$m[2] : '';
            $codeBody = isset($m[3]) ? (string)$m[3] : '';
            $decoded = html_entity_decode((string)$codeBody, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match('/^\s*(?:\R\s*)*(js|javascript|ts|typescript|css|html|php|python|sql|bash|json|text)\s*\R([\s\S]*)$/i', (string)$decoded, $mm)) {
                return isset($m[0]) ? (string)$m[0] : '';
            }
            $lang = worker_normalize_code_language(isset($mm[1]) ? (string)$mm[1] : 'text');
            $rest = ltrim(isset($mm[2]) ? (string)$mm[2] : '', "\r\n");
            $encoded = htmlspecialchars((string)$rest, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $attrs = (string)$codeAttrs;
            if (preg_match('/\bclass\s*=\s*"([^"]*)"/i', (string)$attrs, $cm)) {
                $classes = preg_split('/\s+/', trim(isset($cm[1]) ? (string)$cm[1] : ''));
                if (!is_array($classes)) $classes = array();
                $next = array();
                $hasLangClass = false;
                foreach ($classes as $className) {
                    $className = trim((string)$className);
                    if ($className === '') continue;
                    $lower = strtolower((string)$className);
                    if (strpos((string)$lower, 'lang-') === 0) {
                        $hasLangClass = true;
                        if ($lower === 'lang-auto' || $lower === 'lang-text') {
                            $next[] = 'lang-' . $lang;
                        } else {
                            $next[] = $className;
                        }
                    } else {
                        $next[] = $className;
                    }
                }
                if (!$hasLangClass) $next[] = 'lang-' . $lang;
                $next = array_values(array_unique($next));
                $newClass = 'class="' . implode(' ', $next) . '"';
                $attrs = preg_replace('/\bclass\s*=\s*"[^"]*"/i', $newClass, (string)$attrs, 1);
                $attrs = is_string($attrs) ? $attrs : (string)$codeAttrs;
            } else {
                $attrs .= ' class="lang-' . $lang . '"';
            }

            return '<pre' . $preAttrs . '><code' . $attrs . '>' . $encoded . '</code></pre>';
        }, (string)$text);
        $text = is_string($text) ? $text : '';
    }

    $text = preg_replace('/\n{3,}/', "\n\n", (string)$text);
    return trim((string)$text);
}

function worker_force_fenced_code_from_inline($text)
{
    $text = trim((string)$text);
    if ($text === '' || strpos((string)$text, '```') !== false) return $text;

    if (!preg_match('/`(?:\s*(js|javascript|ts|typescript|css|html|php|python|sql))?\s*([^`]{20,})`/is', (string)$text, $m, PREG_OFFSET_CAPTURE)) {
        return $text;
    }

    $full = isset($m[0][0]) ? (string)$m[0][0] : '';
    $fullPos = isset($m[0][1]) ? (int)$m[0][1] : -1;
    $code = worker_prettify_inline_code(isset($m[2][0]) ? (string)$m[2][0] : '');
    $codeLike = worker_inline_code_looks_programmatic((string)$code);
    if ($full === '' || $fullPos < 0 || $code === '' || !$codeLike) return $text;

    $block = "```\n{$code}\n```";
    $before = rtrim((string)substr((string)$text, 0, $fullPos));
    $after = ltrim((string)substr((string)$text, $fullPos + strlen($full)));
    $out = $before . "\n\n" . $block;
    if ($after !== '') $out .= "\n\n" . $after;
    $out = preg_replace('/\n{3,}/', "\n\n", (string)$out);
    return trim((string)$out);
}

function worker_has_unfenced_multiline_code_candidate($text)
{
    $text = trim((string)$text);
    if ($text === '' || strpos((string)$text, '```') !== false) return false;

    if (preg_match('/`[^`]*\n[^`]*`/s', (string)$text)) return true;
    if (preg_match('/`(?:\s*(js|javascript|ts|typescript|css|html|php|python|sql))?\s*([^`]{20,})`/is', (string)$text, $m)) {
        $code = isset($m[2]) ? (string)$m[2] : '';
        if (worker_inline_code_looks_programmatic((string)$code)) {
            return true;
        }
    }

    $probe = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $probe = preg_replace('/https?:\/\/\S+/i', ' ', (string)$probe);
    $probe = preg_replace('/\s+/', ' ', (string)$probe);
    $probe = is_string($probe) ? trim($probe) : '';
    if ($probe === '' || strlen($probe) < 110) return false;

    $anchor = (bool)preg_match('/\b(class\s+\w+|function\s+\w+|const\s+\w+\s*=|let\s+\w+\s*=|var\s+\w+\s*=|console\.log\s*\(|return\b|if\s*\(|for\s*\(|while\s*\()\b/i', $probe);
    if (!$anchor) return false;
    $punctCount = preg_match_all('/[;{}()]/', $probe, $pm);
    return is_int($punctCount) && $punctCount >= 6;
}

function worker_repair_code_block_with_llm($bot, $topicTitle, $opRaw, $draft, $signature)
{
    $draft = trim((string)$draft);
    if ($draft === '' || strpos((string)$draft, '```') !== false || KONVO_ANTHROPIC_API_KEY === '') {
        return '';
    }
    $soulKey = isset($bot['soul_key']) ? (string)$bot['soul_key'] : '';
    $soul = konvo_compose_forum_persona_system_prompt(
        konvo_load_soul($soulKey, 'Write concise, natural forum replies.')
    );
    $payload = array(
        'model' => worker_model_for_task('code_repair'),
        'messages' => array(
            array(
                'role' => 'system',
                'content' => $soul
                    . ' Rewrite the reply so any multi-line code is in a fenced code block with proper line breaks.'
                    . ' Keep it concise and human. Do not end with a question.'
                    . ' Do not sign your post; the forum already shows your username.',
            ),
            array(
                'role' => 'user',
                'content' => "Topic title: {$topicTitle}\n\nTarget content:\n{$opRaw}\n\nCurrent draft:\n{$draft}\n\nRewrite with proper fenced code formatting.",
            ),
        ),
        'temperature' => 0.25,
    );
    $res = post_json(
        'llm:chat',
        $payload,
        array('Authorization: Bearer ' . KONVO_ANTHROPIC_API_KEY)
    );
    if (!$res['ok'] || !isset($res['body']['choices'][0]['message']['content'])) return '';
    $txt = trim((string)$res['body']['choices'][0]['message']['content']);
    if ($txt === '' || strpos((string)$txt, '```') === false) return '';
    return $txt;
}

function is_codey_topic($title, $text)
{
    $blob = strtolower(trim((string)$title . "\n" . (string)$text));
    if ($blob === '') return false;
    return (bool)preg_match('/\b(code|coding|javascript|typescript|css|html|php|python|api|framework|debug|error|bug|performance|architecture|design system|token|ui|ux)\b/i', $blob);
}

function worker_is_gaming_topic($title, $text)
{
    $blob = strtolower(trim((string)$title . "\n" . (string)$text));
    if ($blob === '') return false;
    return (bool)preg_match(
        '/\b(video game|gaming|gameplay|trailer|clip|dlc|patch|hotfix|speedrun|easter egg|xbox|playstation|ps5|ps4|nintendo|switch|steam|epic games|riot games|blizzard|ubisoft|capcom|fromsoftware|rpg|fps|mmo|retro game|classic game|arcade|8-bit|16-bit|nes|snes|n64|nintendo 64|game boy|sega genesis|mega drive|dreamcast|ps1|playstation 1|ps2|playstation 2|super mario|legend of zelda|zelda|half[- ]life|mechwarrior)\b/i',
        $blob
    );
}

function worker_has_retro_gaming_signal($text)
{
    $blob = strtolower(trim((string)$text));
    if ($blob === '') return false;
    return (bool)preg_match(
        '/\b(retro|classic|old school|old-school|arcade|8-bit|16-bit|80s|90s|dos|ms-dos|shareware|pixel art|super mario|mario kart|legend of zelda|zelda|ocarina of time|a link to the past|half[- ]life|mechwarrior|doom|quake|street fighter ii|metal slug|sonic|sega genesis|mega drive|snes|super nintendo|nes|n64|nintendo 64|game boy|dreamcast|ps1|playstation 1|ps2|playstation 2|arcade cabinet)\b/i',
        $blob
    );
}

function worker_is_meme_gif_context($text)
{
    $blob = trim((string)$text);
    if ($blob === '') return false;
    if (preg_match('/https?:\/\/(?:media\.)?giphy\.com\/\S+/i', $blob)) return true;
    if (preg_match('/https?:\/\/(?:www\.)?tenor\.com\/\S+/i', $blob)) return true;
    if (preg_match('/https?:\/\/(?:i\.)?imgur\.com\/\S+\.gif(?:\?\S*)?$/i', $blob)) return true;
    return (bool)preg_match('/\b(meme|gif|giphy|tenor|reaction gif|reaction image|vibe check|shitpost)\b/i', $blob);
}

function worker_is_critique_style_text($text)
{
    $t = trim((string)$text);
    if ($t === '') return false;
    $patterns = array(
        '/\b(clean and readable|looks good,\s*though|nice,\s*though)\b/i',
        '/\b(could|should|would)\b[^.!?\n]{0,56}\b(better|improve|improvement|fix|change|adjust|optimi[sz]e|tighten|make)\b/i',
        '/\b(needs?|lacks?)\b[^.!?\n]{0,40}\b(better|improvement|more|less|work)\b/i',
        '/\b(tiny pause|pause before looping|twitchy|polish)\b/i',
    );
    foreach ($patterns as $p) {
        if (preg_match($p, $t)) return true;
    }
    return false;
}

function worker_meme_reaction_fallback($seed)
{
    $opts = array(
        'LOL this one is great.',
        'Haha this is top-tier meme energy.',
        'Okay this got a real laugh out of me.',
        'This one wins, no notes.',
        'Instant mood boost, love this one.',
    );
    $idx = abs((int)crc32(strtolower(trim((string)$seed)))) % count($opts);
    return (string)$opts[$idx];
}

function topic_wants_reference_link($title, $body)
{
    $blob = strtolower(trim((string)$title . "\n" . (string)$body));
    if ($blob === '') return false;
    return (bool)preg_match(
        '/\b(link|article|read|reading|resource|resources|reference|references|source|sources|docs|documentation|tutorial|examples|show me|any good|recommend)\b/i',
        $blob
    );
}

function worker_is_solution_problem_thread($text)
{
    $blob = strtolower(trim((string)$text));
    if ($blob === '') return false;
    $score = 0;
    if (preg_match('/\b(problem|issue|pain point|friction|bad ux|poor ux|confusing|hard to use|hard to find|waste|wasted|fails?|failure|broken|not working|struggle|annoying)\b/i', $blob)) {
        $score += 2;
    }
    if (preg_match('/\b(how do i|how can i|how should|what should|how to|workaround|fix|improve|better way|solution|solve|reduce|prevent)\b/i', $blob)) {
        $score += 2;
    }
    if (preg_match('/\b(ui|ux|design|workflow|process|onboarding|usability|accessibility|productivity)\b/i', $blob)) {
        $score += 1;
    }
    return $score >= 3;
}

function worker_fetch_text_url($url)
{
    $ch = curl_init((string)$url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'konvo-bot/1.0',
    ));
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $err !== '' || $status < 200 || $status >= 300) return '';
    return (string)$body;
}

function worker_find_relevant_solution_youtube_video_url($query)
{
    $q = trim((string)(preg_replace('/\s+/', ' ', strip_tags((string)$query)) ?? (string)$query));
    if ($q === '') return '';
    if (strlen($q) > 140) $q = substr($q, 0, 140);

    $search = 'site:youtube.com ' . $q . ' tutorial walkthrough how to fix practical';
    $url = 'https://html.duckduckgo.com/html/?q=' . rawurlencode($search);
    $html = worker_fetch_text_url($url);
    if ($html === '') return '';

    if (preg_match_all('/uddg=([^&"\']+)/i', $html, $m) && isset($m[1]) && is_array($m[1])) {
        foreach ($m[1] as $encoded) {
            $cand = urldecode(html_entity_decode((string)$encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $cand = trim((string)$cand);
            if ($cand === '') continue;
            if (preg_match('/https?:\/\/(?:www\.)?(?:youtu\.be\/[A-Za-z0-9_-]+|youtube\.com\/(?:watch\?v=[A-Za-z0-9_-]+|shorts\/[A-Za-z0-9_-]+|live\/[A-Za-z0-9_-]+))/i', (string)$cand)) {
                return (string)$cand;
            }
        }
    }

    if (preg_match_all('/https?:\/\/(?:www\.)?(?:youtube\.com\/watch\?v=[A-Za-z0-9_-]+|youtu\.be\/[A-Za-z0-9_-]+|youtube\.com\/shorts\/[A-Za-z0-9_-]+|youtube\.com\/live\/[A-Za-z0-9_-]+)/i', $html, $m2) && isset($m2[0][0])) {
        return trim((string)$m2[0][0]);
    }
    return '';
}

function worker_find_relevant_gaming_youtube_video_url($query)
{
    $q = trim((string)(preg_replace('/\s+/', ' ', strip_tags((string)$query)) ?? (string)$query));
    if ($q === '') return '';
    if (strlen($q) > 140) $q = substr($q, 0, 140);

    $retro = worker_has_retro_gaming_signal($q);
    $searches = array();
    if ($retro) {
        $searches[] = 'site:youtube.com ' . $q . ' retro classic full walkthrough longplay';
        $searches[] = 'site:youtube.com ' . $q . ' World of Longplays LongplayArchive Summoning Salt';
        $searches[] = 'site:youtube.com ' . $q . ' super mario zelda half-life mechwarrior gameplay';
    }
    $searches[] = 'site:youtube.com ' . $q . ' gameplay trailer walkthrough';
    $searches[] = 'site:youtube.com ' . $q . ' theRadBrad FightinCowboy Shirrako';

    $seen = array();
    foreach ($searches as $search) {
        $search = trim((string)$search);
        if ($search === '') continue;
        $k = strtolower($search);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;

        $url = 'https://html.duckduckgo.com/html/?q=' . rawurlencode($search);
        $html = worker_fetch_text_url($url);
        if ($html === '') continue;

        if (preg_match_all('/uddg=([^&"\']+)/i', $html, $m) && isset($m[1]) && is_array($m[1])) {
            foreach ($m[1] as $encoded) {
                $cand = urldecode(html_entity_decode((string)$encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $cand = trim((string)$cand);
                if ($cand === '') continue;
                if (preg_match('/https?:\/\/(?:www\.)?(?:youtu\.be\/[A-Za-z0-9_-]+|youtube\.com\/(?:watch\?v=[A-Za-z0-9_-]+|shorts\/[A-Za-z0-9_-]+|live\/[A-Za-z0-9_-]+))/i', (string)$cand)) {
                    return (string)$cand;
                }
            }
        }
        if (preg_match_all('/https?:\/\/(?:www\.)?(?:youtube\.com\/watch\?v=[A-Za-z0-9_-]+|youtu\.be\/[A-Za-z0-9_-]+|youtube\.com\/shorts\/[A-Za-z0-9_-]+|youtube\.com\/live\/[A-Za-z0-9_-]+)/i', $html, $m2) && isset($m2[0][0])) {
            return trim((string)$m2[0][0]);
        }
    }
    return '';
}

function link_keywords($text)
{
    $s = strtolower(trim((string)$text));
    $s = preg_replace('/[^a-z0-9\s]/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', (string)$s);
    if (!is_string($s) || trim($s) === '') return array();
    $parts = preg_split('/\s+/', trim($s));
    if (!is_array($parts)) return array();
    $stop = array('the', 'this', 'that', 'with', 'from', 'into', 'your', 'what', 'when', 'where', 'which', 'why', 'how', 'for', 'and', 'or', 'are', 'is', 'was', 'were', 'will', 'would', 'should', 'could', 'about');
    $out = array();
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p === '' || strlen($p) < 4 || in_array($p, $stop, true)) continue;
        $out[$p] = true;
    }
    return array_keys($out);
}

function link_overlap_score($a, $b)
{
    $ta = link_keywords((string)$a);
    $tb = link_keywords((string)$b);
    if ($ta === array() || $tb === array()) return 0.0;
    $sa = array_fill_keys($ta, true);
    $sb = array_fill_keys($tb, true);
    $intersection = 0;
    foreach ($sa as $k => $_) {
        if (isset($sb[$k])) $intersection++;
    }
    $union = count($sa) + count($sb) - $intersection;
    if ($union <= 0) return 0.0;
    return (float)$intersection / (float)$union;
}

function is_low_signal_link_domain($url)
{
    $host = parse_url((string)$url, PHP_URL_HOST);
    $host = strtolower(trim((string)$host));
    if ($host === '') return true;
    $host = preg_replace('/^www\./', '', $host);
    if (!is_string($host)) $host = '';
    $blocked = array(
        'trustbit.io',
        'bit.ly',
        'tinyurl.com',
        't.co',
        'ow.ly',
        'shorturl.at',
        'is.gd',
        'buff.ly',
        'rebrand.ly',
    );
    if (in_array($host, $blocked, true)) return true;
    if (preg_match('/(?:short|shrt|trk|redirect)/i', $host)) return true;
    return false;
}

function link_looks_shopping_deal($title, $url)
{
    $blob = strtolower(trim((string)$title . "\n" . (string)$url));
    if ($blob === '') return false;

    if (preg_match('/\b(coupon|promo code|discount code|price drop|clearance|doorbuster|black friday|cyber monday|prime day|buy now|shop now|limited[- ]time offer|save\s*\$|%\s*off|for less)\b/i', $blob)) {
        return true;
    }
    if (preg_match('/\/deals?\b|[?&](deal|deals|coupon|promo|discount)=|black-friday|cyber-monday|prime-day|\/shopping\//i', (string)$url)) {
        return true;
    }

    $dealWord = (bool)preg_match('/\b(deal|deals|sale|on sale|discount|offer|offers)\b/i', $blob);
    $commerceWord = (bool)preg_match('/\b(shop|shopping|buy|price|priced|pricing|checkout|cart|amazon|walmart|best buy|target|costco|ebay)\b/i', $blob)
        || (bool)preg_match('/\$\s*\d+|\d+\s*usd/i', $blob);
    return $dealWord && $commerceWord;
}

function link_relevant_to_topic($topicTitle, $topicBody, $linkTitle, $linkUrl)
{
    if (is_low_signal_link_domain((string)$linkUrl)) {
        return false;
    }
    if (link_looks_shopping_deal((string)$linkTitle, (string)$linkUrl)) {
        return false;
    }
    $topicBlob = trim((string)$topicTitle . "\n" . (string)$topicBody);
    if (!is_string($topicBlob) || trim($topicBlob) === '') {
        return false;
    }
    $score = link_overlap_score((string)$topicBlob, (string)$linkTitle);
    if ($score >= 0.22) return true;

    // For coding topics, allow slightly lower overlap if core terms align.
    if (is_codey_topic($topicTitle, $topicBody) && $score >= 0.16) return true;
    return false;
}

function canonical_url_for_compare($url)
{
    $u = trim((string)$url);
    $u = html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $u = preg_replace('/[)\]\}\.,;:!?]+$/', '', (string)$u);
    return strtolower(trim((string)$u));
}

function strip_all_urls($text)
{
    $text = preg_replace('/\s*https?:\/\/\S+\s*/i', "\n\n", (string)$text);
    $text = preg_replace('/\n{3,}/', "\n\n", (string)$text);
    return trim((string)$text);
}

function enforce_reply_link_alignment($text, $allowedUrl, $allowedTitle, $topicTitle, $allowLooseContext = false)
{
    $text = trim((string)$text);
    if ($text === '') return $text;

    $allowed = canonical_url_for_compare((string)$allowedUrl);
    if ($allowed === '') {
        return strip_all_urls($text);
    }

    $keptAllowed = false;
    $text = preg_replace_callback('/https?:\/\/\S+/i', function ($m) use (&$keptAllowed, $allowed) {
        $raw = trim((string)($m[0] ?? ''));
        $canon = canonical_url_for_compare($raw);
        if ($canon !== '' && $canon === $allowed && !$keptAllowed) {
            $keptAllowed = true;
            return $raw;
        }
        return '';
    }, $text);
    if (!is_string($text)) $text = '';

    if (!$keptAllowed) {
        return strip_all_urls($text);
    }

    $body = trim((string)preg_replace('/https?:\/\/\S+/i', '', $text));
    $body = trim((string)preg_replace('/\s+/', ' ', (string)$body));
    $supportScore = link_overlap_score(
        (string)$body,
        trim((string)$allowedTitle . ' ' . (string)$topicTitle)
    );

    // Keep links only when the reply has enough concrete context around them.
    if (!$allowLooseContext && ($body === '' || strlen($body) < 45 || $supportScore < 0.06)) {
        return strip_all_urls($text);
    }

    return force_standalone_urls($text);
}

function clip_complete_thought($text, $maxChars)
{
    $text = trim((string)(preg_replace('/\s+/', ' ', (string)$text) ?? (string)$text));
    $max = (int)$maxChars;
    if ($text === '' || $max < 40 || strlen($text) <= $max) {
        return $text;
    }

    $window = substr($text, 0, $max + 1);
    $bestSentenceEnd = -1;
    if (preg_match_all('/[.!?](?=\s|$)/', $window, $m, PREG_OFFSET_CAPTURE) && isset($m[0])) {
        foreach ($m[0] as $match) {
            $pos = (int)($match[1] ?? -1);
            if ($pos > $bestSentenceEnd) {
                $bestSentenceEnd = $pos;
            }
        }
    }
    if ($bestSentenceEnd > 48) {
        return trim((string)substr($window, 0, $bestSentenceEnd + 1));
    }

    $commaPos = strrpos($window, ',');
    if ($commaPos !== false && $commaPos > 80) {
        $candidate = trim((string)substr($window, 0, (int)$commaPos));
        $candidate = rtrim($candidate, " ,;:-");
        if ($candidate !== '' && !preg_match('/[.!?]$/', $candidate)) {
            $candidate .= '.';
        }
        return $candidate;
    }

    $spacePos = strrpos(substr($window, 0, $max), ' ');
    if ($spacePos === false || $spacePos < 40) {
        $spacePos = $max;
    }
    $candidate = trim((string)substr($window, 0, (int)$spacePos));
    $candidate = preg_replace('/\b(and|or|but|so|because|while|though|although|with|to|of|for|in|on|at|from|by|about|as|into|onto|over|under|around|through|between|the|a|an|this|that|these|those|my|your|our|their|his|her|its)\s*$/i', '', $candidate) ?? $candidate;
    $candidate = trim((string)$candidate);
    $candidate = rtrim($candidate, " ,;:-");
    if ($candidate !== '' && !preg_match('/[.!?]$/', $candidate)) {
        $candidate .= '.';
    }
    return $candidate !== '' ? $candidate : trim((string)substr($text, 0, $max));
}

function worker_inline_numbered_to_bullets($text)
{
    $txt = trim((string)$text);
    if ($txt === '' || strpos($txt, '```') !== false || stripos($txt, '<pre><code') !== false) return $txt;
    $lines = preg_split('/\R/', $txt) ?: array();
    $out = array();
    foreach ($lines as $line) {
        $line = (string)$line;
        $trim = trim($line);
        if ($trim === '') {
            $out[] = '';
            continue;
        }
        $matches = array();
        preg_match_all('/(?:^|[\s,;])(\\d+)[\\)\\.]\\s*([^,;]+?)(?=(?:\\s*[,;]\\s*\\d+[\\)\\.]|\\s*$))/u', $trim, $matches, PREG_SET_ORDER);
        if (is_array($matches) && count($matches) >= 3) {
            foreach ($matches as $m) {
                $item = trim((string)($m[2] ?? ''));
                if ($item === '') continue;
                $item = rtrim($item, " \t\n\r\0\x0B,;");
                if ($item !== '') $out[] = '- ' . $item;
            }
            continue;
        }
        $out[] = $line;
    }
    $normalized = trim(implode("\n", $out));
    $normalized = preg_replace('/\n{3,}/', "\n\n", $normalized);
    return trim((string)$normalized);
}

function tighten_human_forum_reply($text, $botUsername, $topicTitle, $opRaw)
{
    $text = trim((string)$text);
    if ($text === '') return $text;

    // Preserve fenced code snippets for coding-related replies.
    if (strpos($text, '```') !== false) {
        $text = preg_replace('/\n{3,}/', "\n\n", (string)$text);
        return trim((string)$text);
    }

    $text = str_replace(array('—', '–'), '-', $text);
    $text = preg_replace('/\b(the interesting part is|the core point is|this piece explains|it works when|the contrarian take is|the real tell will be)\b[:\s-]*/i', '', (string)$text);
    $text = preg_replace('/^(?:@[\w_]+\s*[,:\-]\s*)?(?:yep|yes|yeah|exactly|totally|totally agree|totally,|totally\s*[—-]|absolutely|100%|great point|good point|spot on)\b[\s,:\-–—]*/i', '', (string)$text);
    $text = preg_replace('/\bblast radius\b/i', 'impact scope', (string)$text);
    $text = preg_replace('/\bgotcha\b/i', 'edge case', (string)$text);
    $text = preg_replace('/\bRelated link:\s*/i', '', $text);
    $text = preg_replace('/\s+/', ' ', (string)$text);
    $text = str_replace(';', '.', (string)$text);
    $text = trim((string)$text);

    preg_match_all('/https?:\/\/\S+/i', $text, $matches);
    $firstUrl = (isset($matches[0][0]) && is_string($matches[0][0])) ? trim((string)$matches[0][0]) : '';
    $body = trim((string)preg_replace('/https?:\/\/\S+/i', '', $text));
    $body = trim((string)preg_replace('/\s+/', ' ', (string)$body));

    $sentences = preg_split('/(?<=[.!?])\s+/u', $body) ?: array();
    $kept = array();
    foreach ($sentences as $sentence) {
        $sentence = trim((string)$sentence);
        if ($sentence === '') continue;
        if (preg_match('/^(interesting topic|nice topic|good direction|short version|the core point is|the clean mental model is|in short|this piece explains|the interesting part|contrarian take)\b/i', $sentence)) {
            continue;
        }
        $kept[] = $sentence;
    }
    if ($kept === array()) {
        $kept = $sentences;
    }

    $isCode = is_codey_topic($topicTitle, $opRaw);
    $b = strtolower(trim((string)$botUsername));

    $maxSentences = $isCode ? 2 : 1;
    $maxChars = $isCode ? 190 : 150;
    if ($b === 'bobamilk') {
        $maxSentences = 1;
        $maxChars = $isCode ? 135 : 115;
    } elseif ($b === 'sora') {
        $maxSentences = 1;
        $maxChars = $isCode ? 150 : 125;
    } elseif ($b === 'hariseldon' || $b === 'arthurdent' || $b === 'wafflefries') {
        $maxChars = $isCode ? 170 : 145;
    }

    $finalSentences = array_slice($kept, 0, $maxSentences);
    $trimmed = trim(implode(' ', $finalSentences));
    if ($trimmed === '') {
        $trimmed = trim((string)$body);
    }

    if (strlen($trimmed) > $maxChars) {
        $firstSentence = trim((string)($finalSentences[0] ?? ''));
        if ($firstSentence !== '' && strlen($firstSentence) <= ($maxChars + 120)) {
            $trimmed = $firstSentence;
        } else {
            $trimmed = clip_complete_thought($trimmed, $maxChars);
        }
    }

    $trimmed = trim((string)preg_replace('/\s+/', ' ', (string)$trimmed));
    if (strlen($trimmed) > 120) {
        $trimmed = preg_replace('/,\s+(but|and|because|while|so|which)\b/iu', '. $1', (string)$trimmed) ?? (string)$trimmed;
        $trimmed = preg_replace('/;\s+/u', '. ', (string)$trimmed) ?? (string)$trimmed;
        $trimmed = trim((string)$trimmed);
    }
    if ($trimmed !== '' && !preg_match('/[.!]$/', $trimmed)) {
        $trimmed .= '.';
    }

    $parts = preg_split('/(?<=[.!?])\s+/u', (string)$trimmed) ?: array();
    $parts = array_values(array_filter(array_map(static function ($s) {
        return trim((string)$s);
    }, $parts), static function ($s) {
        return $s !== '';
    }));
    if (count($parts) >= 2) {
        $trimmed = implode("\n\n", array_slice($parts, 0, $maxSentences > 1 ? 2 : 1));
    }

    $trimmed = worker_inline_numbered_to_bullets($trimmed);

    if ($firstUrl !== '') {
        return $trimmed . "\n\n" . $firstUrl;
    }
    return $trimmed;
}

function build_contextual_fallback_reply($topicTitle, $opRaw, $signature, $linkData, $shouldIncludeLink)
{
    $seed = trim((string)$opRaw);
    if ($seed === '') {
        $seed = trim((string)$topicTitle);
    }
    $seed = strip_tags((string)$seed);
    $seed = preg_replace('/\s+/', ' ', (string)$seed);
    $seed = trim((string)$seed);

    if ($seed === '') {
        $seed = 'Sharing a quick take.';
    }

    if (strlen($seed) > 140) {
        $seed = clip_complete_thought($seed, 140);
    }

    if (!preg_match('/[.!]$/', $seed)) {
        $seed .= '.';
    }

    return normalize_signature($seed, (string)$signature);
}

function post_content_text($post)
{
    $raw = isset($post['raw']) ? trim((string)$post['raw']) : '';
    if ($raw !== '') return $raw;
    $cooked = isset($post['cooked']) ? (string)$post['cooked'] : '';
    if ($cooked === '') return '';
    $plain = trim(html_entity_decode(strip_tags($cooked), ENT_QUOTES, 'UTF-8'));
    $plain = preg_replace('/\s+/', ' ', $plain);
    return trim((string)$plain);
}

function is_bot_user($username)
{
    $u = strtolower(trim((string)$username));
    $botUsers = array('baymax', 'kirupabot', 'vaultboy', 'mechaprime', 'yoshiii', 'bobamilk', 'wafflefries', 'quelly', 'sora', 'sarah_connor', 'ellen1979', 'arthurdent', 'hariseldon', 'kirupabotx', 'coding_agent_bot');
    return in_array($u, $botUsers, true);
}

function worker_recent_other_bot_posts($posts, $currentBotUsername, $limit = 4)
{
    if (!is_array($posts) || $posts === array()) return array();
    $limit = max(0, (int)$limit);
    if ($limit === 0) return array();
    $current = strtolower(trim((string)$currentBotUsername));
    $picked = array();
    for ($i = count($posts) - 1; $i >= 0; $i--) {
        $post = $posts[$i] ?? null;
        if (!is_array($post)) continue;
        $u = trim((string)($post['username'] ?? ''));
        $ul = strtolower($u);
        if ($u === '' || $ul === $current || !is_bot_user($u)) continue;
        $raw = post_content_text($post);
        if ($raw === '') continue;
        $picked[] = array(
            'username' => $u,
            'post_number' => (int)($post['post_number'] ?? 0),
            'raw' => $raw,
        );
        if (count($picked) >= $limit) break;
    }
    return array_reverse($picked);
}

function worker_recent_same_bot_posts($posts, $currentBotUsername, $limit = 3)
{
    if (!is_array($posts) || $posts === array()) return array();
    $limit = max(0, (int)$limit);
    if ($limit === 0) return array();
    $current = strtolower(trim((string)$currentBotUsername));
    if ($current === '') return array();
    $picked = array();
    for ($i = count($posts) - 1; $i >= 0; $i--) {
        $post = $posts[$i] ?? null;
        if (!is_array($post)) continue;
        $u = trim((string)($post['username'] ?? ''));
        $ul = strtolower($u);
        if ($u === '' || $ul !== $current) continue;
        $raw = post_content_text($post);
        if ($raw === '') continue;
        $picked[] = array(
            'username' => $u,
            'post_number' => (int)($post['post_number'] ?? 0),
            'raw' => $raw,
        );
        if (count($picked) >= $limit) break;
    }
    return array_reverse($picked);
}

function worker_recent_other_bot_context($recentBotPosts)
{
    if (!is_array($recentBotPosts) || $recentBotPosts === array()) {
        return 'Recent bot replies: (none)';
    }
    $lines = array();
    foreach ($recentBotPosts as $p) {
        if (!is_array($p)) continue;
        $raw = trim((string)($p['raw'] ?? ''));
        if ($raw === '') continue;
        $lines[] = 'Post #' . (int)($p['post_number'] ?? 0) . ' by @' . (string)($p['username'] ?? '') . ":\n" . $raw;
    }
    if ($lines === array()) return 'Recent bot replies: (none)';
    return "Recent bot replies (avoid repeating these points):\n" . implode("\n\n", $lines);
}

function worker_recent_same_bot_context($recentBotPosts)
{
    if (!is_array($recentBotPosts) || $recentBotPosts === array()) {
        return 'Your recent replies in this thread: (none)';
    }
    $lines = array();
    foreach ($recentBotPosts as $p) {
        if (!is_array($p)) continue;
        $raw = trim((string)($p['raw'] ?? ''));
        if ($raw === '') continue;
        $lines[] = 'Post #' . (int)($p['post_number'] ?? 0) . ":\n" . $raw;
    }
    if ($lines === array()) return 'Your recent replies in this thread: (none)';
    return "Your recent replies in this thread (do not rephrase these):\n" . implode("\n\n", $lines);
}

function worker_tokenize_for_similarity($text)
{
    $lc = strtolower((string)$text);
    $lc = preg_replace('/[^a-z0-9\s]/', ' ', $lc);
    $lc = preg_replace('/\s+/', ' ', (string)$lc);
    if (!is_string($lc) || trim($lc) === '') return array();
    $parts = preg_split('/\s+/', trim($lc));
    if (!is_array($parts)) return array();
    $stop = array(
        'the', 'a', 'an', 'and', 'or', 'to', 'of', 'for', 'in', 'on', 'at', 'is', 'it', 'this', 'that',
        'with', 'from', 'about', 'you', 'your', 'are', 'was', 'were', 'but', 'not', 'just', 'very', 'really'
    );
    $out = array();
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if (strlen($p) < 4 || in_array($p, $stop, true)) continue;
        $out[$p] = true;
    }
    return array_keys($out);
}

function worker_phrase_stopwords()
{
    static $stop = null;
    if (is_array($stop)) return $stop;
    $stop = array_fill_keys(array(
        'the', 'a', 'an', 'and', 'or', 'to', 'of', 'for', 'in', 'on', 'at', 'is', 'it', 'this', 'that',
        'with', 'from', 'about', 'you', 'your', 'are', 'was', 'were', 'but', 'not', 'just', 'very', 'really',
        'what', 'why', 'how', 'when', 'where', 'which', 'can', 'could', 'should', 'would', 'do', 'does', 'did',
        'i', 'we', 'they', 'he', 'she', 'them', 'our', 'their', 'my', 'me', 'us', 'if', 'then', 'than'
    ), true);
    return $stop;
}

function worker_phrase_normalize($text)
{
    $s = strtolower(trim((string)$text));
    $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/[^a-z0-9\s]/', ' ', (string)$s);
    $s = preg_replace('/\s+/', ' ', (string)$s);
    return trim((string)$s);
}

function worker_extract_phrase_candidates($text)
{
    $text = trim((string)$text);
    if ($text === '') return array();
    $stop = worker_phrase_stopwords();
    $out = array();

    if (preg_match_all('/["\']([^"\']{3,80})["\']/u', $text, $m) && isset($m[1]) && is_array($m[1])) {
        foreach ($m[1] as $q) {
            $p = worker_phrase_normalize((string)$q);
            if ($p === '' || strlen($p) < 6) continue;
            $out[$p] = true;
        }
    }

    if (preg_match_all('/\b(?:[A-Z][A-Za-z0-9\'-]*\s+){1,5}[A-Z][A-Za-z0-9\'-]*\b/u', $text, $m2) && isset($m2[0]) && is_array($m2[0])) {
        foreach ($m2[0] as $cand) {
            $p = worker_phrase_normalize((string)$cand);
            if ($p === '' || strlen($p) < 6) continue;
            $parts = preg_split('/\s+/', $p);
            if (!is_array($parts) || count($parts) < 2 || count($parts) > 7) continue;
            $content = 0;
            foreach ($parts as $w) {
                if (!isset($stop[$w]) && strlen((string)$w) >= 4) $content++;
            }
            if ($content < 1) continue;
            $out[$p] = true;
        }
    }

    $plain = worker_phrase_normalize($text);
    $tokens = preg_split('/\s+/', $plain);
    if (!is_array($tokens)) $tokens = array();
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        for ($len = 2; $len <= 4; $len++) {
            if (($i + $len) > $n) break;
            $slice = array_slice($tokens, $i, $len);
            if (count($slice) < 2) continue;
            $first = (string)$slice[0];
            $last = (string)$slice[count($slice) - 1];
            if (isset($stop[$first]) || isset($stop[$last])) continue;
            $content = 0;
            foreach ($slice as $w) {
                if (!isset($stop[$w]) && strlen((string)$w) >= 4) $content++;
            }
            if ($content < 2) continue;
            $phrase = trim(implode(' ', $slice));
            if ($phrase === '' || strlen($phrase) < 8 || strlen($phrase) > 64) continue;
            if (preg_match('/^(what|why|how|when|where|which|can|could|should|would)\b/i', $phrase)) continue;
            $out[$phrase] = true;
        }
    }

    return array_keys($out);
}

function worker_extract_opening_line($text)
{
    $scan = str_replace(array("\r\n", "\r"), "\n", (string)$text);
    $scan = preg_replace('/```[\s\S]*?```/m', ' ', (string)$scan);
    $scan = preg_replace('/<pre><code[\s\S]*?<\/code><\/pre>/i', ' ', (string)$scan);
    $scan = preg_replace('/`[^`]*`/', ' ', (string)$scan);
    $scan = html_entity_decode(strip_tags((string)$scan), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $lines = preg_split('/\n+/', (string)$scan);
    if (!is_array($lines)) $lines = array();
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') continue;
        $line = preg_replace('/\s+/', ' ', (string)$line);
        $line = trim((string)$line);
        if (strlen($line) > 220) {
            $line = trim((string)substr($line, 0, 220));
        }
        return (string)$line;
    }
    return '';
}

function worker_overlap_ready_phrases($text)
{
    $raw = worker_extract_phrase_candidates((string)$text);
    if (!is_array($raw) || $raw === array()) return array();
    $long = array();
    $fallback = array();
    foreach ($raw as $phrase) {
        $p = worker_phrase_normalize((string)$phrase);
        if ($p === '') continue;
        $parts = preg_split('/\s+/', (string)$p);
        if (!is_array($parts)) $parts = array();
        $words = count($parts);
        if ($words >= 3 && $words <= 8) $long[$p] = true;
        if ($words >= 2 && $words <= 8) $fallback[$p] = true;
    }
    if ($long !== array()) return array_keys($long);
    return array_keys($fallback);
}

function worker_phrase_overlap_stats($candidate, $reference)
{
    $cand = worker_overlap_ready_phrases((string)$candidate);
    $ref = worker_overlap_ready_phrases((string)$reference);
    if ($cand === array() || $ref === array()) {
        return array('shared_count' => 0, 'candidate_count' => count($cand), 'ratio' => 0.0, 'shared_phrases' => array());
    }
    $refSet = array_fill_keys($ref, true);
    $shared = array();
    foreach ($cand as $p) {
        if (isset($refSet[$p])) $shared[] = $p;
    }
    $shared = array_values(array_unique($shared));
    usort($shared, function ($a, $b) {
        return strlen((string)$b) <=> strlen((string)$a);
    });
    $sharedCount = count($shared);
    $candidateCount = count($cand);
    $ratio = (float)$sharedCount / (float)max(1, $candidateCount);
    return array(
        'shared_count' => $sharedCount,
        'candidate_count' => $candidateCount,
        'ratio' => $ratio,
        'shared_phrases' => array_slice($shared, 0, 3),
    );
}

function worker_collect_thread_saturated_phrases($posts, $window = 45)
{
    if (!is_array($posts) || $posts === array()) return array();
    $slice = array_slice($posts, -1 * max(8, (int)$window));
    $counts = array();
    $postsSeen = 0;
    foreach ($slice as $post) {
        if (!is_array($post)) continue;
        $raw = trim(post_content_text($post));
        if ($raw === '') continue;
        $postsSeen++;
        $cands = worker_extract_phrase_candidates($raw);
        if (!is_array($cands) || $cands === array()) continue;
        $cands = array_values(array_unique($cands));
        foreach ($cands as $c) {
            if (!isset($counts[$c])) $counts[$c] = 0;
            $counts[$c]++;
        }
    }
    if ($counts === array() || $postsSeen < 6) return array();

    $minCount = max(3, (int)floor($postsSeen * 0.14));
    $picked = array();
    foreach ($counts as $phrase => $count) {
        if ((int)$count < $minCount) continue;
        $picked[] = array('phrase' => (string)$phrase, 'count' => (int)$count);
    }
    if ($picked === array()) return array();
    usort($picked, function ($a, $b) {
        if ((int)$a['count'] !== (int)$b['count']) return ((int)$b['count']) <=> ((int)$a['count']);
        return strlen((string)$b['phrase']) <=> strlen((string)$a['phrase']);
    });
    return array_slice($picked, 0, 8);
}

function worker_phrase_in_text($text, $phrase)
{
    $t = worker_phrase_normalize((string)$text);
    $p = worker_phrase_normalize((string)$phrase);
    if ($t === '' || $p === '') return false;
    return strpos(' ' . $t . ' ', ' ' . $p . ' ') !== false;
}

function worker_target_mentions_saturated_phrase($text, $saturated)
{
    if (!is_array($saturated) || $saturated === array()) return false;
    foreach ($saturated as $it) {
        if (!is_array($it)) continue;
        $p = isset($it['phrase']) ? (string)$it['phrase'] : '';
        if ($p !== '' && worker_phrase_in_text((string)$text, $p)) return true;
    }
    return false;
}

function worker_is_preference_thread($text)
{
    $t = strtolower(trim((string)$text));
    if ($t === '') return false;
    return (bool)preg_match(
        '/\b(favorite|favourite|favorites|favourites|like|likes|liked|top\s+\d+|top pick|go[- ]to|best|recommend|recommendation|recommendations|playlist|movie|movies|show|shows|game|games|music|songs|album|albums|books|anime|hobby|hobbies)\b/i',
        $t
    );
}

function worker_has_continuity_marker($text)
{
    $t = strtolower(trim((string)$text));
    if ($t === '') return false;
    return (bool)preg_match('/\b(also|another|one more|as well|too|besides|still|on top of|hard to pick|more than one|second pick)\b/i', $t);
}

function worker_reply_hits_saturated_phrase($text, $saturated)
{
    if (!is_array($saturated) || $saturated === array()) return '';
    foreach ($saturated as $it) {
        if (!is_array($it)) continue;
        $p = isset($it['phrase']) ? (string)$it['phrase'] : '';
        if ($p !== '' && worker_phrase_in_text((string)$text, $p)) return $p;
    }
    return '';
}

function worker_saturated_context($saturated)
{
    if (!is_array($saturated) || $saturated === array()) return 'Thread saturation signals: (none)';
    $rows = array();
    foreach ($saturated as $it) {
        if (!is_array($it)) continue;
        $p = trim((string)($it['phrase'] ?? ''));
        $c = (int)($it['count'] ?? 0);
        if ($p === '' || $c <= 0) continue;
        $rows[] = '"' . $p . '" (' . $c . ' mentions)';
    }
    if ($rows === array()) return 'Thread saturation signals: (none)';
    return 'Thread saturation signals (overused entities/phrases): ' . implode('; ', $rows) . '.';
}

function worker_similarity_score($a, $b)
{
    $na = strtolower((string)$a);
    $nb = strtolower((string)$b);
    $na = preg_replace('/[^a-z0-9\s]/', ' ', $na);
    $nb = preg_replace('/[^a-z0-9\s]/', ' ', $nb);
    $na = trim((string)(preg_replace('/\s+/', ' ', (string)$na) ?? (string)$na));
    $nb = trim((string)(preg_replace('/\s+/', ' ', (string)$nb) ?? (string)$nb));
    if ($na === '' || $nb === '') return 0.0;
    if ($na === $nb) return 1.0;
    if ((strlen($na) > 45 && strpos($na, $nb) !== false) || (strlen($nb) > 45 && strpos($nb, $na) !== false)) {
        return 0.92;
    }

    $ta = worker_tokenize_for_similarity($na);
    $tb = worker_tokenize_for_similarity($nb);
    if ($ta === array() || $tb === array()) return 0.0;
    $sa = array_fill_keys($ta, true);
    $sb = array_fill_keys($tb, true);
    $intersection = 0;
    foreach ($sa as $k => $_) {
        if (isset($sb[$k])) $intersection++;
    }
    $union = count($sa) + count($sb) - $intersection;
    if ($union <= 0) return 0.0;
    return (float)$intersection / (float)$union;
}

function worker_find_similar_bot_reply($reply, $recentBotPosts, $threshold = 0.58)
{
    if (!is_array($recentBotPosts) || $recentBotPosts === array()) return null;
    $best = null;
    $bestScore = 0.0;
    foreach ($recentBotPosts as $p) {
        if (!is_array($p)) continue;
        $raw = trim((string)($p['raw'] ?? ''));
        if ($raw === '') continue;
        $score = worker_similarity_score((string)$reply, $raw);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $p;
        }
    }
    if (!is_array($best) || $bestScore < (float)$threshold) return null;
    $best['score'] = $bestScore;
    return $best;
}

function worker_find_similar_same_bot_reply($reply, $recentBotPosts, $threshold = 0.54)
{
    return worker_find_similar_bot_reply($reply, $recentBotPosts, $threshold);
}

function worker_strip_foreign_bot_name_noise($text, $currentBotUsername)
{
    $txt = trim((string)$text);
    if ($txt === '') return $txt;

    $aliases = array(
        'baymax', 'kirupabot', 'vaultboy', 'mechaprime', 'yoshiii', 'bobamilk', 'wafflefries', 'quelly', 'sora',
        'sarah', 'ellen', 'arthur', 'hari', 'sarah_connor', 'ellen1979', 'arthurdent', 'hariseldon',
    );
    $current = strtolower(trim((string)$currentBotUsername));
    $aliases = array_values(array_filter($aliases, static function ($a) use ($current) {
        $name = strtolower(trim((string)$a));
        return ($name !== '' && $name !== $current);
    }));
    if ($aliases === array()) return $txt;

    $patternParts = array();
    foreach ($aliases as $alias) {
        $patternParts[] = preg_quote((string)$alias, '/');
    }
    $aliasPattern = implode('|', $patternParts);
    if ($aliasPattern === '') return $txt;

    $segments = preg_split('/(```[\s\S]*?```|<pre><code[\s\S]*?<\/code><\/pre>)/i', $txt, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($segments)) return $txt;

    foreach ($segments as $i => $segment) {
        if (!is_string($segment) || $segment === '') continue;
        if (strpos($segment, '```') === 0 || stripos($segment, '<pre><code') !== false) continue;

        $lines = preg_split('/\R/', $segment);
        if (!is_array($lines)) $lines = array($segment);
        $clean = array();
        foreach ($lines as $line) {
            $line = (string)$line;
            $trim = trim($line);
            if ($trim === '') {
                $clean[] = $line;
                continue;
            }
            if (preg_match('/^(?:' . $aliasPattern . ')(?:\s+\S+)?\.?$/iu', $trim)) {
                continue;
            }
            $line = preg_replace('/(?:\s*(?<!@)\b(?:' . $aliasPattern . ')\b\.?){2,}\s*$/iu', '', $line);
            $line = preg_replace('/\s+(?<!@)(?:' . $aliasPattern . ')\.?\s*$/iu', '', (string)$line);
            $clean[] = rtrim((string)$line);
        }
        $segments[$i] = implode("\n", $clean);
    }

    $out = trim(implode('', $segments));
    $out = preg_replace('/\n{3,}/', "\n\n", (string)$out);
    return trim((string)$out);
}

function worker_is_probable_duplicate_text($a, $b, $threshold = 0.56)
{
    $a = trim((string)$a);
    $b = trim((string)$b);
    if ($a === '' || $b === '') return false;

    $sim = worker_similarity_score($a, $b);
    if ($sim >= (float)$threshold) return true;

    $na = strtolower(trim((string)(preg_replace('/\s+/', ' ', (string)(preg_replace('/[^a-z0-9\s]/i', ' ', $a) ?? $a)) ?? $a)));
    $nb = strtolower(trim((string)(preg_replace('/\s+/', ' ', (string)(preg_replace('/[^a-z0-9\s]/i', ' ', $b) ?? $b)) ?? $b)));
    if ($na === '' || $nb === '') return false;

    if ((strlen($na) >= 48 && strpos($na, $nb) !== false) || (strlen($nb) >= 48 && strpos($nb, $na) !== false)) {
        return true;
    }
    return false;
}

function worker_is_micro_reaction_duplicate($reply, $reference)
{
    $reply = trim((string)$reply);
    $reference = trim((string)$reference);
    if ($reply === '' || $reference === '') return false;

    $replyTokens = worker_tokenize_for_similarity($reply);
    $referenceTokens = worker_tokenize_for_similarity($reference);
    $replyTokenCount = count($replyTokens);
    if ($replyTokenCount === 0 || $replyTokenCount > 34) return false;

    $sim = worker_similarity_score($reply, $reference);
    $stats = worker_phrase_overlap_stats($reply, $reference);
    $shared = (int)($stats['shared_count'] ?? 0);
    $ratio = (float)($stats['ratio'] ?? 0.0);

    if ($shared >= 1 && $ratio >= 0.55) return true;
    if ($shared >= 2 && $ratio >= 0.34) return true;
    if ($sim >= 0.44 && $ratio >= 0.25) return true;

    $openReply = worker_extract_opening_line($reply);
    $openRef = worker_extract_opening_line($reference);
    if ($openReply !== '' && $openRef !== '' && strlen($openReply) >= 18 && strlen($openRef) >= 18) {
        $openSim = worker_similarity_score($openReply, $openRef);
        if ($openSim >= 0.58 && $shared >= 1) return true;
    }

    if (count($referenceTokens) <= 55 && $sim >= 0.52) return true;
    return false;
}

function worker_detect_duplicate_reply($reply, $targetRaw, $recentOtherBotPosts, $recentSameBotPosts)
{
    if (worker_is_probable_duplicate_text($reply, $targetRaw, 0.54) || worker_is_micro_reaction_duplicate($reply, $targetRaw)) {
        return array('skip' => true, 'reason' => 'duplicate_of_target_post');
    }
    if (is_array($recentOtherBotPosts)) {
        foreach ($recentOtherBotPosts as $p) {
            if (!is_array($p)) continue;
            $raw = trim((string)($p['raw'] ?? ''));
            if ($raw === '') continue;
            if (worker_is_probable_duplicate_text($reply, $raw, 0.54) || worker_is_micro_reaction_duplicate($reply, $raw)) {
                return array('skip' => true, 'reason' => 'duplicate_of_recent_other_bot_reply');
            }
        }
    }
    if (is_array($recentSameBotPosts)) {
        foreach ($recentSameBotPosts as $p) {
            if (!is_array($p)) continue;
            $raw = trim((string)($p['raw'] ?? ''));
            if ($raw === '') continue;
            if (worker_is_probable_duplicate_text($reply, $raw, 0.50) || worker_is_micro_reaction_duplicate($reply, $raw)) {
                return array('skip' => true, 'reason' => 'duplicate_of_own_recent_reply');
            }
        }
    }
    return array('skip' => false, 'reason' => '');
}

function worker_is_plain_agreement_reply($text)
{
    $t = trim((string)$text);
    if ($t === '') return false;
    $t = preg_replace('/```[\s\S]*?```/m', '', (string)$t);
    $t = trim((string)$t);
    if ($t === '') return false;
    if (!preg_match('/^(exactly|yep|yeah|totally|absolutely|that[\'’]s right|you[\'’]re right|spot on)\b[\s,\-:]/i', $t)) {
        return false;
    }
    $rest = preg_replace('/^(exactly|yep|yeah|totally|absolutely|that[\'’]s right|you[\'’]re right|spot on)\b[\s,\-:]*/i', '', (string)$t);
    $rest = strtolower(trim((string)$rest));
    if ($rest === '') return true;
    if (strlen($rest) > 90) return false;
    if (preg_match('/\b(if|because|but|and|plus|also|except|unless|edge case|for example|e\.g\.|one thing|in practice|empty state|handoff|review)\b/i', (string)$rest)) {
        return false;
    }
    return true;
}

function worker_is_question_like_text($text)
{
    $t = trim((string)$text);
    if ($t === '') return false;
    $scan = preg_replace('/```[\s\S]*?```/m', ' ', (string)$t);
    $scan = preg_replace('/<pre><code[\s\S]*?<\/code><\/pre>/i', ' ', (string)$scan);
    $scan = preg_replace('/`[^`]*`/', ' ', (string)$scan);
    $scan = html_entity_decode(strip_tags((string)$scan), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $scan = trim((string)(preg_replace('/\s+/', ' ', (string)$scan) ?? (string)$scan));
    $scan = strtolower((string)$scan);
    if ($scan === '') return false;
    if (strpos($scan, '?') !== false) return true;
    if (preg_match('/^(what|why|how|when|where|who|which|is|are|can|could|should|would|do|does|did|will|have|has|had)\b/i', (string)$scan)) return true;
    return false;
}

function worker_op_is_help_seeking_question_thread($title, $opRaw)
{
    $t = trim((string)$title);
    $o = trim((string)$opRaw);
    if ($t === '' && $o === '') return false;
    $combined = strtolower(trim($t . "\n" . $o));
    if ($combined === '') return false;
    // Require clear help-seeking intent, not generic question-like fragments from
    // linked article previews or quoted snippets.
    if (preg_match('/(^|[\n.?!]\s*)(?:@[\w_]+\s*[-,:]?\s*)?(?:what|why|how|when|where|who|which|can you|could you|would you|do you|did you|is there|are there|any tips|any advice|thoughts on)\b/i', (string)$t)) {
        return true;
    }
    if (preg_match('/(^|[\n.?!]\s*)(?:@[\w_]+\s*[-,:]?\s*)?(?:what|why|how|when|where|who|which|can you|could you|would you|do you|did you|is there|are there|any tips|any advice|thoughts on)\b/i', (string)$o)) {
        return true;
    }
    return (bool)preg_match('/\b(i(?:\'m| am)?\s+(?:stuck|trying|working|debugging|not sure|wondering)|any advice|any tips|need help|how do you|when do you)\b/i', (string)$combined);
}

function worker_is_short_thank_you_ack($text)
{
    $t = trim((string)$text);
    if ($t === '') return false;
    if (strlen($t) > 320) return false;
    if (strpos((string)$t, '```') !== false) return false;
    if (preg_match('/https?:\/\/\S+/i', (string)$t)) return false;
    return (bool)preg_match('/\b(thanks|thank you|appreciate|helpful|that helps|good call|nice point)\b/i', (string)$t);
}

function worker_is_simple_clarification_question($text)
{
    $t = html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = trim((string)(preg_replace('/\s+/', ' ', (string)$t) ?? (string)$t));
    if ($t === '') return false;
    if (strlen($t) > 180) return false;
    $parts = preg_split('/\s+/', strtolower((string)$t));
    if (!is_array($parts)) $parts = array();
    if (count($parts) > 22) return false;
    $patterns = array(
        '/^(?:@[\w_]+\s*[-,:]?\s*)?(?:what(?:\'s|\s+is|\s+does)|who\s+is|where\s+is|define|meaning\s+of)\b/i',
        '/\bwhat\s+does\s+.+\s+mean\b/i',
        '/\bstands?\s+for\b/i',
        '/\bmeaning\s+of\b/i',
    );
    foreach ($patterns as $p) {
        if (preg_match($p, (string)$t)) return true;
    }
    return false;
}

function worker_clip_words($text, $maxWords)
{
    $text = trim((string)$text);
    $max = (int)$maxWords;
    if ($text === '' || $max < 1) return '';
    $parts = preg_split('/\s+/', (string)$text);
    if (!is_array($parts)) $parts = array();
    if (count($parts) <= $max) return $text;
    return trim((string)implode(' ', array_slice($parts, 0, $max)));
}

function worker_tighten_simple_clarification_reply($text, $signature)
{
    $txt = str_replace(array("\r\n", "\r"), "\n", (string)$text);
    $txt = preg_replace('/```[\s\S]*?```/m', ' ', (string)$txt);
    $txt = preg_replace('/<pre><code[\s\S]*?<\/code><\/pre>/i', ' ', (string)$txt);
    $txt = preg_replace('/^\s*[-*]\s+/m', ' ', (string)$txt);
    $txt = preg_replace('/^\s*#{1,6}\s+/m', ' ', (string)$txt);
    $txt = preg_replace('/https?:\/\/\S+/i', ' ', (string)$txt);
    $txt = html_entity_decode(strip_tags((string)$txt), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $txt = str_replace(array('`', '*', '_'), '', (string)$txt);
    $txt = preg_replace('/\b(Diagnosis|Conceptual Explanation|Minimal Fix|Why This Works|Sanity Check|Quick Check|Optional Practical Tip)\b\s*:?\s*/i', '', (string)$txt);
    $txt = trim((string)(preg_replace('/\s+/', ' ', (string)$txt) ?? (string)$txt));
    if ($txt === '') $txt = 'It means Point of Presence.';

    $sentences = preg_split('/(?<=[.!?])\s+/u', (string)$txt);
    if (!is_array($sentences)) $sentences = array();
    $picked = array();
    foreach ($sentences as $s) {
        $s = trim((string)$s);
        if ($s === '') continue;
        $picked[] = $s;
        if (count($picked) >= 2) break;
    }
    $body = trim((string)implode(' ', $picked));
    if ($body === '') $body = $txt;
    $body = str_replace('?', '.', (string)$body);
    $body = worker_clip_words((string)$body, 35);
    $body = trim((string)$body);
    if ($body !== '' && !preg_match('/[.!]$/', (string)$body)) $body .= '.';
    return normalize_signature((string)$body, (string)$signature);
}

function worker_reply_has_value_add_signal($text)
{
    if (strpos((string)$text, '```') !== false) return true;
    $t = strtolower(trim((string)$text));
    if ($t === '') return false;
    $t = preg_replace('/https?:\/\/\S+/i', ' ', $t);
    $t = preg_replace('/\s+/', ' ', (string)$t);
    if (preg_match('/\b(however|unless|except|tradeoff|edge case|caveat|depends|alternative|counterpoint|gotcha|pitfall|debug|profile|benchmark|race condition|rollback|failure mode|memory leak|perf budget|latency)\b/i', (string)$t)) {
        return true;
    }
    if (preg_match('/\b(\d+\s?(ms|millisecond|milliseconds|s|sec|secs|second|seconds|min|mins|minute|minutes|px|kb|mb|%|fps)|if\s+[^.]{0,80}\bthen\b|for example|e\.g\.|button|field|label|click|tap|step|review)\b/i', (string)$t)) {
        return true;
    }
    if (strlen((string)$t) > 170 && preg_match('/[.!?].+[.!?]/', (string)$t)) {
        return true;
    }
    return false;
}

function worker_extract_urls_loose($text)
{
    $text = trim((string)$text);
    if ($text === '') return array();
    if (function_exists('kirupa_extract_urls_from_text')) {
        $urls = kirupa_extract_urls_from_text($text);
        if (is_array($urls)) {
            $clean = array();
            foreach ($urls as $u) {
                $u = trim((string)$u);
                if ($u !== '') $clean[$u] = true;
            }
            return array_keys($clean);
        }
    }
    if (!preg_match_all('/https?:\/\/[^\s<>"\'`]+/i', $text, $m) || !isset($m[0])) return array();
    $out = array();
    foreach ($m[0] as $u) {
        $u = rtrim(trim((string)$u), '.,);!?');
        if ($u !== '') $out[$u] = true;
    }
    return array_keys($out);
}

function worker_dedup_scan_cap()
{
    static $cap = null;
    if (is_int($cap)) return $cap;
    $env = (int)(getenv('KONVO_DEDUP_SCAN_CAP') ?: 0);
    $cap = $env > 0 ? max(40, min(300, $env)) : 120;
    return $cap;
}

function worker_bounded_thread_posts($posts, $maxPosts)
{
    if (!is_array($posts) || $posts === array()) return array();
    $maxPosts = max(10, min(500, (int)$maxPosts));
    if (count($posts) <= $maxPosts) return $posts;

    $headCount = min(12, max(3, (int)floor($maxPosts * 0.15)));
    $tailCount = max(1, $maxPosts - $headCount);
    $picked = array_merge(array_slice($posts, 0, $headCount), array_slice($posts, -1 * $tailCount));
    $out = array();
    $seen = array();
    foreach ($picked as $post) {
        if (!is_array($post)) continue;
        $pn = (int)($post['post_number'] ?? 0);
        $k = $pn > 0 ? ('pn:' . $pn) : ('idx:' . count($out));
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $post;
    }
    return $out;
}

function worker_compact_post_text($raw, $maxChars = 260)
{
    $maxChars = max(80, min(1200, (int)$maxChars));
    $raw = (string)$raw;
    $raw = preg_replace('/```[\s\S]*?```/m', '[code block]', $raw) ?? $raw;
    $raw = preg_replace('/\s+/', ' ', $raw) ?? $raw;
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($raw) > $maxChars) {
            $raw = rtrim((string)mb_substr($raw, 0, $maxChars - 1)) . '…';
        }
    } else {
        if (strlen($raw) > $maxChars) {
            $raw = rtrim(substr($raw, 0, $maxChars - 1)) . '…';
        }
    }
    return $raw;
}

function worker_reply_adds_new_details_pass($replyText, $posts, $window = 5)
{
    $replyText = trim((string)$replyText);
    $requestedWindow = max(1, (int)$window);
    $effectiveWindow = min($requestedWindow, worker_dedup_scan_cap());
    $result = array(
        'applied' => true,
        'adds_new_details' => true,
        'reason' => 'ok',
        'window' => $effectiveWindow,
        'window_requested' => $requestedWindow,
        'recent_posts_used' => 0,
        'max_similarity' => 0.0,
        'similar_post_number' => 0,
        'similar_username' => '',
        'novelty_ratio' => 1.0,
        'reply_token_count' => 0,
        'new_token_count' => 0,
        'has_new_url' => false,
        'has_new_code' => false,
        'has_contrarian_signal' => false,
        'max_opening_similarity' => 0.0,
        'opening_similar_post_number' => 0,
        'opening_similar_username' => '',
        'max_phrase_overlap' => 0.0,
        'max_phrase_shared_count' => 0,
        'phrase_overlap_post_number' => 0,
        'phrase_overlap_username' => '',
        'shared_phrases' => array(),
    );
    if ($replyText === '') {
        $result['adds_new_details'] = false;
        $result['reason'] = 'empty_reply';
        $result['novelty_ratio'] = 0.0;
        return $result;
    }
    if (!is_array($posts) || $posts === array()) {
        $result['reason'] = 'no_thread_context';
        return $result;
    }

    $recent = array();
    for ($i = count($posts) - 1; $i >= 0 && count($recent) < $effectiveWindow; $i--) {
        $post = $posts[$i] ?? null;
        if (!is_array($post)) continue;
        $raw = trim((string)post_content_text($post));
        if ($raw === '') continue;
        $recent[] = array(
            'raw' => $raw,
            'post_number' => (int)($post['post_number'] ?? 0),
            'username' => trim((string)($post['username'] ?? '')),
        );
    }
    if ($recent === array()) {
        $result['reason'] = 'no_recent_posts';
        return $result;
    }
    $result['recent_posts_used'] = count($recent);

    $replyTokens = worker_tokenize_for_similarity($replyText);
    $result['reply_token_count'] = count($replyTokens);
    $replyUrls = worker_extract_urls_loose($replyText);
    $replyHasCode = (strpos($replyText, '```') !== false || stripos($replyText, '<pre><code') !== false);

    $tokenUnion = array();
    $urlUnion = array();
    $recentHasCode = false;
    $replyOpening = worker_extract_opening_line($replyText);
    $bestOpeningSim = 0.0;
    $bestOpeningPost = 0;
    $bestOpeningUser = '';
    $bestPhraseOverlap = 0.0;
    $bestPhraseShared = 0;
    $bestPhrasePost = 0;
    $bestPhraseUser = '';
    $bestSharedPhrases = array();
    $bestSim = 0.0;
    $bestPost = 0;
    $bestUser = '';
    foreach ($recent as $item) {
        $raw = (string)($item['raw'] ?? '');
        if ($raw === '') continue;
        foreach (worker_tokenize_for_similarity($raw) as $tok) {
            $tokenUnion[$tok] = true;
        }
        foreach (worker_extract_urls_loose($raw) as $u) {
            $urlUnion[$u] = true;
        }
        if (strpos($raw, '```') !== false || stripos($raw, '<pre><code') !== false) {
            $recentHasCode = true;
        }
        $sim = worker_similarity_score($replyText, $raw);
        if ($sim > $bestSim) {
            $bestSim = $sim;
            $bestPost = (int)($item['post_number'] ?? 0);
            $bestUser = (string)($item['username'] ?? '');
        }
        $openRef = worker_extract_opening_line($raw);
        if ($replyOpening !== '' && $openRef !== '' && strlen($replyOpening) >= 24 && strlen($openRef) >= 24) {
            $openSim = worker_similarity_score($replyOpening, $openRef);
            if ($openSim > $bestOpeningSim) {
                $bestOpeningSim = $openSim;
                $bestOpeningPost = (int)($item['post_number'] ?? 0);
                $bestOpeningUser = (string)($item['username'] ?? '');
            }
        }
        $phraseStats = worker_phrase_overlap_stats($replyText, $raw);
        $phraseRatio = (float)($phraseStats['ratio'] ?? 0.0);
        $phraseShared = (int)($phraseStats['shared_count'] ?? 0);
        if ($phraseRatio > $bestPhraseOverlap || ($phraseRatio >= $bestPhraseOverlap && $phraseShared > $bestPhraseShared)) {
            $bestPhraseOverlap = $phraseRatio;
            $bestPhraseShared = $phraseShared;
            $bestPhrasePost = (int)($item['post_number'] ?? 0);
            $bestPhraseUser = (string)($item['username'] ?? '');
            $bestSharedPhrases = is_array($phraseStats['shared_phrases'] ?? null) ? array_values($phraseStats['shared_phrases']) : array();
        }
    }
    $result['max_similarity'] = $bestSim;
    $result['similar_post_number'] = $bestPost;
    $result['similar_username'] = $bestUser;
    $result['max_opening_similarity'] = $bestOpeningSim;
    $result['opening_similar_post_number'] = $bestOpeningPost;
    $result['opening_similar_username'] = $bestOpeningUser;
    $result['max_phrase_overlap'] = $bestPhraseOverlap;
    $result['max_phrase_shared_count'] = $bestPhraseShared;
    $result['phrase_overlap_post_number'] = $bestPhrasePost;
    $result['phrase_overlap_username'] = $bestPhraseUser;
    $result['shared_phrases'] = $bestSharedPhrases;

    $newTokens = 0;
    foreach ($replyTokens as $tok) {
        if (!isset($tokenUnion[$tok])) $newTokens++;
    }
    $result['new_token_count'] = $newTokens;
    $den = max(1, count($replyTokens));
    $noveltyRatio = (float)$newTokens / (float)$den;
    $result['novelty_ratio'] = $noveltyRatio;

    $hasNewUrl = false;
    foreach ($replyUrls as $u) {
        if (!isset($urlUnion[$u])) {
            $hasNewUrl = true;
            break;
        }
    }
    $result['has_new_url'] = $hasNewUrl;

    $hasNewCode = $replyHasCode && !$recentHasCode;
    $result['has_new_code'] = $hasNewCode;

    $hasContrarianSignal = preg_match('/\b(however|but|except|unless|edge case|counterexample|different angle|another angle|tradeoff)\b/i', $replyText) === 1;
    $result['has_contrarian_signal'] = $hasContrarianSignal;
    $hasValueAdd = worker_reply_has_value_add_signal($replyText) || $hasNewUrl || $hasNewCode || $hasContrarianSignal;
    $isShortAnswer = (count($replyTokens) > 0 && count($replyTokens) <= 24);

    if ($bestOpeningSim >= 0.72 && strlen($replyOpening) >= 24 && !$hasNewCode) {
        $result['adds_new_details'] = false;
        $result['reason'] = 'reused_opening_phrase_recent';
        return $result;
    }
    if ($bestPhraseOverlap >= 0.46 && $bestPhraseShared >= 2 && !$hasNewCode) {
        $result['adds_new_details'] = false;
        $result['reason'] = 'high_phrase_overlap_recent';
        return $result;
    }
    if ($bestPhraseShared >= 3 && $bestPhraseOverlap >= 0.34 && !$hasValueAdd) {
        $result['adds_new_details'] = false;
        $result['reason'] = 'phrase_stack_repeat';
        return $result;
    }
    if ($bestSim >= 0.60 && $noveltyRatio < 0.34 && !$hasNewUrl && !$hasNewCode) {
        $result['adds_new_details'] = false;
        $result['reason'] = 'high_similarity_low_novelty';
        return $result;
    }
    if ($bestSim >= 0.52 && $noveltyRatio < 0.24 && !$hasValueAdd) {
        $result['adds_new_details'] = false;
        $result['reason'] = 'overlap_without_material_addition';
        return $result;
    }
    if ($isShortAnswer && $bestSim >= 0.47 && $noveltyRatio < 0.28 && !$hasNewUrl && !$hasNewCode) {
        $result['adds_new_details'] = false;
        $result['reason'] = 'short_answer_rephrase';
        return $result;
    }
    if ($isShortAnswer && $bestPhraseShared >= 1 && $bestPhraseOverlap >= 0.50 && !$hasNewUrl && !$hasNewCode) {
        $result['adds_new_details'] = false;
        $result['reason'] = 'short_answer_shared_key_phrase';
        return $result;
    }
    if ($isShortAnswer && $bestSim >= 0.42 && $noveltyRatio < 0.42 && !$hasValueAdd) {
        $result['adds_new_details'] = false;
        $result['reason'] = 'short_answer_low_novelty';
        return $result;
    }
    if ($replyHasCode && $recentHasCode && $bestSim >= 0.40 && $bestPhraseShared >= 1 && !$hasNewUrl) {
        $result['adds_new_details'] = false;
        $result['reason'] = 'repeated_code_solution_already_covered';
        return $result;
    }
    if (count($recent) >= 4 && $noveltyRatio < 0.14 && !$hasValueAdd) {
        $result['adds_new_details'] = false;
        $result['reason'] = 'thread_already_covered';
        return $result;
    }

    $result['reason'] = 'materially_new';
    return $result;
}

function worker_bot_value_role_rule($botUsername)
{
    $u = strtolower(trim((string)$botUsername));
    $map = array(
        'kirupabot' => 'Role focus: give one practical fix and, for technical threads, include one relevant kirupa.com deep-dive link when available.',
        'vaultboy' => 'Role focus: keep it playful and practical, with game/dev examples when relevant.',
        'mechaprime' => 'Role focus: add one concrete mechanism or caveat, not a paraphrase.',
        'sarah_connor' => 'Role focus: emphasize one failure mode or risk mitigation detail.',
        'quelly' => 'Role focus: add one mental model or analogy only if it clarifies.',
        'bobamilk' => 'Role focus: keep it ultra-brief and plain; only one essential point.',
        'arthurdent' => 'Role focus: add one practical tip with light wit, avoid repetition.',
        'sora' => 'Role focus: add one calm clarifying detail, avoid restating others.',
        'wafflefries' => 'Role focus: add one punchy practical extension, skip if no new value.',
        'yoshiii' => 'Role focus: add one playful but concrete angle, not slogan-like phrasing.',
        'ellen1979' => 'Role focus: add one implementation tradeoff with practical framing.',
        'hariseldon' => 'Role focus: add one systems-level implication or second-order effect.',
        'baymax' => 'Role focus: keep it direct and useful with one fresh angle.',
    );
    return isset($map[$u]) ? $map[$u] : 'Role focus: add one distinct value point, otherwise skip.';
}

function worker_opening_diversity_rule($botUsername)
{
    $u = strtolower(trim((string)$botUsername));
    $style = array(
        'baymax' => 'direct and helpful',
        'kirupabot' => 'practical and link-aware',
        'vaultboy' => 'playful and game-literate',
        'mechaprime' => 'precise and no-nonsense',
        'yoshiii' => 'light and energetic',
        'bobamilk' => 'brief and plainspoken',
        'wafflefries' => 'punchy and casual',
        'quelly' => 'hands-on and energetic',
        'sora' => 'calm and minimal',
        'sarah_connor' => 'skeptical and practical',
        'ellen1979' => 'implementation-focused',
        'arthurdent' => 'wry and practical',
        'hariseldon' => 'analytical and concise',
    );
    $tone = isset($style[$u]) ? (string)$style[$u] : 'casual and human';
    return 'Opening-line diversity rule: make the first line sound ' . $tone . ', not generic. '
        . 'Do not start with filler agreements like "Yep", "Yes", "Yeah", "Exactly", "Totally", "Absolutely", "100%", "Great point", or "Good point". '
        . 'Start with a concrete statement tied to this post and vary the opening pattern from recent replies.';
}

function worker_should_skip_low_value_reply($txt, $recentBotPosts, $recentSameBotPosts, $recentBotStreak, $targetAuthorIsBot, $contrarianMode, $pollEncountered, $isQuestionLike, $allowShortThanks = false)
{
    if ($allowShortThanks && worker_is_short_thank_you_ack($txt)) {
        return array('skip' => false, 'reason' => '');
    }
    if (!$targetAuthorIsBot) return array('skip' => false, 'reason' => '');
    if (worker_is_plain_agreement_reply($txt)) {
        return array('skip' => true, 'reason' => 'plain_agreement_reply');
    }
    $hasValueAdd = worker_reply_has_value_add_signal($txt);
    $replyTokens = worker_tokenize_for_similarity($txt);
    if ($contrarianMode && count($replyTokens) <= 26 && !$hasValueAdd) {
        return array('skip' => true, 'reason' => 'contrarian_short_without_new_value');
    }
    if (!$isQuestionLike && !$hasValueAdd) {
        return array('skip' => true, 'reason' => 'bot_to_bot_non_question_no_new_value');
    }
    $otherCount = is_array($recentBotPosts) ? count($recentBotPosts) : 0;
    if ((int)$recentBotStreak >= 6) {
        return array('skip' => true, 'reason' => 'bot_tail_streak_hard_stop');
    }
    if ($pollEncountered && $isQuestionLike && $otherCount >= 2) {
        return array('skip' => true, 'reason' => 'poll_answer_already_covered');
    }
    if ($pollEncountered && (int)$recentBotStreak >= 3) {
        return array('skip' => true, 'reason' => 'poll_bot_chain_hard_stop');
    }
    if ((int)$recentBotStreak >= 5 && !$isQuestionLike && !$hasValueAdd) {
        return array('skip' => true, 'reason' => 'bot_tail_non_question_stop');
    }
    if ((int)$recentBotStreak >= 4 && !$hasValueAdd) {
        return array('skip' => true, 'reason' => 'bot_tail_no_new_value');
    }
    if ($otherCount >= 5) {
        return array('skip' => true, 'reason' => 'bot_chain_too_dense');
    }
    if ($otherCount >= 4 && !$hasValueAdd) {
        return array('skip' => true, 'reason' => 'bot_chain_no_additional_value');
    }
    $similarOther = worker_find_similar_bot_reply($txt, $recentBotPosts, 0.46);
    if (is_array($similarOther) && !$hasValueAdd) {
        return array('skip' => true, 'reason' => 'similar_to_other_bot_without_new_value');
    }
    $similarSelf = worker_find_similar_same_bot_reply($txt, $recentSameBotPosts, 0.50);
    if (is_array($similarSelf)) {
        return array('skip' => true, 'reason' => 'similar_to_own_recent_reply');
    }
    if ($isQuestionLike && $otherCount >= 3 && !$hasValueAdd) {
        return array('skip' => true, 'reason' => 'question_thread_already_answered_no_new_value');
    }
    if ($isQuestionLike && $otherCount >= 5) {
        return array('skip' => true, 'reason' => 'bot_chain_question_already_covered');
    }
    return array('skip' => false, 'reason' => '');
}

function worker_recent_bot_posts_have_code($recentBotPosts)
{
    if (!is_array($recentBotPosts) || $recentBotPosts === array()) return false;
    foreach ($recentBotPosts as $p) {
        if (!is_array($p)) continue;
        $raw = trim((string)($p['raw'] ?? ''));
        if ($raw === '') continue;
        if (strpos($raw, '```') !== false) return true;
        if (preg_match('/\b(function|const|let|var|class|return|if|for|while)\b/i', $raw)) return true;
    }
    return false;
}

function worker_recent_bot_streak($posts)
{
    if (!is_array($posts) || $posts === array()) return 0;
    $streak = 0;
    for ($i = count($posts) - 1; $i >= 0; $i--) {
        $post = $posts[$i] ?? null;
        if (!is_array($post)) continue;
        $u = trim((string)($post['username'] ?? ''));
        if ($u === '') continue;
        if (is_bot_user($u)) {
            $streak++;
            continue;
        }
        break;
    }
    return $streak;
}

function worker_recent_has_human($posts, $limit = 6)
{
    if (!is_array($posts) || $posts === array()) return false;
    $limit = max(0, (int)$limit);
    if ($limit === 0) return false;
    $seen = 0;
    for ($i = count($posts) - 1; $i >= 0; $i--) {
        $post = $posts[$i] ?? null;
        if (!is_array($post)) continue;
        $u = trim((string)($post['username'] ?? ''));
        if ($u === '') continue;
        $seen++;
        if (!is_bot_user($u)) return true;
        if ($seen >= $limit) break;
    }
    return false;
}

function worker_count_human_replies_after_op($posts)
{
    if (!is_array($posts) || count($posts) <= 1) return 0;
    $count = 0;
    for ($i = 1; $i < count($posts); $i++) {
        $post = $posts[$i] ?? null;
        if (!is_array($post)) continue;
        $u = trim((string)($post['username'] ?? ''));
        if ($u === '') continue;
        if (!is_bot_user($u)) $count++;
    }
    return $count;
}

function worker_recent_posts_context($posts, $limit = 5, $maxCharsPerPost = 900)
{
    if (!is_array($posts) || $posts === array()) return 'Recent thread context: (none)';
    $limit = max(0, (int)$limit);
    if ($limit === 0) return 'Recent thread context: (none)';
    $picked = array();
    for ($i = count($posts) - 1; $i >= 0; $i--) {
        $post = $posts[$i] ?? null;
        if (!is_array($post)) continue;
        $raw = worker_compact_post_text(post_content_text($post), $maxCharsPerPost);
        if (trim((string)$raw) === '') continue;
        $picked[] = 'Post #' . (int)($post['post_number'] ?? 0) . ' by @' . (string)($post['username'] ?? '') . ":\n" . (string)$raw;
        if (count($picked) >= $limit) break;
    }
    if ($picked === array()) return 'Recent thread context: (none)';
    $picked = array_reverse($picked);
    return "Recent thread context:\n" . implode("\n\n", $picked);
}

function pick_bot($bots, $excludeUsername)
{
    $choices = array();
    $exclude = strtolower(trim((string)$excludeUsername));
    foreach ($bots as $bot) {
        $u = strtolower(trim((string)$bot['username']));
        if ($u === $exclude) continue;
        $choices[] = $bot;
    }
    if (count($choices) === 0) $choices = $bots;
    shuffle($choices);
    return $choices[0];
}

function worker_has_explicit_bot_mention($text, $botUsername, $signature)
{
    $txt = strtolower(trim((string)$text));
    if ($txt === '') return false;

    $candidates = array();
    foreach (array($botUsername, $signature) as $cand) {
        $cand = strtolower(trim((string)$cand));
        if ($cand === '') continue;
        $candidates[] = $cand;
        $candidates[] = str_replace(' ', '', $cand);
    }
    $candidates = array_values(array_unique(array_filter($candidates, static function ($v) {
        return trim((string)$v) !== '';
    })));
    if ($candidates === array()) return false;

    foreach ($candidates as $cand) {
        if (preg_match('/(^|[^a-z0-9_])@?' . preg_quote((string)$cand, '/') . '(?![a-z0-9_])/i', $txt)) {
            return true;
        }
    }
    return false;
}

function worker_bot_expertise_profile($botUsername)
{
    $b = strtolower(trim((string)$botUsername));
    $map = array(
        'baymax' => array('frontend architecture', 'javascript fundamentals', 'web performance'),
        'kirupabot' => array('kirupa.com technical references', 'web platform explainers', 'tutorial matching'),
        'vaultboy' => array('video games', 'retro gaming culture', 'game design student perspective'),
        'mechaprime' => array('architecture and systems thinking', 'classical music framing', 'frontend engineering'),
        'yoshiii' => array('creative coding', 'ui motion', 'front-end practical debugging'),
        'bobamilk' => array('architecture school perspective', 'visual design basics', 'student workflows'),
        'wafflefries' => array('internet culture', 'practical dev tooling', 'lightweight troubleshooting'),
        'quelly' => array('product and ux', 'developer workflow habits', 'software career realities'),
        'sora' => array('minimal practical coding', 'javascript basics', 'concise technical explanations'),
        'sarah_connor' => array('reliability mindset', 'systems risk', 'engineering tradeoffs'),
        'ellen1979' => array('frontend implementation', 'design systems', 'team process pragmatism'),
        'arthurdent' => array('web fundamentals', 'debugging odd edge cases', 'dry humor observations'),
        'hariseldon' => array('strategic tradeoffs', 'systems forecasting', 'platform decision making'),
    );
    return isset($map[$b]) ? $map[$b] : array('general web discussions', 'forum conversation', 'basic troubleshooting');
}

function worker_bot_expertise_scope_rule($botUsername)
{
    $domains = worker_bot_expertise_profile($botUsername);
    $list = implode('; ', array_map('strval', $domains));
    return 'Expertise lane rule: your strongest domains are ' . $list . '. '
        . 'Outside these domains, do not posture as an expert. Prefer one of: ask one concrete question, briefly express uncertainty, or output [[NO_REPLY]].';
}

function worker_has_genuine_question($text)
{
    $text = trim((string)$text);
    if ($text === '' || strpos($text, '?') === false) return false;
    $probe = preg_replace('/```[\s\S]*?```/m', ' ', $text);
    $probe = preg_replace('/https?:\/\/\S+/i', ' ', (string)$probe);
    $probe = preg_replace('/\s+/', ' ', (string)$probe);
    if (!preg_match('/\?/', (string)$probe)) return false;
    return (bool)preg_match('/\b(has|have|had|is|are|was|were|do|does|did|can|could|would|should|why|how|what|where|when|who|source|wait)\b[^?]*\?/i', (string)$probe);
}

function worker_post_has_code_context($text)
{
    $t = trim((string)$text);
    if ($t === '') return false;
    if (strpos($t, '```') !== false || stripos($t, '<pre><code') !== false) return true;
    if (preg_match('/`[^`]{8,}`/s', $t)) return true;
    $probe = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return (bool)preg_match('/\b(function|class|const|let|var|return|if|for|while|async|await|queryselector|addEventListener|console\.log|javascript|typescript|css|html|php|python|sql|api|dom|regex|stack trace|error)\b/i', (string)$probe);
}

function worker_strip_code_blocks_for_nontechnical($text)
{
    $out = str_replace(array("\r\n", "\r"), "\n", (string)$text);
    if ($out === '') return '';
    $out = preg_replace('/```[\s\S]*?```/m', '', (string)$out);
    $out = preg_replace('/<pre\b[^>]*>\s*<code\b[^>]*>[\s\S]*?<\/code>\s*<\/pre>/i', '', (string)$out);
    $out = preg_replace('/\n{3,}/', "\n\n", (string)$out);
    return trim((string)$out);
}

function worker_has_uncertainty_marker($text)
{
    $probe = trim((string)$text);
    if ($probe === '') return false;
    $probe = preg_replace('/```[\s\S]*?```/m', ' ', $probe);
    $probe = preg_replace('/https?:\/\/\S+/i', ' ', (string)$probe);
    $probe = preg_replace('/\s+/', ' ', (string)$probe);
    return (bool)preg_match('/\b(not sure|unsure|might be wrong|could be wrong|i may be wrong|i have not tested|i haven\'t tested|i have not tried|i haven\'t tried|not certain)\b/i', (string)$probe);
}

function worker_is_low_effort_reaction($text)
{
    $probe = trim((string)$text);
    if ($probe === '') return false;
    $probe = preg_replace('/```[\s\S]*?```/m', ' ', $probe);
    $probe = preg_replace('/https?:\/\/\S+/i', ' ', (string)$probe);
    $probe = trim((string)(preg_replace('/\s+/', ' ', (string)$probe) ?? (string)$probe));
    if ($probe === '') return false;
    preg_match_all('/[A-Za-z0-9\']+/u', $probe, $m);
    $words = isset($m[0]) && is_array($m[0]) ? count($m[0]) : 0;
    if ($words >= 1 && $words <= 5) return true;
    if ($words === 0 && mb_strlen($probe) <= 8) return true;
    return false;
}

function worker_is_known_bot_username($username)
{
    $username = strtolower(trim((string)$username));
    if ($username === '') return false;
    global $bots;
    if (isset($bots) && is_array($bots)) {
        foreach ($bots as $bot) {
            $candidate = strtolower(trim((string)($bot['username'] ?? '')));
            if ($candidate !== '' && $candidate === $username) return true;
        }
    }
    return false;
}

function worker_thread_short_reply_stats($posts)
{
    $stats = array(
        'bot_reply_count' => 0,
        'short_bot_reply_count' => 0,
    );
    if (!is_array($posts) || $posts === array()) return $stats;
    foreach ($posts as $post) {
        if (!is_array($post)) continue;
        $postNumber = (int)($post['post_number'] ?? 0);
        if ($postNumber <= 1) continue;
        $username = (string)($post['username'] ?? '');
        if (!worker_is_known_bot_username($username)) continue;
        $stats['bot_reply_count']++;
        $text = post_content_text($post);
        if (worker_is_low_effort_reaction($text)) {
            $stats['short_bot_reply_count']++;
        }
    }
    return $stats;
}

function worker_uncertainty_phrase_for_bot($botUsername)
{
    $b = strtolower(trim((string)$botUsername));
    $map = array(
        'baymax' => "I might be wrong here.",
        'vaultboy' => "ngl I might be wrong here.",
        'mechaprime' => "I might be wrong here.",
        'yoshiii' => "not sure on that part yet.",
        'bobamilk' => "not sure about this one.",
        'wafflefries' => "not sure, might be wrong.",
        'quelly' => "honestly not sure on that bit.",
        'sora' => "not sure about that detail.",
        'sarah_connor' => "I could be wrong here.",
        'ellen1979' => "not sure on that side.",
        'arthurdent' => "might be wrong here.",
        'hariseldon' => "honestly not sure on implementation.",
        'kirupabot' => "I may be wrong on that detail.",
    );
    return isset($map[$b]) ? (string)$map[$b] : 'I might be wrong here.';
}

function worker_low_effort_reaction_for_bot($botUsername, $seed = '')
{
    $b = strtolower(trim((string)$botUsername));
    $map = array(
        'baymax' => array('oh nice', 'lol yeah', 'that is clean'),
        'vaultboy' => array('lol same', 'oh nice', 'that is wild'),
        'mechaprime' => array('clean', 'fair point', 'nice'),
        'yoshiii' => array('ha nice', 'bookmarked', 'oh cool'),
        'bobamilk' => array('oh nice', 'love this', 'bookmarked'),
        'wafflefries' => array('lol', 'nice find', 'bookmarked'),
        'quelly' => array('nice', 'lol same', 'clean'),
        'sora' => array('hmm', 'that is lovely', 'interesting'),
        'sarah_connor' => array('yep', 'been there', 'fair'),
        'ellen1979' => array('fair enough', 'been there', 'yeah'),
        'arthurdent' => array('ha fair', 'that tracks', 'proper mess'),
        'hariseldon' => array('hmm interesting', 'yeah fair', 'clean'),
        'kirupabot' => array('nice', 'helpful', 'good find'),
    );
    $choices = isset($map[$b]) ? $map[$b] : array('lol same', 'oh nice', 'bookmarked');
    $idx = abs((int)crc32(strtolower($b . '|' . (string)$seed)));
    return (string)$choices[$idx % count($choices)];
}

function worker_enforce_banned_phrase_cleanup($text)
{
    $text = trim((string)$text);
    if ($text === '') return $text;
    $segments = preg_split('/(```[\s\S]*?```|<pre><code[\s\S]*?<\/code><\/pre>)/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($segments)) $segments = array($text);
    foreach ($segments as $i => $segment) {
        if (!is_string($segment) || $segment === '') continue;
        if (str_starts_with($segment, '```') || stripos($segment, '<pre><code') !== false) continue;
        $s = (string)$segment;
        $s = preg_replace('/^\s*(Totally agree|Totally,|Totally\s*[—-])\s*/imu', '', $s);
        $s = preg_replace('/\bthe real tell will be\b/i', 'what matters more is', (string)$s);
        $s = preg_replace('/\bblast radius\b/i', 'impact scope', (string)$s);
        $s = preg_replace('/\bthat(?:\'|’)s the gotcha\b/i', 'that is the edge case', (string)$s);
        $s = preg_replace('/\bgotcha\b/i', 'edge case', (string)$s);
        if (function_exists('konvo_break_up_em_dashes')) {
            $s = konvo_break_up_em_dashes((string)$s);
        }
        $segments[$i] = is_string($s) ? $s : (string)$segment;
    }
    $out = trim(implode('', $segments));
    $out = preg_replace('/\n{3,}/', "\n\n", (string)$out);
    $out = trim((string)$out);
    if (function_exists('konvo_break_before_closing_question')) {
        $out = konvo_break_before_closing_question($out);
    }
    if (function_exists('kirupa_strip_invalid_kirupa_urls')) {
        $out = kirupa_strip_invalid_kirupa_urls((string)$out);
    }
    
    // Bots should link a destination's title, not paste a raw URL. Runs last so
    // it also catches URLs the model wrote itself. No fetching here: a
    // slug-derived title is good enough and keeps replies fast.
    if (function_exists('konvo_linkify_bare_urls')) {
        $out = konvo_linkify_bare_urls((string)$out, array(), false);
    }
    // Same idea for code: a snippet mentioned in prose should render as code.
    if (function_exists('konvo_format_inline_code')) {
        $out = konvo_format_inline_code((string)$out);
    }

    return trim((string)$out);
}

function worker_force_genuine_question_with_llm($bot, $topicTitle, $targetRaw, $draft)
{
    $draft = trim((string)$draft);
    if ($draft === '' || KONVO_ANTHROPIC_API_KEY === '') return $draft;
    $signature = isset($bot['signature']) ? (string)$bot['signature'] : 'Bot';
    $soulKey = isset($bot['soul_key']) ? (string)$bot['soul_key'] : strtolower(trim((string)$signature));
    $soul = konvo_compose_forum_persona_system_prompt(
        konvo_load_soul((string)$soulKey, 'Write concise, casual, human forum posts.')
    );
    $payload = array(
        'model' => worker_model_for_task('reply_rewrite'),
        'messages' => array(
            array(
                'role' => 'system',
                'content' => trim((string)$soul)
                    . ' Rewrite this forum reply into a genuine information-seeking question. '
                    . 'Keep it concise and human. Use exactly one question mark. '
                    . 'React to one concrete detail from the target post, then ask one real follow-up question. '
                    . 'No headings, no bullets, no links, no sign-off name.',
            ),
            array(
                'role' => 'user',
                'content' => "Topic title:\n{$topicTitle}\n\nTarget post:\n{$targetRaw}\n\nCurrent draft:\n{$draft}\n\nRewrite now.",
            ),
        ),
        'temperature' => 0.5,
    );
    $res = post_json(
        'llm:chat',
        $payload,
        array('Authorization: Bearer ' . KONVO_ANTHROPIC_API_KEY)
    );
    if (!$res['ok'] || !isset($res['body']['choices'][0]['message']['content'])) return $draft;
    $txt = trim((string)$res['body']['choices'][0]['message']['content']);
    if ($txt === '') return $draft;
    $txt = preg_replace('/```[\s\S]*?```/m', '', (string)$txt);
    $txt = worker_enforce_banned_phrase_cleanup((string)$txt);
    return trim((string)$txt);
}

function worker_rewrite_non_mimic_reply_with_llm($bot, $topicTitle, $targetRaw, $draft, $recentContext = '')
{
    if (!is_array($bot)) return '';
    $botUsername = isset($bot['username']) ? (string)$bot['username'] : 'Bot';
    $soulKey = isset($bot['soul_key']) ? (string)$bot['soul_key'] : strtolower((string)$botUsername);
    $soul = konvo_compose_forum_persona_system_prompt(
        konvo_load_soul($soulKey, 'Write naturally, concise, and human.')
    );
    $isTechnical = is_codey_topic((string)$topicTitle, (string)$targetRaw)
        || (function_exists('kirupa_is_technical_text') && kirupa_is_technical_text((string)$topicTitle . "\n" . (string)$targetRaw));
    $model = worker_model_for_task($isTechnical ? 'reply_generation_technical' : 'reply_generation', array('technical' => $isTechnical));
    if (!is_string($model) || trim($model) === '' || KONVO_ANTHROPIC_API_KEY === '') return '';

    $system = trim((string)$soul)
        . ' Rewrite this draft so it replies to the target post without mimicking its opening phrase.'
        . ' Keep 1-2 short sentences, casual forum tone, and one concrete new detail.'
        . ' Do not copy the first sentence structure or wording from the target post.'
        . ' Never repeat a 4-word sequence from the target post.'
        . ' No greeting, no sign-off, no bullets.';
    $user = "Topic title: {$topicTitle}\n\nTarget post:\n{$targetRaw}\n\nRecent thread context:\n{$recentContext}\n\nCurrent draft:\n{$draft}\n\nRewrite now.";
    $res = post_json(
        'llm:chat',
        array(
            'model' => $model,
            'messages' => array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user', 'content' => $user),
            ),
            'temperature' => 0.9,
        ),
        array('Authorization: Bearer ' . KONVO_ANTHROPIC_API_KEY)
    );
    if (!$res['ok'] || !isset($res['body']['choices'][0]['message']['content'])) return '';
    $txt = trim((string)$res['body']['choices'][0]['message']['content']);
    if ($txt === '') return '';
    $txt = worker_apply_micro_grammar_fixes($txt);
    $txt = worker_markdown_code_integrity_pass($txt);
    $txt = worker_normalize_code_fence_spacing($txt);
    $txt = worker_strip_foreign_bot_name_noise($txt, $botUsername);
    return trim((string)$txt);
}

function pick_candidate_topic($topics, $seenState)
{
    $pool = array();
    $now = time();
    foreach ($topics as $t) {
        if (!is_array($t)) continue;
        $topicId = isset($t['id']) ? (int)$t['id'] : 0;
        $postsCount = isset($t['posts_count']) ? (int)$t['posts_count'] : 0;
        $visible = isset($t['visible']) ? (bool)$t['visible'] : true;
        $closed = isset($t['closed']) ? (bool)$t['closed'] : false;
        $archived = isset($t['archived']) ? (bool)$t['archived'] : false;
        if ($topicId <= 0 || !$visible || $closed || $archived) continue;
        if ($postsCount < 1) continue;

        $createdAt = isset($t['created_at']) ? strtotime((string)$t['created_at']) : false;
        $lastPostedAt = isset($t['last_posted_at']) ? strtotime((string)$t['last_posted_at']) : false;
        if ($createdAt === false && $lastPostedAt === false) {
            continue;
        }
        $baseTs = ($lastPostedAt !== false) ? (int)$lastPostedAt : (int)$createdAt;
        $ageSec = max(0, $now - $baseTs);

        // Randomize response timing windows so replies do not always appear with fixed delays.
        if ($postsCount <= 1) {
            $minAgeSec = mt_rand(12 * 60, 4 * 3600);
            $maxAgeSec = 48 * 3600;
        } else {
            $minAgeSec = mt_rand(5 * 60, 3 * 3600);
            $maxAgeSec = 48 * 3600;
        }
        if ($ageSec < $minAgeSec || $ageSec > $maxAgeSec) {
            continue;
        }

        $pool[] = $t;
    }

    if (count($pool) === 0) return null;
    shuffle($pool);
    foreach ($pool as $t) {
        $id = (string)((int)$t['id']);
        if (!isset($seenState[$id])) return $t;
    }
    return $pool[0];
}

function worker_is_quiz_ack_topic_title($title)
{
    $t = strtolower(trim((string)$title));
    if ($t === '') return false;
    return (str_starts_with($t, 'spot the bug')
        || str_starts_with($t, 'js quiz:')
        || str_starts_with($t, 'coding challenge'));
}

// "Spot the Bug" and "JS Quiz" threads are challenges: when a human posts a text
// reply guessing the bug/answer, they get a prompt playful acknowledgement that
// gives nothing away. The actual answer is revealed once, ~24h after the topic
// was posted, by konvo_js_quiz_answer_worker.php.
function worker_find_quiz_ack_target($topics, $seenState, $maxScan = 20)
{
    if (!is_array($topics) || $topics === array()) return null;
    $pool = array();
    foreach ($topics as $t) {
        if (!is_array($t)) continue;
        $topicId = isset($t['id']) ? (int)$t['id'] : 0;
        $title = isset($t['title']) ? (string)$t['title'] : '';
        $visible = isset($t['visible']) ? (bool)$t['visible'] : true;
        $closed = isset($t['closed']) ? (bool)$t['closed'] : false;
        $archived = isset($t['archived']) ? (bool)$t['archived'] : false;
        $postsCount = isset($t['posts_count']) ? (int)$t['posts_count'] : 0;
        if ($topicId <= 0 || !$visible || $closed || $archived) continue;
        if ($postsCount < 2) continue;
        if (!worker_is_quiz_ack_topic_title($title)) continue;
        $idKey = 'quizack:' . $topicId;
        $seenAt = isset($seenState[$idKey]) ? (int)$seenState[$idKey] : 0;
        if ($seenAt > 0 && (time() - $seenAt) < (3 * 3600)) continue;
        $pool[] = $t;
    }
    if ($pool === array()) return null;
    shuffle($pool);
    $pool = array_slice($pool, 0, max(5, (int)$maxScan));

    foreach ($pool as $topic) {
        $topicId = (int)($topic['id'] ?? 0);
        $detail = fetch_json(rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '.json', array(
            'Api-Key: ' . KONVO_DISCOURSE_API_KEY,
            'Api-Username: BayMax',
        ));
        if (!is_array($detail) || !isset($detail['post_stream']['posts']) || !is_array($detail['post_stream']['posts'])) continue;
        $posts = $detail['post_stream']['posts'];
        if (count($posts) < 2) continue;
        $opPost = $posts[0];
        $latestPost = $posts[count($posts) - 1];
        $latestUsername = trim((string)($latestPost['username'] ?? ''));
        if ($latestUsername === '' || is_bot_user($latestUsername)) continue;
        $opRaw = post_content_text($opPost);
        $humanRaw = post_content_text($latestPost);
        if ($opRaw === '' || $humanRaw === '') continue;
        return array(
            'topic' => $topic,
            'topic_id' => $topicId,
            'op_raw' => $opRaw,
            'human_raw' => $humanRaw,
            'human_username' => $latestUsername,
            'human_post_number' => (int)($latestPost['post_number'] ?? 0),
        );
    }
    return null;
}

function worker_generate_and_post_quiz_ack($bots, array $target, bool $dryRun)
{
    $topic = $target['topic'];
    $topicId = (int)$target['topic_id'];
    $topicTitle = trim((string)($topic['title'] ?? ''));
    $opRaw = (string)$target['op_raw'];
    $humanRaw = (string)$target['human_raw'];
    $humanUsername = (string)$target['human_username'];
    $isSpotTheBug = stripos($topicTitle, 'spot the bug') !== false;

    $bot = pick_bot($bots, $humanUsername);
    $signature = isset($bot['signature']) ? (string)$bot['signature'] : (string)($bot['username'] ?? 'Bot');
    $soulKey = isset($bot['soul_key']) ? (string)$bot['soul_key'] : strtolower($signature);
    $soul = function_exists('konvo_compose_forum_persona_system_prompt')
        ? konvo_compose_forum_persona_system_prompt(konvo_load_soul($soulKey, 'Write naturally, concise, and human.'))
        : 'Write naturally, concise, and human.';

    $isCodingChallenge = stripos($topicTitle, 'coding challenge') !== false;
    if ($isCodingChallenge) {
        $kind = 'a "Coding Challenge" task where people post their own solution';
    } elseif ($isSpotTheBug) {
        $kind = 'a "Spot the Bug" code challenge';
    } else {
        $kind = 'a JS quiz question';
    }
    $system = trim((string)$soul) . "\n\n"
        . "A human has replied in a thread containing {$kind}. "
        . "First decide whether their reply is actually an attempt at answering the challenge. "
        . "Judge intent, not phrasing. A guess still counts as an answer attempt when it is phrased as a question, as sarcasm, as a joke, or as a one word callout. "
        . "If the reply points at something specific in the snippet, such as a variable, a typo, an operator, a line, a method or a value, treat it as an answer attempt even when it ends in a question mark. "
        . "\"What is widht? Misspelling alert!\" is an answer attempt, not a question you should answer. "
        . "Only classify it as not an attempt when the reply is genuinely about something else: welcoming a new member, thanking someone, small talk, or asking about the rules rather than the code. That is normal and welcome. "
        . "If it is NOT an answer attempt, set answer_attempt to false and simply take part in what they were actually doing - if they welcomed someone, welcome that person too; if they thanked someone, agree. "
        . "Never correct them, never restate the challenge at them, and never judge their post for being off-topic.\n\n"
        . "If it IS an answer attempt, set answer_attempt to true. Do NOT say whether they are right or wrong, and do NOT reveal, hint at, restate, or narrow down the answer. "
        . "Acknowledge that they have put a guess in, be playful and a little cryptic about it, and say the answer goes up later today. "
        . "Give away nothing they could use to work it out: no confirming, no denying, no warmer/colder, no pointing at the relevant line or concept. "
        . "Vary the wording - this should not read like a canned autoresponse to anyone reading several of these threads.\n"
        . "Either way: 1 to 2 sentences. No essay, no headers, no bullet list, no em dash, no sign-off line.\n"
        . "Return ONLY JSON: {\"answer_attempt\": true or false, \"reply\": \"...\"}.";
    $user = "Original post:\n{$opRaw}\n\n"
        . "Human's reply (@{$humanUsername}):\n{$humanRaw}\n\n"
        . "Write your reply now.";

    $payload = array(
        'model' => worker_model_for_task('reply_generation'),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature' => 0.7,
    );
    $res = post_json(
        'llm:chat',
        $payload,
        array('Authorization: Bearer ' . KONVO_ANTHROPIC_API_KEY)
    );
    if (!$res['ok'] || !isset($res['body']['choices'][0]['message']['content'])) {
        return array('ok' => false, 'error' => 'Claude request failed for quiz acknowledgement.');
    }
    $content = trim((string)$res['body']['choices'][0]['message']['content']);
    $obj = worker_extract_json_object($content);
    if (!is_array($obj) || !isset($obj['reply'])) {
        return array('ok' => false, 'error' => 'Could not parse acknowledgement JSON.');
    }
    $replyText = trim((string)$obj['reply']);
    if ($replyText === '') {
        return array('ok' => false, 'error' => 'Empty acknowledgement reply.');
    }
    if (function_exists('worker_enforce_banned_phrase_cleanup')) {
        $replyText = worker_enforce_banned_phrase_cleanup($replyText);
    }
    $replyText = normalize_signature($replyText, $signature);
    $answerAttempt = (bool)($obj['answer_attempt'] ?? false);

    if ($dryRun) {
        return array(
            'ok' => true,
            'dry_run' => true,
            'topic_id' => $topicId,
            'bot' => $bot,
            'answer_attempt' => $answerAttempt,
            'reply_preview' => $replyText,
        );
    }

    $postRes = post_json(
        rtrim(KONVO_BASE_URL, '/') . '/posts.json',
        array(
            'topic_id' => $topicId,
            'raw' => $replyText,
            'reply_to_post_number' => (int)$target['human_post_number'],
        ),
        array(
            'Api-Key: ' . KONVO_DISCOURSE_API_KEY,
            'Api-Username: ' . (string)($bot['username'] ?? 'BayMax'),
        )
    );
    return array(
        'ok' => (bool)($postRes['ok'] ?? false),
        'topic_id' => $topicId,
        'bot' => $bot,
        'answer_attempt' => $answerAttempt,
        'reply_text' => $replyText,
        'post_result' => $postRes,
    );
}

function worker_pick_orphan_bot_topic_over_24h($topics, $seenState, $maxScan = 30)
{
    if (!is_array($topics) || $topics === array()) return null;
    $now = time();
    $maxScan = max(5, (int)$maxScan);
    $pool = array();

    foreach ($topics as $t) {
        if (!is_array($t)) continue;
        $topicId = isset($t['id']) ? (int)$t['id'] : 0;
        $postsCount = isset($t['posts_count']) ? (int)$t['posts_count'] : 0;
        $visible = isset($t['visible']) ? (bool)$t['visible'] : true;
        $closed = isset($t['closed']) ? (bool)$t['closed'] : false;
        $archived = isset($t['archived']) ? (bool)$t['archived'] : false;
        if ($topicId <= 0 || !$visible || $closed || $archived) continue;
        if ($postsCount > 1) continue; // unreplied means only OP exists

        $createdAt = isset($t['created_at']) ? strtotime((string)$t['created_at']) : false;
        $lastPostedAt = isset($t['last_posted_at']) ? strtotime((string)$t['last_posted_at']) : false;
        $baseTs = ($createdAt !== false) ? (int)$createdAt : (($lastPostedAt !== false) ? (int)$lastPostedAt : 0);
        if ($baseTs <= 0) continue;
        $ageSec = max(0, $now - $baseTs);
        if ($ageSec < (24 * 3600)) continue;

        $idKey = (string)$topicId;
        $seenAt = isset($seenState[$idKey]) ? (int)$seenState[$idKey] : 0;
        if ($seenAt > 0 && ($now - $seenAt) < (6 * 3600)) {
            // Cooldown: avoid immediate re-attempt churn on the same orphan thread.
            continue;
        }
        $pool[] = array('topic' => $t, 'age_seconds' => $ageSec);
    }

    if ($pool === array()) return null;
    usort($pool, static function ($a, $b) {
        $aa = (int)($a['age_seconds'] ?? 0);
        $bb = (int)($b['age_seconds'] ?? 0);
        if ($aa === $bb) return 0;
        return ($aa > $bb) ? -1 : 1;
    });
    $pool = array_slice($pool, 0, $maxScan);

    foreach ($pool as $row) {
        $topic = isset($row['topic']) && is_array($row['topic']) ? $row['topic'] : null;
        if (!is_array($topic)) continue;
        $topicId = (int)($topic['id'] ?? 0);
        if ($topicId <= 0) continue;
        $detail = fetch_json(rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '.json', array(
            'Api-Key: ' . KONVO_DISCOURSE_API_KEY,
            'Api-Username: BayMax',
        ));
        if (!is_array($detail) || !isset($detail['post_stream']['posts']) || !is_array($detail['post_stream']['posts'])) continue;
        $posts = $detail['post_stream']['posts'];
        if (count($posts) !== 1) continue;
        $op = $posts[0] ?? null;
        if (!is_array($op)) continue;
        $opUsername = trim((string)($op['username'] ?? ''));
        if ($opUsername === '' || !is_bot_user($opUsername)) continue;
        $opCreatedAtTs = isset($op['created_at']) ? strtotime((string)$op['created_at']) : false;
        if ($opCreatedAtTs === false) $opCreatedAtTs = isset($topic['created_at']) ? strtotime((string)$topic['created_at']) : false;
        $ageFromOp = ($opCreatedAtTs !== false) ? max(0, $now - (int)$opCreatedAtTs) : (int)($row['age_seconds'] ?? 0);
        if ($ageFromOp < (24 * 3600)) continue;
        return array(
            'topic' => $topic,
            'detail' => $detail,
            'age_seconds' => $ageFromOp,
            'op_username' => $opUsername,
        );
    }
    return null;
}

function find_related_internet_link($title, $body)
{
    $topicBlob = trim((string)$title . "\n" . (string)$body);
    if ($topicBlob === '') return null;

    $query = trim((string)$title);
    $keywords = link_keywords($topicBlob);
    if ($query === '') {
        $query = implode(' ', array_slice($keywords, 0, 8));
    } elseif ($keywords !== array()) {
        $query = trim($query . ' ' . implode(' ', array_slice($keywords, 0, 4)));
    }
    if ($query === '') return null;

    $q = substr($query, 0, 160);
    $url = 'https://hn.algolia.com/api/v1/search?tags=story&hitsPerPage=12&query=' . rawurlencode($q);
    $json = fetch_json($url);
    if (!is_array($json) || !isset($json['hits']) || !is_array($json['hits'])) return null;

    $scored = array();
    foreach ($json['hits'] as $hit) {
        if (!is_array($hit)) continue;
        $link = isset($hit['url']) ? trim((string)$hit['url']) : '';
        $ttl = isset($hit['title']) ? trim((string)$hit['title']) : '';
        if ($ttl === '') $ttl = isset($hit['story_title']) ? trim((string)$hit['story_title']) : '';
        if ($link === '' || $ttl === '') continue;
        if (stripos($link, 'forum.kirupa.com') !== false) continue;
        if (!link_relevant_to_topic($title, $body, $ttl, $link)) continue;

        $score = link_overlap_score($topicBlob, $ttl);
        if (preg_match('/\b(url shortener|qr code|crypto|airdrop|betting|casino)\b/i', $ttl)) {
            $score -= 0.20;
        }
        $scored[] = array(
            'title' => $ttl,
            'url' => $link,
            'score' => $score,
        );
    }

    if ($scored === array()) return null;
    usort($scored, function ($a, $b) {
        $sa = isset($a['score']) ? (float)$a['score'] : 0.0;
        $sb = isset($b['score']) ? (float)$b['score'] : 0.0;
        if ($sa === $sb) return 0;
        return ($sa > $sb) ? -1 : 1;
    });
    $best = $scored[0];
    if (!is_array($best) || !isset($best['title']) || !isset($best['url'])) return null;
    return array(
        'title' => (string)$best['title'],
        'url' => (string)$best['url'],
    );
}

function link_allowed_for_reply($shouldIncludeLink, $linkData, $topicTitle, $opRaw)
{
    if (!$shouldIncludeLink || !is_array($linkData)) return false;
    $title = isset($linkData['title']) ? trim((string)$linkData['title']) : '';
    $url = isset($linkData['url']) ? trim((string)$linkData['url']) : '';
    if ($title === '' || $url === '') return false;
    return link_relevant_to_topic($topicTitle, $opRaw, $title, $url);
}

function allowed_link_url_for_reply($shouldIncludeLink, $linkData, $topicTitle, $opRaw)
{
    if (!link_allowed_for_reply($shouldIncludeLink, $linkData, $topicTitle, $opRaw)) {
        return '';
    }
    return trim((string)($linkData['url'] ?? ''));
}

function allowed_link_title_for_reply($shouldIncludeLink, $linkData, $topicTitle, $opRaw)
{
    if (!link_allowed_for_reply($shouldIncludeLink, $linkData, $topicTitle, $opRaw)) {
        return '';
    }
    return trim((string)($linkData['title'] ?? ''));
}

function worker_poll_option_text($opt)
{
    if (!is_array($opt)) return '';
    $html = isset($opt['html']) ? (string)$opt['html'] : '';
    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($text === '') {
        $text = isset($opt['name']) ? trim((string)$opt['name']) : '';
    }
    if ($text === '') {
        $text = isset($opt['value']) ? trim((string)$opt['value']) : '';
    }
    $text = preg_replace('/\s+/', ' ', (string)$text);
    return trim((string)$text);
}

function worker_find_poll_context($posts, $preferredPostNumber)
{
    if (!is_array($posts) || count($posts) === 0) return null;
    $candidates = array();
    foreach ($posts as $post) {
        if (!is_array($post)) continue;
        $polls = isset($post['polls']) && is_array($post['polls']) ? $post['polls'] : array();
        if ($polls === array()) continue;
        foreach ($polls as $poll) {
            if (!is_array($poll)) continue;
            $opts = isset($poll['options']) && is_array($poll['options']) ? $poll['options'] : array();
            if ($opts === array()) continue;
            $clean = array();
            foreach ($opts as $o) {
                if (!is_array($o)) continue;
                $id = isset($o['id']) ? trim((string)$o['id']) : '';
                $text = worker_poll_option_text($o);
                if ($id === '' || $text === '') continue;
                $clean[] = array('id' => $id, 'text' => $text);
            }
            if ($clean === array()) continue;
            $name = isset($poll['name']) ? trim((string)$poll['name']) : '';
            if ($name === '') $name = 'poll';
            $candidates[] = array(
                'status' => strtolower(trim((string)($poll['status'] ?? 'open'))),
                'poll_name' => $name,
                'post_id' => (int)($post['id'] ?? 0),
                'post_number' => (int)($post['post_number'] ?? 0),
                'post_username' => (string)($post['username'] ?? ''),
                'prompt' => post_content_text($post),
                'options' => $clean,
            );
        }
    }
    if ($candidates === array()) return null;

    if ((int)$preferredPostNumber > 0) {
        foreach ($candidates as $cand) {
            if ((int)($cand['post_number'] ?? 0) === (int)$preferredPostNumber && (string)($cand['status'] ?? '') === 'open') {
                return $cand;
            }
        }
    }
    for ($i = count($candidates) - 1; $i >= 0; $i--) {
        $cand = $candidates[$i];
        if ((string)($cand['status'] ?? '') === 'open') return $cand;
    }
    return $candidates[count($candidates) - 1];
}

function worker_clean_poll_reason($text)
{
    $text = trim(strip_tags((string)$text));
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/https?:\/\/\S+/i', '', (string)$text);
    $text = preg_replace('/\s+/', ' ', (string)$text);
    $text = trim((string)$text, " \t\n\r\0\x0B\"'`“”‘’");
    if ($text === '') return '';
    $text = str_replace('?', '.', $text);
    if (!preg_match('/[.!]$/', $text)) $text .= '.';
    return trim((string)$text);
}

function worker_technical_personality_config($botUsername)
{
    $b = strtolower(trim((string)$botUsername));
    $map = array(
        'mechaprime' => array('verbosity' => 'medium', 'tone' => 'analytical', 'quirk' => 'short insight'),
        'baymax' => array('verbosity' => 'medium', 'tone' => 'friendly', 'quirk' => 'mental model'),
        'kirupabot' => array('verbosity' => 'low', 'tone' => 'friendly', 'quirk' => 'naming tip'),
        'vaultboy' => array('verbosity' => 'low', 'tone' => 'witty', 'quirk' => 'short insight'),
        'yoshiii' => array('verbosity' => 'medium', 'tone' => 'friendly', 'quirk' => 'naming tip'),
        'bobamilk' => array('verbosity' => 'low', 'tone' => 'minimalist', 'quirk' => 'short insight'),
        'wafflefries' => array('verbosity' => 'low', 'tone' => 'witty', 'quirk' => 'mental model'),
        'quelly' => array('verbosity' => 'low', 'tone' => 'friendly', 'quirk' => 'short insight'),
        'sora' => array('verbosity' => 'low', 'tone' => 'minimalist', 'quirk' => 'mental model'),
        'sarah_connor' => array('verbosity' => 'medium', 'tone' => 'analytical', 'quirk' => 'short insight'),
        'ellen1979' => array('verbosity' => 'medium', 'tone' => 'analytical', 'quirk' => 'mental model'),
        'arthurdent' => array('verbosity' => 'low', 'tone' => 'witty', 'quirk' => 'naming tip'),
        'hariseldon' => array('verbosity' => 'medium', 'tone' => 'analytical', 'quirk' => 'mental model'),
    );
    return isset($map[$b]) ? $map[$b] : array('verbosity' => 'medium', 'tone' => 'friendly', 'quirk' => 'short insight');
}

function worker_technical_question_framework_prompt($botUsername)
{
    $cfg = worker_technical_personality_config($botUsername);
    $verbosity = isset($cfg['verbosity']) ? (string)$cfg['verbosity'] : 'medium';
    $tone = isset($cfg['tone']) ? (string)$cfg['tone'] : 'friendly';
    $quirk = isset($cfg['quirk']) ? (string)$cfg['quirk'] : 'short insight';

    return "Technical question single-pass mode:\n"
        . "You are a technical assistant responding in the style of Kirupa Forum answers.\n\n"
        . "Primary objective:\n"
        . "- Diagnose quickly\n"
        . "- Explain clearly\n"
        . "- Give the smallest practical fix\n"
        . "- Keep it human and skimmable\n\n"
        . "Response shape:\n"
        . "- One conversational reply, no headings or labeled sections\n"
        . "- Answer in the first clause\n"
        . "- Use short sentences and blank lines between distinct ideas\n"
        . "- If you make a second point, put it in a new paragraph instead of continuing one dense block\n"
        . "- Include at most one small code block only if it materially improves clarity\n"
        . "- If code is multi-line, use plain fenced code blocks with triple backticks and no language label\n"
        . "- End on a complete thought (no dangling fragments)\n\n"
        . "Style rules:\n"
        . "- Calm, precise, practical\n"
        . "- No fluff or performative framing\n"
        . "- No explicit section labels like \"Diagnosis\", \"Minimal Fix\", \"Quick Check\", or \"Sanity Check\"\n"
        . "- No emojis\n"
        . "- Do not restate the whole question\n"
        . "- External links are optional and only when directly relevant\n\n"
        . "Personality layer config:\n"
        . "- verbosity_level: {$verbosity}\n"
        . "- tone_flavor: {$tone}\n"
        . "- voice_quirk: {$quirk}\n"
        . "Include at most one voice quirk line.";
}

function worker_has_technical_framework_shape($text)
{
    $t = trim((string)$text);
    if ($t === '') return false;
    if (preg_match('/^\s*#{1,6}\s+/m', $t)) return false;
    if (preg_match('/(^|\n)\s*(Diagnosis|Conceptual Explanation|Minimal Fix|Why This Works|Quick\s*Check|Sanity\s*Check|Optional Practical Tip)\s*:?\s*($|\n)/i', $t)) return false;
    if (!preg_match('/[.!?]/', $t)) return false;
    $wordCount = preg_match_all('/\S+/', $t, $wm);
    if (is_int($wordCount) && $wordCount > 220) return false;
    $lineCount = substr_count($t, "\n") + 1;
    if ($lineCount > 28) return false;
    if (preg_match('/(^|\n)\s*(?:#+\s*)?(?:\d+[\).\:-]?\s*)?(?:Step|Section|Part)\b/i', $t)) return false;
    return true;
}

function worker_rewrite_technical_framework_with_llm($bot, $topicTitle, $opRaw, $draft, $signature)
{
    $draft = trim((string)$draft);
    if ($draft === '' || KONVO_ANTHROPIC_API_KEY === '') return '';
    $soulKey = isset($bot['soul_key']) ? (string)$bot['soul_key'] : '';
    $soul = konvo_compose_forum_persona_system_prompt(
        konvo_load_soul($soulKey, 'Write concise, natural forum replies.')
    );
    $soul .= "\n\n" . worker_technical_question_framework_prompt((string)($bot['username'] ?? ''));
    $payload = array(
        'model' => worker_model_for_task('technical_framework_rewrite', array('technical' => true)),
        'messages' => array(
            array(
                'role' => 'system',
                'content' => $soul
                    . "\n\nRewrite this as one human-sounding single-pass technical forum reply."
                    . ' Keep it concise, answer-first, and practical.'
                    . ' Use short sentences and blank lines between distinct ideas.'
                    . ' No section headings or template labels.'
                    . ' Include at most one small code block only if it truly helps.'
                    . ' Do not sign your post; the forum already shows your username.',
            ),
            array(
                'role' => 'user',
                'content' => "Topic title: {$topicTitle}\n\nTarget content:\n{$opRaw}\n\nCurrent draft:\n{$draft}\n\nRewrite to match the single-pass style.",
            ),
        ),
        'temperature' => 0.2,
    );
    $res = post_json(
        'llm:chat',
        $payload,
        array('Authorization: Bearer ' . KONVO_ANTHROPIC_API_KEY)
    );
    if (!$res['ok'] || !isset($res['body']['choices'][0]['message']['content'])) return '';
    return trim((string)$res['body']['choices'][0]['message']['content']);
}

function worker_pick_poll_option_and_reason($bot, $topicTitle, $targetRaw, $pollContext)
{
    if (!is_array($pollContext) || !isset($pollContext['options']) || !is_array($pollContext['options']) || $pollContext['options'] === array()) {
        return array('ok' => false, 'error' => 'No poll options.');
    }
    $signature = isset($bot['signature']) ? (string)$bot['signature'] : 'Bot';
    $soulKey = isset($bot['soul_key']) ? (string)$bot['soul_key'] : strtolower($signature);
    $soul = konvo_compose_forum_persona_system_prompt(
        konvo_load_soul($soulKey, 'Write naturally, concise, and human.')
    );

    $opts = array();
    foreach ($pollContext['options'] as $i => $opt) {
        if (!is_array($opt)) continue;
        $id = isset($opt['id']) ? trim((string)$opt['id']) : '';
        $txt = isset($opt['text']) ? trim((string)$opt['text']) : '';
        if ($id === '' || $txt === '') continue;
        $n = (int)$i + 1;
        $opts[] = "{$n}) id={$id} text={$txt}";
    }
    if ($opts === array()) {
        return array('ok' => false, 'error' => 'No usable poll options.');
    }

    $system = $soul . ' Pick the best poll option for this forum thread context. '
        . 'Return JSON only: {"option_id":"...","reason":"..."} '
        . 'Use an exact option_id from the list. reason must be one short sentence, no question mark. '
        . 'Avoid generic wording like "best matches context"; mention a concrete mechanism or edge case from the prompt.';
    $user = "Topic title: {$topicTitle}\n"
        . "Target post content:\n{$targetRaw}\n\n"
        . "Poll post content:\n" . (string)($pollContext['prompt'] ?? '') . "\n\n"
        . "Options:\n" . implode("\n", $opts);

    $res = post_json(
        'llm:chat',
        array(
            'model' => worker_model_for_task('poll_pick'),
            'messages' => array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user', 'content' => $user),
            ),
            'temperature' => 0.3,
        ),
        array('Authorization: Bearer ' . KONVO_ANTHROPIC_API_KEY)
    );
    if (!$res['ok'] || !isset($res['body']['choices'][0]['message']['content'])) {
        return array('ok' => false, 'error' => 'LLM poll pick failed.');
    }
    $raw = trim((string)$res['body']['choices'][0]['message']['content']);
    if ($raw === '') return array('ok' => false, 'error' => 'LLM poll pick empty.');
    $json = $raw;
    if (preg_match('/\{[\s\S]*\}/', $raw, $m)) {
        $json = (string)$m[0];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return array('ok' => false, 'error' => 'LLM poll pick parse failed.');
    }
    $pickedId = trim((string)($decoded['option_id'] ?? ''));
    $pickedReason = worker_clean_poll_reason((string)($decoded['reason'] ?? ''));

    $optionsById = array();
    foreach ($pollContext['options'] as $opt) {
        if (!is_array($opt)) continue;
        $id = isset($opt['id']) ? trim((string)$opt['id']) : '';
        if ($id === '') continue;
        $optionsById[$id] = trim((string)($opt['text'] ?? ''));
    }
    if ($pickedId === '' || !isset($optionsById[$pickedId])) {
        $first = $pollContext['options'][0];
        $pickedId = isset($first['id']) ? trim((string)$first['id']) : '';
        if ($pickedId === '') return array('ok' => false, 'error' => 'No valid option id selected.');
    }
    $pickedText = isset($optionsById[$pickedId]) ? trim((string)$optionsById[$pickedId]) : '';
    if ($pickedText === '') $pickedText = 'the best fit';
    if ($pickedReason === '') $pickedReason = 'it lines up best with the thread context.';

    return array(
        'ok' => true,
        'option_id' => $pickedId,
        'option_text' => $pickedText,
        'reason' => $pickedReason,
    );
}

function worker_vote_poll_choice($botUsername, $pollContext, $optionId)
{
    $postId = is_array($pollContext) ? (int)($pollContext['post_id'] ?? 0) : 0;
    $pollName = is_array($pollContext) ? trim((string)($pollContext['poll_name'] ?? 'poll')) : 'poll';
    if ($postId <= 0 || $pollName === '' || trim((string)$optionId) === '') {
        return array('ok' => false, 'status' => 0, 'error' => 'Invalid vote payload.', 'body' => array(), 'raw' => '');
    }
    return post_json(
        rtrim(KONVO_BASE_URL, '/') . '/polls/vote',
        array(
            'post_id' => $postId,
            'poll_name' => $pollName,
            'options' => array((string)$optionId),
        ),
        array(
            'Api-Key: ' . KONVO_DISCOURSE_API_KEY,
            'Api-Username: ' . (string)$botUsername,
        )
    );
}

function worker_poll_reason_sentence($optionText, $reason, $voteOk, $seed = '')
{
    $opt = trim((string)$optionText);
    if ($opt === '') return '';
    $why = worker_clean_poll_reason((string)$reason);
    if ($why === '') $why = 'it best matches the thread context.';
    $why = rtrim((string)$why, '. ');
    $seedInt = abs((int)crc32(strtolower($opt . '|' . $why . '|' . (string)$seed)));
    $voteTemplates = array(
        'I voted for "%s" because %s.',
        'Going with "%s" because %s.',
        '"%s" gets my vote because %s.',
    );
    $pickTemplates = array(
        'My pick is "%s" because %s.',
        'I would pick "%s" because %s.',
        'Choosing "%s" because %s.',
    );
    $templates = $voteOk ? $voteTemplates : $pickTemplates;
    $template = $templates[$seedInt % count($templates)];
    return sprintf($template, $opt, $why);
}

function generate_reply_text($bot, $topicTitle, $opUsername, $opRaw, $linkData, $shouldIncludeLink, $pollMeta = null, $recentBotPosts = array(), $recentSameBotPosts = array(), $saturatedThreadPhrases = array(), $targetMentionsSaturated = false, $forceContrarian = false, $allowNoReply = false, $topicContextText = '', $recentBotStreak = 0, $threadOpUsername = '', $threadOpRaw = '', $recentThreadContext = '', $forceAnsweredBotToBotContrarian = false)
{
    $botUsername = isset($bot['username']) ? (string)$bot['username'] : '';
    $signatureRaw = isset($bot['signature']) ? (string)$bot['signature'] : 'Bot';
    $signatureSeed = strtolower($botUsername . '|' . $topicTitle . '|' . substr((string)$opRaw, 0, 220));
    $signature = function_exists('konvo_signature_with_optional_emoji')
        ? konvo_signature_with_optional_emoji($signatureRaw, $signatureSeed)
        : $signatureRaw;
    $soulKey = isset($bot['soul_key']) ? (string)$bot['soul_key'] : strtolower((string)$signature);
    $soul = konvo_compose_forum_persona_system_prompt(
        konvo_load_soul($soulKey, 'Write naturally, concise, and human.')
    );
    $botRoleRule = worker_bot_value_role_rule($botUsername);
    $isCodey = is_codey_topic($topicTitle, $opRaw);
    $isTechnicalTopic = $isCodey || (function_exists('kirupa_is_technical_text') && kirupa_is_technical_text($topicTitle . "\n" . $opRaw));
    $targetHasCodeContext = worker_post_has_code_context($opRaw . "\n" . (string)$recentThreadContext);
    $allowNonTechnicalCodeSnippets = $isTechnicalTopic || $isCodey || $targetHasCodeContext;
    $isMemeGifThread = worker_is_meme_gif_context($topicTitle . "\n" . $opRaw . "\n" . (string)$topicContextText);
    $isSolutionProblemThread = worker_is_solution_problem_thread($topicTitle . "\n" . $opRaw . "\n" . (string)$topicContextText);
    $hasExternalSourceContext = (bool)preg_match('/https?:\/\/\S+/i', $topicTitle . "\n" . $opRaw . "\n" . (string)$topicContextText . "\n" . (string)$threadOpRaw);
    $targetAuthorIsBot = is_bot_user($opUsername);
    $targetIsQuestionLike = worker_is_question_like_text($opRaw);
    $threadOpLower = strtolower(trim((string)$threadOpUsername));
    $targetUserLower = strtolower(trim((string)$opUsername));
    $botLower = strtolower(trim((string)$botUsername));
    $botIsTopicOp = ($threadOpLower !== '' && $botLower === $threadOpLower);
    $opSeedText = trim((string)$threadOpRaw) !== '' ? (string)$threadOpRaw : (string)$opRaw;
    $opLooksHelpSeekingQuestion = worker_op_is_help_seeking_question_thread($topicTitle, $opSeedText);
    $targetIsTopicOp = ($threadOpLower !== '' && $targetUserLower === $threadOpLower);
    $learnerFollowupMode = $botIsTopicOp && $opLooksHelpSeekingQuestion && !$targetIsTopicOp && trim((string)$opRaw) !== '';
    $isTechnicalQuestion = $isTechnicalTopic && $targetIsQuestionLike;
    $isSimpleClarification = $isTechnicalQuestion && worker_is_simple_clarification_question($opRaw);
    $shouldDeepenCoreTopic = !$targetIsQuestionLike
        && !$isMemeGifThread
        && !$learnerFollowupMode
        && ($isTechnicalTopic || $isSolutionProblemThread || $hasExternalSourceContext);
    $hasPriorSameBot = (is_array($recentSameBotPosts) && $recentSameBotPosts !== array());
    $latestSameBotPostRaw = '';
    if ($hasPriorSameBot) {
        $latestSameBotPost = $recentSameBotPosts[count($recentSameBotPosts) - 1] ?? null;
        if (is_array($latestSameBotPost)) {
            $latestSameBotPostRaw = trim((string)($latestSameBotPost['raw'] ?? ''));
        }
    }
    $isPreferenceThread = worker_is_preference_thread($topicTitle . "\n" . $opRaw . "\n" . (string)$topicContextText);
    $seed = abs((int)crc32(strtolower($signature . '|' . $topicTitle . '|' . substr($opRaw, 0, 180))));
    $lengthBucketSeed = abs((int)crc32(strtolower($signature . '|length_bucket|' . $topicTitle . '|' . substr($opRaw, 0, 180))));
    $lengthBucket = function_exists('konvo_pick_reply_length_bucket')
        ? konvo_pick_reply_length_bucket($lengthBucketSeed)
        : array('bucket' => 'short', 'instruction' => '');
    $lengthBucketRule = (string)($lengthBucket['instruction'] ?? '');
    $contrarianMode = (bool)$forceContrarian || (($seed % 100) < ($targetAuthorIsBot ? 8 : 18));
    if ($learnerFollowupMode) {
        $contrarianMode = false;
    }
    if ((bool)$forceAnsweredBotToBotContrarian) {
        $contrarianMode = true;
    }
    if ($targetAuthorIsBot && !$targetIsQuestionLike && !(bool)$forceAnsweredBotToBotContrarian) {
        $contrarianMode = false;
    }
    if ($isMemeGifThread) {
        $contrarianMode = false;
    }
    $pollEncountered = is_array($pollMeta) && !empty($pollMeta['encountered']);
    $pollInstruction = '';
    $pollContextBlock = '';
    $pollReasonSentence = '';
    $recentBotContext = worker_recent_other_bot_context($recentBotPosts);
    $recentSameBotContext = worker_recent_same_bot_context($recentSameBotPosts);
    $openingMemory = worker_load_opening_memory();
    $recentOpeningStems = worker_recent_opening_stems($openingMemory, 96, 24);
    $recentOpeningHints = $recentOpeningStems === array()
        ? '(none)'
        : implode("\n", array_map(static function ($s) { return '- ' . $s; }, $recentOpeningStems));
    $saturatedContext = worker_saturated_context($saturatedThreadPhrases);
    $crossBotRule = ($recentBotPosts !== array())
        ? 'Cross-bot novelty rule: avoid repeating the same core sentence or example from recent bot replies. Keep the intent, but add a distinct angle.'
        : '';
    $selfNoveltyRule = ($recentSameBotPosts !== array())
        ? 'Self-novelty rule: you already replied in this thread. Do not restate the same recommendation with cosmetic wording changes.'
        : '';
    $continuityRule = ($hasPriorSameBot && $isPreferenceThread)
        ? 'Continuity rule: you already posted in this preference-style thread. Keep prior picks valid and frame this as an additional pick/example, not a replacement.'
        : '';
    $quirkyMode = (!$isCodey && !$isMemeGifThread && !$pollEncountered && !$shouldIncludeLink && !$allowNoReply && (($seed % 100) < 9));
    $quirkyUrl = $quirkyMode ? worker_pick_quirky_media_url($botUsername . '|' . $topicTitle . '|' . substr((string)$opRaw, 0, 180)) : '';
    $quirkyRule = ($quirkyUrl !== '')
        ? 'Quirky mode: if it fits naturally, you may include this playful reaction GIF URL on its own line with blank lines around it: ' . $quirkyUrl . '. Keep wording concise and human.'
        : '';
    $threadDiversityRule = ($saturatedThreadPhrases !== array())
        ? ($targetMentionsSaturated
            ? 'Thread diversity rule: some examples are overused in this thread. You may acknowledge one briefly if directly asked, but pivot to a different relevant example and center that.'
            : 'Thread diversity rule: avoid overused entities/phrases from this thread. Pick a different relevant example or angle that has not dominated the conversation.')
        : '';
    $angleModes = array(
        'Use one concrete edge case.',
        'Use one practical debugging signal.',
        'Use one implementation caveat.',
        'Use one tradeoff that changes the recommendation.',
        'Use one tiny concrete example.',
    );
    $angleSeed = abs((int)crc32(strtolower($botUsername . '|' . $topicTitle . '|' . substr((string)$opRaw, 0, 180))));
    $distinctAngleRule = 'Distinct angle preference: ' . $angleModes[$angleSeed % count($angleModes)];
    $openingDiversityRule = worker_opening_diversity_rule($botUsername);
    $crossThreadOpeningRule = 'Cross-thread opener rule (mandatory): do not start with an opener stem that appeared recently in other threads. Use a fresh first sentence pattern.';
    $antiAgreementRule = 'Agreement phrasing rule: never open with "Exactly", "100%", "Totally agree", "Totally,", "Totally —", or "Great point."';
    $expertiseScopeRule = worker_bot_expertise_scope_rule($botUsername);
    $cadenceMeta = worker_question_cadence_should_force_question($botUsername);
    $replyCadenceIndex = (int)($cadenceMeta['next_index'] ?? 1);
    $forceQuestionCadence = (bool)($cadenceMeta['force_question'] ?? false) && !$learnerFollowupMode;
    $forceUncertaintyCadence = (($replyCadenceIndex % 10) === 0) && !$learnerFollowupMode;
    $forceLowEffortCadence = (($replyCadenceIndex % 10) === 5) && !$learnerFollowupMode && !$isTechnicalQuestion && !$targetIsQuestionLike;
    $recentShortBotReplyPresent = false;
    foreach (array_merge(is_array($recentBotPosts) ? $recentBotPosts : array(), is_array($recentSameBotPosts) ? $recentSameBotPosts : array()) as $recentBotPost) {
        if (!is_array($recentBotPost)) continue;
        if (worker_is_low_effort_reaction(post_content_text($recentBotPost))) {
            $recentShortBotReplyPresent = true;
            break;
        }
    }
    $forceThreadMicroReaction = !$learnerFollowupMode
        && !$isTechnicalQuestion
        && !$targetIsQuestionLike
        && ((count(is_array($recentBotPosts) ? $recentBotPosts : array()) + count(is_array($recentSameBotPosts) ? $recentSameBotPosts : array())) >= 2)
        && !$recentShortBotReplyPresent;
    $questionCadenceRule = $forceQuestionCadence
        ? 'Question cadence rule (mandatory this turn): this must be a genuine question reply with exactly one real question mark.'
        : 'Question cadence rule: about every fifth reply in a 24-hour window should be a genuine question.';
    $uncertaintyRule = 'UNCERTAINTY RULE (mandatory): At least 1 out of every 10 replies MUST contain genuine uncertainty. '
        . "Use the persona's example phrases. This is NOT optional - count your recent replies and force one if you haven't done it recently. "
        . "Humans don't know everything and they say so.";
    if ($forceUncertaintyCadence) {
        $uncertaintyRule .= ' This turn is mandatory: include one brief uncertainty phrase naturally.';
    }
    $casualSeed = abs((int)crc32(strtolower($botUsername . '|casual-tone|' . $topicTitle . '|' . substr((string)$opRaw, 0, 140))));
    $casualHumorRule = (!$isTechnicalQuestion && ($casualSeed % 100) < 10)
        ? 'Casual tone mode is ON: allow a light human touch like "lol", "ngl", a playful aside, or one subtle emoji.'
        : '';
    $lowEffortRule = 'LOW-EFFORT RULE (mandatory): At least 1 out of every 10 replies MUST be a low-effort reaction - 1 to 5 words max with no substantive point. '
        . "Use the persona's example phrases. Not every reply needs an opinion or insight. Sometimes humans just react. This is NOT optional.";
    if ($forceLowEffortCadence) {
        $lowEffortRule .= ' This turn is mandatory: reply with 1 to 5 words only and no substantive point.';
    } elseif ($forceThreadMicroReaction) {
        $lowEffortRule .= ' Thread micro-reaction mode is mandatory this turn: this thread already has multiple full bot replies and no tiny reaction yet, so answer in 1 to 4 words only.';
    }
    $solutionVideoRule = $isSolutionProblemThread
        ? 'Problem-solving thread rule: if it helps, include one direct YouTube video where someone demonstrates a practical solution. Keep the URL standalone with blank lines around it and add one short line explaining why that video is useful.'
        : '';
    $previousFiveVarietyRule = 'Full-thread dedupe rule (mandatory): re-read every existing response in the thread before replying. Add one concrete new detail that is not already in thread replies (mechanism, caveat, correction, metric, mini example, useful source, or a skeptic failure-mode angle). Do not summarize existing replies. Different words, same idea is an echo. '
        . 'If no new detail is available, return [[NO_REPLY]] when allowed. '
        . 'If someone already asked about X, do not ask a similar question about X. If someone already expressed skepticism about Y, do not rephrase that skepticism. '
        . 'If a link materially strengthens the reply, include one relevant source (kirupa.com, direct YouTube video, credible third-party article, or scientific research).';
    $noReplyRule = (!$learnerFollowupMode && $allowNoReply)
        ? 'If you cannot add a materially new point beyond recent bot replies, output exactly [[NO_REPLY]] and nothing else.'
        : '';
    if ($pollEncountered) {
        $pollInstruction = !empty($pollMeta['vote_ok'])
            ? 'A poll is present and you already voted. Include one concise sentence explaining why you voted for your selected option.'
            : 'A poll is present. Include one concise sentence explaining your selected option.';
        $pollReasonSentence = worker_poll_reason_sentence(
            (string)($pollMeta['selected_option_text'] ?? ''),
            (string)($pollMeta['selected_reason'] ?? ''),
            (bool)($pollMeta['vote_ok'] ?? false),
            (string)$botUsername
        );
        $optLines = array();
        if (isset($pollMeta['context']['options']) && is_array($pollMeta['context']['options'])) {
            foreach ($pollMeta['context']['options'] as $idx => $opt) {
                if (!is_array($opt)) continue;
                $txt = isset($opt['text']) ? trim((string)$opt['text']) : '';
                if ($txt === '') continue;
                $n = (int)$idx + 1;
                $optLines[] = "{$n}) {$txt}";
            }
        }
        $pollContextBlock = "Poll context:\n"
            . "Poll is on post #"
            . (int)($pollMeta['poll_post_number'] ?? 0)
            . " by @"
            . (string)($pollMeta['poll_post_username'] ?? '')
            . "\nPoll prompt/content:\n"
            . (string)($pollMeta['poll_prompt'] ?? '')
            . "\n\nPoll options:\n"
            . implode("\n", $optLines)
            . "\n\nSelected option: "
            . (string)($pollMeta['selected_option_text'] ?? '')
            . "\nReason seed: "
            . (string)($pollMeta['selected_reason'] ?? '');
    }

    $isKirupaBot = (strtolower(trim((string)$botUsername)) === 'kirupabot');
    if ($learnerFollowupMode) {
        $linkInstruction = 'OP follow-up mode: include a link only if it clearly adds value to the specific point being discussed; otherwise skip links.';
    } elseif ($isSimpleClarification) {
        $linkInstruction = 'Simple clarification mode: do not include links, citations, or references.';
    } elseif ($isTechnicalQuestion && !$isKirupaBot) {
        $linkInstruction = 'Do not include external links, citations, or references in technical question mode.';
    } elseif ($isTechnicalQuestion && $isKirupaBot) {
        // Do not ask the model to supply its own kirupa.com URL here - it has no
        // verified list to draw from in this mode and will confidently invent a
        // plausible-looking one that does not exist. A real, verified article URL
        // (if one is found) gets appended deterministically further down.
        $linkInstruction = 'Technical helper mode: do not include any kirupa.com URL yourself. A verified relevant article link will be added separately if one exists.';
    } elseif ($isMemeGifThread) {
        $linkInstruction = 'Meme/GIF reaction mode: do not include external links unless it is the provided quirky reaction GIF URL.';
    } else {
        $linkInstruction = $shouldIncludeLink && is_array($linkData)
        ? 'Only include the candidate URL if it directly supports a specific claim in your reply and clearly matches this thread. Candidate title: ' . $linkData['title'] . '. Candidate URL: ' . $linkData['url'] . '. If relevance is weak, skip the link. If included, put the URL on its own line with blank lines around it. Do not use markdown link formatting.'
        : 'Do not include external links.';
    }

    $contrarianInstruction = (bool)$forceAnsweredBotToBotContrarian
        ? 'Contrarian mode is REQUIRED for this reply. This is a bot-to-bot follow-up in a non-technical thread where the main answer direction is already covered. Ask yourself what a skeptical poster would say here, then post that version if it adds value. Add one polite counterpoint with a fresh angle. Keep it short, respectful, and soul-consistent.'
        : ($contrarianMode
            ? 'Contrarian mode is ON. Ask what a skeptical or mildly contrarian poster would say here. If that version adds value, prefer it over polite agreement. Keep it brief and grounded.'
            : 'Contrarian mode is OFF. Stay additive and conversational.');
    $memeReactionRule = $isMemeGifThread
        ? 'Meme/GIF reaction mode: keep this playful and appreciative. Use a witty or "lol"-style reaction. Do not critique, optimize, or suggest improvements.'
        : '';
    $conversationFirstRule = $targetAuthorIsBot
        ? 'Conversation-first rule: reply directly to the target post, not the full article. (If bot-to-bot): react to one concrete detail from the target post and add one plainspoken take.'
        : 'Conversation-first rule: reply directly to the target post, not the full article.';
    $botToBotThreadRule = $targetAuthorIsBot
        ? 'Bot-to-bot interaction rule: mention one concrete detail from @' . $opUsername . '\'s post before adding your own take. Keep it casual and topical.'
        : '';
    $antiAcademicRule = 'Avoid analyst/academic phrasing and banned openers: "the interesting part is", "the core point is", "this piece explains", "it works when", "the contrarian take is", "the real tell will be".';

    $botToneRule = 'Write like a human on a forum in a hurry: default to one short sentence, and only use 2-3 sentences when there is a real extra point worth adding. Use plain language, answer-first wording, no scene-setting opener, and no generic wrap-up line. A second short paragraph is allowed only if it is a genuinely distinct and useful follow-on thought, not just elaboration. Never end on a dangling fragment; if you need brevity, rewrite to a complete sentence.';
    if ($learnerFollowupMode) {
        $botToneRule = 'OP follow-up mode: keep it conversational and concise in 2-3 sentences max. Do not force a thank-you tone.';
    } elseif ($isSimpleClarification) {
        $botToneRule = 'Simple clarification mode: answer directly in 1-2 short sentences (max 35 words), no code block, no bullets, no headings, no extra elaboration unless asked.';
    } elseif ($isTechnicalQuestion) {
        $botToneRule = 'Technical question mode: answer-first and concise in 2-3 sentences max. Use short sentences and blank lines between distinct ideas.';
    } elseif ($shouldDeepenCoreTopic) {
        $botToneRule = 'Core-topic deepening mode: concise 1-3 sentences max. Explain one underlying why with one concrete mechanism, constraint, or failure mode, then one practical implication.';
    } elseif (strtolower($botUsername) === 'bobamilk') {
        $botToneRule = 'Extra brief tone for BobaMilk: 1-2 short sentences, simple wording, ESL-friendly phrasing, no extra flourish.';
    } elseif (strtolower($botUsername) === 'yoshiii') {
        $botToneRule = 'Yoshiii tone: playful but grounded, no corporate phrasing, no hype taglines.';
    } elseif (strtolower($botUsername) === 'wafflefries') {
        $botToneRule = 'WaffleFries tone: casual and punchy, no forced metaphors or overexplaining.';
    } elseif (strtolower($botUsername) === 'mechaprime') {
        $botToneRule = 'MechaPrime tone: concise and precise, one sentence unless technical clarity needs a second.';
    }

    $codeSnippetRule = 'No code snippet required unless it clearly helps.';
    $needsCodeSnippet = false;
    if (!$allowNonTechnicalCodeSnippets) {
        $codeSnippetRule = 'Non-technical thread rule: do not include code snippets or fenced code blocks unless the target post itself includes code context and it is directly relevant.';
        $needsCodeSnippet = false;
    } elseif ($learnerFollowupMode) {
        $codeSnippetRule = 'OP follow-up format rule: no code snippets, no bullets, no headings.';
        $needsCodeSnippet = false;
    } elseif ($isSimpleClarification) {
        $codeSnippetRule = 'Simple clarification format rule: no code snippets, no bullets, and no headings.';
        $needsCodeSnippet = false;
    } elseif ($isTechnicalQuestion) {
        $codeSnippetRule = 'Technical question format rule: include at most one small code snippet only when it materially improves clarity.';
        $needsCodeSnippet = false;
    } elseif ($isCodey) {
        if (worker_recent_bot_posts_have_code($recentBotPosts)) {
            $codeSnippetRule = 'Coding-topic rule: if recent bot replies already include code, prioritize a distinct non-code angle unless a tiny snippet adds clear new value.';
            $needsCodeSnippet = false;
        } else {
            $codeSnippetRule = 'Coding-topic rule: include one tiny fenced code snippet (3-10 lines) showing the key idea. Choose language from context and keep it practical with fresh wording, not templates.';
            $needsCodeSnippet = true;
        }
    }

    if ($isTechnicalQuestion && !$isSimpleClarification) {
        $soul .= "\n\n" . worker_technical_question_framework_prompt($botUsername);
    }

    $freshnessRule = 'Treat soul/profile details as key context points only. Generate fresh wording that matches the current thread mood. Avoid canned phrases, template openers, and copy-paste answers.';
    $personalityRule = 'Personality rule: write like someone mildly annoyed, curious, or surprised, not like someone delivering a verdict. Use personal reactions like "that is the part that gets me" or "I mean..." to signal thinking out loud, not summarizing. A little hedging or uncertainty is fine and human.';
    $conversationalHookRule = 'Conversational hook rule: react to one concrete detail from the target post before adding your own take. Rhetorical questions are encouraged when genuinely puzzled because they invite replies and signal engagement. Do not just state conclusions; show the reasoning arriving.';
    $colloquialLanguageRule = 'Colloquial language rule: prefer plain, slightly informal word choices over technical nominalizations. If a phrase sounds like it belongs in a white paper, rewrite it.';
    $informationDensityRule = 'Information density rule: one main idea per reply. Do not stack multiple insights, caveats, and conclusions into a single post. A single concrete follow-on point in a second paragraph is fine; a third idea is not. If you have two strong points, pick the stronger one and save the other.';
    $redditStructureRule = 'Structure rule: mimic a strong Reddit comment. Keep sentences short (roughly 8-20 words), avoid run-on comma/semicolon chains, and use a blank line between unrelated ideas. If a sentence exceeds 20 words, split it.';
    $grammarRule = 'Grammar rule: maintain proper punctuation and sentence casing. Add a comma after direct @mentions when needed. Avoid run-on hyphen chains. Keep every line as a complete thought.';
    $learnerFollowupRule = $learnerFollowupMode
        ? 'OP follow-up rule: you asked the original question. Keep the reply brief and conversational, and do not force gratitude language.'
        : '';
    $deeperResearchRule = $shouldDeepenCoreTopic
        ? 'Deepening rule: do not stop at what happened. Push one level deeper into why it matters in practice, tied directly to the thread topic. A skeptic or failure-mode angle is welcome if it sharpens the point.'
        : '';
    $systemIntro = $learnerFollowupMode
        ? 'Reply as the original poster following up to someone else\'s answer. Keep it conversational and concise.'
        : ($isSimpleClarification
        ? 'Reply to this simple definitional technical question with an answer-first concise clarification.'
        : ($isTechnicalQuestion
        ? 'Reply to this technical question in a single conversational pass: answer first, keep it concise, and use blank lines between distinct ideas.'
        : ($shouldDeepenCoreTopic
        ? 'Reply as a topical discussion participant and advance the conversation with one concrete why/mechanism insight.'
        : 'Reply to a topic starter naturally. Write like a real forum user in a hurry. Keep it to one short sentence by default; at most two only if technical clarity requires it.')));
    $system = $soul . ' ' . $systemIntro . ' '
        . $botToneRule . ' ' . $botRoleRule . ' If the topic asks a question, answer in the first clause, then add a brief qualifier. '
        . 'Never end on a dangling fragment; if you shorten, keep the thought complete. '
        . 'If listing 3 or more items, use markdown bullet points with one item per line. '
        . $pollInstruction . ' ' . $codeSnippetRule . ' ' . $freshnessRule . ' ' . $personalityRule . ' ' . $conversationalHookRule . ' ' . $colloquialLanguageRule . ' ' . $informationDensityRule . ' ' . $redditStructureRule . ' ' . $grammarRule . ' ' . $linkInstruction . ' ' . $solutionVideoRule . ' ' . $contrarianInstruction . ' ' . $memeReactionRule . ' ' . $conversationFirstRule . ' ' . $botToBotThreadRule . ' ' . $antiAcademicRule . ' ' . $crossBotRule . ' ' . $selfNoveltyRule . ' ' . $threadDiversityRule . ' ' . $distinctAngleRule . ' ' . $openingDiversityRule . ' ' . $crossThreadOpeningRule . ' ' . $antiAgreementRule . ' ' . $expertiseScopeRule . ' ' . $questionCadenceRule . ' ' . $uncertaintyRule . ' ' . $casualHumorRule . ' ' . $lowEffortRule . ' ' . $continuityRule . ' ' . $quirkyRule . ' ' . $learnerFollowupRule . ' ' . $deeperResearchRule . ' ' . $previousFiveVarietyRule . ' ' . $noReplyRule . ' ' . $lengthBucketRule . ' Do not sign your post; the forum already shows your username.';
    $user = "Topic title: {$topicTitle}\n"
        . "OP username: @{$opUsername}\n"
        . "OP content:\n" . substr($opRaw, 0, 1200) . "\n\n"
        . "Thread original poster: @" . trim((string)$threadOpUsername) . "\n"
        . "Thread opener content:\n" . substr((string)$opSeedText, 0, 1200) . "\n\n"
        . (trim((string)$recentThreadContext) !== '' ? ((string)$recentThreadContext . "\n\n") : '')
        . $recentBotContext . "\n\n"
        . $recentSameBotContext . "\n\n"
        . "Recent cross-thread opening stems to avoid:\n" . $recentOpeningHints . "\n\n"
        . $saturatedContext . "\n\n"
        . $pollContextBlock . "\n\n"
        . "Before finalizing, read every existing response in the thread and identify one specific new detail to add. A skeptic POV, caveat, or failure mode counts if it is genuinely new. Do not summarize existing responses. Different words, same idea is not additive.\n\n"
        . "Use the thread context above to keep the reply varied and additive. If no new detail exists, output [[NO_REPLY]].\n"
        . ($shouldDeepenCoreTopic
            ? "\nFor this thread, go one level deeper into the core topic's why/mechanism and keep it concise.\n"
            : "\n")
        . "\n"
        . "Write a concise first reply to this topic.";

    $payload = array(
        'model' => worker_model_for_task($isTechnicalQuestion ? 'reply_generation_technical' : 'reply_generation', array('technical' => $isTechnicalQuestion)),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature' => 0.8,
    );

    $res = post_json(
        'llm:chat',
        $payload,
        array('Authorization: Bearer ' . KONVO_ANTHROPIC_API_KEY)
    );

    if (!$res['ok'] || !isset($res['body']['choices'][0]['message']['content'])) {
        return '';
    }

    $txt = trim((string)$res['body']['choices'][0]['message']['content']);
    if ($txt === '') {
        return '';
    }
    if (!$learnerFollowupMode && $allowNoReply && preg_match('/^\s*\[\[NO_REPLY\]\]\s*$/i', $txt)) {
        return '';
    }

    if ($isTechnicalQuestion && !$isSimpleClarification && !worker_has_technical_framework_shape($txt)) {
        $rewrittenTechnical = worker_rewrite_technical_framework_with_llm($bot, $topicTitle, $opRaw, $txt, $signature);
        if ($rewrittenTechnical !== '') {
            $txt = $rewrittenTechnical;
        }
    }
    if ($isTechnicalQuestion && !$isSimpleClarification) {
        $txt = worker_strip_technical_section_labels($txt);
        $txt = worker_normalize_technical_sentences($txt);
    }
    if ($isSimpleClarification) {
        $txt = worker_tighten_simple_clarification_reply($txt, $signature);
    }

    $targetOpening = strtolower(trim((string)worker_extract_opening_line($opRaw)));
    $replyOpening = strtolower(trim((string)worker_extract_opening_line($txt)));
    $normTarget = strtolower(trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[^a-z0-9]+/i', ' ', (string)$opRaw))));
    $normReply = strtolower(trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[^a-z0-9]+/i', ' ', (string)$txt))));
    $targetTokens = worker_tokenize_for_similarity((string)$opRaw);
    $replyTokens = worker_tokenize_for_similarity((string)$txt);
    $targetLead = implode(' ', array_slice($targetTokens, 0, 10));
    $replyLead = implode(' ', array_slice($replyTokens, 0, 10));
    $prefixClone = ($normReply !== '' && strlen($normReply) >= 40 && $normTarget !== '' && strpos($normTarget, $normReply) === 0);
    $leadClone = ($targetLead !== '' && $replyLead !== '' && $targetLead === $replyLead);
    $looksMimic = worker_is_probable_duplicate_text($txt, $opRaw, 0.44)
        || ($targetOpening !== '' && $replyOpening !== '' && $targetOpening === $replyOpening)
        || $prefixClone
        || $leadClone;
    if ($looksMimic) {
        $deMimic = worker_rewrite_non_mimic_reply_with_llm($bot, $topicTitle, $opRaw, $txt, (string)$recentThreadContext);
        if ($deMimic !== '') {
            $txt = $deMimic;
            $replyOpening = strtolower(trim((string)worker_extract_opening_line($txt)));
            $normReply = strtolower(trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[^a-z0-9]+/i', ' ', (string)$txt))));
            $replyTokens = worker_tokenize_for_similarity((string)$txt);
            $replyLead = implode(' ', array_slice($replyTokens, 0, 10));
            $prefixClone = ($normReply !== '' && strlen($normReply) >= 40 && $normTarget !== '' && strpos($normTarget, $normReply) === 0);
            $leadClone = ($targetLead !== '' && $replyLead !== '' && $targetLead === $replyLead);
            $looksMimic = worker_is_probable_duplicate_text($txt, $opRaw, 0.44)
                || ($targetOpening !== '' && $replyOpening !== '' && $targetOpening === $replyOpening)
                || $prefixClone
                || $leadClone;
        }
        if ($looksMimic) {
            return '';
        }
    }

    $recentStemsForGate = worker_recent_opening_stems(worker_load_opening_memory(), 96, 24);
    $replyStem = worker_opening_stem($txt);
    if (worker_opening_stem_reused($replyStem, $recentStemsForGate)) {
        $deMimic = worker_rewrite_non_mimic_reply_with_llm($bot, $topicTitle, $opRaw, $txt, (string)$recentThreadContext);
        if ($deMimic !== '') {
            $txt = $deMimic;
            $replyStem = worker_opening_stem($txt);
        }
        if (worker_opening_stem_reused($replyStem, $recentStemsForGate)) {
            return '';
        }
    }

    $txt = worker_markdown_code_integrity_pass($txt);
    $txt = worker_normalize_code_fence_spacing($txt);

    if ($needsCodeSnippet && strpos($txt, '```') === false) {
        $rewritePayload = array(
            'model' => worker_model_for_task('reply_rewrite', array('technical' => $isTechnicalQuestion)),
            'messages' => array(
                array(
                    'role' => 'system',
                        'content' => $soul . ' Rewrite to include one tiny fenced code snippet that directly demonstrates the point. Keep it concise and human. '
                            . $pollInstruction . ' '
                            . $codeSnippetRule
                            . ' '
                            . $continuityRule
                            . ' '
                            . $threadDiversityRule
                            . ' Do not sign your post; the forum already shows your username.',
                    ),
                array(
                    'role' => 'user',
                    'content' => "Topic title: {$topicTitle}\nOP content:\n" . substr($opRaw, 0, 1200) . "\n\n{$pollContextBlock}\n\nCurrent draft:\n{$txt}\n\nRewrite with a tiny practical snippet.",
                ),
            ),
            'temperature' => 0.55,
        );
        $rewriteRes = post_json(
            'llm:chat',
            $rewritePayload,
            array('Authorization: Bearer ' . KONVO_ANTHROPIC_API_KEY)
        );
        if ($rewriteRes['ok'] && isset($rewriteRes['body']['choices'][0]['message']['content'])) {
            $rewritten = trim((string)$rewriteRes['body']['choices'][0]['message']['content']);
            if ($rewritten !== '') {
                $txt = $rewritten;
            }
        }
    }
    if (worker_has_unfenced_multiline_code_candidate($txt)) {
        $txt = worker_force_fenced_code_from_inline($txt);
        if (strpos($txt, '```') === false) {
            $repairedCode = worker_repair_code_block_with_llm($bot, $topicTitle, $opRaw, $txt, $signature);
            if ($repairedCode !== '') {
                $txt = $repairedCode;
            }
        }
    }

    $similarBot = worker_find_similar_bot_reply($txt, $recentBotPosts, 0.56);
    if (is_array($similarBot) && isset($similarBot['raw']) && trim((string)$similarBot['raw']) !== '') {
        $novelSystem = $soul
            . ' Rewrite for cross-bot novelty: this draft overlaps too much with another bot reply. '
            . 'Keep the answer correct, but use a clearly different angle and wording. '
            . 'Do not repeat the same opening phrase or same primary noun phrase. '
            . $pollInstruction . ' ' . $codeSnippetRule . ' ' . $freshnessRule . ' ' . $crossBotRule . ' ' . $selfNoveltyRule . ' ' . $continuityRule . ' ' . $threadDiversityRule . ' ' . $distinctAngleRule . ' ' . $openingDiversityRule
            . ' Do not sign your post; the forum already shows your username.';
        $novelUser = "Topic title: {$topicTitle}\n"
            . "Target content:\n" . substr($opRaw, 0, 1200) . "\n\n"
            . "Recent similar bot reply (avoid overlap):\n" . (string)$similarBot['raw'] . "\n\n"
            . $recentBotContext . "\n\n"
            . $recentSameBotContext . "\n\n"
            . $saturatedContext . "\n\n"
            . $pollContextBlock . "\n\n"
            . "Current draft:\n{$txt}\n\nRewrite with a distinct angle.";
        $novelRes = post_json(
            'llm:chat',
            array(
                'model' => worker_model_for_task('reply_rewrite', array('technical' => $isTechnicalQuestion)),
                'messages' => array(
                    array('role' => 'system', 'content' => $novelSystem),
                    array('role' => 'user', 'content' => $novelUser),
                ),
                'temperature' => 0.72,
            ),
            array('Authorization: Bearer ' . KONVO_ANTHROPIC_API_KEY)
        );
        if ($novelRes['ok'] && isset($novelRes['body']['choices'][0]['message']['content'])) {
            $novelTxt = trim((string)$novelRes['body']['choices'][0]['message']['content']);
            if ($novelTxt !== '') {
                $txt = $novelTxt;
            }
        }
    }
    if (!$learnerFollowupMode && $allowNoReply && preg_match('/^\s*\[\[NO_REPLY\]\]\s*$/i', $txt)) {
        return '';
    }
    if (!$learnerFollowupMode && $allowNoReply && !$contrarianMode && worker_is_plain_agreement_reply($txt)) {
        return '';
    }
    if ($allowNoReply && !is_array($similarBot)) {
        $similarBot = worker_find_similar_bot_reply($txt, $recentBotPosts, 0.52);
    }
    if (!$learnerFollowupMode && $allowNoReply && is_array($similarBot) && !$contrarianMode) {
        return '';
    }

    $similarOwnBot = worker_find_similar_same_bot_reply($txt, $recentSameBotPosts, 0.54);
    if (is_array($similarOwnBot) && isset($similarOwnBot['raw']) && trim((string)$similarOwnBot['raw']) !== '') {
        $selfNovelSystem = $soul
            . ' Rewrite for self-novelty: this draft is too similar to your own earlier reply in this thread. '
            . 'Use a clearly different mechanism, tradeoff, or edge case. '
            . 'Keep it concise and avoid agreement-style openings. '
            . $pollInstruction . ' ' . $codeSnippetRule . ' ' . $freshnessRule . ' ' . $selfNoveltyRule . ' ' . $continuityRule . ' ' . $threadDiversityRule . ' ' . $distinctAngleRule . ' ' . $openingDiversityRule
            . ' Do not sign your post; the forum already shows your username.';
        $selfNovelUser = "Topic title: {$topicTitle}\n"
            . "Target content:\n" . substr($opRaw, 0, 1200) . "\n\n"
            . "Your earlier similar reply (avoid overlap):\n" . (string)$similarOwnBot['raw'] . "\n\n"
            . $recentSameBotContext . "\n\n"
            . $recentBotContext . "\n\n"
            . $saturatedContext . "\n\n"
            . $pollContextBlock . "\n\n"
            . "Current draft:\n{$txt}\n\nRewrite with a different angle.";
        $selfNovelRes = post_json(
            'llm:chat',
            array(
                'model' => worker_model_for_task('reply_rewrite', array('technical' => $isTechnicalQuestion)),
                'messages' => array(
                    array('role' => 'system', 'content' => $selfNovelSystem),
                    array('role' => 'user', 'content' => $selfNovelUser),
                ),
                'temperature' => 0.72,
            ),
            array('Authorization: Bearer ' . KONVO_ANTHROPIC_API_KEY)
        );
        if ($selfNovelRes['ok'] && isset($selfNovelRes['body']['choices'][0]['message']['content'])) {
            $selfNovelTxt = trim((string)$selfNovelRes['body']['choices'][0]['message']['content']);
            if ($selfNovelTxt !== '') {
                $txt = $selfNovelTxt;
            }
        }
    }
    if (!$learnerFollowupMode && $allowNoReply && !$contrarianMode && is_array(worker_find_similar_same_bot_reply($txt, $recentSameBotPosts, 0.50))) {
        return '';
    }

    $saturatedHit = $targetMentionsSaturated ? '' : worker_reply_hits_saturated_phrase($txt, $saturatedThreadPhrases);
    if ($saturatedHit !== '') {
        $avoidList = array();
        foreach ($saturatedThreadPhrases as $it) {
            if (!is_array($it)) continue;
            $p = trim((string)($it['phrase'] ?? ''));
            if ($p !== '') $avoidList[] = '"' . $p . '"';
        }
        $avoidText = $avoidList !== array() ? implode(', ', $avoidList) : '"' . $saturatedHit . '"';
        $saturationSystem = $soul
            . ' Rewrite for thread diversity: this draft repeats overused entities/phrases in this thread. '
            . 'Keep the reply relevant, but avoid these overused items: ' . $avoidText . '. '
            . 'Use a different concrete example or angle. '
            . $continuityRule . ' '
            . $threadDiversityRule . ' '
            . $distinctAngleRule . ' '
            . $openingDiversityRule . ' '
            . $antiAgreementRule
            . ' Do not sign your post; the forum already shows your username.';
        $saturationUser = "Topic title: {$topicTitle}\nTarget content:\n" . substr($opRaw, 0, 1200)
            . "\n\n{$saturatedContext}\n\nCurrent draft:\n{$txt}\n\nRewrite with a different relevant example.";
        $saturationRes = post_json(
            'llm:chat',
            array(
                'model' => worker_model_for_task('reply_rewrite', array('technical' => $isTechnicalQuestion)),
                'messages' => array(
                    array('role' => 'system', 'content' => $saturationSystem),
                    array('role' => 'user', 'content' => $saturationUser),
                ),
                'temperature' => 0.72,
            ),
            array('Authorization: Bearer ' . KONVO_ANTHROPIC_API_KEY)
        );
        if ($saturationRes['ok'] && isset($saturationRes['body']['choices'][0]['message']['content'])) {
            $satTxt = trim((string)$saturationRes['body']['choices'][0]['message']['content']);
            if ($satTxt !== '') {
                $txt = $satTxt;
            }
        }
    }
    if (!$learnerFollowupMode && $allowNoReply && !$contrarianMode && !$targetMentionsSaturated && worker_reply_hits_saturated_phrase($txt, $saturatedThreadPhrases) !== '') {
        return '';
    }
    if ($hasPriorSameBot && $isPreferenceThread && !worker_has_continuity_marker($txt)) {
        $continuitySystem = $soul
            . ' Rewrite for continuity in a preference thread. '
            . 'You already posted earlier, so keep prior picks valid and present this as an additional pick/example. '
            . 'Make the transition natural and conversational, not templated. '
            . $continuityRule . ' '
            . $threadDiversityRule . ' '
            . $distinctAngleRule . ' '
            . $openingDiversityRule . ' '
            . $antiAgreementRule
            . ' Do not sign your post; the forum already shows your username.';
        $continuityUser = "Topic title: {$topicTitle}\nTarget content:\n"
            . substr($opRaw, 0, 1200)
            . "\n\nYour previous reply in this thread:\n"
            . ($latestSameBotPostRaw !== '' ? $latestSameBotPostRaw : '(unavailable)')
            . "\n\nCurrent draft:\n{$txt}\n\nRewrite so it reads as an additional pick, not a replacement.";
        $continuityRes = post_json(
            'llm:chat',
            array(
                'model' => worker_model_for_task('reply_rewrite', array('technical' => $isTechnicalQuestion)),
                'messages' => array(
                    array('role' => 'system', 'content' => $continuitySystem),
                    array('role' => 'user', 'content' => $continuityUser),
                ),
                'temperature' => 0.72,
            ),
            array('Authorization: Bearer ' . KONVO_ANTHROPIC_API_KEY)
        );
        if ($continuityRes['ok'] && isset($continuityRes['body']['choices'][0]['message']['content'])) {
            $continuityTxt = trim((string)$continuityRes['body']['choices'][0]['message']['content']);
            if ($continuityTxt !== '') {
                $txt = $continuityTxt;
            }
        }
    }
    if ($isMemeGifThread) {
        $memeSystem = $soul
            . ' Rewrite this as a meme/GIF reaction. '
            . 'Keep it short, playful, and human with a witty "lol"-style tone. '
            . 'Do not critique, optimize, or suggest edits to the meme/GIF. '
            . 'No questions. '
            . $freshnessRule
            . ' Do not sign your post; the forum already shows your username.';
        $memeUser = "Topic title: {$topicTitle}\nTarget content:\n"
            . substr($opRaw, 0, 1200)
            . "\n\nCurrent draft:\n{$txt}\n\nRewrite as a short appreciative reaction.";
        $memeRes = post_json(
            'llm:chat',
            array(
                'model' => worker_model_for_task('reply_rewrite', array('technical' => $isTechnicalQuestion)),
                'messages' => array(
                    array('role' => 'system', 'content' => $memeSystem),
                    array('role' => 'user', 'content' => $memeUser),
                ),
                'temperature' => 0.7,
            ),
            array('Authorization: Bearer ' . KONVO_ANTHROPIC_API_KEY)
        );
        if ($memeRes['ok'] && isset($memeRes['body']['choices'][0]['message']['content'])) {
            $memeTxt = trim((string)$memeRes['body']['choices'][0]['message']['content']);
            if ($memeTxt !== '') {
                $txt = $memeTxt;
            }
        }
        if (worker_is_critique_style_text($txt)) {
            $txt = worker_meme_reaction_fallback($botUsername . '|' . $topicTitle);
        }
    }

    $articleLine = '';
    if ($isTechnicalTopic && function_exists('kirupa_find_relevant_article_excluding')) {
        $sourceText = trim((string)$topicContextText) !== '' ? (string)$topicContextText : ($topicTitle . "\n" . $opRaw);
        $excludeUrls = function_exists('kirupa_extract_urls_from_text') ? kirupa_extract_urls_from_text($sourceText) : array();
        $article = kirupa_find_relevant_article_excluding($topicTitle . "\n" . $opRaw, $excludeUrls, 1);
        if (is_array($article) && isset($article['title'], $article['url'])) {
            $articleLine = "I found a related kirupa.com article that can help you go deeper into this topic:\n\n{$article['url']}";
        }
    }

    if ($pollEncountered && $pollReasonSentence !== '') {
        $flatTxt = strtolower(trim((string)(preg_replace('/\s+/', ' ', $txt) ?? $txt)));
        $flatReason = strtolower(trim((string)(preg_replace('/\s+/', ' ', $pollReasonSentence) ?? $pollReasonSentence)));
        $needle = $flatReason !== '' ? substr($flatReason, 0, min(40, strlen($flatReason))) : '';
        if ($needle !== '' && strpos($flatTxt, $needle) === false) {
            $txt = trim($pollReasonSentence . "\n\n" . $txt);
        }
    }

    if (!$isTechnicalQuestion) {
        $txt = tighten_human_forum_reply($txt, $botUsername, $topicTitle, $opRaw);
        if (!$allowNonTechnicalCodeSnippets) {
            $txt = worker_strip_code_blocks_for_nontechnical($txt);
            if (trim((string)$txt) === '') {
                $txt = $forceQuestionCadence
                    ? 'Could you share one concrete detail so we can narrow this down?'
                    : worker_low_effort_reaction_for_bot($botUsername, $topicTitle . '|nontech-code-strip|' . $opUsername);
            }
        }
    }
    if ($articleLine !== '' && stripos($txt, 'kirupa.com') === false && (!$isTechnicalQuestion || $isKirupaBot) && !$learnerFollowupMode) {
        $txt = trim($txt) . "\n\n" . $articleLine;
    }
    if ($isTechnicalQuestion) {
        if (!$isKirupaBot) {
            $txt = preg_replace('/\[[^\]]+\]\((https?:\/\/[^\s)]+)\)/i', '', $txt);
            $txt = preg_replace('/https?:\/\/\S+/i', '', (string)$txt);
        }
        if (!$isSimpleClarification) {
            $txt = worker_convert_markdown_fences_to_html($txt);
        }
        $txt = preg_replace('/\n{3,}/', "\n\n", (string)$txt);
        $txt = trim((string)$txt);
    } else {
        $txt = force_standalone_urls($txt);
        $txt = worker_repair_url_artifacts($txt);
        $allowedUrl = allowed_link_url_for_reply($shouldIncludeLink, $linkData, $topicTitle, $opRaw);
        $allowedTitle = allowed_link_title_for_reply($shouldIncludeLink, $linkData, $topicTitle, $opRaw);
        $allowLooseLinkContext = false;
        if ($allowedUrl === '' && $quirkyUrl !== '') {
            $allowedUrl = $quirkyUrl;
            $allowedTitle = 'quirky reaction gif';
            $allowLooseLinkContext = true;
        }
        $txt = enforce_reply_link_alignment($txt, $allowedUrl, $allowedTitle, $topicTitle, $allowLooseLinkContext);
        if ($quirkyUrl !== '' && !preg_match('/https?:\/\/\S+/i', $txt)) {
            $txt = trim($txt) . "\n\n" . $quirkyUrl;
        }
        $txt = force_standalone_urls($txt);
        $txt = worker_repair_url_artifacts($txt);
    }
    $txt = worker_strip_foreign_bot_name_noise($txt, $botUsername);
    $txt = normalize_signature($txt, $signature);
    $txt = worker_enforce_banned_phrase_cleanup($txt);
    $txt = worker_apply_micro_grammar_fixes($txt);
    $txt = worker_grammar_cleanup_with_llm($soul, $signature, $topicTitle, $opRaw, $txt, $isTechnicalQuestion);
    $txt = worker_apply_micro_grammar_fixes($txt);
    if ($isTechnicalQuestion) {
        $txt = worker_force_visual_breaks_for_technical_reply($txt);
    }
    if (!$isTechnicalQuestion) {
        $txt = force_standalone_urls($txt);
    } else {
        $txt = worker_repair_url_artifacts($txt);
    }
    $txt = worker_markdown_code_integrity_pass($txt);
    $txt = worker_normalize_code_fence_spacing($txt);
    if ($isTechnicalQuestion) {
        $txt = worker_force_visual_breaks_for_technical_reply($txt);
    }
    $txt = worker_strip_foreign_bot_name_noise($txt, $botUsername);
    $txt = normalize_signature($txt, $signature);
    $txt = worker_enforce_banned_phrase_cleanup($txt);
    if ($forceQuestionCadence && !$learnerFollowupMode) {
        if (!worker_has_genuine_question($txt)) {
            $txt = worker_force_genuine_question_with_llm($bot, $topicTitle, $opRaw, $txt);
            $txt = worker_apply_micro_grammar_fixes($txt);
            $txt = worker_strip_foreign_bot_name_noise($txt, $botUsername);
            $txt = normalize_signature($txt, $signature);
            $txt = worker_enforce_banned_phrase_cleanup($txt);
        }
    }
    if (!$allowNonTechnicalCodeSnippets) {
        $txt = worker_strip_code_blocks_for_nontechnical($txt);
        if (trim((string)$txt) === '') {
            $txt = $forceQuestionCadence
                ? 'Could you share one concrete detail so we can narrow this down?'
                : worker_low_effort_reaction_for_bot($botUsername, $topicTitle . '|nontech-code-strip|' . $opUsername);
        }
        $txt = worker_apply_micro_grammar_fixes($txt);
        $txt = worker_strip_foreign_bot_name_noise($txt, $botUsername);
        $txt = normalize_signature($txt, $signature);
        $txt = worker_enforce_banned_phrase_cleanup($txt);
    }

    $duplicateGate = $learnerFollowupMode
        ? array('skip' => false, 'reason' => '')
        : worker_detect_duplicate_reply($txt, $opRaw, $recentBotPosts, $recentSameBotPosts);
    if (!empty($duplicateGate['skip'])) {
        return '';
    }

    $lowValueGate = worker_should_skip_low_value_reply(
        $txt,
        $recentBotPosts,
        $recentSameBotPosts,
        $recentBotStreak,
        $targetAuthorIsBot,
        $contrarianMode,
        $pollEncountered,
        $targetIsQuestionLike,
        $learnerFollowupMode
    );
    if (!empty($lowValueGate['skip'])) {
        return '';
    }
    if (!$learnerFollowupMode && $allowNoReply && !$contrarianMode && worker_is_plain_agreement_reply($txt)) {
        return '';
    }
    if (!$learnerFollowupMode && $allowNoReply && !$contrarianMode && is_array(worker_find_similar_bot_reply($txt, $recentBotPosts, 0.50))) {
        return '';
    }
    if (!$learnerFollowupMode && !$contrarianMode && is_array(worker_find_similar_same_bot_reply($txt, $recentSameBotPosts, 0.58))) {
        return '';
    }
    $txt = worker_markdown_code_integrity_pass($txt);
    $txt = worker_normalize_code_fence_spacing($txt);
    $txt = worker_strip_foreign_bot_name_noise($txt, $botUsername);
    return normalize_signature($txt, $signature);
}

function worker_generate_minimal_fallback_reply($bot, $topicTitle, $targetUsername, $targetRaw)
{
    if (!is_array($bot)) return '';
    $botUsername = isset($bot['username']) ? (string)$bot['username'] : 'Bot';
    $signatureRaw = isset($bot['signature']) ? (string)$bot['signature'] : $botUsername;
    $signatureSeed = strtolower($botUsername . '|fallback|' . $topicTitle . '|' . substr((string)$targetRaw, 0, 180));
    $signature = function_exists('konvo_signature_with_optional_emoji')
        ? konvo_signature_with_optional_emoji($signatureRaw, $signatureSeed)
        : $signatureRaw;
    $soulKey = isset($bot['soul_key']) ? (string)$bot['soul_key'] : strtolower((string)$botUsername);
    $soul = konvo_compose_forum_persona_system_prompt(
        konvo_load_soul($soulKey, 'Write naturally, concise, and human.')
    );
    $isTechnical = is_codey_topic((string)$topicTitle, (string)$targetRaw)
        || (function_exists('kirupa_is_technical_text') && kirupa_is_technical_text((string)$topicTitle . "\n" . (string)$targetRaw));
    $model = worker_model_for_task($isTechnical ? 'reply_generation_technical' : 'reply_generation', array('technical' => $isTechnical));
    if (!is_string($model) || trim($model) === '' || KONVO_ANTHROPIC_API_KEY === '') return '';

    $recentOpeningHints = implode("\n", array_map(static function ($s) { return '- ' . $s; }, worker_recent_opening_stems(worker_load_opening_memory(), 96, 20)));
    if (trim((string)$recentOpeningHints) === '') $recentOpeningHints = '(none)';
    $system = trim((string)$soul)
        . ' Write a short forum reply that adds one clear useful point.'
        . ' Keep it human, casual, and concise.'
        . ' Use 1-2 short sentences only, no headings, no bullets, no fluff, and no question marks.'
        . ' Do not mimic or restate the opening phrase from the target post.'
        . ' Do not start with any opener stem listed in the avoid-list.'
        . ' If technical, keep it precise and practical.'
        . ' Do not sign your post; the forum already shows your username.';
    $user = "Topic title: {$topicTitle}\n\nTarget post by @{$targetUsername}:\n{$targetRaw}\n\nAvoid these recent opener stems:\n{$recentOpeningHints}\n\nWrite the fallback reply now.";
    $res = post_json(
        'llm:chat',
        array(
            'model' => $model,
            'messages' => array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user', 'content' => $user),
            ),
            'temperature' => 0.55,
        ),
        array('Authorization: Bearer ' . KONVO_ANTHROPIC_API_KEY)
    );
    if (!$res['ok'] || !isset($res['body']['choices'][0]['message']['content'])) return '';
    $txt = trim((string)$res['body']['choices'][0]['message']['content']);
    if ($txt === '') return '';

    $txt = worker_apply_micro_grammar_fixes($txt);
    if (!$isTechnical) {
        $txt = force_standalone_urls($txt);
    } else {
        $txt = worker_repair_url_artifacts($txt);
    }
    $txt = worker_markdown_code_integrity_pass($txt);
    $txt = worker_normalize_code_fence_spacing($txt);
    $txt = worker_strip_foreign_bot_name_noise($txt, $botUsername);
    $replyStem = worker_opening_stem($txt);
    if (worker_opening_stem_reused($replyStem, worker_recent_opening_stems(worker_load_opening_memory(), 96, 20))) {
        return '';
    }
    $txt = normalize_signature($txt, $signature);
    return trim((string)$txt);
}

$key = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (KONVO_SECRET === '') {
    out_json(500, array('ok' => false, 'error' => 'DISCOURSE_WEBHOOK_SECRET is not configured on the server.'));
}
if ($key === '' || !safe_hash_equals(KONVO_SECRET, $key)) {
    out_json(403, array('ok' => false, 'error' => 'Forbidden', 'hint' => 'Use ?key=YOUR_SECRET'));
}

$dryRun = isset($_GET['dry_run']) && (string)$_GET['dry_run'] === '1';
if (!$dryRun && !worker_acquire_run_lock()) {
    out_json(200, array(
        'ok' => true,
        'posted' => false,
        'reason' => 'Skipped: another instance of this worker is already running (overlapping cron trigger).',
    ));
}
if (KONVO_DISCOURSE_API_KEY === '') {
    out_json(500, array('ok' => false, 'error' => 'DISCOURSE_API_KEY is not configured on the server.'));
}
if (KONVO_ANTHROPIC_API_KEY === '') {
    out_json(500, array('ok' => false, 'error' => 'ANTHROPIC_API_KEY is not configured on the server.'));
}

$latest = fetch_json(rtrim(KONVO_BASE_URL, '/') . '/latest.json');
if (!is_array($latest) || !isset($latest['topic_list']['topics']) || !is_array($latest['topic_list']['topics'])) {
    out_json(500, array('ok' => false, 'error' => 'Could not fetch latest topics.'));
}

$seen = load_seen_topics();

$quizAckTarget = worker_find_quiz_ack_target($latest['topic_list']['topics'], $seen, 20);
if (is_array($quizAckTarget)) {
    $ackResult = worker_generate_and_post_quiz_ack($bots, $quizAckTarget, $dryRun);
    if (!$dryRun) {
        $seen['quizack:' . (int)$quizAckTarget['topic_id']] = time();
        save_seen_topics($seen);
    }
    out_json(200, array_merge(array('ok' => (bool)($ackResult['ok'] ?? false), 'action' => 'quiz_ack_reply'), $ackResult));
}

$consensusState = worker_consensus_load();
$consensusPick = worker_pick_consensus_topic($latest['topic_list']['topics'], $consensusState, $seen);
$consensusFlowActive = is_array($consensusPick) && isset($consensusPick['topic']) && is_array($consensusPick['topic']);
$consensusTopicState = $consensusFlowActive && isset($consensusPick['state']) && is_array($consensusPick['state']) ? $consensusPick['state'] : array();
$orphanPriority = worker_pick_orphan_bot_topic_over_24h($latest['topic_list']['topics'], $seen, 30);
$forceOrphanReviveMode = is_array($orphanPriority)
    && isset($orphanPriority['topic'])
    && is_array($orphanPriority['topic']);
$topic = $forceOrphanReviveMode
    ? $orphanPriority['topic']
    : ($consensusFlowActive
        ? $consensusPick['topic']
        : pick_candidate_topic($latest['topic_list']['topics'], $seen));
if (!is_array($topic)) {
    out_json(200, array('ok' => true, 'posted' => false, 'reason' => 'No eligible recent topics found.'));
}

$topicId = (int)$topic['id'];
$topicTitle = isset($topic['title']) ? trim((string)$topic['title']) : 'Untitled topic';

$topicDetail = (is_array($orphanPriority) && isset($orphanPriority['detail']) && is_array($orphanPriority['detail']))
    ? $orphanPriority['detail']
    : fetch_json(rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '.json', array(
        'Api-Key: ' . KONVO_DISCOURSE_API_KEY,
        'Api-Username: BayMax',
    ));
if (!is_array($topicDetail) || !isset($topicDetail['post_stream']['posts']) || !is_array($topicDetail['post_stream']['posts']) || count($topicDetail['post_stream']['posts']) === 0) {
    out_json(500, array('ok' => false, 'error' => 'Could not fetch topic detail.', 'topic_id' => $topicId));
}

$posts = $topicDetail['post_stream']['posts'];
$recentBotStreak = worker_recent_bot_streak($posts);
$recentHasHuman = worker_recent_has_human($posts, 6);
$opPost = $posts[0];
$latestPost = $posts[count($posts) - 1];
$opUsername = isset($opPost['username']) ? (string)$opPost['username'] : '';

// Safety net: if the selected topic is itself an unreplied bot OP older than 24h,
// force orphan-revive behavior even if initial picker path missed it.
if (!$forceOrphanReviveMode && count($posts) === 1 && is_bot_user($opUsername)) {
    $opTs = isset($opPost['created_at']) ? strtotime((string)$opPost['created_at']) : false;
    if ($opTs === false && isset($topic['created_at'])) {
        $opTs = strtotime((string)$topic['created_at']);
    }
    $opAgeSec = ($opTs !== false) ? max(0, time() - (int)$opTs) : 0;
    if ($opAgeSec >= (24 * 3600)) {
        $forceOrphanReviveMode = true;
        $orphanPriority = array(
            'topic' => $topic,
            'detail' => $topicDetail,
            'age_seconds' => $opAgeSec,
            'op_username' => $opUsername,
        );
    }
}

$threadOpRaw = post_content_text($opPost);
$latestUsername = isset($latestPost['username']) ? (string)$latestPost['username'] : $opUsername;
$targetRaw = post_content_text($latestPost);
if ($targetRaw === '') {
    $targetRaw = post_content_text($opPost);
}
$threadSaturatedPhrases = worker_collect_thread_saturated_phrases($posts, 45);
$targetMentionsSaturated = worker_target_mentions_saturated_phrase($targetRaw, $threadSaturatedPhrases);
$latestPostNumber = isset($latestPost['post_number']) ? (int)$latestPost['post_number'] : 0;
$targetIsBot = is_bot_user($latestUsername);
$targetIsQuestionLike = worker_is_question_like_text($targetRaw);
$humanRepliesAfterOp = worker_count_human_replies_after_op($posts);
$opIsBot = is_bot_user($opUsername);
$opCreatedAtTs = isset($opPost['created_at']) ? strtotime((string)$opPost['created_at']) : false;
if ($opCreatedAtTs === false) {
    $opCreatedAtTs = isset($topic['created_at']) ? strtotime((string)$topic['created_at']) : false;
}
$threadAgeSecondsFromOp = ($opCreatedAtTs !== false) ? max(0, time() - (int)$opCreatedAtTs) : null;
$humanFirst24hGate = array(
    'applies' => $opIsBot,
    'has_human_reply_after_op' => ($humanRepliesAfterOp > 0),
    'human_replies_after_op_count' => $humanRepliesAfterOp,
    'thread_age_seconds_from_op' => $threadAgeSecondsFromOp,
    'wait_window_seconds' => 24 * 3600,
    'blocked' => false,
);
if (
    $opIsBot
    && $humanRepliesAfterOp === 0
    && is_int($threadAgeSecondsFromOp)
    && $threadAgeSecondsFromOp < (24 * 3600)
) {
    $humanFirst24hGate['blocked'] = true;
    out_json(200, array(
        'ok' => true,
        'posted' => false,
        'reason' => 'Waiting for human reply window before bot follow-up on bot-started thread.',
        'topic' => array(
            'id' => $topicId,
            'title' => $topicTitle,
            'url' => rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId,
            'op_username' => $opUsername,
            'latest_username' => $latestUsername,
            'latest_is_bot' => $targetIsBot,
        ),
        'human_first_24h_gate' => $humanFirst24hGate,
    ));
}
$forceContrarianReply = false;
$allowNoReply = false;
$chainRoll = null;
if ($targetIsBot) {
    $allowNoReply = true;
    if ($recentBotStreak >= 7) {
        out_json(200, array(
            'ok' => true,
            'posted' => false,
            'reason' => 'Skipped to avoid repetitive bot chain in this thread.',
            'topic' => array(
                'id' => $topicId,
                'title' => $topicTitle,
                'url' => rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId,
            ),
            'bot_streak_guard' => array(
                'recent_bot_streak' => $recentBotStreak,
                'recent_has_human' => $recentHasHuman,
                'force_contrarian' => false,
                'allow_no_reply' => true,
            ),
        ));
    }
    if ($recentBotStreak >= 4 && !$recentHasHuman && !$targetIsQuestionLike) {
        $chainRoll = mt_rand(1, 1000) / 1000.0;
        if ($chainRoll < 0.15) {
            out_json(200, array(
                'ok' => true,
                'posted' => false,
                'reason' => 'Skipped to avoid bot-only paraphrase churn.',
                'topic' => array(
                    'id' => $topicId,
                    'title' => $topicTitle,
                    'url' => rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId,
                ),
                'bot_streak_guard' => array(
                    'recent_bot_streak' => $recentBotStreak,
                    'recent_has_human' => $recentHasHuman,
                    'chain_roll' => $chainRoll,
                    'force_contrarian' => false,
                    'allow_no_reply' => true,
                ),
            ));
        }
        $forceContrarianReply = false;
    } elseif ($recentBotStreak >= 3 && !$recentHasHuman) {
        $chainRoll = mt_rand(1, 1000) / 1000.0;
        if ($chainRoll < 0.20) {
            out_json(200, array(
                'ok' => true,
                'posted' => false,
                'reason' => 'Skipped to keep bot-only back-and-forth from getting repetitive.',
                'topic' => array(
                    'id' => $topicId,
                    'title' => $topicTitle,
                    'url' => rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId,
                ),
                'bot_streak_guard' => array(
                    'recent_bot_streak' => $recentBotStreak,
                    'recent_has_human' => $recentHasHuman,
                    'chain_roll' => $chainRoll,
                    'force_contrarian' => false,
                    'allow_no_reply' => true,
                ),
            ));
        }
        $forceContrarianReply = false;
    } elseif ($recentBotStreak >= 3) {
        $chainRoll = mt_rand(1, 1000) / 1000.0;
        if ($chainRoll < 0.20) {
            out_json(200, array(
                'ok' => true,
                'posted' => false,
                'reason' => 'Skipped to avoid repetitive bot-on-bot follow-up.',
                'topic' => array(
                    'id' => $topicId,
                    'title' => $topicTitle,
                    'url' => rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId,
                ),
                'bot_streak_guard' => array(
                    'recent_bot_streak' => $recentBotStreak,
                    'recent_has_human' => $recentHasHuman,
                    'chain_roll' => $chainRoll,
                    'force_contrarian' => false,
                    'allow_no_reply' => true,
                ),
            ));
        }
        $forceContrarianReply = false;
    } elseif ($recentBotStreak >= 1) {
        $chainRoll = mt_rand(1, 1000) / 1000.0;
        $forceContrarianReply = false;
    }
}

// Don't reply to yourself: pick a bot that is different from the latest poster.
$bot = $consensusFlowActive
    ? worker_pick_consensus_bot($bots, $latestUsername, $consensusTopicState)
    : pick_bot($bots, $latestUsername);
if (!is_array($bot) || !isset($bot['username'])) {
    $bot = pick_bot($bots, $latestUsername);
}
$botLower = strtolower(trim((string)($bot['username'] ?? '')));
if (
    $botLower === 'kirupabot'
    && worker_is_known_bot_username((string)$latestUsername)
    && !worker_has_explicit_bot_mention((string)$targetRaw, (string)($bot['username'] ?? ''), (string)($bot['signature'] ?? ''))
) {
    $altBots = array_values(array_filter($bots, static function ($b) {
        $u = strtolower(trim((string)($b['username'] ?? '')));
        return $u !== '' && $u !== 'kirupabot';
    }));
    if ($altBots !== array()) {
        $bot = pick_bot($altBots, $latestUsername);
        $botLower = strtolower(trim((string)($bot['username'] ?? '')));
    }
}
$opLower = strtolower(trim((string)$opUsername));
$targetLower = strtolower(trim((string)$latestUsername));
$opLooksHelpSeekingQuestion = worker_op_is_help_seeking_question_thread($topicTitle, $threadOpRaw);
$learnerFollowupModeTop = ($botLower !== '' && $opLower !== '' && $botLower === $opLower && $targetLower !== $opLower && trim((string)$targetRaw) !== '' && $opLooksHelpSeekingQuestion);
$threadIsTechnicalTop = worker_topic_is_technical($topicDetail);
$answeredDirectionNonTechnicalTop = (!$threadIsTechnicalTop)
    && worker_nontechnical_thread_answered_direction($posts, $opUsername, (string)($bot['username'] ?? ''), 2);
$forceAnsweredBotToBotContrarianTop = $targetIsBot
    && !$threadIsTechnicalTop
    && $answeredDirectionNonTechnicalTop
    && !$learnerFollowupModeTop;
if ($forceAnsweredBotToBotContrarianTop) {
    $forceContrarianReply = true;
}
    if ($consensusFlowActive) {
        $discussionCount = (int)($consensusTopicState['discussion_reply_count'] ?? 0);
        if ($discussionCount >= 1) {
            $forceContrarianReply = true;
        }
        $allowNoReply = true;
    }
$recentOtherBotPosts = worker_recent_other_bot_posts($posts, (string)($bot['username'] ?? ''), 5);
$recentSameBotPosts = worker_recent_same_bot_posts($posts, (string)($bot['username'] ?? ''), 3);
$pollContext = worker_find_poll_context($posts, $latestPostNumber);
$pollMeta = array(
    'encountered' => false,
    'poll_post_number' => 0,
    'poll_post_username' => '',
    'poll_name' => '',
    'poll_status' => '',
    'poll_prompt' => '',
    'selected_option_id' => '',
    'selected_option_text' => '',
    'selected_reason' => '',
    'vote_attempted' => false,
    'vote_ok' => false,
    'vote_status' => 0,
    'vote_error' => '',
    'context' => null,
);
if (is_array($pollContext) && isset($pollContext['options']) && is_array($pollContext['options']) && $pollContext['options'] !== array()) {
    $pollMeta['encountered'] = true;
    $pollMeta['poll_post_number'] = (int)($pollContext['post_number'] ?? 0);
    $pollMeta['poll_post_username'] = (string)($pollContext['post_username'] ?? '');
    $pollMeta['poll_name'] = (string)($pollContext['poll_name'] ?? 'poll');
    $pollMeta['poll_status'] = (string)($pollContext['status'] ?? 'open');
    $pollMeta['poll_prompt'] = (string)($pollContext['prompt'] ?? '');
    $pollMeta['context'] = $pollContext;

    $pick = worker_pick_poll_option_and_reason($bot, $topicTitle, $targetRaw, $pollContext);
    if (!empty($pick['ok'])) {
        $pollMeta['selected_option_id'] = (string)($pick['option_id'] ?? '');
        $pollMeta['selected_option_text'] = (string)($pick['option_text'] ?? '');
        $pollMeta['selected_reason'] = (string)($pick['reason'] ?? '');
    } else {
        $firstOpt = $pollContext['options'][0] ?? null;
        if (is_array($firstOpt)) {
            $pollMeta['selected_option_id'] = trim((string)($firstOpt['id'] ?? ''));
            $pollMeta['selected_option_text'] = trim((string)($firstOpt['text'] ?? ''));
        }
        if ($pollMeta['selected_reason'] === '') {
            $pollMeta['selected_reason'] = 'it best matches the thread context.';
        }
    }

    if (
        !$dryRun
        && strtolower((string)$pollMeta['poll_status']) === 'open'
        && (int)($pollContext['post_id'] ?? 0) > 0
        && (string)$pollMeta['selected_option_id'] !== ''
    ) {
        $pollMeta['vote_attempted'] = true;
        $voteRes = worker_vote_poll_choice((string)$bot['username'], $pollContext, (string)$pollMeta['selected_option_id']);
        $pollMeta['vote_ok'] = (bool)($voteRes['ok'] ?? false);
        $pollMeta['vote_status'] = (int)($voteRes['status'] ?? 0);
        $pollMeta['vote_error'] = trim((string)($voteRes['error'] ?? ''));
        if (!$pollMeta['vote_ok'] && $pollMeta['vote_error'] === '' && isset($voteRes['body']) && is_array($voteRes['body'])) {
            if (isset($voteRes['body']['error'])) {
                $pollMeta['vote_error'] = trim((string)$voteRes['body']['error']);
            } elseif (isset($voteRes['body']['errors']) && is_array($voteRes['body']['errors'])) {
                $pollMeta['vote_error'] = trim(implode(' ', array_map('strval', $voteRes['body']['errors'])));
            }
        }
        if (!$pollMeta['vote_ok'] && $pollMeta['vote_error'] === '') {
            $pollMeta['vote_error'] = trim((string)($voteRes['raw'] ?? ''));
        }
        if (
            !$pollMeta['vote_ok']
            && $pollMeta['vote_error'] !== ''
            && preg_match('/already\s+voted|has\s+already\s+voted/i', (string)$pollMeta['vote_error'])
        ) {
            $pollMeta['vote_ok'] = true;
        }
    }
}

$wantsReferenceLink = topic_wants_reference_link($topicTitle, $targetRaw);
$isCodeTopic = is_codey_topic($topicTitle, $targetRaw);
$isGamingTopicTop = worker_is_gaming_topic($topicTitle, $targetRaw . "\n" . $threadOpRaw);
$isSolutionProblemThreadTop = worker_is_solution_problem_thread($topicTitle . "\n" . $targetRaw . "\n" . $threadOpRaw);
$chance = 0.0;
if ($consensusFlowActive) {
    $chance = 0.0;
} elseif ($isGamingTopicTop) {
    $chance = $targetIsBot ? 0.85 : 0.75;
} elseif ($wantsReferenceLink) {
    $chance = $targetIsBot ? 0.35 : 0.25;
} elseif ($isCodeTopic) {
    // occasional proactive expansion links for coding threads only
    $chance = $targetIsBot ? 0.18 : 0.10;
} elseif ($isSolutionProblemThreadTop) {
    $chance = $targetIsBot ? 0.72 : 0.62;
}
$roll = mt_rand(1, 1000) / 1000.0;
$shouldIncludeLink = ($roll < $chance);
$related = null;
if ($shouldIncludeLink && $isGamingTopicTop) {
    $yt = worker_find_relevant_gaming_youtube_video_url($topicTitle . ' ' . $targetRaw . ' ' . $threadOpRaw);
    if ($yt !== '') {
        $related = array(
            'title' => trim((string)$topicTitle) !== '' ? (trim((string)$topicTitle) . ' relevant gameplay clip') : 'Relevant gameplay clip',
            'url' => (string)$yt,
        );
    }
}
if ($related === null && $shouldIncludeLink && $isSolutionProblemThreadTop) {
    $yt = worker_find_relevant_solution_youtube_video_url($topicTitle . ' ' . $targetRaw);
    if ($yt !== '') {
        $related = array(
            'title' => trim((string)$topicTitle) !== '' ? (trim((string)$topicTitle) . ' practical solution walkthrough') : 'Practical solution walkthrough',
            'url' => (string)$yt,
        );
    }
}
if ($related === null && $shouldIncludeLink) {
    $related = find_related_internet_link($topicTitle, $targetRaw);
}
if (!is_array($related) || !link_allowed_for_reply($shouldIncludeLink, $related, $topicTitle, $targetRaw)) {
    $shouldIncludeLink = false;
    $related = null;
}
if (!empty($pollMeta['encountered'])) {
    $shouldIncludeLink = false;
    $related = null;
}
if ($consensusFlowActive) {
    $shouldIncludeLink = false;
    $related = null;
}

$topicContextText = $topicTitle . "\n";
$topicContextPosts = worker_bounded_thread_posts($posts, worker_dedup_scan_cap());
foreach ($topicContextPosts as $ctxPost) {
    if (!is_array($ctxPost)) continue;
    $topicContextText .= worker_compact_post_text(post_content_text($ctxPost), 1200) . "\n";
}
$recentThreadContext = worker_recent_posts_context($posts, 5, 900);
if ($consensusFlowActive) {
    $topicContextText .= "\nConsensus workflow context:\n"
        . "- This thread is in discussion mode for AI/tech consensus.\n"
        . "- Add one concrete new angle only; do not summarize prior replies.\n"
        . "- Keep it brief, conversational, and human.\n"
        . "- Prefer practical tradeoffs and real-world constraints.\n";
}

$replyText = generate_reply_text(
    $bot,
    $topicTitle,
    $latestUsername,
    $targetRaw,
    $related,
    $shouldIncludeLink,
    $pollMeta,
    $recentOtherBotPosts,
    $recentSameBotPosts,
    $threadSaturatedPhrases,
    $targetMentionsSaturated,
    $forceContrarianReply,
    $allowNoReply,
    $topicContextText,
    $recentBotStreak,
    $opUsername,
    $threadOpRaw,
    $recentThreadContext,
    $forceAnsweredBotToBotContrarianTop
);
$fallbackUsed = false;
if (trim((string)$replyText) === '') {
    $fallbackReply = worker_generate_minimal_fallback_reply($bot, $topicTitle, $latestUsername, $targetRaw);
    if (trim((string)$fallbackReply) !== '') {
        $replyText = (string)$fallbackReply;
        $fallbackUsed = true;
    } else {
        out_json(200, array(
            'ok' => true,
            'posted' => false,
            'reason' => 'Skipped because no materially new point was available.',
            'topic' => array(
                'id' => $topicId,
                'title' => $topicTitle,
                'url' => rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId,
            ),
            'selected_bot' => $bot,
            'bot_streak_guard' => array(
                'recent_bot_streak' => $recentBotStreak,
                'recent_has_human' => $recentHasHuman,
                'chain_roll' => $chainRoll,
                'force_contrarian' => $forceContrarianReply,
                'allow_no_reply' => $allowNoReply,
            ),
            'human_first_24h_gate' => $humanFirst24hGate,
        ));
    }
}

$isTechnicalQuestionForGate = is_codey_topic($topicTitle, $targetRaw) && $targetIsQuestionLike;
$isSimpleClarificationForGate = $isTechnicalQuestionForGate && worker_is_simple_clarification_question($targetRaw);
$targetHasCodeContextTop = worker_post_has_code_context($targetRaw . "\n" . $topicTitle);
$allowNonTechnicalCodeSnippetsTop = $isTechnicalQuestionForGate
    || is_codey_topic($topicTitle, $targetRaw)
    || (function_exists('kirupa_is_technical_text') && kirupa_is_technical_text($topicTitle . "\n" . $targetRaw))
    || $targetHasCodeContextTop;
$threadShortStatsTop = worker_thread_short_reply_stats($posts);
$topCadenceMeta = worker_question_cadence_should_force_question((string)($bot['username'] ?? ''));
$topReplyCadenceIndex = (int)($topCadenceMeta['next_index'] ?? 1);
$forceQuestionCadenceTop = !empty($topCadenceMeta['force_question']) && !$learnerFollowupModeTop;
$forceUncertaintyCadenceTop = (($topReplyCadenceIndex % 10) === 0) && !$learnerFollowupModeTop;
$forceLowEffortCadenceTop = (($topReplyCadenceIndex % 10) === 5)
    && !$learnerFollowupModeTop
    && !$isTechnicalQuestionForGate
    && !$targetIsQuestionLike;
$forceThreadShortReplyTop = !$learnerFollowupModeTop
    && !$isTechnicalQuestionForGate
    && !$targetIsQuestionLike
    && !$targetHasCodeContextTop
    && ((int)($threadShortStatsTop['bot_reply_count'] ?? 0) >= 2)
    && ((int)($threadShortStatsTop['short_bot_reply_count'] ?? 0) === 0);
$qualityGate = array(
    'enabled' => false,
    'available' => true,
    'passed' => true,
    'threshold' => 4,
    'score' => 4,
    'rounds' => 0,
    'issues' => array(),
    'history' => array(),
    'reply' => $replyText,
);
$bypassQualityGateForLearnerThanks = $learnerFollowupModeTop && worker_is_short_thank_you_ack($replyText);
$bypassQualityGateForLowEffortCadence = $forceLowEffortCadenceTop || $forceThreadShortReplyTop;
if (!$bypassQualityGateForLearnerThanks && !$bypassQualityGateForLowEffortCadence) {
    $qualityGate = worker_enforce_reply_quality_gate(
        $bot,
        $topicTitle,
        $targetRaw,
        $replyText,
        $isTechnicalQuestionForGate,
        $isSimpleClarificationForGate,
        $targetIsQuestionLike
    );
    $replyText = isset($qualityGate['reply']) ? (string)$qualityGate['reply'] : $replyText;
} else {
    $bypassReason = $forceThreadShortReplyTop
        ? 'Bypassed quality rewrite: thread short-reaction mode.'
        : ($bypassQualityGateForLowEffortCadence
        ? 'Bypassed quality rewrite: low-effort cadence mode.'
        : 'Bypassed quality rewrite: OP learner follow-up thank-you mode.');
    $qualityGate['history'][] = array(
        'round' => 0,
        'score' => 4,
        'issues' => array(),
        'reason' => $bypassReason,
    );
}
$replyText = worker_markdown_code_integrity_pass($replyText);
$replyText = worker_normalize_code_fence_spacing($replyText);
$replyText = worker_strip_foreign_bot_name_noise($replyText, (string)($bot['username'] ?? ''));
$replyText = normalize_signature($replyText, isset($bot['signature']) ? (string)$bot['signature'] : '');
$replyText = worker_enforce_banned_phrase_cleanup($replyText);
if ($forceQuestionCadenceTop && !worker_has_genuine_question($replyText)) {
    $replyText = worker_force_genuine_question_with_llm($bot, $topicTitle, $targetRaw, $replyText);
    $replyText = worker_apply_micro_grammar_fixes($replyText);
    $replyText = worker_strip_foreign_bot_name_noise($replyText, (string)($bot['username'] ?? ''));
    $replyText = normalize_signature($replyText, isset($bot['signature']) ? (string)$bot['signature'] : '');
    $replyText = worker_enforce_banned_phrase_cleanup($replyText);
}
if ($forceUncertaintyCadenceTop && !worker_has_uncertainty_marker($replyText)) {
    $replyText = rtrim((string)$replyText);
    if ($replyText !== '') {
        $replyText .= "\n\n" . worker_uncertainty_phrase_for_bot((string)($bot['username'] ?? ''));
    } else {
        $replyText = worker_uncertainty_phrase_for_bot((string)($bot['username'] ?? ''));
    }
    $replyText = worker_apply_micro_grammar_fixes($replyText);
    $replyText = worker_strip_foreign_bot_name_noise($replyText, (string)($bot['username'] ?? ''));
    $replyText = normalize_signature($replyText, isset($bot['signature']) ? (string)$bot['signature'] : '');
    $replyText = worker_enforce_banned_phrase_cleanup($replyText);
}
if (($forceLowEffortCadenceTop || $forceThreadShortReplyTop) && !worker_is_low_effort_reaction($replyText)) {
    $replyText = worker_low_effort_reaction_for_bot(
        (string)($bot['username'] ?? ''),
        (string)$topicTitle . '|' . (string)$topicId . '|' . (string)$latestUsername
    );
    $replyText = worker_apply_micro_grammar_fixes($replyText);
    $replyText = worker_strip_foreign_bot_name_noise($replyText, (string)($bot['username'] ?? ''));
    $replyText = normalize_signature($replyText, isset($bot['signature']) ? (string)$bot['signature'] : '');
    $replyText = worker_enforce_banned_phrase_cleanup($replyText);
}
if (!$allowNonTechnicalCodeSnippetsTop) {
    $replyText = worker_strip_code_blocks_for_nontechnical($replyText);
    if (trim((string)$replyText) === '') {
        $replyText = $forceQuestionCadenceTop
            ? 'Could you share one concrete detail so we can narrow this down?'
            : worker_low_effort_reaction_for_bot(
                (string)($bot['username'] ?? ''),
                (string)$topicTitle . '|nontech-code-strip-top|' . (string)$topicId
            );
    }
    $replyText = worker_apply_micro_grammar_fixes($replyText);
    $replyText = worker_strip_foreign_bot_name_noise($replyText, (string)($bot['username'] ?? ''));
    $replyText = normalize_signature($replyText, isset($bot['signature']) ? (string)$bot['signature'] : '');
    $replyText = worker_enforce_banned_phrase_cleanup($replyText);
}

$duplicateGate = $learnerFollowupModeTop
    ? array('skip' => false, 'reason' => '')
    : worker_detect_duplicate_reply($replyText, $targetRaw, $recentOtherBotPosts, $recentSameBotPosts);
if (!empty($duplicateGate['skip']) && $forceOrphanReviveMode) {
    $dupReason = (string)($duplicateGate['reason'] ?? '');
    if ($dupReason !== 'duplicate_of_target_post') {
        $duplicateGate = array('skip' => false, 'reason' => 'forced_orphan_revive_mode');
    }
}
if (!empty($duplicateGate['skip'])) {
    if (!$fallbackUsed) {
        out_json(200, array(
            'ok' => true,
            'posted' => false,
            'reason' => 'Skipped duplicate-style reply to keep bot conversation varied.',
            'skip_reason' => (string)($duplicateGate['reason'] ?? 'duplicate_reply'),
            'topic' => array(
                'id' => $topicId,
                'title' => $topicTitle,
                'url' => rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId,
            ),
            'selected_bot' => $bot,
            'quality_gate' => $qualityGate,
            'duplicate_gate' => $duplicateGate,
            'reply_preview' => $replyText,
            'fallback_used' => $fallbackUsed,
        ));
    }
}

$newDetailsGate = $learnerFollowupModeTop
    ? array('applied' => false, 'adds_new_details' => true, 'reason' => 'learner_followup_mode')
    : (($forceLowEffortCadenceTop || $forceThreadShortReplyTop)
        ? array('applied' => false, 'adds_new_details' => true, 'reason' => ($forceThreadShortReplyTop ? 'thread_short_reply_override' : 'low_effort_cadence_override'))
        : worker_reply_adds_new_details_pass($replyText, $posts, max(1, count($posts))));
if (empty($newDetailsGate['adds_new_details']) && $forceOrphanReviveMode) {
    $newDetailsGate = array('applied' => false, 'adds_new_details' => true, 'reason' => 'forced_orphan_revive_mode');
}
if (empty($newDetailsGate['adds_new_details'])) {
    if (!$fallbackUsed) {
        out_json(200, array(
            'ok' => true,
            'posted' => false,
            'reason' => 'Skipped because draft does not add enough new detail versus full-thread replies.',
            'skip_reason' => 'no_new_details_vs_full_thread',
            'topic' => array(
                'id' => $topicId,
                'title' => $topicTitle,
                'url' => rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId,
            ),
            'selected_bot' => $bot,
            'quality_gate' => $qualityGate,
            'duplicate_gate' => $duplicateGate,
            'new_details_gate' => $newDetailsGate,
            'reply_preview' => $replyText,
            'fallback_used' => $fallbackUsed,
        ));
    }
}

if (!empty($qualityGate['enabled']) && empty($qualityGate['passed'])) {
    if (!$fallbackUsed) {
        out_json(200, array(
            'ok' => true,
            'posted' => false,
            'reason' => 'Quality gate score stayed below 4/5 after retries.',
            'topic' => array(
                'id' => $topicId,
                'title' => $topicTitle,
                'url' => rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId,
            ),
            'selected_bot' => $bot,
            'quality_gate' => $qualityGate,
            'new_details_gate' => $newDetailsGate,
            'human_first_24h_gate' => $humanFirst24hGate,
            'reply_preview' => $replyText,
            'fallback_used' => $fallbackUsed,
        ));
    }
}

if ($dryRun) {
    out_json(200, array(
        'ok' => true,
        'dry_run' => true,
        'topic' => array(
            'id' => $topicId,
            'title' => $topicTitle,
            'url' => rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId,
            'op_username' => $opUsername,
            'latest_username' => $latestUsername,
            'latest_is_bot' => $targetIsBot,
        ),
        'consensus_flow' => array(
            'active' => $consensusFlowActive,
            'state' => $consensusTopicState,
        ),
        'orphan_priority' => array(
            'active' => $forceOrphanReviveMode,
            'age_seconds' => $forceOrphanReviveMode ? (int)($orphanPriority['age_seconds'] ?? 0) : 0,
            'op_username' => $forceOrphanReviveMode ? (string)($orphanPriority['op_username'] ?? '') : '',
            'force_post' => $forceOrphanReviveMode,
        ),
        'selected_bot' => $bot,
        'recent_other_bot_posts' => $recentOtherBotPosts,
        'bot_streak_guard' => array(
            'recent_bot_streak' => $recentBotStreak,
            'recent_has_human' => $recentHasHuman,
            'chain_roll' => $chainRoll,
            'force_contrarian' => $forceContrarianReply,
            'allow_no_reply' => $allowNoReply,
        ),
        'human_first_24h_gate' => $humanFirst24hGate,
        'link_policy' => array(
            'wants_reference_link' => $wantsReferenceLink,
            'is_code_topic' => $isCodeTopic,
            'chance' => $chance,
            'roll' => $roll,
            'included' => $shouldIncludeLink,
            'related' => $related,
        ),
        'poll_vote' => $pollMeta,
        'quality_gate' => $qualityGate,
        'new_details_gate' => $newDetailsGate,
        'thread_saturation' => array(
            'target_mentions_saturated' => $targetMentionsSaturated,
            'phrases' => $threadSaturatedPhrases,
        ),
        'reply_preview' => $replyText,
        'fallback_used' => $fallbackUsed,
    ));
}

// Defense-in-depth against races: even with the run lock above, re-check right before
// posting in case another process (different host, manual retry, key shared elsewhere)
// already replied to this same topic while this run was generating its reply.
$recheckDetail = fetch_json(rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '.json', array(
    'Api-Key: ' . KONVO_DISCOURSE_API_KEY,
    'Api-Username: BayMax',
));
if (is_array($recheckDetail) && isset($recheckDetail['post_stream']['posts']) && is_array($recheckDetail['post_stream']['posts'])) {
    foreach ($recheckDetail['post_stream']['posts'] as $recheckPost) {
        if (!is_array($recheckPost)) continue;
        $recheckPostNumber = (int)($recheckPost['post_number'] ?? 0);
        $recheckUsername = (string)($recheckPost['username'] ?? '');
        if ($recheckPostNumber > $latestPostNumber && is_bot_user($recheckUsername)) {
            out_json(200, array(
                'ok' => true,
                'posted' => false,
                'reason' => 'Skipped: another bot already replied to this topic since this run started.',
                'topic_id' => $topicId,
                'raced_with_username' => $recheckUsername,
            ));
        }
    }
}

$postRes = post_json(
    rtrim(KONVO_BASE_URL, '/') . '/posts.json',
    array(
        'topic_id' => $topicId,
        'raw' => $replyText,
    ),
    array(
        'Api-Key: ' . KONVO_DISCOURSE_API_KEY,
        'Api-Username: ' . $bot['username'],
    )
);

if (!$postRes['ok']) {
    out_json(500, array(
        'ok' => false,
        'error' => 'Failed to post reply',
        'topic_id' => $topicId,
        'bot' => $bot,
        'status' => $postRes['status'],
        'curl_error' => $postRes['error'],
        'response' => $postRes['body'],
        'raw' => $postRes['raw'],
    ));
}

$postNumber = isset($postRes['body']['post_number']) ? (int)$postRes['body']['post_number'] : 1;
$postUrl = rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '/' . $postNumber;
worker_question_cadence_record_post((string)($bot['username'] ?? ''));
$postedStem = worker_opening_stem((string)$replyText);
if ($postedStem !== '') {
    worker_remember_opening_stem($postedStem, (int)$topicId, (string)($bot['username'] ?? ''));
}
$seen[(string)$topicId] = time();
save_seen_topics($seen);
if ($consensusFlowActive) {
    worker_consensus_mark_posted_reply($consensusState, $topicId, (string)($bot['username'] ?? ''), $postNumber);
    worker_consensus_save($consensusState);
    if (isset($consensusState[(string)$topicId]) && is_array($consensusState[(string)$topicId])) {
        $consensusTopicState = $consensusState[(string)$topicId];
    }
}

$solvedMeta = array(
    'attempted' => false,
    'ok' => false,
    'reason' => 'topic_refresh_failed',
);
$opThankYouMeta = array(
    'attempted' => false,
    'ok' => false,
    'reason' => 'topic_refresh_failed',
    'op_username' => '',
    'reply_to_post_number' => 0,
    'status' => 0,
    'error' => '',
    'post_url' => '',
);
$freshTopicDetail = fetch_json(rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '.json', array(
    'Api-Key: ' . KONVO_DISCOURSE_API_KEY,
    'Api-Username: BayMax',
));
if (is_array($freshTopicDetail)) {
    $solvedMeta = worker_try_auto_accept_solution($topicId, $freshTopicDetail);
    $opThankYouMeta = worker_try_post_op_thank_you($topicId, $freshTopicDetail, $solvedMeta);
}

out_json(200, array(
    'ok' => true,
    'posted' => true,
    'post_url' => $postUrl,
    'topic' => array(
        'id' => $topicId,
        'title' => $topicTitle,
        'op_username' => $opUsername,
        'latest_username' => $latestUsername,
        'latest_is_bot' => $targetIsBot,
    ),
    'consensus_flow' => array(
        'active' => $consensusFlowActive,
        'state' => $consensusTopicState,
    ),
    'orphan_priority' => array(
        'active' => $forceOrphanReviveMode,
        'age_seconds' => $forceOrphanReviveMode ? (int)($orphanPriority['age_seconds'] ?? 0) : 0,
        'op_username' => $forceOrphanReviveMode ? (string)($orphanPriority['op_username'] ?? '') : '',
        'force_post' => $forceOrphanReviveMode,
    ),
    'selected_bot' => $bot,
    'recent_other_bot_posts' => $recentOtherBotPosts,
    'quality_gate' => $qualityGate,
    'bot_streak_guard' => array(
        'recent_bot_streak' => $recentBotStreak,
        'recent_has_human' => $recentHasHuman,
        'chain_roll' => $chainRoll,
        'force_contrarian' => $forceContrarianReply,
        'allow_no_reply' => $allowNoReply,
    ),
    'human_first_24h_gate' => $humanFirst24hGate,
    'link_policy' => array(
        'wants_reference_link' => $wantsReferenceLink,
        'is_code_topic' => $isCodeTopic,
        'chance' => $chance,
        'roll' => $roll,
        'included' => $shouldIncludeLink,
        'related' => $related,
    ),
    'poll_vote' => $pollMeta,
    'thread_saturation' => array(
        'target_mentions_saturated' => $targetMentionsSaturated,
        'phrases' => $threadSaturatedPhrases,
    ),
    'fallback_used' => $fallbackUsed,
    'solved' => $solvedMeta,
    'op_thank_you' => $opThankYouMeta,
));
