<?php

/*
 * Which model handles which job.
 *
 * Four tiers, cheapest first. The split that matters for cost is xs vs the
 * rest: roughly two thirds of all calls are yes/no scoring, category picks and
 * uniqueness checks, and those do not need a capable model. Putting them on
 * nano is most of the saving and carries no quality risk, because nothing they
 * produce is ever published: they only gate or label text written elsewhere.
 *
 * Prices per 1M tokens, verified 2026-08-11 (input / cached input / output):
 *   gpt-5.4-nano   0.20 / 0.02  / 1.25
 *   gpt-5.4-mini   0.75 / 0.075 / 4.50
 *   gpt-5.4        2.50 / 0.25  / 15.00
 *
 * The 5.4 family is used rather than the cheaper gpt-5 / gpt-5-mini / gpt-5-nano
 * on purpose. Those reject any non-default temperature and spend part of the
 * completion budget on hidden reasoning tokens, which returns empty replies.
 * This codebase leans on temperature for variety (0.9 topics, 0.2 grading), so
 * the ~4x cheaper family would cost us the main lever against robotic prose.
 *
 * Every tier is overridable without a deploy:
 *   SetEnv MODEL_TIER_XS gpt-5.4-nano
 *   SetEnv MODEL_TIER_S  gpt-5.4-mini
 *   SetEnv MODEL_TIER_M  gpt-5.4-mini
 *   SetEnv MODEL_TIER_L  gpt-5.4
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
            'xs' => $xs !== '' ? $xs : 'gpt-5.4-nano',
            's'  => $s  !== '' ? $s  : 'gpt-5.4-mini',
            'm'  => $m  !== '' ? $m  : 'gpt-5.4-mini',
            'l'  => $l  !== '' ? $l  : 'gpt-5.4',
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
            // Scoring, labelling and short picks over text that already exists.
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

            // ---- s: short generation that readers do see ---------------------
            case 'reply_ack':
            case 'quality_rewrite':
            case 'casual_topic':
                return $tiers['s'];

            // ---- m: the main prose and code the forum is judged on -----------
            case 'reply_generation':
            case 'reply_generation_technical':
            case 'reply_rewrite':
            case 'quality_hard':
            case 'technical_framework_rewrite':
            case 'code_repair':
            case 'deep_question':
                return $tiers['m'];

            // ---- l: last-resort rescue on technical threads ------------------
            case 'quality_rescue':
                return $technical ? $tiers['l'] : $tiers['m'];

            default:
                return $tiers['m'];
        }
    }
}
