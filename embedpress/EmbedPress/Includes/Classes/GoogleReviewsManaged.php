<?php

namespace EmbedPress\Includes\Classes;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hosted-proxy review fetch via api.embedpress.com/google-reviews/v1.
 *
 * Lets EmbedPress installs fetch reviews with ZERO user setup — search runs
 * through the existing managed_search endpoint (`google-places.php`), and
 * now the review fetch runs through the scraping worker at
 * `api.embedpress.com/google-reviews/v1`. The site never sees an API key,
 * never connects an Apify token; the proxy holds both.
 *
 * Architecture mirrors GoogleReviewsApify on purpose so the two are
 * interchangeable from the renderer's POV:
 *
 *   start_job($place_id, $args)
 *     → POSTs to enqueue.php with the place_id + max
 *     → on cache hit (24h): writes reviews directly to the store, done
 *     → on queued: stores the job_id in fetch_run_id, schedules a WP-cron poll
 *
 *   poll_job($place_id)
 *     → GETs status.php?job_id=…
 *     → "running" → reschedule
 *     → "done"    → write reviews to the store, status=done
 *     → "failed"  → set the failed status with the proxy's message
 *
 * This class is the LOWEST-priority handler on the `start_fetch_job` and
 * `pre_fetch` filters — Apify (user's token) wins when connected; managed
 * runs as the fallback when no user keys exist.
 */
class GoogleReviewsManaged
{
    const CRON_HOOK = 'ep_gr_poll_managed';

    // Where the proxy lives. Filterable so dev/staging environments can
    // point at a local instance.
    const DEFAULT_ENDPOINT = 'https://api.embedpress.com/google-reviews/v1';

    // wp_options key holding the Bearer token + binding metadata returned
    // by api.embedpress.com/google-reviews/v1/connect.php. Shape:
    //   [
    //     'token'        => 'epgr_...',
    //     'site_id'      => '<uuid>',
    //     'home_url'     => 'https://example.com',
    //     'fingerprint'  => '<sha256 hex>',
    //     'tier'         => 'free' | 'pro',
    //     'connected_at' => 1781700000,
    //   ]
    // Stored with autoload=no so it doesn't bloat every wp_load_alloptions().
    const OPT_AUTH = 'embedpress_google_reviews_managed_auth';

    public function __construct()
    {
        add_action(self::CRON_HOOK, [$this, 'poll_job'], 10, 1);
        // Priority 20 = LOWER than Apify (10) and Pro overrides. Apify-with-
        // token takes precedence; managed kicks in only when nothing else
        // produces a result.
        add_filter('embedpress/google_reviews/start_fetch_job', [$this, 'start_fetch_job_filter'], 20, 3);
        add_filter('embedpress/google_reviews/pre_fetch', [$this, 'pre_fetch_filter'], 20, 3);
    }

    public static function endpoint(): string
    {
        return (string) apply_filters(
            'embedpress/google_reviews/managed_endpoint',
            self::DEFAULT_ENDPOINT
        );
    }

    /**
     * Sibling endpoint: api.embedpress.com/google-places.php hosts the
     * INSTANT review-fetch path (Places Details with reviews) — up to 5
     * reviews per place returned in ~300ms, no Chrome involved. Reuses the
     * same Bearer token issued by connect.php.
     *
     * We derive the URL from `endpoint()` so a dev-override (filter pointing
     * at staging) keeps both endpoints in sync.
     */
    public static function instant_endpoint(): string
    {
        $base = rtrim(self::endpoint(), '/');
        // endpoint() points at /google-reviews/v1 — pop two segments to get
        // the host root, then append /google-places.php.
        $root = preg_replace('#/google-reviews/v1$#', '', $base);
        $root = rtrim($root, '/');
        return (string) apply_filters(
            'embedpress/google_reviews/managed_instant_endpoint',
            $root . '/google-places.php'
        );
    }

    /**
     * Synchronous, instant 5-review fetch via Google Places API.
     *
     * Returns:
     *   ['ok' => true, 'reviews' => [...], 'meta' => [...]]   on success
     *   ['ok' => false, 'message' => string]                  on failure
     *
     * Used by the add-place handler so the user sees real reviews on the
     * place card the moment they add it — no spinner, no background job.
     * Chrome-based scraping is reserved for "Fetch all" when the user
     * explicitly wants more than the 5 Google's Place Details returns.
     */
    public static function fetch_instant(string $place_id): array
    {
        if ($place_id === '') {
            return ['ok' => false, 'message' => __('Missing place ID.', 'embedpress')];
        }
        if (!self::is_connected()) {
            return ['ok' => false, 'message' => __('Not connected to EmbedPress API.', 'embedpress')];
        }

        $url = add_query_arg(
            [
                'action'   => 'reviews',
                'place_id' => $place_id,
            ],
            self::instant_endpoint()
        );

        $response = wp_remote_get($url, [
            'timeout' => (int) apply_filters('embedpress/google_reviews/instant_timeout', 8, $place_id),
            'headers' => self::auth_headers(),
        ]);
        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => $response->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        // Auth failure: surface as a reconnect prompt, mirroring start_job.
        $auth_msg = self::auth_failure_message($code, $body);
        if ($auth_msg !== '') {
            if ($code === 401 && is_array($body) && in_array(($body['error'] ?? ''), ['invalid_token', 'missing_token'], true)) {
                self::disconnect();
            }
            return ['ok' => false, 'message' => $auth_msg];
        }

        if ($code !== 200 || !is_array($body)) {
            $msg = is_array($body) && !empty($body['message'])
                ? (string) $body['message']
                : __('Couldn’t fetch reviews this time. Please try again.', 'embedpress');
            return ['ok' => false, 'message' => $msg];
        }

        $reviews = isset($body['reviews']) && is_array($body['reviews']) ? array_values($body['reviews']) : [];
        $meta    = isset($body['meta'])    && is_array($body['meta'])    ? $body['meta']    : [];
        return ['ok' => true, 'reviews' => $reviews, 'meta' => $meta];
    }

    // ------------------------------------------------------------------
    // Connect / disconnect — Bearer-token issuance with api.embedpress.com.
    // The proxy refuses enqueue / status calls without a valid token bound
    // to this site's home_url + fingerprint, so the admin clicks Connect
    // exactly once before the managed scrape path can run.
    // ------------------------------------------------------------------

    /**
     * Stable per-install fingerprint sent to the proxy at connect time.
     * Combines home_url with the site's AUTH_KEY (or an install-time UUID
     * fallback) so two installs at the same URL still produce different
     * fingerprints. The proxy stores it and rejects later calls that
     * present a mismatching fingerprint, making a leaked token useless on
     * any other install.
     */
    public static function compute_fingerprint(): string
    {
        $secret = defined('AUTH_KEY') && AUTH_KEY !== '' ? AUTH_KEY : '';
        if ($secret === '') {
            // No salts (e.g. fresh wp-config) — fall back to a stable
            // install-time UUID so the fingerprint still differs per install.
            $secret = get_option('embedpress_install_uuid');
            if (!$secret) {
                $secret = wp_generate_uuid4();
                update_option('embedpress_install_uuid', $secret, false);
            }
        }
        $material = home_url() . '|' . $secret . '|embedpress-google-reviews';
        return hash('sha256', $material);
    }

    /**
     * Return the stored auth blob, or [] if the site is not connected.
     * Cheap call — single wp_options lookup.
     */
    public static function get_auth(): array
    {
        $a = get_option(self::OPT_AUTH);
        return is_array($a) && !empty($a['token']) ? $a : [];
    }

    public static function is_connected(): bool
    {
        return self::get_auth() !== [];
    }

    /**
     * Perform the Connect handshake with the proxy. Stores the returned
     * token + binding metadata in wp_options on success. Returns
     * ['ok' => true, ...] on success, ['ok' => false, 'message' => ...]
     * on failure so the REST controller can surface the reason verbatim.
     */
    public static function connect(): array
    {
        $payload = [
            'site_url'       => home_url(),
            'fingerprint'    => self::compute_fingerprint(),
            'admin_email'    => get_option('admin_email'),
            'plugin_version' => defined('EMBEDPRESS_VERSION') ? EMBEDPRESS_VERSION : '',
            'wp_version'     => get_bloginfo('version'),
            'tier'           => \EmbedPress\Includes\Classes\Helper::is_pro_active() ? 'pro' : 'free',
        ];

        $response = wp_remote_post(self::endpoint() . '/connect.php', [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code !== 200 || !is_array($body) || empty($body['token'])) {
            $msg = is_array($body) && !empty($body['message'])
                ? (string) $body['message']
                : sprintf(__('Connect failed (HTTP %d).', 'embedpress'), $code);
            return ['ok' => false, 'message' => $msg];
        }

        $auth = [
            'token'        => (string) $body['token'],
            'site_id'      => (string) ($body['site_id'] ?? ''),
            'home_url'     => (string) ($body['home_url'] ?? home_url()),
            'fingerprint'  => $payload['fingerprint'],
            'tier'         => (string) ($body['tier'] ?? 'free'),
            'connected_at' => (int) ($body['issued_at'] ?? time()),
        ];
        update_option(self::OPT_AUTH, $auth, false); // autoload=no
        return ['ok' => true] + $auth;
    }

    /**
     * Forget the locally-stored token. The proxy-side row stays put (so an
     * audit trail remains); a future Connect just rotates the token.
     */
    public static function disconnect(): void
    {
        delete_option(self::OPT_AUTH);
    }

    /**
     * Build the headers every proxy call sends. Adds the Authorization
     * Bearer + X-EmbedPress-Fingerprint when the site is connected. Falls
     * back to plain headers (no auth) when not connected so the proxy can
     * answer with a clear 401 instead of a confusing 400.
     */
    private static function auth_headers(): array
    {
        $headers = [
            'Content-Type'      => 'application/json',
            'Accept'            => 'application/json',
            'X-EmbedPress-Site' => home_url(),
        ];
        $auth = self::get_auth();
        if (!empty($auth['token'])) {
            $headers['Authorization']            = 'Bearer ' . $auth['token'];
            // Some hosts strip Authorization at FPM; the proxy honors this
            // alternate header as a fallback so connectivity still works.
            $headers['X-EmbedPress-Token']       = $auth['token'];
            $headers['X-EmbedPress-Fingerprint'] = !empty($auth['fingerprint'])
                ? $auth['fingerprint']
                : self::compute_fingerprint();
        }
        return $headers;
    }

    /**
     * Surface a 401/403 from the proxy as a single "Disconnected — please
     * reconnect" status string. Caller passes the parsed body + HTTP code;
     * returns the message to write into the store, or '' if the response
     * wasn't an auth failure.
     */
    private static function auth_failure_message(int $code, $body): string
    {
        if ($code !== 401 && $code !== 403) {
            return '';
        }
        $err = is_array($body) ? (string) ($body['error'] ?? '') : '';
        if ($err === 'site_mismatch' || $err === 'fingerprint_mismatch') {
            return __('This site’s connection has expired. Reconnect to EmbedPress API to continue.', 'embedpress');
        }
        // missing_token / invalid_token / revoked / banned all map to the
        // same user action: reconnect.
        return __('Not connected to EmbedPress API. Open EmbedPress → Google Reviews and click Connect.', 'embedpress');
    }

    /**
     * Renderer filter: kicks in only when the prior handler (Apify) didn't
     * start a job AND no user Apify token is configured. Returns true if
     * we successfully enqueued (or cache-hit) so the caller skips its
     * sync ≤5 fallback.
     */
    public function start_fetch_job_filter($started, $place_id, $args)
    {
        if ($started) {
            return $started;
        }
        // EmbedPress managed scraper WINS by default: it fetches ALL reviews
        // for free via api.embedpress.com/google-reviews/v1, which is strictly
        // better than the Apify ≤preview run or the Google Places ≤5 API. We
        // only fall through to those when the managed path can't run (not
        // connected, or the proxy refused) — handled inside start_job(), which
        // returns false on failure so the caller's Apify/Google fallback runs.
        return self::start_job((string) $place_id, is_array($args) ? $args : []);
    }

    /**
     * Single-place sync fallback for the renderer's get_reviews_for_render.
     * If the proxy has the place CACHED, we get reviews inline — no job
     * dance. Otherwise enqueue + return null (caller renders empty; cron
     * polls in the background and the block re-renders when reviews land).
     */
    public function pre_fetch_filter($pre, $place_id, $args)
    {
        if (is_array($pre)) {
            return $pre;
        }
        // EmbedPress managed scraper WINS by default (all reviews, free) over
        // both the user's Apify token and a Google key. Try it first: a cache
        // hit returns reviews inline; a miss enqueues a background job and
        // returns null so the renderer shows empty until the cron poller
        // populates the store. Only when the managed path can't run (not
        // connected / proxy unreachable — try_inline_cache_or_enqueue returns
        // null) do we fall through to the Apify/Google API paths.
        $result = self::try_inline_cache_or_enqueue((string) $place_id, is_array($args) ? $args : []);
        return is_array($result) ? $result : null;
    }

    /**
     * Public entry: enqueue a scrape for $place_id at the proxy. If the
     * proxy reports a CACHE HIT, write the reviews to the store immediately
     * and return true with status=done. If the proxy queues, store the
     * job_id + schedule the WP-cron poller.
     *
     * Returns true if the job moved forward (cache hit or queued); false
     * if the proxy refused the request or is unreachable.
     */
    public static function start_job(string $place_id, array $args = []): bool
    {
        if ($place_id === '') {
            GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_FAILED, ['message' => __('No place selected.', 'embedpress')]);
            return false;
        }

        $max = self::resolve_max($args);

        // Refuse to call the proxy if we don't have a token — the admin
        // hasn't clicked Connect yet. Surface a clear "please connect"
        // status so the UI can render a "Connect to EmbedPress API" CTA
        // instead of a confusing HTTP error.
        if (!self::is_connected()) {
            GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_FAILED, [
                'message' => __('Not connected to EmbedPress API. Click Connect in the Google Reviews settings to start fetching reviews.', 'embedpress'),
            ]);
            return false;
        }

        // A USER-INITIATED REFETCH (the Refetch button) must NEVER come from the
        // proxy's cache — the whole point of clicking Refetch is "go get fresh
        // data now". So force refresh=true whenever the caller flags user_refetch
        // (set by the REST refetch handler), regardless of quick/full mode. This
        // is also what lets a Refetch replace a poisoned/partial cache row (e.g.
        // an exhausted=1 row holding only 3 reviews from before a worker fix).
        // Auto/render-triggered fetches omit the flag and still use the cache.
        $force_refresh = !empty($args['user_refetch'])
            || (array_key_exists('incremental', $args) && $args['incremental'] === false);

        $payload = [
            'place_id' => $place_id,
            'max'      => $max,
        ];
        if ($force_refresh) {
            $payload['refresh'] = true;
        }

        $response = wp_remote_post(self::endpoint() . '/enqueue.php', [
            'timeout' => (int) apply_filters('embedpress/google_reviews/managed_enqueue_timeout', 10, $place_id, $args),
            'headers' => self::auth_headers(),
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_FAILED, ['message' => $response->get_error_message()]);
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        // Auth failure: clear stored credentials when the proxy says the
        // token is invalid/revoked so the next page load shows "Connect"
        // again instead of looping on a dead token.
        $auth_msg = self::auth_failure_message($code, $body);
        if ($auth_msg !== '') {
            if ($code === 401 && is_array($body) && in_array(($body['error'] ?? ''), ['invalid_token', 'missing_token'], true)) {
                self::disconnect();
            }
            GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_FAILED, ['message' => $auth_msg]);
            return false;
        }

        // 429: rate-limited. Surface verbatim so the user understands.
        if ($code === 429) {
            $msg = is_array($body) && !empty($body['message']) ? $body['message'] : __('EmbedPress API is busy. Please try again in a minute.', 'embedpress');
            GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_FAILED, ['message' => $msg]);
            return false;
        }

        // 200 + cached=true → inline write, no job needed. Merge (append) rather
        // than reset+replace so a cached result with fewer reviews can't shrink
        // the stored count — same never-lose rule as the polled 'done' path.
        if ($code === 200 && is_array($body) && !empty($body['cached'])) {
            $reviews = is_array($body['reviews'] ?? null) ? $body['reviews'] : [];
            $meta    = is_array($body['meta']    ?? null) ? $body['meta']    : [];
            GoogleReviewsStore::append_reviews($place_id, $reviews, $meta, 'managed', true);
            GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_DONE, ['message' => null, 'run_id' => null]);
            return true;
        }

        // 202 + job_id → queued. Save run_id + start polling.
        if ($code === 202 && is_array($body) && !empty($body['job_id'])) {
            // DO NOT reset_reviews here. Wiping the stored set upfront made the
            // count drop to 0 and "climb back up" during a refetch (570 → 0 →
            // 430…), and if the new scrape got fewer reviews or failed midway we
            // LOST the reviews we already had. Instead keep the existing reviews
            // visible while fetching; the 'done' poll merges the new set in via
            // append_reviews() (dedup union), so a refetch only ever ADDS or
            // refreshes — it never loses what we already stored.
            GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_RUNNING, [
                'run_id'  => (string) $body['job_id'],
                // Initial placeholder shown only until the first poll pulls the
                // worker's real live status ("Looking up your place…", etc.).
                'message' => __('Starting…', 'embedpress'),
            ]);
            // First poll fires in 3s — the worker starts writing the
            // progress count within ~1.5s of claiming the job, so 3s
            // gets the user a number on screen almost immediately.
            $delay = isset($body['poll_after_seconds']) ? max(3, (int) $body['poll_after_seconds']) : 3;
            self::schedule_poll($place_id, $delay);
            return true;
        }

        // Anything else → surface as failure.
        $msg = is_array($body) && !empty($body['message'])
            ? (string) $body['message']
            : __('We couldn’t reach EmbedPress API. Please try again.', 'embedpress');
        GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_FAILED, ['message' => $msg]);
        return false;
    }

    /**
     * Try a cache-hit pre-fetch synchronously. If the proxy has the place
     * cached, the response is inline — return it without enqueuing. If
     * not cached, kick off start_job() to put the place in the queue and
     * return null (the caller renders empty; cron picks it up).
     */
    private static function try_inline_cache_or_enqueue(string $place_id, array $args): ?array
    {
        $row = GoogleReviewsStore::get($place_id);
        if ($row && ($row['fetch_status'] ?? '') === GoogleReviewsStore::STATUS_RUNNING) {
            return null; // already polling
        }

        // Cheap probe — same payload as start_job but with max=1 so a cache
        // miss doesn't end up scraping more than necessary if the proxy uses
        // requested_max to size the eventual run.
        $started = self::start_job($place_id, $args);
        if (!$started) {
            return null;
        }
        // start_job() already wrote to the store on cache hit; pull it back
        // out and return so the renderer can serve this request inline.
        $row = GoogleReviewsStore::get($place_id);
        if ($row && ($row['fetch_status'] ?? '') === GoogleReviewsStore::STATUS_DONE) {
            return [
                'reviews' => is_array($row['reviews']) ? $row['reviews'] : [],
                'meta'    => is_array($row['meta'])    ? $row['meta']    : [],
            ];
        }
        return null;
    }

    /**
     * Schedule a one-off WP-cron tick to poll the proxy's status.
     * Minimum 3s — anything tighter risks overwhelming WP cron if many
     * places are running simultaneously, and the worker's progress writes
     * at ~1.8s cadence so a 3s poll matches that closely.
     */
    public static function schedule_poll(string $place_id, int $delay = 3): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK, [$place_id])) {
            wp_schedule_single_event(time() + max(3, $delay), self::CRON_HOOK, [$place_id]);
        }
    }

    /**
     * Cron callback. Polls status.php?job_id=…; when status=done, writes
     * reviews to the store + finalizes. When status=running, reschedules
     * itself. When failed, surfaces the proxy's message.
     */
    public function poll_job($place_id): void
    {
        $place_id = (string) $place_id;
        $row = GoogleReviewsStore::get($place_id);
        if (!$row || ($row['fetch_status'] ?? '') !== GoogleReviewsStore::STATUS_RUNNING) {
            return; // cancelled or already done
        }
        $job_id = (string) ($row['fetch_run_id'] ?? '');
        if ($job_id === '') {
            GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_FAILED, ['message' => __('Something went wrong. Please click Refetch to try again.', 'embedpress')]);
            return;
        }

        $response = wp_remote_get(
            self::endpoint() . '/status.php?' . http_build_query(['job_id' => $job_id]),
            [
                'timeout' => 10,
                'headers' => self::auth_headers(),
            ]
        );
        if (is_wp_error($response)) {
            // Transient error — retry in a moment. If the proxy is permanently
            // down, multiple reschedules eventually look like "stuck"; the user
            // can cancel via the admin Refetch UI. Longer delay on errors so
            // we don't hammer a down proxy.
            self::schedule_poll($place_id, 10);
            return;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        // Auth failure during polling = user disconnected (or token revoked).
        // Stop the cron loop and surface a clear status — don't reschedule.
        $auth_msg = self::auth_failure_message($code, $body);
        if ($auth_msg !== '') {
            if ($code === 401 && is_array($body) && in_array(($body['error'] ?? ''), ['invalid_token', 'missing_token'], true)) {
                self::disconnect();
            }
            GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_FAILED, [
                'message' => $auth_msg,
                'run_id'  => null,
            ]);
            return;
        }

        if ($code === 404) {
            // Proxy lost the job (cache eviction, DB reset, …). Tell the user.
            GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_FAILED, [
                'message' => __('That fetch attempt timed out. Please click Refetch to try again.', 'embedpress'),
                'run_id'  => null,
            ]);
            return;
        }
        if ($code !== 200 || !is_array($body)) {
            self::schedule_poll($place_id, 10);
            return;
        }

        $status = (string) ($body['status'] ?? '');
        switch ($status) {
            case 'queued':
            case 'running':
                // Live progress: surface the proxy's status message so the
                // admin UI shows the same wording the scraper reports.
                $msg = !empty($body['message']) ? (string) $body['message'] : __('Fetching reviews…', 'embedpress');
                $so_far = isset($body['fetched_count']) ? (int) $body['fetched_count'] : 0;
                GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_RUNNING, [
                    'message' => $msg,
                    'so_far'  => $so_far,
                ]);
                // Worker writes progress at ~1.8s per scroll round; 3s
                // matches that closely so the visible count updates feel
                // continuous instead of jumpy.
                self::schedule_poll($place_id, 3);
                return;

            case 'done':
                $reviews = is_array($body['reviews'] ?? null) ? $body['reviews'] : [];
                $meta    = is_array($body['meta']    ?? null) ? $body['meta']    : [];
                GoogleReviewsStore::append_reviews($place_id, $reviews, $meta, 'managed', true);
                GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_DONE, [
                    'message' => null,
                    'run_id'  => null,
                ]);
                return;

            case 'failed':
                GoogleReviewsStore::set_job($place_id, GoogleReviewsStore::STATUS_FAILED, [
                    'message' => !empty($body['message']) ? (string) $body['message'] : __('Couldn’t fetch reviews this time. Please try Refetch again in a moment.', 'embedpress'),
                    'run_id'  => null,
                ]);
                return;

            default:
                // Unknown status — give the proxy one more chance with a
                // longer delay so we don't tight-loop on a bad response.
                self::schedule_poll($place_id, 10);
        }
    }

    /**
     * What `max` to send to the proxy enqueue endpoint.
     *
     * Contract with `api.embedpress.com/google-reviews/v1/enqueue.php`:
     *   - 0 means "scrape every review Google exposes" (worker scrolls
     *     until exhausted, capped server-side at MAX_REVIEWS_PER_JOB=1000).
     *   - >0 is a soft cap that the worker honors as a hard stop.
     *
     * GLOBAL CAP: 1000 reviews/place max (product + Cloud-Run-free-tier
     * decision, 2026-06-19). Requested here AND enforced server-side.
     *
     * TIER CAP: FREE users get at most FREE_MAX_REVIEWS (10) per place — a
     * "request all" (0) is rewritten to 10 so free never pulls the full set.
     * PRO is uncapped (0 = all up to the server ceiling). This is the single
     * enforcement point: every fetch (add, refetch, render) routes through here.
     */
    const FREE_MAX_REVIEWS = 10;

    private static function resolve_max(array $args): int
    {
        $is_pro    = \EmbedPress\Includes\Classes\Helper::is_pro_active();
        $free_cap  = (int) apply_filters('embedpress/google_reviews/free_max_reviews', self::FREE_MAX_REVIEWS);
        $requested = isset($args['fetch_max']) ? (int) $args['fetch_max'] : 0;

        if (!$is_pro) {
            // Free: "all" (0) → free cap; an explicit N → min(N, free cap).
            return $requested <= 0 ? $free_cap : min($requested, $free_cap);
        }

        // Pro: "all" stays all (proxy expands to its 1000 ceiling); an explicit
        // N is bounded by the 1000-review global cap.
        if ($requested <= 0) {
            return 0; // "all" — proxy caps at MAX_REVIEWS_PER_JOB (1000)
        }
        $ceiling = (int) apply_filters('embedpress/google_reviews/managed_max_ceiling', 1000);
        return min($requested, $ceiling);
    }
}
