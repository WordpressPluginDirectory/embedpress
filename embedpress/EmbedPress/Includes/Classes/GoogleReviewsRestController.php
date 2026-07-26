<?php

namespace EmbedPress\Includes\Classes;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

(defined('ABSPATH') && defined('EMBEDPRESS_IS_LOADED')) or die("No direct script access allowed.");

/**
 * REST endpoints powering the Google Reviews searchable picker, live preview,
 * settings save, and cache flush. All endpoints require `edit_posts` so the
 * Places API key never leaves the server.
 */
class GoogleReviewsRestController
{
    const NS = 'embedpress/v1';

    public static function register()
    {
        register_rest_route(self::NS, '/google-reviews/search', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'search'],
            'permission_callback' => [__CLASS__, 'can_edit'],
            'args'                => [
                'q'             => ['type' => 'string', 'required' => true],
                'session_token' => ['type' => 'string'],
            ],
        ]);

        register_rest_route(self::NS, '/google-reviews/preview', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'preview'],
            'permission_callback' => [__CLASS__, 'can_edit'],
            'args'                => [
                'place_id'   => ['type' => 'string', 'required' => true],
                'place_name' => ['type' => 'string'],
                'limit'      => ['type' => 'integer', 'default' => 5],
                'min_rating' => ['type' => 'integer', 'default' => 0],
                'layout'     => ['type' => 'string', 'default' => 'list'],
                'show_photo' => ['type' => 'boolean', 'default' => true],
                'show_date'  => ['type' => 'boolean', 'default' => true],
                'show_stars' => ['type' => 'boolean', 'default' => true],
                'show_link'   => ['type' => 'boolean', 'default' => false],
                'show_images' => ['type' => 'boolean', 'default' => true],
                'columns'    => ['type' => 'integer', 'default' => 3],
                'max_width'  => ['type' => 'integer', 'default' => 0],
                'gap'        => ['type' => 'integer', 'default' => 32],
                'show_arrows'    => ['type' => 'boolean', 'default' => true],
                'show_dots'      => ['type' => 'boolean', 'default' => true],
                'carousel_loop'  => ['type' => 'boolean', 'default' => true],
                'autoplay'       => ['type' => 'boolean', 'default' => false],
                'autoplay_speed' => ['type' => 'number',  'default' => 5],
                'show_summary'        => ['type' => 'boolean', 'default' => true],
                'show_summary_name'   => ['type' => 'boolean', 'default' => true],
                'show_summary_rating' => ['type' => 'boolean', 'default' => true],
                'show_summary_stars'  => ['type' => 'boolean', 'default' => true],
                'show_summary_count'  => ['type' => 'boolean', 'default' => true],
                'show_write_review'   => ['type' => 'boolean', 'default' => true],
                'summary_align'       => ['type' => 'string',  'default' => 'left'],
                // Pro controls — declared so WP type-coerces "false"→false (a query
                // string "false" is otherwise truthy). The free renderer ignores
                // them; Pro's render filters consume them.
                'sort'         => ['type' => 'string',  'default' => 'newest'],
                'keyword'      => ['type' => 'string',  'default' => ''],
                'hide_empty'   => ['type' => 'boolean', 'default' => false],
                'load_more'    => ['type' => 'boolean', 'default' => false],
                'theme'        => ['type' => 'string',  'default' => 'light'],
                'accent_color' => ['type' => 'string',  'default' => ''],
                'schema'       => ['type' => 'boolean', 'default' => false],
            ],
        ]);

        // PUBLIC pagination endpoint — frontend visitors fetch the next page of
        // review cards on "Load more" (AJAX). No auth: it only ever returns
        // already-public review content from the DB store, server-rendered.
        register_rest_route(self::NS, '/google-reviews/page', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'page'],
            'permission_callback' => '__return_true',
            'args'                => [
                'place_id'   => ['type' => 'string',  'required' => true],
                'offset'     => ['type' => 'integer', 'default' => 0],
                'per_page'   => ['type' => 'integer', 'default' => 5],
                'min_rating' => ['type' => 'integer', 'default' => 0],
                'layout'     => ['type' => 'string',  'default' => 'list'],
                'show_photo'  => ['type' => 'boolean', 'default' => true],
                'show_date'   => ['type' => 'boolean', 'default' => true],
                'show_stars'  => ['type' => 'boolean', 'default' => true],
                'show_images' => ['type' => 'boolean', 'default' => true],
                // Pro display args (renderer/filters consume what applies).
                'sort'        => ['type' => 'string',  'default' => 'newest'],
                'keyword'     => ['type' => 'string',  'default' => ''],
                'hide_empty'  => ['type' => 'boolean', 'default' => false],
                'theme'       => ['type' => 'string',  'default' => 'light'],
                'accent_color' => ['type' => 'string', 'default' => ''],
                'places'      => ['type' => 'array',   'default' => []],
            ],
        ]);

        register_rest_route(self::NS, '/google-reviews/settings', [
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'get_settings'],
                'permission_callback' => [__CLASS__, 'can_manage'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'save_settings'],
                'permission_callback' => [__CLASS__, 'can_manage'],
                'args'                => [
                    'api_key'         => ['type' => 'string'],
                    'cache_ttl'       => ['type' => 'integer'],
                    'apify_token'     => ['type' => 'string'],
                    'search_provider' => ['type' => 'string'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/google-reviews/clear-cache', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'clear_cache'],
            'permission_callback' => [__CLASS__, 'can_manage'],
        ]);

        register_rest_route(self::NS, '/google-reviews/verify-key', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'verify_key'],
            'permission_callback' => [__CLASS__, 'can_manage'],
            'args'                => [
                'provider' => ['type' => 'string', 'required' => true],
                'key'      => ['type' => 'string', 'required' => true],
            ],
        ]);

        // Managed-proxy connect / disconnect / status. The Google Reviews
        // settings page drives a "Connect to EmbedPress API" button through
        // these — once connected, the managed scrape path can call
        // api.embedpress.com without the user wiring their own keys.
        register_rest_route(self::NS, '/google-reviews/managed/connect', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'managed_connect'],
            'permission_callback' => [__CLASS__, 'can_manage'],
        ]);
        register_rest_route(self::NS, '/google-reviews/managed/disconnect', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'managed_disconnect'],
            'permission_callback' => [__CLASS__, 'can_manage'],
        ]);
        register_rest_route(self::NS, '/google-reviews/managed/status', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'managed_status'],
            'permission_callback' => [__CLASS__, 'can_edit'],
        ]);

        // Lightweight per-place fetch-status poll. The block editor uses this to
        // show a live progress bar while the background Apify job runs.
        register_rest_route(self::NS, '/google-reviews/status', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_status'],
            'permission_callback' => [__CLASS__, 'can_edit'],
            'args'                => [
                'place_id' => ['type' => 'string', 'required' => true],
            ],
        ]);

        // PUBLIC, read-only variant of the status poll, scoped to a single
        // place_id. Used by the FRONTEND poller (static/js/google-reviews-status.js)
        // so a published page that rendered the "loading" placeholder (a place
        // added but not yet fetched) can self-refresh the moment the background
        // job finishes — without a manual reload. Returns only non-sensitive
        // job state (status + review_count); same handler as the editor poll,
        // which also advances the job (WP-cron is unreliable on quiet pages).
        register_rest_route(self::NS, '/google-reviews/public-status', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_public_status'],
            'permission_callback' => '__return_true',
            'args'                => [
                'place_id' => ['type' => 'string', 'required' => true],
            ],
        ]);

        register_rest_route(self::NS, '/google-reviews/places', [
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'get_places'],
                'permission_callback' => [__CLASS__, 'can_edit'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'post_places'],
                'permission_callback' => [__CLASS__, 'can_edit'],
                'args'                => [
                    'action'        => ['type' => 'string', 'required' => true],
                    'place_id'      => ['type' => 'string', 'required' => true],
                    'place_name'    => ['type' => 'string'],
                    'place_address' => ['type' => 'string'], // location description (saved with the place)
                    // Search-result seed: the place's real total review count +
                    // rating, so the row shows them immediately on add (before the
                    // background fetch finishes). 0 = not provided.
                    'total'      => ['type' => 'integer', 'default' => 0],
                    'rating'     => ['type' => 'number',  'default' => 0],
                    'fetch_max'  => ['type' => 'integer', 'default' => 0], // 0 = all up to ceiling
                    'limit'      => ['type' => 'integer', 'default' => 100],
                    'sort'       => ['type' => 'string', 'default' => 'newest'],
                    // 'quick' (default) = incremental: keep stored reviews, append new ones,
                    // stop when we hit one we already have. Cheap.
                    // 'full' = wipe and re-pull. Surfaces edits/deletes, costs more.
                    'mode'       => ['type' => 'string', 'default' => 'quick'],
                ],
            ],
        ]);
    }

    public static function can_edit()
    {
        return current_user_can('edit_posts');
    }

    public static function can_manage()
    {
        return current_user_can('manage_options');
    }

    /**
     * Proxy Google Places Autocomplete so the API key stays server-side.
     */
    public static function search(WP_REST_Request $request)
    {
        $q = trim((string) $request->get_param('q'));
        if ($q === '' || mb_strlen($q) < 2) {
            return new WP_REST_Response(['predictions' => []], 200);
        }

        // Session-token billing (managed proxy only): when the picker passes
        // the same UUID across each keystroke + the final details call, Google
        // bills the whole session as ONE event instead of N. Skip the WP-side
        // transient cache for tokened calls — caching would let a future
        // session reuse a prior response and break the token-billing link.
        $session_token = trim((string) $request->get_param('session_token'));

        $provider  = GoogleReviewsRenderer::get_search_provider();

        // BACKEND GUARD: search runs through a provider. When the effective
        // provider is the hosted EmbedPress API ('managed' — the default when
        // the user has no own Google/Apify key) but the site is NOT connected,
        // refuse the search outright (a 403). The UI also blocks this, but the
        // server must enforce it too so a direct REST call can't search without
        // a connection. A user with their own Google/Apify key is unaffected.
        if ($provider === 'managed' && GoogleReviewsManaged::get_auth() === []) {
            return new WP_Error(
                'embedpress_gr_not_connected',
                __('Connect to the EmbedPress API to search for places.', 'embedpress'),
                ['status' => 403]
            );
        }

        if ($session_token === '') {
            $cache_key = 'embedpress_gr_ac_' . $provider . '_' . md5(strtolower($q));
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return new WP_REST_Response(['predictions' => $cached, 'provider' => $provider, 'cached' => true], 200);
            }
        }

        $apify_error = null;
        if ($provider === 'managed') {
            // Hosted-proxy search: zero user setup, EmbedPress's server runs
            // the session-billed Google Places Autocomplete on the user's
            // behalf (fast, ~1.4s). This is the DEFAULT in 'auto' mode.
            $predictions = GoogleReviewsRenderer::managed_search($q, $session_token);
            // If the proxy is rate-limited / down AND the user happens to have
            // an Apify token (set up for "fetch all reviews"), fall back to
            // Apify search so the picker still works. Apify is only a fallback
            // here — never the default — because its search is a ~20s scrape.
            if (is_wp_error($predictions) && GoogleReviewsRenderer::get_apify_token() !== '') {
                $apify = GoogleReviewsRenderer::apify_search($q);
                if (!is_wp_error($apify)) {
                    $predictions = $apify;
                }
            }
        } elseif ($provider === 'apify') {
            // Apify-backed place search is FREE (the block's place picker must
            // work without Pro). Returns a predictions array, or a WP_Error.
            $predictions = GoogleReviewsRenderer::apify_search($q);
            if (is_wp_error($predictions)) {
                // A real Apify timeout is its own actionable failure: tell the
                // user to retry rather than swallowing it into the Google
                // fallback (which, with no Google key, would report a misleading
                // "no provider connected" message). Surface it directly.
                if ($predictions->get_error_code() === 'embedpress_gr_apify_timeout') {
                    return $predictions;
                }
                $apify_error = $predictions;
                // Out-of-credit / bad-token / unexpected response → try Google
                // (user key first, then the hosted proxy). If everything fails
                // we surface $apify_error.
                $predictions = GoogleReviewsRenderer::autocomplete($q);
                if (is_wp_error($predictions)) {
                    $predictions = GoogleReviewsRenderer::managed_search($q, $session_token);
                }
            }
        } else {
            $predictions = GoogleReviewsRenderer::autocomplete($q);
        }

        if (is_wp_error($predictions)) {
            // Last-resort fallback: managed proxy. The user picked a provider
            // and it failed; rather than tell them "no provider", try our own.
            if ($provider !== 'managed') {
                $managed = GoogleReviewsRenderer::managed_search($q, $session_token);
                if (!is_wp_error($managed)) {
                    $predictions = $managed;
                }
            }
        }

        if (is_wp_error($predictions)) {
            // All paths failed. Prefer the Apify reason if we have one (it's
            // usually the most actionable "top up / add a key" message).
            $err = $apify_error instanceof \WP_Error ? $apify_error : $predictions;

            // Don't leak raw transport errors (e.g. "cURL error 7: Failed to
            // connect to host… port 9920") to the UI — they're meaningless to
            // users. Replace connection/timeout failures with a friendly note.
            $msg = (string) $err->get_error_message();
            if (preg_match('/cURL error|failed to connect|could not connect|connection (timed out|refused)|name or service not known|resolve host|timed out/i', $msg)) {
                return new \WP_Error(
                    'embedpress_gr_search_unreachable',
                    __('Couldn’t reach the place-search service right now. Please check your connection and try again in a moment.', 'embedpress'),
                    ['status' => 503]
                );
            }
            return $err;
        }

        // Apify search costs a run per query — cache longer than Google/managed.
        // Skip caching for session-tokened calls (caching would let a future
        // session reuse a response and break session-token billing).
        if ($session_token === '') {
            $ttl = $provider === 'apify' ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS;
            set_transient($cache_key, $predictions, $ttl);
        }
        return new WP_REST_Response(['predictions' => $predictions, 'provider' => $provider], 200);
    }

    /**
     * Server-rendered preview of the Google Reviews block. The editor & the
     * settings-page shortcode generator both use this so what they see is
     * exactly what the frontend will render.
     */
    public static function preview(WP_REST_Request $request)
    {
        // Query-string params arrive as STRINGS. `(bool) "false"` is true in PHP
        // (any non-empty string is truthy), so booleans MUST go through
        // rest_sanitize_boolean(), which maps "false"/"0"/""/false → false.
        // (Plain (bool) casts were a latent bug — harmless for default-true free
        // toggles, but it made hide_empty/schema/load_more always-on.)
        $boolish = static function ($key) use ($request) {
            return rest_sanitize_boolean($request->get_param($key));
        };
        $args = [
            'place_id'   => sanitize_text_field((string) $request->get_param('place_id')),
            'place_name' => sanitize_text_field((string) $request->get_param('place_name')),
            'limit'      => (int) $request->get_param('limit'),
            'min_rating' => (int) $request->get_param('min_rating'),
            'layout'     => sanitize_key((string) $request->get_param('layout')),
            'show_photo' => $boolish('show_photo'),
            'show_date'  => $boolish('show_date'),
            'show_stars' => $boolish('show_stars'),
            'show_link'   => $boolish('show_link'),
            'show_images' => $boolish('show_images'),
            'show_arrows'    => $boolish('show_arrows'),
            'show_dots'      => $boolish('show_dots'),
            'carousel_loop'  => $boolish('carousel_loop'),
            'autoplay'       => $boolish('autoplay'),
            'autoplay_speed' => (float) $request->get_param('autoplay_speed'),
            'show_summary'        => $boolish('show_summary'),
            'show_summary_name'   => $boolish('show_summary_name'),
            'show_summary_rating' => $boolish('show_summary_rating'),
            'show_summary_stars'  => $boolish('show_summary_stars'),
            'show_summary_count'  => $boolish('show_summary_count'),
            'show_write_review'   => $boolish('show_write_review'),
            'summary_align'       => sanitize_key((string) $request->get_param('summary_align')),
            'columns'    => (int) $request->get_param('columns'),
            'max_width'  => (int) $request->get_param('max_width'),
            'gap'        => (int) $request->get_param('gap'),
            // Pro args — without these the preview never reflects the Pro controls
            // (hide-empty, sort, keyword, theme, accent, load-more, schema). They
            // feed the Pro render filters; the free renderer ignores them. Mirror
            // Shortcode::do_shortcode_google_reviews().
            'sort'         => sanitize_key((string) $request->get_param('sort')),
            'keyword'      => sanitize_text_field((string) $request->get_param('keyword')),
            'hide_empty'   => $boolish('hide_empty'),
            'load_more'    => $boolish('load_more'),
            'theme'        => sanitize_key((string) $request->get_param('theme')),
            'accent_color' => sanitize_hex_color((string) $request->get_param('accent_color')) ?: '',
            'schema'       => $boolish('schema'),
        ];
        return new WP_REST_Response([
            'html'         => GoogleReviewsRenderer::render($args),
            'stylesheet'   => EMBEDPRESS_URL_ASSETS . 'css/google-reviews.css?ver=' . EMBEDPRESS_VERSION,
        ], 200);
    }

    /**
     * PUBLIC: return one page of rendered review CARDS for "Load more" (AJAX).
     * The frontend appends the returned HTML and asks for the next offset until
     * has_more is false. Pure DB read — no network.
     */
    public static function page(WP_REST_Request $request)
    {
        $boolish = static function ($key) use ($request) {
            return rest_sanitize_boolean($request->get_param($key));
        };
        $places = $request->get_param('places');
        $args = [
            'place_id'    => sanitize_text_field((string) $request->get_param('place_id')),
            'min_rating'  => (int) $request->get_param('min_rating'),
            'layout'      => sanitize_key((string) $request->get_param('layout')),
            'show_photo'  => $boolish('show_photo'),
            'show_date'   => $boolish('show_date'),
            'show_stars'  => $boolish('show_stars'),
            'show_images' => $boolish('show_images'),
            // Pro filter args (ignored by free renderer/filters when inactive).
            'sort'         => sanitize_key((string) $request->get_param('sort')),
            'keyword'      => sanitize_text_field((string) $request->get_param('keyword')),
            'hide_empty'   => $boolish('hide_empty'),
            'theme'        => sanitize_key((string) $request->get_param('theme')),
            'accent_color' => sanitize_hex_color((string) $request->get_param('accent_color')) ?: '',
            'places'       => is_array($places) ? array_map('sanitize_text_field', $places) : [],
        ];
        $offset   = max(0, (int) $request->get_param('offset'));
        $per_page = max(1, min(50, (int) $request->get_param('per_page')));

        $page = GoogleReviewsRenderer::render_page($args, $offset, $per_page);
        return new WP_REST_Response($page, 200);
    }

    public static function get_settings()
    {
        return new WP_REST_Response([
            'api_key_configured'    => GoogleReviewsRenderer::get_api_key() !== '',
            'api_key_masked'        => self::mask_key(GoogleReviewsRenderer::get_api_key()),
            'cache_ttl'             => GoogleReviewsRenderer::get_cache_ttl(),
            'api_mode'              => GoogleReviewsRenderer::get_api_mode(),
            'apify_token_configured' => GoogleReviewsRenderer::get_apify_token() !== '',
            'apify_token_masked'    => self::mask_key(GoogleReviewsRenderer::get_apify_token()),
            'search_provider'       => (string) get_option(GoogleReviewsRenderer::OPT_SEARCH_PROVIDER, 'auto'),
            'search_provider_active' => GoogleReviewsRenderer::get_search_provider(),
        ], 200);
    }

    public static function save_settings(WP_REST_Request $request)
    {
        $api_key = $request->get_param('api_key');
        if (is_string($api_key) && $api_key !== '') {
            // Allow a sentinel "***" to mean "leave the key alone" so the
            // masked-display flow doesn't accidentally wipe it.
            if (trim($api_key) !== '***') {
                $previous = GoogleReviewsRenderer::get_api_key();
                update_option(GoogleReviewsRenderer::OPT_API_KEY, sanitize_text_field($api_key));
                // Key changed → invalidate the cached API variant so the next
                // call re-probes which endpoint family this key is enabled for.
                if (trim($api_key) !== $previous) {
                    GoogleReviewsRenderer::set_api_mode('auto');
                }
            }
        } elseif ($api_key === '') {
            delete_option(GoogleReviewsRenderer::OPT_API_KEY);
            GoogleReviewsRenderer::set_api_mode('auto');
        }

        $ttl = $request->get_param('cache_ttl');
        if ($ttl !== null) {
            $ttl = (int) $ttl;
            if ($ttl > 0) {
                update_option(GoogleReviewsRenderer::OPT_CACHE_TTL, $ttl);
            }
        }

        // Apify token (Pro "fetch all reviews" provider). "***" sentinel = keep.
        $apify = $request->get_param('apify_token');
        if (is_string($apify) && $apify !== '') {
            if (trim($apify) !== '***') {
                update_option(GoogleReviewsRenderer::OPT_APIFY_TOKEN, sanitize_text_field($apify));
            }
        } elseif ($apify === '') {
            delete_option(GoogleReviewsRenderer::OPT_APIFY_TOKEN);
        }

        // Search provider preference: auto | google | apify.
        $sp = $request->get_param('search_provider');
        if (is_string($sp) && $sp !== '') {
            $sp = in_array($sp, ['auto', 'google', 'apify'], true) ? $sp : 'auto';
            update_option(GoogleReviewsRenderer::OPT_SEARCH_PROVIDER, $sp);
        }

        return self::get_settings();
    }

    /**
     * Live-verify a key/token before it's saved, so the user gets immediate
     * feedback ("connected" vs "that key didn't work"). Google → a tiny
     * autocomplete probe; Apify → the token's /users/me endpoint.
     */
    public static function verify_key(WP_REST_Request $request)
    {
        $provider = sanitize_key((string) $request->get_param('provider'));
        $key      = trim((string) $request->get_param('key'));
        if ($key === '') {
            return new WP_REST_Response(['valid' => false, 'message' => __('No key provided.', 'embedpress')], 200);
        }

        if ($provider === 'apify') {
            $res = wp_remote_get('https://api.apify.com/v2/users/me?token=' . rawurlencode($key), ['timeout' => 12]);
            if (is_wp_error($res)) {
                return new WP_REST_Response(['valid' => false, 'message' => $res->get_error_message()], 200);
            }
            $code = (int) wp_remote_retrieve_response_code($res);
            if ($code === 200) {
                $body = json_decode(wp_remote_retrieve_body($res), true);
                $name = $body['data']['username'] ?? '';
                return new WP_REST_Response(['valid' => true, 'message' => $name ? sprintf(__('Verified (Apify user: %s).', 'embedpress'), $name) : __('Verified.', 'embedpress')], 200);
            }
            return new WP_REST_Response(['valid' => false, 'message' => __('Apify rejected this token. Check it in your Apify account → Integrations.', 'embedpress')], 200);
        }

        // Google: probe Places autocomplete with this exact key (don't touch the
        // saved option). A successful or zero-results status = the key works.
        $valid = GoogleReviewsRenderer::verify_google_key($key);
        if (is_wp_error($valid)) {
            return new WP_REST_Response(['valid' => false, 'message' => $valid->get_error_message()], 200);
        }
        return new WP_REST_Response(['valid' => true, 'message' => __('Verified.', 'embedpress')], 200);
    }

    public static function clear_cache()
    {
        $deleted = GoogleReviewsRenderer::clear_cache();
        return new WP_REST_Response(['deleted' => $deleted], 200);
    }

    /**
     * Connect this install to api.embedpress.com/google-reviews/v1. POSTs
     * the home_url + fingerprint and stores the Bearer token returned by
     * the proxy. Idempotent — calling again rotates the token.
     */
    public static function managed_connect()
    {
        $result = GoogleReviewsManaged::connect();
        if (empty($result['ok'])) {
            return new WP_Error(
                'embedpress_gr_managed_connect_failed',
                isset($result['message']) ? (string) $result['message'] : __('Connect failed.', 'embedpress'),
                ['status' => 502]
            );
        }
        return new WP_REST_Response(self::managed_status_payload(), 200);
    }

    public static function managed_disconnect()
    {
        GoogleReviewsManaged::disconnect();
        return new WP_REST_Response(self::managed_status_payload(), 200);
    }

    public static function managed_status()
    {
        return new WP_REST_Response(self::managed_status_payload(), 200);
    }

    /**
     * Shape consumed by the React settings UI: { connected, home_url,
     * site_id, tier, connected_at }. The token itself is NEVER returned —
     * only the boolean + binding metadata. Anyone with edit_posts can read
     * this to know whether to show "Connect" or "Disconnect" affordances.
     */
    private static function managed_status_payload(): array
    {
        $auth = GoogleReviewsManaged::get_auth();
        // `would_send` mirrors the payload connect.php will receive — used by
        // the Connect button on the admin page to show the user exactly what
        // will leave their server (site URL, admin email, install fingerprint)
        // BEFORE they click. Honesty over magic-button UX.
        $would_send = [
            'site_url'    => home_url(),
            'admin_email' => (string) get_option('admin_email'),
        ];
        return [
            'connected'    => $auth !== [],
            'home_url'     => $auth['home_url']     ?? home_url(),
            'site_id'      => $auth['site_id']      ?? '',
            'tier'         => $auth['tier']         ?? '',
            'connected_at' => isset($auth['connected_at']) ? (int) $auth['connected_at'] : 0,
            'endpoint'     => GoogleReviewsManaged::endpoint(),
            'would_send'   => $would_send,
        ];
    }

    /**
     * Return all saved places from the DB store (the global library), each with
     * its fetch status so the picker/manager can show "saved / N reviews / last
     * fetched". Shape stays back-compatible: { places: [...], saved: [...] } —
     * `saved` mirrors `places` so the existing block picker keeps working.
     */
    public static function get_places()
    {
        // DRIVE RUNNING JOBS INLINE. The admin UI polls THIS endpoint every 2s
        // while any place is fetching. Advancing each running job's poll here
        // (instead of relying on WP-cron, which is unreliable on a quiet admin
        // page) is what surfaces the worker's live progress — "Looking up your
        // place…", "Fetched N reviews so far…" — and finalizes the job to done.
        // Without this, fetch_message stays frozen at the initial placeholder
        // ("Fetching reviews…") until cron eventually fires. Wrapped per-place
        // so one bad poll can't break the whole list.
        foreach (GoogleReviewsStore::all() as $row) {
            if (($row['fetch_status'] ?? '') !== GoogleReviewsStore::STATUS_RUNNING) {
                continue;
            }
            try {
                (new GoogleReviewsManaged())->poll_job($row['place_id']);
            } catch (\Throwable $e) {
                // Ignore — fall through and return the current store state.
            }
        }

        return new WP_REST_Response(self::places_payload(), 200);
    }

    /**
     * Per-place fetch status — polled by the block editor for its progress bar.
     * Returns the live counters without the full reviews payload.
     */
    public static function get_status(WP_REST_Request $request)
    {
        $place_id = trim((string) $request->get_param('place_id'));

        // DRIVE THE POLL INLINE. Job progress (running → done) is normally
        // advanced by a WP-cron callback (poll_job), but WP-cron only fires on
        // site traffic and is unreliable on a quiet admin page — so a place
        // can sit at "running" in the UI forever even though the proxy
        // finished seconds ago (the "stuck spinner" bug). Since the admin UI
        // polls THIS endpoint every few seconds, we advance the job here too:
        // each status poll checks the proxy and finalizes when done. WP-cron
        // stays as a backup for when no one is watching the page.
        if ($place_id !== '') {
            $pre = GoogleReviewsStore::get($place_id);
            if ($pre && ($pre['fetch_status'] ?? '') === GoogleReviewsStore::STATUS_RUNNING) {
                try {
                    (new GoogleReviewsManaged())->poll_job($place_id);
                } catch (\Throwable $e) {
                    // Never let a poll error break the status read — fall
                    // through and return whatever the store currently has.
                }
            }
        }

        $row = $place_id !== '' ? GoogleReviewsStore::get($place_id) : null;
        if (!$row) {
            return new WP_REST_Response([
                'place_id'       => $place_id,
                'fetch_status'   => 'idle',
                'review_count'   => 0,
                'fetched_so_far' => 0,
                'fetch_message'  => null,
                'exists'         => false,
            ], 200);
        }
        return new WP_REST_Response([
            'place_id'       => $place_id,
            'fetch_status'   => $row['fetch_status'] ?? 'idle',
            'review_count'   => (int) $row['review_count'],
            'fetched_so_far' => isset($row['fetched_so_far']) ? (int) $row['fetched_so_far'] : 0,
            'fetch_message'  => $row['fetch_message'] ?? null,
            'last_fetched_at' => $row['last_fetched_at'] ?? null,
            'exists'         => true,
        ], 200);
    }

    /**
     * PUBLIC per-place status poll for the frontend "loading" placeholder.
     * Reuses get_status() (which also advances a running job, since WP-cron is
     * unreliable on quiet pages) but returns ONLY non-sensitive job state, and
     * only for a place that already exists in the store — an unknown place_id
     * returns idle/0 without touching the network, so the endpoint can't be
     * abused to enqueue arbitrary fetches.
     */
    public static function get_public_status(WP_REST_Request $request)
    {
        $place_id = trim((string) $request->get_param('place_id'));
        if ($place_id === '' || !GoogleReviewsStore::exists($place_id)) {
            return new WP_REST_Response([
                'place_id'     => $place_id,
                'fetch_status' => 'idle',
                'review_count' => 0,
                'ready'        => false,
            ], 200);
        }

        // Delegate to the editor handler so the job is advanced identically.
        $full = self::get_status($request);
        $data = $full instanceof WP_REST_Response ? $full->get_data() : (array) $full;

        $status = (string) ($data['fetch_status'] ?? 'idle');
        $count  = (int) ($data['review_count'] ?? 0);
        // "ready" = the poller should stop and reload the block: the job is done
        // (or failed) and no longer running/queued. The frontend reloads on
        // done-with-reviews; on failed/done-empty it just stops polling.
        $ready = !in_array($status, [GoogleReviewsStore::STATUS_RUNNING, GoogleReviewsStore::STATUS_QUEUED], true);

        return new WP_REST_Response([
            'place_id'     => $place_id,
            'fetch_status' => $status,
            'review_count' => $count,
            'ready'        => $ready,
        ], 200);
    }

    /**
     * Mutate the global places store. action ∈ {add, remove, refresh}.
     * Legacy actions {recent, save, unsave} are mapped to add/remove so the
     * existing block picker continues to work.
     *
     *   add     → lookup-first insert (no re-add/re-fetch if already saved)
     *   remove  → delete the place entry
     *   refresh → re-fetch this place's reviews into the store (network)
     */
    public static function post_places(WP_REST_Request $request)
    {
        $action     = sanitize_key((string) $request->get_param('action'));
        $place_id   = sanitize_text_field((string) $request->get_param('place_id'));
        $place_name = sanitize_text_field((string) $request->get_param('place_name'));
        // Location description (the search result's secondary_text) — saved so the
        // saved-place lists can show it without a fetch.
        $place_address = sanitize_text_field((string) $request->get_param('place_address'));

        if ($place_id === '') {
            return new WP_Error('embedpress_gr_missing_place', __('place_id is required.', 'embedpress'), ['status' => 400]);
        }

        // Map legacy picker actions onto the store.
        if ($action === 'recent' || $action === 'save') {
            $action = 'add';
        } elseif ($action === 'unsave') {
            $action = 'remove';
        }

        switch ($action) {
            case 'add':
                // Free plan supports ONE place in the global library; Pro is
                // unlimited. Re-adding a place that's already saved is always
                // fine (idempotent). A NET-NEW place is rejected for free users
                // once the library already holds a different place — this is the
                // server-side backstop for the settings-page crown upsell, so the
                // cap holds regardless of how the add is attempted.
                if (!Helper::is_pro_active() && !GoogleReviewsStore::get($place_id)) {
                    $existing = GoogleReviewsStore::all();
                    if (is_array($existing) && count($existing) >= 1) {
                        return new WP_Error(
                            'embedpress_gr_pro_required',
                            __('The free plan supports one place. Upgrade to EmbedPress Pro to add unlimited places.', 'embedpress'),
                            ['status' => 403]
                        );
                    }
                }
                // Lookup-first: if already saved, this just returns the existing
                // row — no duplicate, no fetch.
                $is_new = !GoogleReviewsStore::get($place_id);
                GoogleReviewsStore::add($place_id, $place_name, $place_address);

                // Seed the search result's real total review count + rating into
                // the place meta so the row shows the actual numbers right away,
                // before the background fetch completes. Only seed when missing
                // (don't clobber a fetched/refreshed value).
                $seed_total  = (int) $request->get_param('total');
                $seed_rating = (float) $request->get_param('rating');
                if ($seed_total > 0 || $seed_rating > 0) {
                    GoogleReviewsStore::seed_meta($place_id, [
                        'total'  => $seed_total,
                        'rating' => $seed_rating,
                    ]);
                }

                // ON ADD — WORKER FIRST (hybrid):
                //   1. Try the EmbedPress worker (start_job → enqueue.php). If
                //      the place was scraped recently it returns a CACHE HIT and
                //      reviews land in the store instantly; otherwise it queues a
                //      Chrome scrape, marks the place "running", and a WP-cron
                //      poller imports the result. Either way NO paid Google
                //      Places Details call is made. This also lets API-missing
                //      places (new/unverified listings, pasted as a Maps URL →
                //      CID/feature handle) get reviews the API can't return.
                //   2. FALLBACK — only if the worker can't move forward (not
                //      connected / unreachable / refused) do we fetch the first
                //      5 via Google Places Details so the card still shows
                //      something. This keeps the worker as the primary path per
                //      the "scrape on add, not Places API" contract.
                if ($is_new) {
                    $job_args = [
                        'fetch_all' => true,   // worker pulls up to the ceiling
                        'fetch_max' => 0,      // 0 = all up to MAX_REVIEWS_PER_JOB
                        'sort'      => 'newest',
                        'places'    => [],
                    ];
                    $started = (bool) apply_filters('embedpress/google_reviews/start_fetch_job', false, $place_id, $job_args);

                    if (!$started && GoogleReviewsManaged::is_connected()) {
                        // Worker unavailable → Places Details fallback (≤5).
                        $instant = GoogleReviewsManaged::fetch_instant($place_id);
                        if (!empty($instant['ok'])) {
                            $reviews = is_array($instant['reviews'] ?? null) ? $instant['reviews'] : [];
                            $meta    = is_array($instant['meta']    ?? null) ? $instant['meta']    : [];
                            if ($reviews || $meta) {
                                GoogleReviewsStore::reset_reviews($place_id);
                                GoogleReviewsStore::append_reviews($place_id, $reviews, $meta, 'managed', true);
                                GoogleReviewsStore::set_job(
                                    $place_id,
                                    GoogleReviewsStore::STATUS_DONE,
                                    ['message' => null, 'run_id' => null]
                                );
                            }
                        }
                        // Don't fail the add if the sub-fetch fails — the place is
                        // saved and the user can hit Refetch.
                    }
                }
                break;
            case 'remove':
                GoogleReviewsStore::remove($place_id);
                break;
            case 'refresh':
            case 'refetch':
                // Provider routing for refetch:
                //   - 'google'  → synchronous ≤5 API fetch (no background job).
                //   - 'apify'   → Pro background batched job via Apify (fetch_all=true).
                //   - 'managed' → hosted scraping proxy at api.embedpress.com,
                //                 also a background job (fetch_all=true).
                // Both background providers hook the start_fetch_job filter;
                // GoogleReviewsApify wins at priority 10 when a user token is
                // present, GoogleReviewsManaged falls in at priority 20.
                $provider  = GoogleReviewsRenderer::get_search_provider();
                $fetch_all = in_array($provider, ['apify', 'managed'], true);
                $fetch_max = (int) $request->get_param('fetch_max'); // 0 = all
                $mode      = sanitize_key((string) $request->get_param('mode'));
                $incremental = ($mode === 'quick' || $mode === ''); // default to quick
                $job_args  = [
                    'fetch_all'   => $fetch_all,
                    'fetch_max'   => max(0, $fetch_max),
                    'sort'        => sanitize_key((string) $request->get_param('sort')) ?: 'newest',
                    'places'      => [],
                    'incremental' => $incremental,
                    // This is a USER-INITIATED refetch (the Refetch button) — it
                    // must NEVER be served from the proxy's cache. Forces a fresh
                    // scrape regardless of quick/full mode. (Auto/render fetches
                    // omit this flag and still use the cache.)
                    'user_refetch' => true,
                ];

                // Pro "fetch all" runs as a BACKGROUND batched job (Apify async +
                // cron) to avoid the 300s sync timeout on large places. Pro hooks
                // this filter to start the job and returns true; the hosted
                // scraping proxy (GoogleReviewsManaged) also hooks it at the
                // lowest priority so it kicks in for users with no Apify token
                // and no Google key. Falls through to a synchronous ≤5 API fetch
                // when the active provider is plain Google.
                $started = $fetch_all
                    ? (bool) apply_filters('embedpress/google_reviews/start_fetch_job', false, $place_id, $job_args)
                    : false;

                if (!$started) {
                    $fetched = GoogleReviewsRenderer::fetch_into_store($place_id, $job_args);
                    if (is_wp_error($fetched)) {
                        return $fetched;
                    }
                }
                break;
            default:
                return new WP_Error('embedpress_gr_bad_action', __('Unknown action.', 'embedpress'), ['status' => 400]);
        }

        return new WP_REST_Response(self::places_payload(), 200);
    }

    /**
     * Build the places list payload from the DB store.
     */
    private static function places_payload(): array
    {
        $rows   = GoogleReviewsStore::all();
        $places = array_map(function ($r) {
            return [
                'place_id'        => $r['place_id'],
                'place_name'      => $r['place_name'] ?: ($r['meta']['name'] ?? ''),
                // Location description (address) for the saved-place lists.
                'address'         => (string) ($r['meta']['address'] ?? ''),
                'review_count'    => (int) $r['review_count'],
                'rating'          => isset($r['meta']['rating']) ? (float) $r['meta']['rating'] : 0,
                'total'           => isset($r['meta']['total']) ? (int) $r['meta']['total'] : 0,
                'source'          => $r['source'] ?? '',
                'last_fetched_at' => $r['last_fetched_at'] ?? null,
                // Background-fetch job state (Pro Apify batched fetch).
                'fetch_status'    => $r['fetch_status'] ?? 'idle',
                'fetch_message'   => $r['fetch_message'] ?? null,
                'fetched_so_far'  => isset($r['fetched_so_far']) ? (int) $r['fetched_so_far'] : 0,
            ];
        }, $rows);

        // `saved` kept as an alias of the full list for the legacy block picker;
        // `recent` left empty (the store has no recency concept — everything is
        // a saved entry now).
        return [
            'places' => $places,
            'saved'  => $places,
            'recent' => [],
        ];
    }

    private static function mask_key(string $key): string
    {
        if ($key === '') return '';
        $len = mb_strlen($key);
        if ($len <= 6) return str_repeat('*', $len);
        return mb_substr($key, 0, 4) . str_repeat('*', max(0, $len - 8)) . mb_substr($key, -4);
    }
}
