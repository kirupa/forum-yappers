<?php

/*
 * Which model handles which job. Routed to Google Gemini, Flash line only.
 *
 * Prices per 1M tokens, verified 2026-08-14 (input / output):
 *   gemini-3.1-flash-lite   0.25 / 1.50   no thinking tokens
 *   gemini-2.5-flash        0.30 / 2.50   thinks by default; the client sends
 *                                         thinkingBudget=0, since Gemini bills
 *                                         thinking at the output rate
 *
 * Deliberately NOT used: gemini-3.5-flash (1.50 / 9.00) and anything in the Pro
 * line. gemini-2.5-flash-lite would have been cheapest but returns 404, retired
 * for new keys.
 *
 * The split that matters: roughly two thirds of calls are scoring, labelling and
 * uniqueness checks whose output is never published, only used to gate text
 * written elsewhere. Those run on the lite model. Prose the forum is judged on
 * gets gemini-2.5-flash.
 *
 * Overridable without a deploy:
 *   SetEnv MODEL_TIER_XS gemini-3.1-flash-lite
 *   SetEnv MODEL_TIER_S  gemini-2.5-flash
 *   SetEnv MODEL_TIER_M  gemini-2.5-flash
 *   SetEnv MODEL_TIER_L  gemini-2.5-flash
 */

declare(strict_types=1);

if (!function_exists('konvo_model_tiers')) {
    function konvo_model_tiers(): array
    {
        static $tiers = null;
        if (is_array($tiers)) {
            return $tiers;
        }

        $xs = trim((string)getenv('MODEL_TIER_XS'));
        $s  = trim((string)getenv('MODEL_TIER_S'));
        $m  = trim((string)getenv('MODEL_TIER_M'));
        $l  = trim((string)getenv('MODEL_TIER_L'));

        $tiers = [
            'xs' => $xs !== '' ? $xs : 'gemini-3.1-flash-lite',
            's'  => $s  !== '' ? $s  : 'gemini-2.5-flash',
            'm'  => $m  !== '' ? $m  : 'gemini-2.5-flash',
            'l'  => $l  !== '' ? $l  : 'gemini-2.5-flash',
        ];
        return $tiers;
    }
}

if (!function_exists('konvo_model_for_task')) {
    function konvo_model_for_task(string $task, array $ctx = []): string
    {
        $tiers = konvo_model_tiers();
        $technical = !empty($ctx['technical']);

        switch ($task) {
            // ---- xs: nothing here is ever published verbatim -----------------
            case 'poll_pick':
            case 'quality_eval':
            case 'topic_category':
            case 'topic_uniqueness':
            case 'casual_topic_seed_pick':
            case 'low_effort_reaction':
            case 'article_title':
            case 'article_summary':
            case 'article_image_lead':
            case 'article_video_lead':
                return $tiers['xs'];

            // ---- s: short generation readers do see ---------------------------
            case 'reply_ack':
            case 'quality_rewrite':
            case 'casual_topic':
                return $tiers['s'];

            // ---- m: the main prose and code -----------------------------------
            case 'reply_generation':
            case 'reply_generation_technical':
            case 'reply_rewrite':
            case 'quality_hard':
            case 'technical_framework_rewrite':
            case 'code_repair':
            case 'deep_question':
                return $tiers['m'];

            case 'quality_rescue':
                return $technical ? $tiers['l'] : $tiers['m'];

            default:
                return $tiers['m'];
        }
    }
}
