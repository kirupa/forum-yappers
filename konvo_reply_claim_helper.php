<?php

/*
 * Per-target reply claims, shared by every path that can answer a forum post
 * (webhook-triggered bot endpoints and the cron quiz acknowledgement).
 *
 * Without this, one human post could collect several near-identical replies:
 *   - the webhook re-POSTed a bot endpoint after a client timeout while the
 *     first run was still generating, then fired safe_mode on top of that;
 *   - the cron quiz-ack worker saw the human post as still unanswered while the
 *     webhook reply was mid-generation, and a second bot answered it too.
 *
 * A claim is keyed by topic + target post number + who is replying. It is held
 * as "in_progress" for the life of a run, upgraded to "posted" once a reply
 * lands, and released when the run ends without posting so a genuine retry can
 * still go through.
 */

declare(strict_types=1);

if (!defined('KONVO_REPLY_CLAIM_TTL')) {
    // Longer than any single reply run, short enough that a crashed run does
    // not block the target for long.
    define('KONVO_REPLY_CLAIM_TTL', 15 * 60);
}
if (!defined('KONVO_REPLY_POSTED_TTL')) {
    define('KONVO_REPLY_POSTED_TTL', 7 * 24 * 3600);
}

if (!function_exists('konvo_is_challenge_topic_title')) {
    /** Threads where exactly one bot should answer each human post. */
    function konvo_is_challenge_topic_title(string $title): bool
    {
        $t = strtolower(trim($title));
        if ($t === '') return false;
        return str_starts_with($t, 'spot the bug')
            || str_starts_with($t, 'js quiz:')
            || str_starts_with($t, 'coding challenge');
    }
}

if (!function_exists('konvo_reply_claim_key')) {
    function konvo_reply_claim_key(int $topicId, int $postNumber, string $who): string
    {
        return $topicId . ':' . $postNumber . ':' . strtolower(trim($who));
    }
}

if (!function_exists('konvo_reply_claims_update')) {
    /**
     * Runs $fn(&$state) under an exclusive file lock and persists the result.
     * Returns whatever $fn returns, or null if the state file is unusable.
     */
    function konvo_reply_claims_update(callable $fn)
    {
        $dir = __DIR__ . '/.konvo_state';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $fp = @fopen($dir . '/reply_claims.json', 'c+');
        if ($fp === false) return null;
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return null;
        }
        $raw = stream_get_contents($fp);
        $state = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : array();
        if (!is_array($state)) $state = array();

        $now = time();
        foreach ($state as $k => $row) {
            $ts = is_array($row) ? (int)($row['t'] ?? 0) : 0;
            $status = is_array($row) ? (string)($row['s'] ?? '') : '';
            $ttl = $status === 'posted' ? KONVO_REPLY_POSTED_TTL : KONVO_REPLY_CLAIM_TTL;
            if ($ts <= 0 || ($now - $ts) > $ttl) unset($state[$k]);
        }

        $result = $fn($state);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state, JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $result;
    }
}

if (!function_exists('konvo_reply_claim')) {
    /**
     * Returns true when the caller now owns the target. Returns false when
     * another run is already working on it or has already replied.
     * Fails open if the state file cannot be used.
     */
    function konvo_reply_claim(int $topicId, int $postNumber, string $who): bool
    {
        if ($topicId <= 0 || $postNumber <= 0) return true;
        $key = konvo_reply_claim_key($topicId, $postNumber, $who);
        $res = konvo_reply_claims_update(function (array &$state) use ($key) {
            if (isset($state[$key])) return false;
            $state[$key] = array('s' => 'in_progress', 't' => time());
            return true;
        });
        return $res !== false;
    }
}

if (!function_exists('konvo_reply_claim_mark_posted')) {
    function konvo_reply_claim_mark_posted(int $topicId, int $postNumber, string $who): void
    {
        if ($topicId <= 0 || $postNumber <= 0) return;
        $key = konvo_reply_claim_key($topicId, $postNumber, $who);
        konvo_reply_claims_update(function (array &$state) use ($key) {
            $state[$key] = array('s' => 'posted', 't' => time());
            return true;
        });
    }
}

if (!function_exists('konvo_reply_claim_release')) {
    /** Drops an in-progress claim. A "posted" claim is left in place. */
    function konvo_reply_claim_release(int $topicId, int $postNumber, string $who): void
    {
        if ($topicId <= 0 || $postNumber <= 0) return;
        $key = konvo_reply_claim_key($topicId, $postNumber, $who);
        konvo_reply_claims_update(function (array &$state) use ($key) {
            if (isset($state[$key]) && (string)($state[$key]['s'] ?? '') === 'in_progress') {
                unset($state[$key]);
            }
            return true;
        });
    }
}

if (!function_exists('konvo_posts_have_bot_reply_to')) {
    /**
     * True when a bot post in $posts replies to $postNumber. Pass a username to
     * restrict the check to that bot; pass '' to match any bot.
     */
    function konvo_posts_have_bot_reply_to(array $posts, int $postNumber, string $botUsername, callable $isBot): bool
    {
        if ($postNumber <= 0) return false;
        $only = strtolower(trim($botUsername));
        foreach ($posts as $p) {
            if (!is_array($p)) continue;
            if ((int)($p['reply_to_post_number'] ?? 0) !== $postNumber) continue;
            $u = strtolower(trim((string)($p['username'] ?? '')));
            if ($u === '') continue;
            if ($only !== '' ? ($u === $only) : $isBot($u)) return true;
        }
        return false;
    }
}

if (!function_exists('konvo_posts_contain_text')) {
    /** True when any post body already contains $text (whitespace/case-insensitive). */
    function konvo_posts_contain_text(array $posts, string $text): bool
    {
        $norm = static function (string $s): string {
            return strtolower(trim((string)preg_replace('/\s+/u', ' ', str_replace(array('’', '‘'), "'", $s))));
        };
        $needle = $norm($text);
        if ($needle === '') return false;
        foreach ($posts as $p) {
            if (!is_array($p)) continue;
            $body = (string)($p['raw'] ?? '');
            if ($body === '' && isset($p['cooked'])) {
                $body = html_entity_decode(strip_tags((string)$p['cooked']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if ($body !== '' && str_contains($norm($body), $needle)) return true;
        }
        return false;
    }
}
