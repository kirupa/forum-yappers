<?php

/*
 * Browser-callable spot-the-bug topic poster.
 *
 * Example:
 * https://www.kirupa.com/konvo_spot_the_bug_worker.php?key=YOUR_SECRET
 * https://www.kirupa.com/konvo_spot_the_bug_worker.php?key=YOUR_SECRET&dry_run=1
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

function spot_out(int $code, array $data): void
{
    if (function_exists('http_response_code')) {
        http_response_code($code);
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function spot_safe_hash_equals(string $a, string $b): bool
{
    if (function_exists('hash_equals')) {
        return hash_equals($a, $b);
    }
    if (strlen($a) !== strlen($b)) {
        return false;
    }
    $res = 0;
    $len = strlen($a);
    for ($i = 0; $i < $len; $i++) {
        $res |= ord($a[$i]) ^ ord($b[$i]);
    }
    return $res === 0;
}

function spot_state_path(): string
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/spot_the_bug_state.json';
}

function spot_load_state(): array
{
    $path = spot_state_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function spot_save_state(array $state): void
{
    @file_put_contents(spot_state_path(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function spot_pick_bot(array $bots): array
{
    if ($bots === array()) {
        return array('username' => 'BayMax', 'name' => 'BayMax');
    }
    shuffle($bots);
    return $bots[0];
}

function spot_norm_lang(string $lang): string
{
    $v = strtolower(trim($lang));
    if ($v === 'js' || $v === 'javascript' || $v === 'node') return 'js';
    if ($v === 'ts' || $v === 'typescript') return 'ts';
    if ($v === 'html') return 'html';
    if ($v === 'css') return 'css';
    if ($v === 'php') return 'php';
    if ($v === 'python' || $v === 'py') return 'python';
    return 'js';
}

function spot_recent_vals(array $state, string $key, int $max = 12): array
{
    $out = array();
    if (isset($state[$key]) && is_array($state[$key])) {
        foreach ($state[$key] as $v) {
            $s = strtolower(trim((string)$v));
            if ($s === '') continue;
            if (!in_array($s, $out, true)) $out[] = $s;
            if (count($out) >= $max) break;
        }
    }
    return $out;
}

function spot_recent_case_records(array $state, int $max = 16): array
{
    $out = array();
    if (!isset($state['recent_cases']) || !is_array($state['recent_cases'])) return $out;
    foreach ($state['recent_cases'] as $row) {
        if (!is_array($row)) continue;
        $bugShape = strtolower(trim((string)($row['bug_shape'] ?? '')));
        $theme = strtolower(trim((string)($row['theme'] ?? '')));
        $surface = strtolower(trim((string)($row['ui_surface'] ?? '')));
        $lang = strtolower(trim((string)($row['language'] ?? '')));
        $lead = trim((string)($row['lead'] ?? ''));
        $terms = isset($row['terms']) && is_array($row['terms']) ? array_values(array_filter(array_map('strval', $row['terms']))) : array();
        $tags = isset($row['concept_tags']) && is_array($row['concept_tags']) ? array_values(array_filter(array_map('strval', $row['concept_tags']))) : array();
        if ($bugShape === '' && $theme === '' && $surface === '' && $lead === '') continue;
        $out[] = array(
            'bug_shape' => $bugShape,
            'theme' => $theme,
            'ui_surface' => $surface,
            'language' => $lang,
            'lead' => $lead,
            'terms' => $terms,
            'concept_tags' => $tags,
        );
        if (count($out) >= $max) break;
    }
    return $out;
}

function spot_norm_text(string $text): string
{
    $text = strtolower(trim($text));
    if ($text === '') return '';
    $text = preg_replace('/https?:\/\/\S+/i', ' ', $text) ?? $text;
    $text = preg_replace('/[^a-z0-9\s]+/i', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return trim($text);
}

function spot_text_terms(string $text, int $max = 18): array
{
    $text = spot_norm_text($text);
    if ($text === '') return array();
    $stop = array_fill_keys(array(
        'const','let','var','function','return','class','new','true','false','null','undefined',
        'this','that','with','from','into','your','have','just','will','when','then','else',
        'they','them','what','how','there','here','code','snippet','spot','bug','reply','broken',
        'would','should','could','make','gets','keep','very','really','about','because'
    ), true);
    $parts = preg_split('/\s+/', $text) ?: array();
    $out = array();
    foreach ($parts as $part) {
        $p = trim((string)$part);
        if ($p === '' || strlen($p) < 3) continue;
        if (isset($stop[$p])) continue;
        if (!in_array($p, $out, true)) $out[] = $p;
        if (count($out) >= $max) break;
    }
    return $out;
}

function spot_overlap_ratio(array $a, array $b): float
{
    $a = array_values(array_unique(array_filter(array_map('strval', $a))));
    $b = array_values(array_unique(array_filter(array_map('strval', $b))));
    if ($a === array() || $b === array()) return 0.0;
    $sa = array_fill_keys($a, true);
    $sb = array_fill_keys($b, true);
    $intersection = 0;
    foreach ($sa as $k => $_) {
        if (isset($sb[$k])) $intersection++;
    }
    $union = count($sa) + count($sb) - $intersection;
    if ($union <= 0) return 0.0;
    return (float)$intersection / (float)$union;
}

function spot_case_similarity_check(array $candidate, array $state): array
{
    $recent = spot_recent_case_records($state, 18);
    if ($recent === array()) {
        return array('similar' => false, 'reason' => '');
    }

    $candShape = spot_norm_text((string)($candidate['bug_shape'] ?? ''));
    $candTheme = spot_norm_text((string)($candidate['theme'] ?? ''));
    $candSurface = spot_norm_text((string)($candidate['ui_surface'] ?? ''));
    $candLead = spot_norm_text((string)($candidate['lead'] ?? ''));
    $candLang = spot_norm_text((string)($candidate['language'] ?? ''));
    $candTerms = spot_text_terms((string)($candidate['code'] ?? '') . "\n" . (string)($candidate['lead'] ?? ''), 20);
    $candTags = array_values(array_unique(array_filter(array_map(static function ($v): string {
        return spot_norm_text((string)$v);
    }, isset($candidate['concept_tags']) && is_array($candidate['concept_tags']) ? $candidate['concept_tags'] : array()))));

    foreach ($recent as $row) {
        $rowShape = spot_norm_text((string)($row['bug_shape'] ?? ''));
        $rowTheme = spot_norm_text((string)($row['theme'] ?? ''));
        $rowSurface = spot_norm_text((string)($row['ui_surface'] ?? ''));
        $rowLead = spot_norm_text((string)($row['lead'] ?? ''));
        $rowLang = spot_norm_text((string)($row['language'] ?? ''));
        $rowTerms = spot_text_terms(implode(' ', (array)($row['terms'] ?? array())), 20);
        if ($rowTerms === array()) {
            $rowTerms = array_values(array_unique(array_filter(array_map(static function ($v): string {
                return spot_norm_text((string)$v);
            }, isset($row['terms']) && is_array($row['terms']) ? $row['terms'] : array()))));
        }
        $rowTags = array_values(array_unique(array_filter(array_map(static function ($v): string {
            return spot_norm_text((string)$v);
        }, isset($row['concept_tags']) && is_array($row['concept_tags']) ? $row['concept_tags'] : array()))));

        if ($candShape !== '' && $rowShape !== '' && $candShape === $rowShape) {
            return array('similar' => true, 'reason' => 'same bug shape as recent Spot the Bug');
        }
        if ($candTheme !== '' && $rowTheme !== '' && $candTheme === $rowTheme && $candSurface !== '' && $rowSurface !== '' && $candSurface === $rowSurface) {
            return array('similar' => true, 'reason' => 'same theme and surface as recent Spot the Bug');
        }
        if ($candLang !== '' && $candLang === $rowLang && $candLead !== '' && $candLead === $rowLead) {
            return array('similar' => true, 'reason' => 'same lead and language as recent Spot the Bug');
        }
        if ($candTags !== array() && $rowTags !== array()) {
            $tagOverlap = spot_overlap_ratio($candTags, $rowTags);
            if ($tagOverlap >= 0.67 && count(array_intersect($candTags, $rowTags)) >= 2) {
                return array('similar' => true, 'reason' => 'same concept tags as recent Spot the Bug');
            }
        }
        if ($candTerms !== array() && $rowTerms !== array()) {
            $termOverlap = spot_overlap_ratio($candTerms, $rowTerms);
            if ($candLang !== '' && $candLang === $rowLang && $termOverlap >= 0.58) {
                return array('similar' => true, 'reason' => 'code terms overlap too much with recent Spot the Bug');
            }
        }
    }

    return array('similar' => false, 'reason' => '');
}

function spot_source_seeds(): array
{
    return array(
        array('theme' => 'animation', 'source_name' => 'kirupa Web Animation', 'source_url' => 'https://www.kirupa.com/html5/learn_animation.htm'),
        array('theme' => 'animation', 'source_name' => 'kirupa Animation in JavaScript', 'source_url' => 'https://www.kirupa.com/html5/animating_in_code_using_javascript.htm'),
        array('theme' => 'dom-events', 'source_name' => 'kirupa JavaScript Events', 'source_url' => 'https://www.kirupa.com/html5/introduction_to_javascript_events.htm'),
        array('theme' => 'text-manipulation', 'source_name' => 'MDN String docs', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/String'),
        array('theme' => 'logic', 'source_name' => 'MDN Control flow', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Guide/Control_flow_and_error_handling'),
        array('theme' => 'forms', 'source_name' => 'MDN FormData', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/API/FormData'),
        array('theme' => 'arrays', 'source_name' => 'MDN Array docs', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Array'),
        array('theme' => 'objects', 'source_name' => 'MDN Object docs', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Object'),
        array('theme' => 'dom', 'source_name' => 'MDN DOM API', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/API/Document_Object_Model'),
        array('theme' => 'css-layout', 'source_name' => 'MDN CSS guides', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/CSS'),
        array('theme' => 'canvas', 'source_name' => 'kirupa Canvas Intro', 'source_url' => 'https://www.kirupa.com/html5/getting_started_with_canvas.htm'),
        array('theme' => 'regex', 'source_name' => 'MDN Regular expressions', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Guide/Regular_expressions'),
        array('theme' => 'coding-exercises', 'source_name' => 'kirupa Coding Exercises', 'source_url' => 'https://www.kirupa.com/codingexercises/index.htm'),
    );
}

function spot_pick_seed(array $state): array
{
    $seeds = spot_source_seeds();
    $recentThemes = spot_recent_vals($state, 'recent_themes', 8);
    $pool = array();
    foreach ($seeds as $s) {
        $k = strtolower(trim((string)($s['theme'] ?? '')));
        if ($k !== '' && !in_array($k, $recentThemes, true)) $pool[] = $s;
    }
    if ($pool === array()) $pool = $seeds;
    return $pool[mt_rand(0, count($pool) - 1)];
}

function spot_pick_difficulty(array $state): string
{
    $choices = array('Extremely Easy', 'Easy', 'Medium', 'Hard', 'Extremely Hard');
    $recent = spot_recent_vals($state, 'recent_difficulties', 5);
    $pool = array();
    foreach ($choices as $c) {
        if (!in_array(strtolower($c), $recent, true)) $pool[] = $c;
    }
    if ($pool === array()) $pool = $choices;
    return $pool[mt_rand(0, count($pool) - 1)];
}

function spot_pick_size(array $state): string
{
    $choices = array('tiny', 'short', 'medium', 'long', 'xlong');
    $recent = spot_recent_vals($state, 'recent_sizes', 5);
    $pool = array();
    foreach ($choices as $c) {
        if (!in_array(strtolower($c), $recent, true)) $pool[] = $c;
    }
    if ($pool === array()) $pool = $choices;
    return $pool[mt_rand(0, count($pool) - 1)];
}

function spot_fallback_cases(): array
{
    return array(
        array(
            'language' => 'js',
            'difficulty' => 'Easy',
            'theme' => 'animation',
            'bug_shape' => 'missing assignment in animation loop',
            'ui_surface' => 'moving sprite position',
            'concept_tags' => array('animation', 'assignment', 'requestanimationframe'),
            'snippet_size' => 'tiny',
            'source_name' => 'kirupa Animating with requestAnimationFrame',
            'source_url' => 'https://www.kirupa.com/animations/ensuring_consistent_animation_speeds.htm',
            'lead' => 'Find the bug in this animation loop.',
            'code' => "let x = 0;\nfunction tick() {\n  x + 2;\n  requestAnimationFrame(tick);\n}\ntick();",
        ),
        array(
            'language' => 'js',
            'difficulty' => 'Hard',
            'theme' => 'logic',
            'bug_shape' => 'inverted duplicate check return',
            'ui_surface' => 'duplicate finder helper',
            'concept_tags' => array('set', 'logic', 'duplicates'),
            'snippet_size' => 'long',
            'source_name' => 'MDN for...of',
            'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Statements/for...of',
            'lead' => 'There is one subtle logic bug.',
            'code' => "function hasDuplicate(nums) {\n  const seen = new Set();\n  for (const n of nums) {\n    if (seen.has(n)) {\n      return false;\n    }\n    seen.add(n);\n  }\n  return true;\n}\n\nconsole.log(hasDuplicate([2, 7, 4, 7]));",
        ),
        array(
            'language' => 'js',
            'difficulty' => 'Medium',
            'theme' => 'text-manipulation',
            'bug_shape' => 'wrong string method casing',
            'ui_surface' => 'tag normalizer',
            'concept_tags' => array('string', 'method', 'normalization'),
            'snippet_size' => 'short',
            'source_name' => 'MDN String.trim',
            'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/String/trim',
            'lead' => 'This text cleanup has one bug.',
            'code' => "function normalizeTag(tag) {\n  return tag.trim().toLowercase();\n}\n\nconsole.log(normalizeTag('  JavaScript  '));",
        ),
        array(
            'language' => 'js',
            'difficulty' => 'Easy',
            'theme' => 'dom-events',
            'bug_shape' => 'handler invoked during registration',
            'ui_surface' => 'save button click',
            'concept_tags' => array('events', 'handler', 'dom'),
            'snippet_size' => 'short',
            'source_name' => 'MDN addEventListener',
            'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/API/EventTarget/addEventListener',
            'lead' => 'Quick one: event handling bug here.',
            'code' => "const button = document.querySelector('#save');\nbutton.addEventListener('click', saveForm());\n\nfunction saveForm() {\n  console.log('saved');\n}",
        ),
        array(
            'language' => 'html',
            'difficulty' => 'Extremely Easy',
            'theme' => 'forms',
            'bug_shape' => 'missing submit prevention',
            'ui_surface' => 'email signup form',
            'concept_tags' => array('form', 'submit', 'preventdefault'),
            'snippet_size' => 'tiny',
            'source_name' => 'MDN Forms',
            'source_url' => 'https://developer.mozilla.org/en-US/docs/Learn_web_development/Extensions/Forms',
            'lead' => 'Can you spot the form bug?',
            'code' => "<form>\n  <label>Email</label>\n  <input type=\"email\" id=\"email\">\n  <button type=\"submit\">Join</button>\n</form>\n<script>\n  document.querySelector('form').addEventListener('submit', (e) => {\n    if (!email.value.includes('@')) alert('invalid');\n  });\n</script>",
        ),
        array(
            'language' => 'css',
            'difficulty' => 'Extremely Hard',
            'theme' => 'css-layout',
            'bug_shape' => 'grid span exceeds track count',
            'ui_surface' => 'dashboard card grid',
            'concept_tags' => array('css-grid', 'layout', 'span'),
            'snippet_size' => 'xlong',
            'source_name' => 'MDN Grid Layout',
            'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/CSS/CSS_grid_layout',
            'lead' => 'Layout bug is hidden in plain sight.',
            'code' => ".dashboard {\n  display: grid;\n  grid-template-columns: repeat(3, minmax(120px, 1fr));\n  gap: 12px;\n}\n.card {\n  grid-column: span 4;\n  padding: 12px;\n  border: 1px solid #ddd;\n}\n@media (max-width: 700px) {\n  .dashboard {\n    grid-template-columns: 1fr;\n  }\n}",
        ),
        array(
            'language' => 'js',
            'difficulty' => 'Medium',
            'theme' => 'canvas',
            'bug_shape' => 'wrong canvas api method name',
            'ui_surface' => 'particle trail canvas',
            'concept_tags' => array('canvas', 'api', 'drawing'),
            'snippet_size' => 'short',
            'source_name' => 'kirupa Canvas Intro',
            'source_url' => 'https://www.kirupa.com/html5/getting_started_with_canvas.htm',
            'lead' => 'Tiny canvas bug in this one.',
            'code' => "const canvas = document.querySelector('canvas');\nconst ctx = canvas.getContext('2d');\nctx.fillStyle = '#ff6b6b';\nctx.fillReact(20, 20, 60, 60);",
        ),
        array(
            'language' => 'js',
            'difficulty' => 'Easy',
            'theme' => 'color',
            'bug_shape' => 'off by one hex slice',
            'ui_surface' => 'theme color parser',
            'concept_tags' => array('color', 'hex', 'slice'),
            'snippet_size' => 'short',
            'source_name' => 'MDN String.slice',
            'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/String/slice',
            'lead' => 'Color helper has one tiny bug.',
            'code' => "function normalizeHex(hex) {\n  const value = hex.startsWith('#') ? hex : '#' + hex;\n  return value.slice(0, 6);\n}\n\nconsole.log(normalizeHex('#12abef'));",
        ),
        array(
            'language' => 'js',
            'difficulty' => 'Medium',
            'theme' => 'arrays',
            'bug_shape' => 'boolean sort comparator',
            'ui_surface' => 'scoreboard sorter',
            'concept_tags' => array('array', 'sort', 'comparator'),
            'snippet_size' => 'short',
            'source_name' => 'MDN Array.sort',
            'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Array/sort',
            'lead' => 'Sorting bug hiding in here.',
            'code' => "const scores = [12, 4, 30, 21];\nscores.sort((a, b) => a > b);\n\nconsole.log(scores);",
        ),
        array(
            'language' => 'js',
            'difficulty' => 'Easy',
            'theme' => 'dom',
            'bug_shape' => 'wrong dom selector api for multiple nodes',
            'ui_surface' => 'todo list counter',
            'concept_tags' => array('dom', 'selector', 'nodelist'),
            'snippet_size' => 'short',
            'source_name' => 'MDN querySelectorAll',
            'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/API/Document/querySelectorAll',
            'lead' => 'DOM bug, pretty sneaky.',
            'code' => "const items = document.querySelector('.todo li');\nconsole.log(items.length);\n",
        ),
        array(
            'language' => 'css',
            'difficulty' => 'Medium',
            'theme' => 'animation',
            'bug_shape' => 'missing transition time unit',
            'ui_surface' => 'hover lift card',
            'concept_tags' => array('css', 'transition', 'animation'),
            'snippet_size' => 'tiny',
            'source_name' => 'MDN transition',
            'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/CSS/transition',
            'lead' => 'Animation bug in plain sight.',
            'code' => ".card {\n  transition: transform 200 ease;\n}\n.card:hover {\n  transform: translateY(-4px);\n}",
        ),
        array(
            'language' => 'js',
            'difficulty' => 'Medium',
            'theme' => 'regex',
            'bug_shape' => 'string used instead of regex',
            'ui_surface' => 'slug formatter',
            'concept_tags' => array('regex', 'replace', 'strings'),
            'snippet_size' => 'short',
            'source_name' => 'MDN String.replace',
            'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/String/replace',
            'lead' => 'One regex bug in this formatter.',
            'code' => "function slugify(title) {\n  return title.trim().toLowerCase().replace('/\\s+/g', '-');\n}\n\nconsole.log(slugify('Hello World Again'));",
        ),
        array(
            'language' => 'js',
            'difficulty' => 'Medium',
            'theme' => 'objects',
            'bug_shape' => 'wrong destructured property name',
            'ui_surface' => 'card size helper',
            'concept_tags' => array('objects', 'destructuring', 'properties'),
            'snippet_size' => 'short',
            'source_name' => 'MDN Destructuring',
            'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Operators/Destructuring',
            'lead' => 'Object bug, one line off.',
            'code' => "const card = { width: 320, height: 180 };\nconst { widht, height } = card;\n\nconsole.log(widht * height);",
        ),
    );
}

function spot_is_overused_case(array $case): bool
{
    $theme = strtolower(trim((string)($case['theme'] ?? '')));
    $lead = strtolower(trim((string)($case['lead'] ?? '')));
    $code = strtolower((string)($case['code'] ?? ''));
    $blob = $theme . "\n" . $lead . "\n" . $code;
    return (bool)preg_match('/\b(lru|cache|memoiz|new\s+map\s*\(|map\s*\(\)|map\s*\[|hashmap)\b/i', $blob);
}

function spot_extract_json_object(string $content): ?array
{
    $content = trim($content);
    if ($content === '') return null;

    $decoded = json_decode($content, true);
    if (is_array($decoded)) return $decoded;

    $start = strpos($content, '{');
    $end = strrpos($content, '}');
    if ($start === false || $end === false || $end <= $start) return null;
    $slice = substr($content, (int)$start, (int)($end - $start + 1));
    $decoded = json_decode((string)$slice, true);
    return is_array($decoded) ? $decoded : null;
}

function spot_llm_json(array $payload): array
{
    return konvo_anthropic_chat_json($payload, 70);
}

function spot_generate_case_llm(array $state): array
{
    $recentLeads = array();
    if (isset($state['recent_leads']) && is_array($state['recent_leads'])) {
        foreach ($state['recent_leads'] as $v) {
            $s = trim((string)$v);
            if ($s !== '') $recentLeads[] = $s;
            if (count($recentLeads) >= 10) break;
        }
    }
    $avoid = $recentLeads === array() ? '(none)' : implode('; ', $recentLeads);
    $targetDifficulty = spot_pick_difficulty($state);
    $targetSize = spot_pick_size($state);
    $recentThemes = spot_recent_vals($state, 'recent_themes', 10);
    $themeAvoid = $recentThemes === array() ? '(none)' : implode(', ', $recentThemes);
    $recentShapes = spot_recent_vals($state, 'recent_bug_shapes', 12);
    $shapeAvoid = $recentShapes === array() ? '(none)' : implode('; ', $recentShapes);
    $recentSurfaces = spot_recent_vals($state, 'recent_surfaces', 12);
    $surfaceAvoid = $recentSurfaces === array() ? '(none)' : implode('; ', $recentSurfaces);
    $recentCases = spot_recent_case_records($state, 8);
    $recentCaseSummary = array();
    foreach ($recentCases as $row) {
        $line = array();
        $shape = trim((string)($row['bug_shape'] ?? ''));
        $surface = trim((string)($row['ui_surface'] ?? ''));
        $theme = trim((string)($row['theme'] ?? ''));
        if ($shape !== '') $line[] = 'shape=' . $shape;
        if ($surface !== '') $line[] = 'surface=' . $surface;
        if ($theme !== '') $line[] = 'theme=' . $theme;
        if ($line !== array()) $recentCaseSummary[] = implode(', ', $line);
    }
    $recentCaseBlock = $recentCaseSummary === array() ? '(none)' : implode(" | ", $recentCaseSummary);
    $attemptErrors = array();
    $triedThemes = array();

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $seed = spot_pick_seed($state);
        $sourceName = (string)($seed['source_name'] ?? 'MDN');
        $sourceUrl = (string)($seed['source_url'] ?? 'https://developer.mozilla.org/');
        $theme = strtolower(trim((string)($seed['theme'] ?? 'javascript')));
        if (in_array($theme, $triedThemes, true) && $attempt < 4) {
            continue;
        }
        $triedThemes[] = $theme;

        $system = 'You generate short forum coding challenges. Return strict JSON only.';
        $user = "Give me a coding example that has a bug in it, and the expectation is for the reader to detect the bug. "
            . "The coding example has to be HTML/CSS/JS based, and it should be a small self contained example or code snippet. "
            . "Keep the example itself quirky. The target difficulty should be for a beginner or intermediate.\n\n"
            . "Now adapt this into one web-dev forum \"Spot the Bug\" post.\n"
            . "Use this source as conceptual inspiration: {$sourceName} ({$sourceUrl}). Do not copy text verbatim.\n"
            . "Primary theme: {$theme}\n"
            . "Target difficulty: {$targetDifficulty}\n"
            . "Target snippet size: {$targetSize}\n"
            . "Recent themes to avoid repeating: {$themeAvoid}\n"
            . "Recent bug shapes to avoid repeating: {$shapeAvoid}\n"
            . "Recent UI surfaces to avoid repeating: {$surfaceAvoid}\n"
            . "Recent case summaries to avoid echoing: {$recentCaseBlock}\n"
            . "Do not use cache/Map/LRU/memoization examples unless the theme is explicitly collections.\n"
            . "Prefer variety across animation, logic, text manipulation, DOM events, forms, CSS layout, arrays/objects, canvas, color, and little UI interactions.\n"
            . "Return JSON with keys: language, lead, code, difficulty, theme, source_name, source_url, snippet_size, bug_shape, ui_surface, concept_tags.\n"
            . "Rules:\n"
            . "- language must be one of: js, ts, html, css.\n"
            . "- lead must be one short sentence (4-12 words), casual, no emoji.\n"
            . "- difficulty must be one of: Extremely Easy, Easy, Medium, Hard, Extremely Hard.\n"
            . "- snippet_size must be one of: tiny, short, medium, long, xlong.\n"
            . "- bug_shape must be a short lowercase label naming the actual bug archetype, like immediate handler invocation, wrong method name, missing preventdefault, stale closure, bad grid span, off by one, wrong property access.\n"
            . "- ui_surface must be a short lowercase label for where the bug lives, like search input, card grid, submit form, canvas loop, color picker, drag handle.\n"
            . "- concept_tags must be an array of 2-4 short lowercase tags.\n"
            . "- code length target by snippet_size: tiny=3-5 lines, short=6-9 lines, medium=10-15 lines, long=16-24 lines, xlong=25-40 lines.\n"
            . "- code must contain exactly one deliberate bug.\n"
            . "- no answer, no explanation, no comments revealing the bug.\n"
            . "- avoid repeating recent leads: {$avoid}\n"
            . "- do not reuse the same bug_shape or ui_surface from the recent history.\n"
            . "- output JSON only, no markdown.";

        $payload = array(
            'model' => konvo_model_for_task('deep_question', array('technical' => true)),
            'temperature' => 0.9,
            'max_tokens' => 500,
            'messages' => array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user', 'content' => $user),
            ),
        );

        $res = spot_llm_json($payload);
        if (!$res['ok']) {
            $attemptErrors[] = (string)($res['error'] ?? 'OpenAI request failed');
            continue;
        }

        $content = trim((string)($res['body']['choices'][0]['message']['content'] ?? ''));
        $obj = spot_extract_json_object($content);
        if (!is_array($obj)) {
            $attemptErrors[] = 'OpenAI response format error';
            continue;
        }

        $lang = spot_norm_lang((string)($obj['language'] ?? ''));
        $lead = trim((string)($obj['lead'] ?? ''));
        $code = rtrim((string)($obj['code'] ?? ''));
        $difficulty = trim((string)($obj['difficulty'] ?? $targetDifficulty));
        $pickedTheme = trim((string)($obj['theme'] ?? $theme));
        $pickedSourceName = trim((string)($obj['source_name'] ?? $sourceName));
        $pickedSourceUrl = trim((string)($obj['source_url'] ?? $sourceUrl));
        $pickedSize = strtolower(trim((string)($obj['snippet_size'] ?? $targetSize)));
        $bugShape = strtolower(trim((string)($obj['bug_shape'] ?? '')));
        $uiSurface = strtolower(trim((string)($obj['ui_surface'] ?? '')));
        $conceptTags = isset($obj['concept_tags']) && is_array($obj['concept_tags']) ? $obj['concept_tags'] : array();
        if ($lead === '' || $code === '') {
            $attemptErrors[] = 'OpenAI response missing fields';
            continue;
        }
        if (substr_count($code, "\n") < 2) {
            $attemptErrors[] = 'OpenAI code snippet too short';
            continue;
        }

        $candidate = array(
            'language' => $lang,
            'lead' => $lead,
            'code' => $code,
            'difficulty' => $difficulty,
            'theme' => $pickedTheme,
            'source_name' => $pickedSourceName,
            'source_url' => $pickedSourceUrl,
            'snippet_size' => $pickedSize,
            'bug_shape' => $bugShape,
            'ui_surface' => $uiSurface,
            'concept_tags' => $conceptTags,
        );
        if (spot_is_overused_case($candidate) && $attempt < 4) {
            $attemptErrors[] = 'Rejected repetitive cache/map style case';
            continue;
        }
        $similarity = spot_case_similarity_check($candidate, $state);
        if (!empty($similarity['similar']) && $attempt < 4) {
            $attemptErrors[] = 'Rejected near-duplicate case: ' . (string)($similarity['reason'] ?? 'too similar');
            continue;
        }

        return array('ok' => true, 'case' => $candidate);
    }

    return array(
        'ok' => false,
        'error' => 'LLM generation failed after retries',
        'attempt_errors' => $attemptErrors,
    );
}

function spot_pick_case(array $state): array
{
    $gen = spot_generate_case_llm($state);
    $targetDifficulty = spot_pick_difficulty($state);
    $targetSize = spot_pick_size($state);
    if (!empty($gen['ok']) && isset($gen['case']) && is_array($gen['case'])) {
        $case = $gen['case'];
        $case['_origin'] = 'llm';
        if (!isset($case['theme']) || trim((string)$case['theme']) === '') $case['theme'] = 'javascript';
        if (!isset($case['difficulty']) || trim((string)$case['difficulty']) === '') $case['difficulty'] = 'Medium';
        if (!isset($case['snippet_size']) || trim((string)$case['snippet_size']) === '') $case['snippet_size'] = 'medium';
        if (!isset($case['source_name'])) $case['source_name'] = '';
        if (!isset($case['source_url'])) $case['source_url'] = '';
        if (!isset($case['bug_shape'])) $case['bug_shape'] = '';
        if (!isset($case['ui_surface'])) $case['ui_surface'] = '';
        if (!isset($case['concept_tags']) || !is_array($case['concept_tags'])) $case['concept_tags'] = array();
        $case['_target_difficulty'] = $targetDifficulty;
        $case['_target_size'] = $targetSize;
        return $case;
    }

    $fallback = spot_fallback_cases();
    if ($fallback === array()) {
        return array(
            'language' => 'js',
            'lead' => 'Spot the bug in this snippet.',
            'code' => "console.log('hello');",
        );
    }
    $pool = array();
    foreach ($fallback as $c) {
        $cd = strtolower(trim((string)($c['difficulty'] ?? '')));
        $cs = strtolower(trim((string)($c['snippet_size'] ?? '')));
        if ($cd === strtolower($targetDifficulty) && $cs === strtolower($targetSize)) {
            $pool[] = $c;
        }
    }
    if ($pool === array()) {
        foreach ($fallback as $c) {
            $cd = strtolower(trim((string)($c['difficulty'] ?? '')));
            if ($cd === strtolower($targetDifficulty)) $pool[] = $c;
        }
    }
    if ($pool === array()) {
        foreach ($fallback as $c) {
            $cs = strtolower(trim((string)($c['snippet_size'] ?? '')));
            if ($cs === strtolower($targetSize)) $pool[] = $c;
        }
    }
    if ($pool === array()) $pool = $fallback;

    shuffle($pool);
    $candidates = array_merge($pool, $fallback);
    foreach ($candidates as $picked) {
        if (!is_array($picked)) continue;
        $picked['language'] = spot_norm_lang((string)($picked['language'] ?? 'js'));
        if (!isset($picked['theme'])) $picked['theme'] = 'javascript';
        if (!isset($picked['difficulty'])) $picked['difficulty'] = 'Medium';
        if (!isset($picked['snippet_size'])) $picked['snippet_size'] = 'medium';
        if (!isset($picked['source_name'])) $picked['source_name'] = '';
        if (!isset($picked['source_url'])) $picked['source_url'] = '';
        if (!isset($picked['bug_shape'])) $picked['bug_shape'] = '';
        if (!isset($picked['ui_surface'])) $picked['ui_surface'] = '';
        if (!isset($picked['concept_tags']) || !is_array($picked['concept_tags'])) $picked['concept_tags'] = array();
        $similarity = spot_case_similarity_check($picked, $state);
        if (!empty($similarity['similar'])) {
            continue;
        }
        $picked['_origin'] = 'fallback';
        $picked['_target_difficulty'] = $targetDifficulty;
        $picked['_target_size'] = $targetSize;
        return $picked;
    }

    $picked = $fallback[mt_rand(0, count($fallback) - 1)];
    $picked['language'] = spot_norm_lang((string)($picked['language'] ?? 'js'));
    if (!isset($picked['theme'])) $picked['theme'] = 'javascript';
    if (!isset($picked['difficulty'])) $picked['difficulty'] = 'Medium';
    if (!isset($picked['snippet_size'])) $picked['snippet_size'] = 'medium';
    if (!isset($picked['source_name'])) $picked['source_name'] = '';
    if (!isset($picked['source_url'])) $picked['source_url'] = '';
    if (!isset($picked['bug_shape'])) $picked['bug_shape'] = '';
    if (!isset($picked['ui_surface'])) $picked['ui_surface'] = '';
    if (!isset($picked['concept_tags']) || !is_array($picked['concept_tags'])) $picked['concept_tags'] = array();
    $picked['_origin'] = 'fallback';
    $picked['_target_difficulty'] = $targetDifficulty;
    $picked['_target_size'] = $targetSize;
    return $picked;
}

function spot_title_case_word(string $w): string
{
    if ($w === '') return '';
    // Keep short all-caps acronyms (DOM, CSS, API) as-is rather than title-casing them,
    // whether the source already capitalized them or not (theme slugs are lowercase).
    $acronyms = array('css', 'dom', 'api', 'html', 'json', 'sql', 'ui', 'ux', 'url');
    if (in_array(strtolower($w), $acronyms, true)) {
        return strtoupper($w);
    }
    if (preg_match('/^[A-Z0-9]{2,6}$/', $w)) return $w;
    return mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1);
}

function spot_derive_title_summary(array $case): string
{
    $source = trim((string)($case['ui_surface'] ?? ''));
    if ($source === '') {
        $source = str_replace(array('-', '_'), ' ', trim((string)($case['theme'] ?? '')));
    }
    if ($source === '') return '';
    $words = preg_split('/\s+/', trim($source)) ?: array();
    $words = array_values(array_filter(array_map('trim', $words)));
    if ($words === array()) return '';
    $words = array_slice($words, 0, 3);
    $words = array_map('spot_title_case_word', $words);
    return trim(implode(' ', $words));
}

function spot_build_raw(array $case, string $signature): string
{
    $lead = trim((string)($case['lead'] ?? 'Spot the bug in this snippet.'));
    if ($lead === '') $lead = 'Spot the bug in this snippet.';
    $lang = spot_norm_lang((string)($case['language'] ?? 'js'));
    $code = rtrim((string)($case['code'] ?? "console.log('hello');"));
    if ($code === '') $code = "console.log('hello');";

    $lines = array();
    $lines[] = function_exists('konvo_format_inline_code') ? konvo_format_inline_code($lead) : $lead;
    $lines[] = '';
    $lines[] = '```' . $lang;
    $lines[] = $code;
    $lines[] = '```';
    $lines[] = '';
    $lines[] = 'Reply with what is broken and how you would fix it.';
    $body = trim(implode("\n", $lines));

    return $body;
}

function spot_post_topic(string $botUsername, string $title, string $raw): array
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
        'ok' => ($err === '') && $status >= 200 && $status < 300 && is_array($decoded),
        'status' => $status,
        'error' => $err,
        'body' => is_array($decoded) ? $decoded : null,
        'raw' => (string)$body,
    );
}

$providedKey = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (KONVO_SECRET === '') {
    spot_out(500, array('ok' => false, 'error' => 'DISCOURSE_WEBHOOK_SECRET is not configured on the server.'));
}
if ($providedKey === '' || !spot_safe_hash_equals(KONVO_SECRET, $providedKey)) {
    spot_out(403, array('ok' => false, 'error' => 'Forbidden', 'hint' => 'Pass ?key=YOUR_SECRET'));
}
if (KONVO_API_KEY === '') {
    spot_out(500, array('ok' => false, 'error' => 'DISCOURSE_API_KEY is not configured on the server.'));
}

$dryRun = isset($_GET['dry_run']) && (string)$_GET['dry_run'] === '1';
$force = isset($_GET['force']) && (string)$_GET['force'] === '1';
$allowNewTopicsEnv = strtolower(trim((string)getenv('KONVO_ALLOW_NEW_TOPICS')));
$allowNewTopics = in_array($allowNewTopicsEnv, array('1', 'true', 'yes', 'on'), true);

if (!$dryRun && !$allowNewTopics && !$force) {
    spot_out(200, array(
        'ok' => true,
        'posted' => false,
        'reason' => 'new_topic_creation_disabled',
        'hint' => 'Set KONVO_ALLOW_NEW_TOPICS=1 or pass force=1 to override.',
    ));
}

$state = spot_load_state();
$lastNumber = (int)($state['last_number'] ?? 0);
$nextNumber = max(1, $lastNumber + 1);
$titleBase = 'Spot the bug - #' . $nextNumber;

$bot = spot_pick_bot($bots);
$signatureSeed = strtolower((string)($bot['username'] ?? '') . '|' . $titleBase . '|spot-the-bug');
$botSignature = function_exists('konvo_signature_with_optional_emoji')
    ? konvo_signature_with_optional_emoji((string)($bot['name'] ?? 'BayMax'), $signatureSeed)
    : (function_exists('konvo_signature_base_name')
        ? konvo_signature_base_name((string)($bot['name'] ?? 'BayMax'))
        : (string)($bot['name'] ?? 'BayMax'));
$case = spot_pick_case($state);

// Same reasoning as the coding challenge worker: a canned bug while the API is
// down means repeats, so skip the slot instead.
if ((string)($case['_origin'] ?? '') === 'fallback'
    && function_exists('konvo_anthropic_unavailable') && konvo_anthropic_unavailable()) {
    $lastFailure = function_exists('konvo_anthropic_last_failure') ? konvo_anthropic_last_failure() : array();
    spot_out(200, array(
        'ok' => true,
        'posted' => false,
        'reason' => 'model_unavailable',
        'detail' => (string)($lastFailure['error'] ?? ''),
        'status' => (int)($lastFailure['status'] ?? 0),
        'hint' => 'Skipped rather than posting a canned challenge. Will retry on the next scheduled run.',
    ));
}
$raw = spot_build_raw($case, $botSignature);
$titleSummary = spot_derive_title_summary($case);
$title = $titleSummary !== '' ? $titleBase . ': ' . $titleSummary : $titleBase;

if ($dryRun) {
    spot_out(200, array(
        'ok' => true,
        'dry_run' => true,
        'action' => 'would_post_spot_the_bug',
        'bot' => $bot,
        'topic' => array(
            'title' => $title,
            'category_id' => (int)KONVO_WEBDEV_CATEGORY_ID,
            'difficulty' => (string)($case['difficulty'] ?? ''),
            'theme' => (string)($case['theme'] ?? ''),
            'bug_shape' => (string)($case['bug_shape'] ?? ''),
            'ui_surface' => (string)($case['ui_surface'] ?? ''),
            'concept_tags' => isset($case['concept_tags']) && is_array($case['concept_tags']) ? $case['concept_tags'] : array(),
            'snippet_size' => (string)($case['snippet_size'] ?? ''),
            'source_name' => (string)($case['source_name'] ?? ''),
            'source_url' => (string)($case['source_url'] ?? ''),
            'origin' => (string)($case['_origin'] ?? ''),
            'target_difficulty' => (string)($case['_target_difficulty'] ?? ''),
            'target_size' => (string)($case['_target_size'] ?? ''),
            'raw_preview' => $raw,
        ),
    ));
}

$postRes = spot_post_topic((string)$bot['username'], $title, $raw);
if (!$postRes['ok']) {
    spot_out(500, array(
        'ok' => false,
        'error' => 'Failed to post Spot the bug topic.',
        'status' => (int)($postRes['status'] ?? 0),
        'curl_error' => (string)($postRes['error'] ?? ''),
        'response' => $postRes['body'],
        'raw' => (string)($postRes['raw'] ?? ''),
    ));
}

$topicId = (int)($postRes['body']['topic_id'] ?? 0);
$postNumber = (int)($postRes['body']['post_number'] ?? 1);
$topicUrl = rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '/' . $postNumber;

$recentLeads = isset($state['recent_leads']) && is_array($state['recent_leads']) ? $state['recent_leads'] : array();
array_unshift($recentLeads, (string)($case['lead'] ?? ''));
$cleanLeads = array();
foreach ($recentLeads as $v) {
    $s = trim((string)$v);
    if ($s === '') continue;
    if (!in_array($s, $cleanLeads, true)) $cleanLeads[] = $s;
}
$cleanLeads = array_slice($cleanLeads, 0, 20);

$recentThemes = isset($state['recent_themes']) && is_array($state['recent_themes']) ? $state['recent_themes'] : array();
array_unshift($recentThemes, strtolower(trim((string)($case['theme'] ?? ''))));
$recentThemes = array_values(array_filter(array_unique(array_map('strval', $recentThemes))));
$recentThemes = array_slice($recentThemes, 0, 20);

$recentShapes = isset($state['recent_bug_shapes']) && is_array($state['recent_bug_shapes']) ? $state['recent_bug_shapes'] : array();
array_unshift($recentShapes, strtolower(trim((string)($case['bug_shape'] ?? ''))));
$recentShapes = array_values(array_filter(array_unique(array_map('strval', $recentShapes))));
$recentShapes = array_slice($recentShapes, 0, 24);

$recentSurfaces = isset($state['recent_surfaces']) && is_array($state['recent_surfaces']) ? $state['recent_surfaces'] : array();
array_unshift($recentSurfaces, strtolower(trim((string)($case['ui_surface'] ?? ''))));
$recentSurfaces = array_values(array_filter(array_unique(array_map('strval', $recentSurfaces))));
$recentSurfaces = array_slice($recentSurfaces, 0, 24);

$recentDiff = isset($state['recent_difficulties']) && is_array($state['recent_difficulties']) ? $state['recent_difficulties'] : array();
array_unshift($recentDiff, strtolower(trim((string)($case['difficulty'] ?? ''))));
$recentDiff = array_values(array_filter(array_unique(array_map('strval', $recentDiff))));
$recentDiff = array_slice($recentDiff, 0, 20);

$recentSizes = isset($state['recent_sizes']) && is_array($state['recent_sizes']) ? $state['recent_sizes'] : array();
array_unshift($recentSizes, strtolower(trim((string)($case['snippet_size'] ?? ''))));
$recentSizes = array_values(array_filter(array_unique(array_map('strval', $recentSizes))));
$recentSizes = array_slice($recentSizes, 0, 20);

$recentSources = isset($state['recent_sources']) && is_array($state['recent_sources']) ? $state['recent_sources'] : array();
array_unshift($recentSources, strtolower(trim((string)($case['source_name'] ?? ''))));
$recentSources = array_values(array_filter(array_unique(array_map('strval', $recentSources))));
$recentSources = array_slice($recentSources, 0, 20);

$recentCases = isset($state['recent_cases']) && is_array($state['recent_cases']) ? $state['recent_cases'] : array();
array_unshift($recentCases, array(
    'bug_shape' => strtolower(trim((string)($case['bug_shape'] ?? ''))),
    'theme' => strtolower(trim((string)($case['theme'] ?? ''))),
    'ui_surface' => strtolower(trim((string)($case['ui_surface'] ?? ''))),
    'language' => strtolower(trim((string)($case['language'] ?? ''))),
    'lead' => trim((string)($case['lead'] ?? '')),
    'terms' => spot_text_terms((string)($case['code'] ?? '') . "\n" . (string)($case['lead'] ?? ''), 20),
    'concept_tags' => array_values(array_unique(array_filter(array_map(static function ($v): string {
        return spot_norm_text((string)$v);
    }, isset($case['concept_tags']) && is_array($case['concept_tags']) ? $case['concept_tags'] : array())))),
));
$cleanCases = array();
foreach ($recentCases as $row) {
    if (!is_array($row)) continue;
    $shape = strtolower(trim((string)($row['bug_shape'] ?? '')));
    $theme = strtolower(trim((string)($row['theme'] ?? '')));
    $surface = strtolower(trim((string)($row['ui_surface'] ?? '')));
    $lang = strtolower(trim((string)($row['language'] ?? '')));
    $lead = trim((string)($row['lead'] ?? ''));
    if ($shape === '' && $theme === '' && $surface === '' && $lead === '') continue;
    $cleanCases[] = array(
        'bug_shape' => $shape,
        'theme' => $theme,
        'ui_surface' => $surface,
        'language' => $lang,
        'lead' => $lead,
        'terms' => isset($row['terms']) && is_array($row['terms']) ? array_values(array_filter(array_map('strval', $row['terms']))) : array(),
        'concept_tags' => isset($row['concept_tags']) && is_array($row['concept_tags']) ? array_values(array_filter(array_map('strval', $row['concept_tags']))) : array(),
    );
}
$cleanCases = array_slice($cleanCases, 0, 24);

$state['last_number'] = $nextNumber;
$state['last_posted_at'] = time();
$state['last_topic_id'] = $topicId;
$state['last_post_number'] = $postNumber;
$state['last_title'] = $title;
$state['recent_leads'] = $cleanLeads;
$state['recent_themes'] = $recentThemes;
$state['recent_bug_shapes'] = $recentShapes;
$state['recent_surfaces'] = $recentSurfaces;
$state['recent_difficulties'] = $recentDiff;
$state['recent_sizes'] = $recentSizes;
$state['recent_sources'] = $recentSources;
$state['recent_cases'] = $cleanCases;
spot_save_state($state);

spot_out(200, array(
    'ok' => true,
    'posted' => true,
    'action' => 'posted_spot_the_bug',
    'bot' => $bot,
    'topic' => array(
        'id' => $topicId,
        'post_number' => $postNumber,
        'title' => $title,
        'category_id' => (int)KONVO_WEBDEV_CATEGORY_ID,
        'url' => $topicUrl,
        'difficulty' => (string)($case['difficulty'] ?? ''),
        'theme' => (string)($case['theme'] ?? ''),
        'snippet_size' => (string)($case['snippet_size'] ?? ''),
        'source_name' => (string)($case['source_name'] ?? ''),
        'source_url' => (string)($case['source_url'] ?? ''),
        'origin' => (string)($case['_origin'] ?? ''),
        'target_difficulty' => (string)($case['_target_difficulty'] ?? ''),
        'target_size' => (string)($case['_target_size'] ?? ''),
    ),
));
