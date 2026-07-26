<?php

namespace EmbedPress\Includes\Classes;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Background, batched Apify fetch for "Fetch all reviews".
 *
 * Fetching all reviews for a place can return hundreds of items — too slow for
 * one synchronous request (Apify run-sync hard-caps at 300s). So instead:
 *
 *   1. start_job(): POST an ASYNC Apify run (returns immediately with a run id),
 *      mark the place fetch_status=running.
 *   2. WP-cron (ep_gr_poll_apify) polls the run; once SUCCEEDED, it pages the
 *      dataset in BATCHES (PAGE_SIZE at a time), appending each batch to the DB
 *      store and updating fetched_so_far, then reschedules itself until the
 *      whole dataset is imported. status→done.
 *
 * The admin UI shows live progress ("fetching… N so far"); the block always
 * renders whatever is already stored (never blocked on the fetch).
 *
 * Moved from embedpress-pro/includes/Filters/Google_Reviews_Apify.php to free
 * so refetch works without Pro. Pro extends behaviour via filters (multi-place
 * merge, advanced layouts, filtering, theme, schema) — see
 * embedpress-pro/includes/Filters/Google_Reviews_Pro.php.
 */
class GoogleReviewsApify
{
    const ACTOR        = 'compass~google-maps-reviews-scraper';
    const CRON_HOOK    = 'ep_gr_poll_apify';
    const PAGE_SIZE    = 100;   // reviews imported per cron tick
    const MAX_TOTAL    = 1000;  // safety ceiling per place

    public function __construct()
    {
        add_action(self::CRON_HOOK, [$this, 'poll_job'], 10, 1);
        // Default behaviour for the renderer's extension points. Pro overrides
        // these with higher-priority callbacks to layer in multi-place merge,
        // unlimited fetch caps, etc.
        add_filter('embedpress/google_reviews/start_fetch_job', [$this, 'start_fetch_job_filter'], 10, 3);
        add_filter('embedpress/google_reviews/pre_fetch', [$this, 'pre_fetch_filter'], 10, 3);
    }

    public static function api_base(): string
    {
        return 'https://api.apify.com/v2';
    }

    /**
     * Filter callback for the renderer's "start_fetch_job" hook (used by the
     * refetch REST action). Returns true if the background job started so the
     * caller skips the sync ≤5 fallback.
     */
    public function start_fetch_job_filter($started, $place_id, $args)
    {
        if ($started) {
            return $started; // another handler already started one
        }
        if (GoogleReviewsRenderer::get_apify_token() === '') {
            return false; // no token → let the caller do the sync ≤5 fallback
        }
        return self::start_job((string) $place_id, is_array($args) ? $args : []);
    }

    /**
     * Filter callback for the renderer's "pre_fetch" hook. Single-place Apify
     * sync fetch when "fetch all" is on and a token is set. Multi-place merge
     * is layered on by Pro at a higher priority.
     *
     * Returns null to fall through to the official ≤5 API path.
     */
    public function pre_fetch_filter($pre, $place_id, $args)
    {
        if (is_array($pre)) {
            return $pre; // another handler already produced a result
        }
        $fetch_all = !empty($args['fetch_all']) && GoogleReviewsRenderer::get_apify_token() !== '';
        if (!$fetch_all) {
            return null;
        }
        $fetch_max = isset($args['fetch_max']) && (int) $args['fetch_max'] > 0
            ? (int) $args['fetch_max']
            : 0; // 0 = "all" (provider applies its own ceiling)
        $result = self::fetch_via_apify_sync($place_id, $fetch_max, is_array($args) ? $args : []);
        return is_array($result) ? $result : null;
    }

    /**
     * Kick off an async Apify run for a place and mark the job running.
     * Returns true if the run started, false (with a stored error) otherwise.
     */
    public static function start_job(string $place_id, array $args = []): bool
    {
        $token = GoogleReviewsRenderer::get_apify_token();
        if ($token === '' || $place_id === '') {
            GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_FAILED, ['message' => __('No Apify token configured.', 'embedpress')]);
            return false;
        }

        // Two refetch modes:
        //   - incremental (default): preserve the existing review set, ask Apify
        //     for a small newest-first window, stop importing the moment we hit
        //     a fingerprint already in the DB. Cheap, picks up new reviews
        //     without re-fetching the whole list.
        //   - full: wipe and re-pull. The user-explicit "give me a fresh copy"
        //     mode — surfaces edits/deletes, costs more.
        $incremental = !empty($args['incremental']);

        // Ceiling is the hard safety cap; the user's chosen count (fetch_max)
        // lowers it so they control how many reviews — and thus how much Apify
        // cost — each refetch incurs. fetch_max = 0 (or unset) means "all up to
        // the ceiling".
        $ceiling   = (int) apply_filters('embedpress/google_reviews/apify_ceiling', self::MAX_TOTAL, $place_id, $args);
        $fetch_max = isset($args['fetch_max']) ? (int) $args['fetch_max'] : 0;

        if ($incremental && $fetch_max <= 0) {
            // Size the incremental window to actual need using Google's
            // reported total (cached in meta from a previous fetch):
            //   expected_new = google_total - already_in_db
            // Add a 20% buffer for last-minute reviews + cap at the ceiling
            // so a brand-new place with 50,000 reviews doesn't accidentally
            // run a full scrape on a quick check.
            $row = GoogleReviewsStore::get($place_id);
            $meta_total = 0;
            $db_count   = 0;
            if ($row) {
                $meta_total = isset($row['meta']['total']) ? (int) $row['meta']['total'] : 0;
                $db_count   = isset($row['review_count']) ? (int) $row['review_count'] : 0;
            }
            $expected_new = max(0, $meta_total - $db_count);
            $window_floor = (int) apply_filters('embedpress/google_reviews/apify_incremental_floor', 50, $place_id, $args);
            $window_ceil  = (int) apply_filters('embedpress/google_reviews/apify_incremental_ceiling', 500, $place_id, $args);
            $fetch_max    = max($window_floor, min($window_ceil, (int) ceil($expected_new * 1.2) + 5));
        }
        $max = ($fetch_max > 0) ? min($fetch_max, $ceiling) : $ceiling;

        $payload = [
            'startUrls'   => [['url' => 'https://www.google.com/maps/place/?q=place_id:' . $place_id]],
            'placeIds'    => [$place_id],
            'maxReviews'  => $max,
            'language'    => 'en',
            'reviewsSort' => self::sort_param($args),
        ];

        // Pin memory so the run fits Apify's free 8192MB plan. The reviews-scraper
        // defaults to 1024MB; we keep that explicit (filterable for paid plans
        // that want more for faster/bigger fetches). Without pinning, an actor
        // default bump or an overlapping run can trip "you will exceed the memory
        // limit". See embedpress/google_reviews/apify_search_memory (search side).
        $memory   = (int) apply_filters('embedpress/google_reviews/apify_fetch_memory', 1024, $place_id, $args);
        $endpoint = self::api_base() . '/acts/' . self::ACTOR . '/runs?token=' . rawurlencode($token);
        if ($memory > 0) {
            $endpoint .= '&memory=' . $memory;
        }
        $response = wp_remote_post($endpoint, [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_FAILED, ['message' => $response->get_error_message()]);
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (($code !== 200 && $code !== 201) || empty($body['data']['id'])) {
            $msg = $body['error']['message'] ?? sprintf(__('Apify returned HTTP %d.', 'embedpress'), $code);
            GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_FAILED, ['message' => $msg]);
            return false;
        }

        // FULL mode: clear the stored set so this refresh REPLACES the reviews
        // (batches append as they import; without the reset they'd stack on top
        // of the previous fetch and inflate the count).
        // INCREMENTAL mode: keep the existing set; new reviews append via the
        // store's dedup; cron stops on first known-fingerprint hit.
        if (!$incremental) {
            GoogleReviewsStore::reset_reviews($place_id);
        }

        // Persist the mode so poll_job (running in a different request) knows
        // whether to apply early-stop fingerprint matching. Transient TTL bounds
        // any leak if the job dies — ample for a typical job that finishes in
        // under 5 minutes.
        set_transient(self::mode_transient_key($place_id), $incremental ? 'incremental' : 'full', 30 * MINUTE_IN_SECONDS);

        $run_id = (string) $body['data']['id'];
        GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_RUNNING, [
            'run_id'  => $run_id,
            'message' => $incremental ? __('Checking for new reviews…', 'embedpress') : __('Starting…', 'embedpress'),
            // Preserve fetched_so_far in incremental mode — it's a running count
            // of reviews already in the DB, not a job-local counter.
        ]);

        self::schedule_poll($place_id, 15);
        return true;
    }

    private static function mode_transient_key(string $place_id): string
    {
        return 'embedpress_gr_job_mode_' . md5($place_id);
    }

    public static function get_job_mode(string $place_id): string
    {
        return (string) (get_transient(self::mode_transient_key($place_id)) ?: 'full');
    }

    public static function clear_job_mode(string $place_id): void
    {
        delete_transient(self::mode_transient_key($place_id));
    }

    /** Schedule a one-off poll for a place after $delay seconds. */
    public static function schedule_poll(string $place_id, int $delay = 30): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK, [$place_id])) {
            wp_schedule_single_event(time() + max(5, $delay), self::CRON_HOOK, [$place_id]);
        }
    }

    /**
     * Cron callback. Check the run; when finished, import one batch of dataset
     * items and reschedule until the whole dataset is in the DB.
     */
    public function poll_job($place_id)
    {
        $place_id = (string) $place_id;
        $row = GoogleReviewsStore::get($place_id);
        if (!$row || ($row['fetch_status'] ?? '') !== GoogleReviewsStore::STATUS_RUNNING) {
            return; // cancelled / already done
        }
        $token  = GoogleReviewsRenderer::get_apify_token();
        $run_id = (string) ($row['fetch_run_id'] ?? '');
        if ($token === '' || $run_id === '') {
            GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_FAILED, ['message' => __('Lost run reference.', 'embedpress')]);
            self::clear_job_mode($place_id);
            return;
        }

        // 1) Check run status.
        $run = self::get_json(self::api_base() . '/actor-runs/' . rawurlencode($run_id) . '?token=' . rawurlencode($token));
        $status = $run['data']['status'] ?? 'UNKNOWN';

        if (in_array($status, ['READY', 'RUNNING'], true)) {
            // still working — check again shortly
            self::schedule_poll($place_id, 20);
            GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_RUNNING, ['message' => __('Fetching from Google…', 'embedpress')]);
            return;
        }
        if ($status !== 'SUCCEEDED') {
            GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_FAILED, ['message' => sprintf(__('Apify run %s.', 'embedpress'), strtolower($status))]);
            self::clear_job_mode($place_id);
            return;
        }

        $dataset_id = $run['data']['defaultDatasetId'] ?? '';
        if ($dataset_id === '') {
            GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_FAILED, ['message' => __('No dataset produced.', 'embedpress')]);
            self::clear_job_mode($place_id);
            return;
        }

        // 2) Import one batch. The DATASET OFFSET (where we are inside Apify's
        // result set) differs from fetched_so_far in incremental mode:
        //   - full mode: store was wiped at start_job, so fetched_so_far == dataset offset.
        //   - incremental: store was preserved, so fetched_so_far is the existing
        //     review count, NOT a scan position. Use a transient counter instead.
        $mode = self::get_job_mode($place_id);
        $incremental = ($mode === 'incremental');

        if ($incremental) {
            $offset_key = 'embedpress_gr_job_offset_' . md5($place_id);
            $offset = (int) (get_transient($offset_key) ?: 0);
        } else {
            $offset = (int) ($row['fetched_so_far'] ?? 0);
        }

        $items = self::get_json(
            self::api_base() . '/datasets/' . rawurlencode($dataset_id) . '/items'
            . '?clean=true&format=json&offset=' . $offset . '&limit=' . self::PAGE_SIZE
            . '&token=' . rawurlencode($token)
        );

        if (!is_array($items) || empty($items)) {
            // Nothing more → finalize.
            GoogleReviewsStore::append_reviews($place_id, [], [], 'apify', true);
            GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_DONE, ['message' => null, 'run_id' => null]);
            self::clear_job_mode($place_id);
            if ($incremental) delete_transient('embedpress_gr_job_offset_' . md5($place_id));
            return;
        }

        $mapped = self::map_items($items);
        $meta   = !empty($row['meta']) && empty($row['meta']['name']) === false ? $row['meta'] : $mapped['meta'];

        // Incremental: walk batch newest-first; the FIRST review we already
        // have means we've caught up — everything past it is already stored.
        // Apify returns reviews newest-first by default, so this works.
        $caught_up = false;
        if ($incremental) {
            $known = GoogleReviewsStore::fingerprints_for($place_id);
            $new_in_batch = [];
            foreach ($mapped['reviews'] as $r) {
                $fp = GoogleReviewsStore::fingerprint_of($r);
                if (isset($known[$fp])) {
                    $caught_up = true;
                    break;
                }
                $new_in_batch[] = $r;
            }
            $mapped['reviews'] = $new_in_batch;
        }

        // Append this batch; final if we caught up (incremental), got a short
        // page, or hit the safety ceiling.
        $reached_end = $caught_up
            || count($items) < self::PAGE_SIZE
            || ($offset + count($items)) >= self::MAX_TOTAL;
        GoogleReviewsStore::append_reviews($place_id, $mapped['reviews'], $meta, 'apify', $reached_end);

        if ($reached_end) {
            $new_count = count($mapped['reviews'] ?? []);
            // Surface a "may have missed more" hint when incremental finishes
            // without finding a known fingerprint — likely the window was too
            // small. Encourages the user to do a Full refresh.
            $message = null;
            if ($incremental) {
                if ($new_count === 0) {
                    $message = __('Already up to date.', 'embedpress');
                } elseif (!$caught_up) {
                    $message = sprintf(
                        /* translators: %d is the number of newly added reviews */
                        _n(
                            'Added %d new review. There may be more — run a Full refresh to be sure.',
                            'Added %d new reviews. There may be more — run a Full refresh to be sure.',
                            $new_count,
                            'embedpress'
                        ),
                        $new_count
                    );
                } else {
                    $message = sprintf(
                        _n('Added %d new review.', 'Added %d new reviews.', $new_count, 'embedpress'),
                        $new_count
                    );
                }
            }
            GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_DONE, [
                'message' => $message,
                'run_id'  => null,
            ]);
            self::clear_job_mode($place_id);
            if ($incremental) delete_transient('embedpress_gr_job_offset_' . md5($place_id));
        } else {
            if ($incremental) {
                set_transient('embedpress_gr_job_offset_' . md5($place_id), $offset + count($items), 30 * MINUTE_IN_SECONDS);
            }
            GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_RUNNING, [
                'message' => $incremental
                    ? __('Checking for new reviews…', 'embedpress')
                    : sprintf(__('Imported %d…', 'embedpress'), $offset + count($items)),
            ]);
            self::schedule_poll($place_id, 8);
        }
    }

    /**
     * Synchronous single-place Apify fetch (used by pre_fetch_filter for the
     * non-background path). Bounded by $limit + the actor ceiling. Returns
     * {reviews, meta} or null on any failure (caller falls back to API).
     *
     * @return array|null {reviews, meta}
     */
    public static function fetch_via_apify_sync(string $place_id, int $limit, array $args = [])
    {
        $token = GoogleReviewsRenderer::get_apify_token();
        if ($token === '' || $place_id === '') {
            return null;
        }
        $ceiling = (int) apply_filters('embedpress/google_reviews/apify_ceiling', 200, $place_id, $args);
        $max     = ($limit <= 0) ? $ceiling : min($limit, $ceiling);

        $memory   = (int) apply_filters('embedpress/google_reviews/apify_fetch_memory', 1024, $place_id, $args);
        $endpoint = self::api_base() . '/acts/' . self::ACTOR . '/run-sync-get-dataset-items?token=' . rawurlencode($token);
        if ($memory > 0) {
            $endpoint .= '&memory=' . $memory;
        }
        $payload = [
            'startUrls'   => [['url' => 'https://www.google.com/maps/place/?q=place_id:' . $place_id]],
            'placeIds'    => [$place_id],
            'maxReviews'  => $max,
            'language'    => 'en',
            'reviewsSort' => self::sort_param($args),
        ];

        $response = wp_remote_post($endpoint, [
            'timeout' => 120, // generous; the actor itself is capped at 300s server-side
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200 && $code !== 201) {
            return null;
        }
        $items = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($items) || empty($items)) {
            return null;
        }
        $mapped = self::map_items($items);
        return empty($mapped['reviews']) ? null : $mapped;
    }

    /* ── helpers ──────────────────────────────────────────────────────── */

    private static function get_json(string $url)
    {
        $res = wp_remote_get($url, ['timeout' => 30, 'headers' => ['Accept' => 'application/json']]);
        if (is_wp_error($res)) {
            return null;
        }
        return json_decode(wp_remote_retrieve_body($res), true);
    }

    private static function sort_param(array $args): string
    {
        switch (isset($args['sort']) ? (string) $args['sort'] : 'newest') {
            case 'highest':  return 'highestRanking';
            case 'lowest':   return 'lowestRanking';
            case 'relevant': return 'mostRelevant';
            default:         return 'newest';
        }
    }

    /**
     * Map Apify dataset items → {reviews, meta}, same shape EmbedPress uses.
     */
    public static function map_items(array $items): array
    {
        $reviews = [];
        $meta    = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            if (empty($meta)) {
                $meta = [
                    'name'    => (string) ($it['title'] ?? $it['placeName'] ?? ''),
                    'rating'  => isset($it['totalScore']) ? (float) $it['totalScore'] : 0.0,
                    'total'   => isset($it['reviewsCount']) ? (int) $it['reviewsCount'] : 0,
                    'address' => (string) ($it['address'] ?? ''),
                ];
            }
            $stars = isset($it['stars']) ? (int) $it['stars'] : 0;
            if ($stars < 1) {
                continue;
            }
            $time = 0;
            if (!empty($it['publishedAtDate'])) {
                $t = strtotime((string) $it['publishedAtDate']);
                $time = $t ? $t : 0;
            }
            // Rich fields (Apify-only — Google's official API never returns
            // these). Each is optional; the renderer renders them only when
            // present, so API-sourced reviews simply omit them.
            $images = [];
            if (!empty($it['reviewImageUrls']) && is_array($it['reviewImageUrls'])) {
                foreach ($it['reviewImageUrls'] as $img) {
                    $u = esc_url_raw((string) $img);
                    if ($u !== '') {
                        $images[] = $u;
                    }
                }
            }

            $reviews[] = [
                'author_name'          => (string) ($it['name'] ?? __('Google user', 'embedpress')),
                'rating'               => $stars,
                'text'                 => (string) ($it['text'] ?? $it['textTranslated'] ?? ''),
                'time'                 => $time,
                'relative_time'        => (string) ($it['publishAt'] ?? ''),
                'profile_photo_url'    => !empty($it['reviewerPhotoUrl']) ? esc_url_raw((string) $it['reviewerPhotoUrl']) : '',
                // Rich (Apify):
                'is_local_guide'       => !empty($it['isLocalGuide']),
                'reviewer_reviews'     => isset($it['reviewerNumberOfReviews']) ? (int) $it['reviewerNumberOfReviews'] : 0,
                'likes'                => isset($it['likesCount']) ? (int) $it['likesCount'] : 0,
                'images'               => $images,
                'owner_response'       => (string) ($it['responseFromOwnerText'] ?? ''),
                'owner_response_time'  => (string) ($it['responseFromOwnerDate'] ?? ''),
            ];
        }
        return ['reviews' => $reviews, 'meta' => $meta];
    }
}
