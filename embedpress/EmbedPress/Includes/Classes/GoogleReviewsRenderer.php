<?php

namespace EmbedPress\Includes\Classes;

(defined('ABSPATH') && defined('EMBEDPRESS_IS_LOADED')) or die("No direct script access allowed.");

/**
 * Renders Google Reviews for shortcode, Gutenberg block, and Elementor widget.
 * Single source of truth so all three surfaces produce identical markup.
 */
class GoogleReviewsRenderer
{
    const CACHE_PREFIX   = 'embedpress_gr_';
    const OPT_API_KEY    = 'embedpress_google_reviews_api_key';
    const OPT_CACHE_TTL  = 'embedpress_google_reviews_cache_ttl';
    const OPT_API_MODE   = 'embedpress_google_reviews_api_mode';
    const OPT_RECENT     = 'embedpress_google_reviews_recent';
    const OPT_SAVED      = 'embedpress_google_reviews_saved';
    // Apify API token — powers the Pro "Fetch all reviews" provider (gets past
    // Google's 5-review API cap). Stored globally; Pro reads it via get_apify_token().
    const OPT_APIFY_TOKEN = 'embedpress_google_reviews_apify_token';
    // Which provider powers place SEARCH: 'auto' | 'google' | 'apify'.
    const OPT_SEARCH_PROVIDER = 'embedpress_google_reviews_search_provider';
    const RECENT_MAX     = 10;

    // How many reviews the one-time auto-preview fetch pulls on first render of a
    // freshly-selected place. Kept small + cheap (matches Google's ≤5 ceiling) so
    // dropping the block never triggers an expensive unbounded Apify run. Users
    // pull more via EmbedPress → Google Reviews → Refetch.
    const AUTO_PREVIEW_LIMIT = 5;

    const ENDPOINT_LEGACY_AUTOCOMPLETE = 'https://maps.googleapis.com/maps/api/place/autocomplete/json';
    const ENDPOINT_LEGACY_DETAILS      = 'https://maps.googleapis.com/maps/api/place/details/json';
    const ENDPOINT_NEW_AUTOCOMPLETE    = 'https://places.googleapis.com/v1/places:autocomplete';
    const ENDPOINT_NEW_DETAILS         = 'https://places.googleapis.com/v1/places/';

    /**
     * Per-layout capability matrix — MUST stay identical to LAYOUT_CAPS in the
     * Gutenberg block (src/Blocks/google-reviews/src/edit.js) and the Elementor
     * widget (Embedpress_Google_Reviews::LAYOUT_CAPS). Single source of truth for
     * which controls/outputs each layout uses, so the renderer never emits a CSS
     * var or data-attr for a layout that doesn't support it.
     */
    const LAYOUT_CAPS = [
        'list'      => ['reviews' => true,  'header' => 'optional', 'columns' => false, 'gap' => false, 'max_width' => true, 'slider' => false, 'autoplay' => false, 'speed' => false, 'load_more' => true,  'images' => true,  'write_review' => true],
        'grid'      => ['reviews' => true,  'header' => 'optional', 'columns' => true,  'gap' => true,  'max_width' => true, 'slider' => false, 'autoplay' => false, 'speed' => false, 'load_more' => true,  'images' => true,  'write_review' => true],
        'card'      => ['reviews' => true,  'header' => 'optional', 'columns' => true,  'gap' => true,  'max_width' => true, 'slider' => false, 'autoplay' => false, 'speed' => false, 'load_more' => true,  'images' => true,  'write_review' => true],
        'carousel'  => ['reviews' => true,  'header' => 'optional', 'columns' => true,  'gap' => true,  'max_width' => true, 'slider' => true,  'autoplay' => true,  'speed' => false, 'load_more' => false, 'images' => false, 'write_review' => true],
        'masonry'   => ['reviews' => true,  'header' => 'optional', 'columns' => true,  'gap' => true,  'max_width' => true, 'slider' => false, 'autoplay' => false, 'speed' => false, 'load_more' => true,  'images' => true,  'write_review' => true],
        // badge + knowledge are compact summary widgets — the Pro CSS hides the
        // write-review button there, so the control is not supported (QA #4).
        'badge'     => ['reviews' => false, 'header' => 'forced',   'columns' => false, 'gap' => false, 'max_width' => true, 'slider' => false, 'autoplay' => false, 'speed' => false, 'load_more' => false, 'images' => false, 'write_review' => false],
        'spotlight' => ['reviews' => true,  'header' => 'optional', 'columns' => false, 'gap' => false, 'max_width' => true, 'slider' => false, 'autoplay' => true,  'speed' => false, 'load_more' => false, 'images' => true,  'write_review' => true],
        'knowledge' => ['reviews' => false, 'header' => 'forced',   'columns' => false, 'gap' => false, 'max_width' => true, 'slider' => false, 'autoplay' => false, 'speed' => false, 'load_more' => false, 'images' => false, 'write_review' => false],
        'marquee'   => ['reviews' => true,  'header' => 'optional', 'columns' => true,  'gap' => true,  'max_width' => true, 'slider' => false, 'autoplay' => false, 'speed' => true,  'load_more' => false, 'images' => false, 'write_review' => true],
        'bubble'    => ['reviews' => true,  'header' => 'optional', 'columns' => true,  'gap' => true,  'max_width' => true, 'slider' => false, 'autoplay' => false, 'speed' => false, 'load_more' => true,  'images' => true,  'write_review' => true],
    ];

    /** Capabilities for a layout (falls back to list for unknown layouts). */
    public static function layout_caps(string $layout): array
    {
        return self::LAYOUT_CAPS[$layout] ?? self::LAYOUT_CAPS['list'];
    }

    /**
     * Reset any attribute a layout doesn't support back to a safe value, so a
     * stale saved attribute (e.g. load_more enabled on list, then switched to
     * carousel) can never leak into the rendered output. The editor's conditional
     * controls are UX only — THIS is the authoritative gate on every surface.
     */
    public static function enforce_layout_caps(array $args): array
    {
        $layout = isset($args['layout']) ? sanitize_key((string) $args['layout']) : 'list';
        $caps   = self::layout_caps($layout);

        // Header is forced on for summary-only layouts; the toggle can't turn it
        // off (handled again at render, but normalize the arg here too).
        if (($caps['header'] ?? 'optional') === 'forced') {
            $args['show_summary'] = true;
        }

        // Per-review images: off unless the layout renders them.
        if (empty($caps['images'])) {
            $args['show_images'] = false;
        }
        // Load-more pagination: off unless the layout supports it.
        if (empty($caps['load_more'])) {
            $args['load_more'] = false;
        }
        // Autoplay: off unless the layout auto-advances (carousel/spotlight).
        if (empty($caps['autoplay'])) {
            $args['autoplay'] = false;
        }
        // Slider nav (arrows/dots/loop): off unless the layout is a slider.
        if (empty($caps['slider'])) {
            $args['show_arrows']   = false;
            $args['show_dots']     = false;
            $args['carousel_loop'] = false;
        }
        // Columns: neutralize to 1 when the layout doesn't use a column/per-view
        // count (no stale multi-column on a single-card layout).
        if (empty($caps['columns'])) {
            $args['columns'] = 1;
        }
        // Gap: zero out when the layout doesn't use the control (badge/list/etc.).
        if (empty($caps['gap'])) {
            $args['gap'] = 0;
        }

        return $args;
    }

    const DEFAULTS = [
        'place_id'   => '',
        'place_name' => '',
        'limit'      => 10,
        'min_rating' => 0,
        'layout'     => 'list',
        'show_photo'  => true,
        'show_date'   => true,
        'show_stars'  => true,
        'show_link'   => false,
        'show_images' => true,  // review photo strips (not the reviewer avatar)
        // Structural layout controls (free; apply to every layout that uses
        // them). columns drives grid/masonry column count + carousel
        // slides-per-view; max_width caps the block width (px, 0 = unset);
        // gap is the inter-card gap (px). Emitted as CSS custom properties on
        // the wrapper so every layout's CSS can read them without markup churn.
        'columns'    => 3,             // 1..6 (grid/masonry cols, carousel per-view)
        'max_width'  => 0,             // px; 0 = no cap
        'gap'        => 20,            // px; inter-card gap
        // Header / summary controls (free).
        'show_summary'        => true,  // the whole header block
        'show_summary_name'   => true,  // business name
        'show_summary_rating' => true,  // rating score number
        'show_summary_stars'  => true,  // the star row
        'show_summary_count'  => true,  // "N reviews"
        'show_write_review'   => true,  // "Write a review" button
        'summary_align'       => 'left', // left | center | right
        // Carousel / slider controls (free — carousel is a free layout).
        'show_arrows'    => true,      // prev/next arrows
        'show_dots'      => true,      // pagination dots
        'carousel_loop'  => true,      // seamless infinite loop
        'autoplay_speed' => 5,         // seconds per slide (1..30); 'autoplay' below
        // Pro-extended keys. Defined here so they round-trip through
        // wp_parse_args() and are visible to Pro's render filters even on
        // surfaces (shortcode/Elementor) that don't emit them. Free ignores
        // them; Pro reads them from the filtered $args.
        'sort'         => 'newest',   // newest | highest | lowest | relevant
        'keyword'      => '',          // keyword search filter
        'hide_empty'   => false,       // hide reviews with no text
        'fetch_all'    => false,       // Pro: fetch all reviews via Apify (past API 5-cap)
        'theme'        => 'light',     // light | dark
        'accent_color' => '',          // hex accent color
        'autoplay'     => false,       // carousel/slider autoplay
        'schema'       => false,       // emit AggregateRating + Review JSON-LD
        'places'       => [],          // multi-place id list
        'cache_ttl'    => 0,           // per-block cache TTL override (seconds)
        'load_more'    => true,        // paginate display with a Load More button (FREE) — on by default
        'per_page'     => 0,           // page size; 0 = use the "Reviews per page" (limit) control
        'is_editor_preview' => false,  // true only for the block-editor SSR preview (caps sliding-layout cards)
    ];

    /**
     * Render a Google Reviews block from a set of args. Single entry point used by
     * the shortcode, Gutenberg render_callback, and Elementor widget.
     */
    public static function render(array $args): string
    {
        // Enforce the per-layout capability matrix on the INCOMING args, before
        // anything else. The editor only HIDES controls a layout doesn't use, but
        // the saved attribute persists — e.g. enabling "Load more" on a list, then
        // switching to carousel, would otherwise still emit load-more on the
        // frontend. This resets every cap-violating attribute to a safe value so
        // the output can never contradict the layout, on any surface (block /
        // shortcode / Elementor / REST).
        $args = self::enforce_layout_caps($args);

        $args = wp_parse_args($args, self::DEFAULTS);

        // Server-side free/Pro enforcement (so the gating can't be bypassed via
        // shortcode/REST). The split now MIRRORS Essential Addons' free Business
        // Reviews widget: sort/keyword/hide-empty, header per-part toggles +
        // alignment, autoplay + speed, theme/accent, and JSON-LD schema are ALL
        // FREE. Only genuinely premium things stay Pro: the per-review attached
        // IMAGES strip, multi-place merge (pre_fetch filter), and the premium
        // layouts (gated separately via gr_pro_unlocked / allowed_layouts).
        if (!Helper::is_pro_active()) {
            // Review images (the per-review photo strip) are Pro — force off so it
            // can't be enabled via shortcode/REST without Pro.
            $args['show_images'] = false;
            // Minimum rating ("show only N★") is Pro — the highest-demand filter.
            // Force to 0 (Any) so it can't be applied via shortcode/REST in free.
            $args['min_rating'] = 0;
        }

        /**
         * Filter the normalized render args before anything else. Pro uses this
         * to inject its own attributes (style preset, sort, keyword filter,
         * multi-place list, cache override, schema toggle, etc.) into the
         * pipeline. Runs for every surface (block, shortcode, Elementor).
         *
         * @param array $args Normalized args (see self::DEFAULTS + Pro extras).
         */
        $args = (array) apply_filters('embedpress/google_reviews/render_args', $args);

        // Free layouts are list/grid/carousel/card; Pro extends the allowed set
        // via this filter (masonry/badge/spotlight/knowledge/marquee/bubble).
        $allowed_layouts = (array) apply_filters('embedpress/google_reviews/allowed_layouts', ['list', 'grid', 'carousel', 'card']);
        $requested = (string) $args['layout'];

        // If a PRO layout is selected without a Pro LICENSE, don't silently fall
        // back to the free list (or render a half-broken unstyled Pro layout) —
        // show an upsell so the user understands why their layout isn't rendering.
        // Gate on the license (is_pro_active), NOT allowed_layouts: the Pro PLUGIN
        // registers those layouts in allowed_layouts even when unlicensed, so the
        // filter alone can't tell "unlocked" from "merely installed".
        $pro_layouts = ['masonry', 'badge', 'spotlight', 'knowledge', 'marquee', 'bubble'];
        if (in_array($requested, $pro_layouts, true) && !self::gr_pro_unlocked()) {
            return self::render_pro_layout_upsell($requested);
        }

        $layout = in_array($requested, $allowed_layouts, true) ? $requested : 'list';

        // Visible-count ceiling. Free shows up to 10 reviews. When the site is
        // connected to the hosted EmbedPress API (the managed proxy), the store
        // holds many more, so lift the cap to 50 — matching the editor control's
        // ceiling (REVIEWS_PER_PAGE_MAX in edit.js) so the UI and the renderer
        // agree. Pro can lift further (multi-place / paginated) via the filter.
        $source_has_more = \EmbedPress\Includes\Classes\GoogleReviewsManaged::is_connected();
        $default_cap = $source_has_more ? 50 : 10;
        $max_allowed = (int) apply_filters('embedpress/google_reviews/max_reviews', $default_cap, $args);
        $max_allowed = $max_allowed > 0 ? $max_allowed : $default_cap;
        $limit       = max(1, min($max_allowed, (int) $args['limit']));

        // READ from the DB store — rendering does no network calls. If the place
        // was never fetched, populate it once (auto-fetch), then it's DB-only
        // until an explicit refresh from the settings page.
        $result = self::get_reviews_for_render($args['place_id'], $args);

        if (is_wp_error($result)) {
            // "No place selected" is the normal starting state of a freshly added
            // block/widget — NOT an error. Show a friendly "pick a place" prompt
            // instead of the alarming red error box.
            if ($result->get_error_code() === 'embedpress_gr_missing_place') {
                return self::render_pick_place_prompt();
            }
            return self::render_error($result->get_error_message());
        }

        $reviews      = $result['reviews'] ?? [];
        $meta         = $result['meta'] ?? [];
        $fetch_status = $result['fetch_status'] ?? GoogleReviewsStore::STATUS_DONE;

        if (!empty($args['min_rating'])) {
            $min     = (int) $args['min_rating'];
            $reviews = array_values(array_filter($reviews, function ($r) use ($min) {
                return (int) ($r['rating'] ?? 0) >= $min;
            }));
        }

        // Sort / keyword / hide-empty are FREE (mirrors Essential Addons' free
        // Business Reviews widget). Applied here in the free renderer so they
        // work without Pro. Pro NO LONGER hooks the reviews filter for these —
        // it would double-apply. Multi-place merge stays Pro (via pre_fetch).
        $reviews = self::apply_review_filters($reviews, $args);

        /**
         * Filter the review set after the built-in min-rating/sort/keyword/
         * hide-empty handling but before the limit slice. Pro hooks here ONLY
         * for multi-place merging now; sort/keyword/hide-empty are free (above).
         *
         * @param array $reviews List of normalized review arrays.
         * @param array $args    Render args.
         * @param array $meta    Place meta (name/rating/total).
         */
        $reviews = (array) apply_filters('embedpress/google_reviews/reviews', $reviews, $args, $meta);

        // Total available (sanity-capped) BEFORE slicing — the AJAX load-more
        // path needs it to know whether more pages exist.
        $total_available = min(count($reviews), 200);

        // How many cards to render in the INITIAL markup. "Load more" (FREE,
        // AJAX) renders only the first page; the button fetches the rest from
        // /google-reviews/page. Sliding layouts render the full set into their
        // track. (Filter kept for Pro overrides.)
        $render_count = self::gr_render_count($limit, $reviews, $args);
        $render_count = (int) apply_filters('embedpress/google_reviews/render_count', $render_count, $reviews, $args);
        $render_count = $render_count > 0 ? $render_count : $limit;
        $reviews = array_slice($reviews, 0, $render_count);

        if (empty($reviews)) {
            // State-aware empty handling — a freshly-added place whose reviews
            // are still being fetched in the background must NOT look like a dead
            // "no reviews" block:
            //   running / queued → a "loading" placeholder that self-refreshes
            //       on the frontend (a tiny poller swaps in the reviews when the
            //       job finishes — no manual reload).
            //   failed           → nothing for visitors; an actionable retry
            //       notice for admins (current_user_can('edit_posts')).
            //   done / idle      → the genuine "No reviews yet" message.
            if (in_array($fetch_status, [GoogleReviewsStore::STATUS_RUNNING, GoogleReviewsStore::STATUS_QUEUED], true)) {
                return self::render_loading($args['place_id']);
            }
            if ($fetch_status === GoogleReviewsStore::STATUS_FAILED) {
                return self::render_fetch_failed($args['place_id']);
            }
            return self::render_empty();
        }

        $client_id   = 'ep-gr-' . substr(md5(wp_json_encode($args) . microtime(true)), 0, 10);
        $caps        = self::layout_caps($layout);

        // Summary-only layouts (badge/knowledge) ARE the header — force it on so
        // turning "Show header" off (or an old saved value) can't render an empty
        // widget. Other layouts honor the toggle.
        $show_summary = (($caps['header'] ?? 'optional') === 'forced')
            || (!isset($args['show_summary']) || $args['show_summary']);

        // Theme (light/dark) + accent + carousel/spotlight autoplay are FREE
        // (mirrors Essential Addons). Build the base class/data set here; Pro
        // only ADDS its premium-layout preset classes (masonry/badge/etc.) via
        // the wrapper_class filter below.
        $base_classes = [];
        $theme = isset($args['theme']) ? sanitize_key($args['theme']) : 'light';
        if ($theme === 'dark') {
            $base_classes[] = 'ep-gr--dark';
        }
        if (!empty($args['autoplay']) && in_array($layout, ['carousel', 'spotlight'], true)) {
            $base_classes[] = 'ep-gr--autoplay';
        }

        // Pro appends premium-layout preset classes (masonry/badge/spotlight/
        // knowledge/marquee/bubble). Theme/autoplay above are already free.
        $extra_class = trim(
            implode(' ', $base_classes) . ' '
            . trim((string) apply_filters('embedpress/google_reviews/wrapper_class', '', $args))
        );

        // Accent color + autoplay flag (FREE) travel as data-* attrs the frontend
        // JS reads. Pro can still add its own via the wrapper_data filter.
        $data_attrs = [];
        $accent = isset($args['accent_color']) ? sanitize_hex_color($args['accent_color']) : '';
        if ($accent) {
            $data_attrs['ep-gr-accent'] = $accent;
        }
        if (!empty($args['autoplay'])) {
            $data_attrs['ep-gr-autoplay'] = '1';
        }
        $data_attrs = (array) apply_filters('embedpress/google_reviews/wrapper_data', $data_attrs, $args);

        // "Load more" (FREE, AJAX) → emit a config blob the frontend JS uses to
        // fetch the NEXT page of cards from /google-reviews/page. Skipped for
        // sliding layouts (they reveal everything through their own nav). The
        // per-page count IS the "Reviews per page" control.
        if (self::gr_load_more_applies($args) && $total_available > count($reviews)) {
            $per_page = self::gr_page_size($args, $limit);
            $data_attrs['ep-gr-loadmore'] = wp_json_encode([
                'rest'     => esc_url_raw(rest_url(GoogleReviewsRestController::NS . '/google-reviews/page')),
                'place_id' => (string) $args['place_id'],
                'per_page' => $per_page,
                'offset'   => count($reviews), // first page already rendered
                'query'    => [
                    'min_rating'  => (int) ($args['min_rating'] ?? 0),
                    'layout'      => (string) $layout,
                    'show_photo'  => !empty($args['show_photo']) ? 1 : 0,
                    'show_date'   => !empty($args['show_date']) ? 1 : 0,
                    'show_stars'  => !empty($args['show_stars']) ? 1 : 0,
                    'show_images' => !empty($args['show_images']) ? 1 : 0,
                    'sort'        => (string) ($args['sort'] ?? 'newest'),
                    'keyword'     => (string) ($args['keyword'] ?? ''),
                    'hide_empty'  => !empty($args['hide_empty']) ? 1 : 0,
                    'theme'       => (string) ($args['theme'] ?? 'light'),
                    'accent_color' => (string) ($args['accent_color'] ?? ''),
                    'places'      => array_values((array) ($args['places'] ?? [])),
                ],
            ]);
        }

        // Structural controls → CSS custom properties on the wrapper, emitted
        // per the layout's capability matrix so a layout never gets a var it
        // doesn't use (e.g. no columns var for list/badge/spotlight/knowledge,
        // no gap var for list/spotlight).
        $columns     = max(1, min(6, (int) $args['columns']));
        $max_width   = max(0, (int) $args['max_width']);
        $gap         = max(0, (int) $args['gap']);
        $style_parts = [];
        if ($caps['columns']) {
            $style_parts[] = '--ep-gr-columns:' . $columns;
            // Expose columns/per-view to frontend JS (carousel slides-per-view).
            $data_attrs['ep-gr-columns'] = $columns;
        }
        if ($caps['gap']) {
            $style_parts[] = '--ep-gr-gap:' . $gap . 'px';
        }
        if ($max_width > 0) {
            $style_parts[] = '--ep-gr-max-width:' . $max_width . 'px';
        }
        // Accent color must be set as the CSS custom property so the
        // var(--ep-gr-accent) rules (stars, load-more, dots…) actually pick it
        // up. The data-ep-gr-accent attribute alone never defines the variable,
        // so the accent had no visible effect (QA #8). $accent is the sanitized
        // hex computed above.
        if (!empty($accent)) {
            $style_parts[] = '--ep-gr-accent:' . $accent;
        }
        $wrapper_style = !empty($style_parts) ? implode(';', $style_parts) . ';' : '';

        // Slider controls (free) → data attrs the frontend reads. Each sliding
        // layout gets the subset it uses.
        $speed = max(1, min(30, (float) ($args['autoplay_speed'] ?? 5)));
        if ($layout === 'carousel') {
            $data_attrs['ep-gr-arrows'] = !empty($args['show_arrows']) ? '1' : '0';
            $data_attrs['ep-gr-dots']   = !empty($args['show_dots']) ? '1' : '0';
            $data_attrs['ep-gr-loop']   = !empty($args['carousel_loop']) ? '1' : '0';
            $data_attrs['ep-gr-autoplay'] = !empty($args['autoplay']) ? '1' : '0';
            $data_attrs['ep-gr-speed']  = $speed;
        } elseif ($layout === 'spotlight') {
            $data_attrs['ep-gr-autoplay'] = !empty($args['autoplay']) ? '1' : '0';
            $data_attrs['ep-gr-speed']    = $speed;
        } elseif ($layout === 'marquee') {
            // Marquee speed expressed as the same seconds knob (lower = faster).
            $data_attrs['ep-gr-speed'] = $speed;
        }

        ob_start();
        ?>
        <div id="<?php echo esc_attr($client_id); ?>" class="ep-google-reviews ep-google-reviews--<?php echo esc_attr($layout); ?><?php echo $extra_class !== '' ? ' ' . esc_attr($extra_class) : ''; ?>" data-layout="<?php echo esc_attr($layout); ?>" style="<?php echo esc_attr($wrapper_style); ?>"<?php
            foreach ($data_attrs as $k => $v) {
                if (!is_string($k) || $k === '') {
                    continue;
                }
                echo ' data-' . esc_attr($k) . '="' . esc_attr(is_scalar($v) ? (string) $v : wp_json_encode($v)) . '"';
            }
        ?>>
            <?php
            // Schema.org JSON-LD (FREE — mirrors Essential Addons' free Local
            // Business schema). Emitted inside the wrapper so it travels with the
            // markup. The filter is kept so Pro/3rd-parties can still augment it.
            echo apply_filters('embedpress/google_reviews/schema_jsonld', self::build_schema_jsonld('', $reviews, $meta, $args), $reviews, $meta, $args);

            if ($show_summary) {
                echo self::render_summary($meta, $args);
            }
            ?>
            <div class="ep-gr-items">
                <?php foreach ($reviews as $review) : ?>
                    <?php
                    /**
                     * Filter a single rendered review card's HTML. Pro hooks
                     * here to add per-card chrome (e.g. a Google logo, owner
                     * response, "verified" badge).
                     *
                     * @param string $card_html Rendered card HTML.
                     * @param array  $review    The review data.
                     * @param array  $args      Render args.
                     */
                    echo apply_filters('embedpress/google_reviews/review_card', self::render_review($review, $args), $review, $args);
                    ?>
                <?php endforeach; ?>
            </div>
            <?php if ($args['show_link'] && !empty($args['place_id'])) : ?>
                <div class="ep-gr-footer">
                    <a class="ep-gr-view-on-google" href="<?php echo esc_url('https://search.google.com/local/reviews?placeid=' . rawurlencode($args['place_id'])); ?>" target="_blank" rel="noopener nofollow">
                        <?php echo esc_html__('View on Google', 'embedpress'); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php
        $html = ob_get_clean();

        // "Load more" button (FREE, AJAX) — appended when more reviews exist than
        // the first page rendered. The JS fetches subsequent pages on click.
        $html = self::gr_append_load_more($html, count($reviews), $total_available, $args);

        /**
         * Final filter over the complete block HTML. Last-resort hook for Pro
         * (e.g. wrapping the whole thing). Most Pro work should use the more
         * specific filters above.
         */
        return (string) apply_filters('embedpress/google_reviews/html', $html, $reviews, $meta, $args);
    }

    /* ── Load more (FREE): paginate the rendered set, no extra API calls ──── */

    /**
     * Layouts where "Load more" applies — vertical/flow layouts that stack
     * cards. Sliding layouts (carousel/marquee/spotlight) own their navigation,
     * so load-more is a no-op there.
     */
    private static function gr_load_more_applies(array $args): bool
    {
        if (empty($args['load_more'])) {
            return false;
        }
        $layout  = isset($args['layout']) ? sanitize_key($args['layout']) : 'list';
        return !in_array($layout, ['carousel', 'marquee', 'spotlight'], true);
    }

    /**
     * Is Pro UNLOCKED for Google Reviews? Keyed on the Pro PLUGIN being active —
     * NOT on a valid license. This MUST match the editor's gate
     * (`isProPluginActive` = defined EMBEDPRESS_SL_ITEM_SLUG, i.e. Pro plugin
     * active) and the GR block's other Pro controls (sort/theme/etc. gate on
     * is_pro_active()). Using the stricter license check (is_pro_features_enabled)
     * here caused the "Pro is active but Masonry shows the upsell" bug: the editor
     * let you pick the Pro layout (plugin active) while the renderer demanded a
     * valid license and rendered the upsell — a confusing editor/renderer
     * mismatch. Gate on plugin-active everywhere so the two surfaces agree.
     */
    private static function gr_pro_unlocked(): bool
    {
        return (bool) Helper::is_pro_active();
    }

    /** Effective page size = the "Reviews per page" count (limit). */
    private static function gr_page_size(array $args, int $limit): int
    {
        $n = (int) ($args['per_page'] ?? 0);
        if ($n < 1) {
            $n = (int) ($args['limit'] ?? $limit);
        }
        return max(1, $n);
    }

    /**
     * How many cards to render in the INITIAL markup.
     *  - Sliding layouts render the full set (their nav reveals it).
     *  - "Load more" (AJAX) renders just the FIRST page; the button fetches the
     *    rest from /google-reviews/page on demand.
     *  - Otherwise the display limit.
     */
    private static function gr_render_count(int $limit, array $reviews, array $args): int
    {
        $layout = isset($args['layout']) ? sanitize_key($args['layout']) : 'list';
        if (in_array($layout, ['carousel', 'marquee', 'spotlight'], true)) {
            // EDITOR PREVIEW: cap sliding layouts to a few cards. Rendering up to
            // 200 cards makes each block-renderer SSR response ~1.3MB; with
            // ServerSideRender re-firing on every attribute change, rapid layout
            // switches piled up huge in-flight requests and froze the editor. A
            // handful of cards is plenty to preview the layout. The FRONTEND
            // still renders the full set (this flag is only set for the
            // block-editor SSR request).
            if (!empty($args['is_editor_preview'])) {
                return min(count($reviews), 12);
            }
            return min(count($reviews), 200);
        }
        if (self::gr_load_more_applies($args)) {
            return self::gr_page_size($args, $limit); // first page only — AJAX loads more
        }
        return $limit;
    }

    /** Append the "Load more" button when more reviews exist than the rendered page. */
    private static function gr_append_load_more(string $html, int $rendered, int $total, array $args): string
    {
        if (!self::gr_load_more_applies($args)) {
            return $html;
        }
        if ($total <= $rendered) {
            return $html; // everything already shown — nothing to page
        }
        $btn = '<div class="ep-gr-loadmore-wrap"><button type="button" class="ep-gr-loadmore">'
            . esc_html__('Load more reviews', 'embedpress')
            . '</button></div>';
        // Insert before the closing wrapper </div> so it sits inside .ep-google-reviews.
        $pos = strrpos($html, '</div>');
        if ($pos === false) {
            return $html . $btn;
        }
        return substr($html, 0, $pos) . $btn . substr($html, $pos);
    }

    /**
     * AJAX "Load more": render ONE page of review cards from the DB store.
     * Returns ['html' => cards, 'has_more' => bool, 'next_offset' => int].
     * Pure DB read — same fetch+filter pipeline as render(), then a slice.
     */
    public static function render_page(array $args, int $offset, int $per_page): array
    {
        $args = self::enforce_layout_caps($args);
        $args = wp_parse_args($args, self::DEFAULTS);

        if (!Helper::is_pro_active()) {
            // Mirror render()'s server-side free enforcement for the Pro-gated bits
            // a card can carry (none affect card text, but keep parity).
            $args['accent_color'] = $args['accent_color'] ?? '';
        }

        $args   = (array) apply_filters('embedpress/google_reviews/render_args', $args);
        $result = self::get_reviews_for_render($args['place_id'], $args);
        if (is_wp_error($result)) {
            return ['html' => '', 'has_more' => false, 'next_offset' => $offset];
        }

        $reviews = $result['reviews'] ?? [];
        $meta    = $result['meta'] ?? [];

        // Apply the SAME filter pipeline as render() so paged ("Load more")
        // results match the first page. Previously this only applied min_rating,
        // so hide-empty / keyword / sort were skipped on pages 2+ and e.g.
        // no-text reviews reappeared after Load more even with "Hide reviews
        // with no text" enabled.
        if (!empty($args['min_rating'])) {
            $min     = (int) $args['min_rating'];
            $reviews = array_values(array_filter($reviews, static function ($r) use ($min) {
                return (int) ($r['rating'] ?? 0) >= $min;
            }));
        }
        $reviews = self::apply_review_filters($reviews, $args);
        $reviews = (array) apply_filters('embedpress/google_reviews/reviews', $reviews, $args, $meta);

        // Cap the full set the same way render() does (sanity ceiling), then slice
        // to the requested page.
        $total = min(count($reviews), 200);
        $slice = array_slice($reviews, $offset, $per_page);

        $html = '';
        foreach ($slice as $review) {
            $html .= apply_filters('embedpress/google_reviews/review_card', self::render_review($review, $args), $review, $args);
        }

        $next_offset = $offset + count($slice);
        return [
            'html'        => $html,
            'has_more'    => $next_offset < $total,
            'next_offset' => $next_offset,
        ];
    }

    /**
     * Get a place's reviews for rendering — from the DB store, never the network.
     * If the place has never been fetched, do a one-time auto-fetch to populate
     * the store; thereafter it's DB-only until the user refreshes from settings.
     *
     * @return array|\WP_Error {reviews, meta}
     */
    public static function get_reviews_for_render(string $place_id, array $args = [])
    {
        $place_id = trim($place_id);
        if ($place_id === '') {
            return new \WP_Error('embedpress_gr_missing_place', __('No place selected.', 'embedpress'));
        }

        $row = GoogleReviewsStore::get($place_id);

        // Never fetched (new place, or added but not yet populated) → auto-fetch
        // once to fill the store. Pro's "fetch all" mode is honoured here too.
        // BUT skip if a background job is already running/queued for this place —
        // otherwise every render would restart the job (the instant batch sets
        // reviews but not last_fetched_at, which stays null until the job ends).
        $job_active = $row && in_array(
            $row['fetch_status'] ?? '',
            [GoogleReviewsStore::STATUS_RUNNING, GoogleReviewsStore::STATUS_QUEUED],
            true
        );
        if (!$job_active && (!$row || empty($row['last_fetched_at']))) {
            // First render of a freshly-selected place: do a CHEAP, BOUNDED
            // preview fetch (≤5) only. The expensive "fetch all" (Apify run /
            // background job, up to the ceiling) must be an explicit, opted-in
            // action from EmbedPress → Google Reviews → Refetch — never an
            // automatic side effect of dropping the block on a page. The `auto`
            // flag tells fetch_into_store to stay on the cheap path.
            $fetched = self::fetch_into_store($place_id, array_merge($args, ['auto' => true]));
            if (is_wp_error($fetched)) {
                return $fetched;
            }
            $row = GoogleReviewsStore::get($place_id);
        }

        if (!$row) {
            return ['reviews' => [], 'meta' => [], 'fetch_status' => GoogleReviewsStore::STATUS_IDLE];
        }

        return [
            'reviews'      => is_array($row['reviews']) ? $row['reviews'] : [],
            'meta'         => is_array($row['meta']) ? $row['meta'] : [],
            // Surface the fetch state so render() can tell apart "still fetching"
            // (running/queued) from "fetched but genuinely empty" (done) and
            // "failed" — each gets a different placeholder instead of one flat
            // "No reviews" message. last_fetched_at null + no active job → idle.
            'fetch_status' => (string) ($row['fetch_status'] ?? GoogleReviewsStore::STATUS_IDLE),
        ];
    }

    /**
     * Fetch a place's reviews from the source (Pro Apify when fetch_all + token,
     * else the official ≤5 API) and persist them to the DB store. This is the
     * ONLY path that hits the network for reviews. Triggered by:
     *   - the settings-page "Refresh" action, or
     *   - a one-time auto-fetch on first render (get_reviews_for_render).
     *
     * @return array|\WP_Error {reviews, meta, source}
     */
    public static function fetch_into_store(string $place_id, array $args = [])
    {
        $place_id = trim($place_id);
        if ($place_id === '') {
            return new \WP_Error('embedpress_gr_missing_place', __('No place selected.', 'embedpress'));
        }

        // Cheap auto-preview fetch (first render): never fetch-all. A bounded
        // ≤5 pull (Apify run capped at 5, or the Google ≤5 API) is enough to make
        // the block show real reviews immediately, without burning a large Apify
        // run. The expensive fetch-all only happens via an explicit Settings
        // Refetch (which does NOT set `auto`).
        $is_auto = !empty($args['auto']);
        if ($is_auto) {
            $args['fetch_all'] = false;
            if (!isset($args['fetch_max']) || (int) $args['fetch_max'] < 1) {
                $args['fetch_max'] = self::AUTO_PREVIEW_LIMIT;
            }
        }

        // ── EmbedPress managed scraper WINS (when connected) ──────────────────
        // The self-hosted scraper at api.embedpress.com/google-reviews/v1
        // fetches ALL reviews for free — strictly better than the Apify
        // ≤preview run or the Google Places ≤5 API. When the site is connected,
        // route EVERY fetch (incl. auto-add) straight to it: a cache HIT returns
        // all reviews inline; a MISS enqueues a background job (the store goes
        // `running`, the cron poller fills it in, the block re-renders). We do
        // NOT fall back to a partial Apify/Google preview that would "succeed"
        // with a few reviews and freeze the place as done.
        if (\EmbedPress\Includes\Classes\GoogleReviewsManaged::is_connected()) {
            $managed_args = $args;
            $managed_args['fetch_all'] = true;          // always pull everything
            unset($managed_args['fetch_max']);          // no preview cap
            $started = \EmbedPress\Includes\Classes\GoogleReviewsManaged::start_job($place_id, $managed_args);
            $row = GoogleReviewsStore::get($place_id);
            if ($started) {
                // Cache hit → store already holds the full set; miss → running.
                return [
                    'reviews' => ($row && is_array($row['reviews'])) ? $row['reviews'] : [],
                    'meta'    => ($row && is_array($row['meta'])) ? $row['meta'] : [],
                    'source'  => 'embedpress',
                ];
            }
            // start_job returned false (proxy refused / unreachable) → fall
            // through to the legacy Apify/Google paths so the block still shows
            // something rather than nothing.
        }

        // Not connected (or managed refused): legacy provider preference.
        $args = self::maybe_prefer_apify_fetch($args);

        // ── Background fetch-all strategy (Apify) ─────────────────────────────
        // Pulling every review via Apify takes time (the run-sync actor alone has
        // a ~15s floor). Rather than block the first render, we start the
        // background batched job and return immediately — the UIs render now and
        // show a live progress bar ("Fetching all reviews… N so far"), then
        // re-render as the cron imports batches into the store (up to the 1000
        // ceiling). The job manages fetch_status / fetched_so_far itself.
        // Skipped entirely for auto-previews (cheap path only).
        if (!$is_auto && !empty($args['fetch_all']) && self::get_apify_token() !== '') {
            $started = (bool) apply_filters('embedpress/google_reviews/start_fetch_job', false, $place_id, $args);
            if ($started) {
                $row = GoogleReviewsStore::get($place_id);
                return [
                    'reviews' => ($row && is_array($row['reviews'])) ? $row['reviews'] : [],
                    'meta'    => ($row && is_array($row['meta'])) ? $row['meta'] : [],
                    'source'  => 'apify',
                ];
            }
            // Job couldn't start (no token / Apify error) → fall through to the
            // official ≤5 API path below so the block still shows something.
        }

        // Pro hooks pre_fetch to source reviews (Apify "fetch all" / multi-place).
        // Returns a {reviews, meta} array, or null to use the official API.
        $pre = apply_filters('embedpress/google_reviews/pre_fetch', null, $place_id, $args);
        if (is_array($pre)) {
            $result = self::normalize_result($pre);
            $source = !empty($args['fetch_all']) ? 'apify' : 'api';
        } else {
            $result = self::fetch_from_api($place_id);
            if (is_wp_error($result)) {
                return $result;
            }
            $source = 'api';
        }

        $reviews = $result['reviews'] ?? [];
        $meta    = $result['meta'] ?? [];

        GoogleReviewsStore::save_reviews($place_id, $reviews, $meta, $source);

        return ['reviews' => $reviews, 'meta' => $meta, 'source' => $source];
    }

    /**
     * If Apify is the usable provider (token set) and Google is not (no key),
     * flag fetch_all so the Pro Apify pre_fetch sources reviews instead of the
     * Google API. Only flips the flag when it isn't already set and a Google
     * fetch would otherwise fail. No-op when a Google key is present (Google
     * stays the default free path) or when no Apify token is configured.
     */
    private static function maybe_prefer_apify_fetch(array $args): array
    {
        if (!empty($args['fetch_all'])) {
            return $args; // already routing through Apify (explicit fetch-all)
        }
        $has_google = self::get_api_key() !== '';
        $has_apify  = self::get_apify_token() !== '';
        if (!$has_google && $has_apify) {
            // Apify-only site. For an auto-preview, route through Apify but keep
            // it BOUNDED (fetch_max already set to the preview limit) instead of
            // flipping fetch_all — a key-less site should still show a few
            // reviews without an unbounded run. For non-auto (explicit) fetches,
            // fetch_all stays the full pull.
            if (empty($args['auto'])) {
                $args['fetch_all'] = true;
            } else {
                // Bounded Apify preview: pre_fetch keys off fetch_all OR places,
                // so signal a capped fetch_all and let fetch_max do the bounding.
                $args['fetch_all'] = true;
                $args['fetch_max'] = isset($args['fetch_max']) && (int) $args['fetch_max'] > 0
                    ? (int) $args['fetch_max']
                    : self::AUTO_PREVIEW_LIMIT;
            }
        }
        return $args;
    }

    /**
     * Render the summary header: place name + overall Google rating + total
     * review count. Falls back gracefully when meta is unavailable (e.g. an
     * older cache or an API that didn't return it) — it simply renders nothing
     * rather than a half-empty header.
     */
    private static function render_summary(array $meta, array $args): string
    {
        $name   = $meta['name'] ?? ($args['place_name'] ?? '');
        $rating = isset($meta['rating']) ? (float) $meta['rating'] : 0.0;
        $total  = isset($meta['total']) ? (int) $meta['total'] : 0;

        if ($name === '' && $rating <= 0) {
            return '';
        }

        $address = isset($meta['address']) ? (string) $meta['address'] : '';

        // Granular header toggles (free). Each part can be hidden independently.
        $show_name   = !isset($args['show_summary_name'])   || $args['show_summary_name'];
        $show_rating = !isset($args['show_summary_rating']) || $args['show_summary_rating'];
        $show_stars  = !isset($args['show_summary_stars'])  || $args['show_summary_stars'];
        $show_count  = !isset($args['show_summary_count'])  || $args['show_summary_count'];
        $show_write  = !isset($args['show_write_review'])   || $args['show_write_review'];

        $align = isset($args['summary_align']) ? sanitize_key($args['summary_align']) : 'left';
        $align = in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
        $align_class = ' ep-gr-summary--align-' . $align;

        // Nothing left to show in the rating row? skip it entirely.
        $has_rating_row = $rating > 0 && ($show_rating || $show_stars || $show_count);

        ob_start();
        ?>
        <div class="ep-gr-summary<?php echo esc_attr($align_class); ?>">
            <div class="ep-gr-summary-head">
                <div class="ep-gr-summary-place">
                    <?php if ($name !== '' && $show_name) : ?>
                        <div class="ep-gr-summary-name"><?php echo esc_html($name); ?></div>
                    <?php endif; ?>
                    <?php if ($address !== '' && $show_name) : ?>
                        <div class="ep-gr-summary-address"><?php echo esc_html($address); ?></div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($args['place_id']) && $show_write) : ?>
                    <a class="ep-gr-write-review" href="<?php echo esc_url('https://search.google.com/local/writereview?placeid=' . rawurlencode($args['place_id'])); ?>" target="_blank" rel="noopener nofollow"><?php esc_html_e('Write a review', 'embedpress'); ?></a>
                <?php endif; ?>
            </div>
            <?php if ($has_rating_row) : ?>
                <div class="ep-gr-summary-rating">
                    <?php if ($show_rating) : ?>
                        <span class="ep-gr-summary-score"><?php echo esc_html(number_format_i18n($rating, 1)); ?></span>
                    <?php endif; ?>
                    <?php if ($show_stars) : ?>
                        <span class="ep-gr-stars ep-gr-stars--lg" role="img" aria-label="<?php /* translators: %s: average star rating out of 5 */ echo esc_attr(sprintf(__('%s out of 5 stars', 'embedpress'), number_format_i18n($rating, 1))); ?>">
                            <?php echo self::render_star_row($rating); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($total > 0 && $show_count) : ?>
                        <span class="ep-gr-summary-count"><?php
                            /* translators: %s: number of Google reviews */
                            echo esc_html(sprintf(_n('%s review', '%s reviews', $total, 'embedpress'), number_format_i18n($total)));
                        ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a single review card.
     */
    private static function render_review(array $review, array $args): string
    {
        $author   = $review['author_name'] ?? __('Anonymous', 'embedpress');
        $rating   = (int) ($review['rating'] ?? 0);
        $text     = $review['text'] ?? '';
        $photo    = $review['profile_photo_url'] ?? '';
        $time     = isset($review['time']) ? (int) $review['time'] : 0;
        // Prefer Google's relative phrasing ("a week ago") and fall back to an
        // absolute date. Matches how reviews read on Google itself.
        $relative = isset($review['relative_time']) ? (string) $review['relative_time'] : '';

        // Rich fields (present only for Apify-sourced reviews; absent → not rendered).
        $is_local_guide = !empty($review['is_local_guide']);
        $rev_count      = isset($review['reviewer_reviews']) ? (int) $review['reviewer_reviews'] : 0;
        $images         = (isset($review['images']) && is_array($review['images'])) ? $review['images'] : [];
        $owner_resp     = isset($review['owner_response']) ? (string) $review['owner_response'] : '';
        $likes          = isset($review['likes']) ? (int) $review['likes'] : 0;

        ob_start();
        ?>
        <article class="ep-gr-review" itemscope itemtype="https://schema.org/Review">
            <header class="ep-gr-review-head">
                <?php if ($args['show_photo']) : ?>
                    <?php /* Initials placeholder sits underneath; the photo overlays it
                             and, if it fails to load, removes itself so the initials show. */ ?>
                    <span class="ep-gr-avatar ep-gr-avatar--placeholder" aria-hidden="true">
                        <?php echo esc_html(self::initials($author)); ?>
                        <?php if ($photo) : ?>
                            <img class="ep-gr-avatar-img" src="<?php echo esc_url($photo); ?>" alt="" loading="lazy" width="40" height="40" referrerpolicy="no-referrer" onerror="this.remove()" />
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
                <div class="ep-gr-meta">
                    <div class="ep-gr-author" itemprop="author"><?php echo esc_html($author); ?></div>
                    <?php if ($is_local_guide || $rev_count > 0) : ?>
                        <div class="ep-gr-reviewer-meta">
                            <?php if ($is_local_guide) : ?>
                                <span class="ep-gr-local-guide"><?php esc_html_e('Local Guide', 'embedpress'); ?></span>
                            <?php endif; ?>
                            <?php if ($is_local_guide && $rev_count > 0) : ?>
                                <span class="ep-gr-dot" aria-hidden="true">·</span>
                            <?php endif; ?>
                            <?php if ($rev_count > 0) : ?>
                                <span class="ep-gr-reviewer-count"><?php
                                    /* translators: %s: number of reviews the reviewer has written */
                                    echo esc_html(sprintf(_n('%s review', '%s reviews', $rev_count, 'embedpress'), number_format_i18n($rev_count)));
                                ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php /* Google "G" badge — the recognizable mark real Google review
                         widgets show top-right of each card. */ ?>
                <span class="ep-gr-source" aria-label="<?php esc_attr_e('Posted on Google', 'embedpress'); ?>">
                    <?php echo self::google_g_svg(); ?>
                </span>
            </header>

            <?php if ($args['show_stars'] || ($args['show_date'] && ($relative !== '' || $time))) : ?>
                <div class="ep-gr-stars-line">
                    <?php if ($args['show_stars']) : ?>
                        <span class="ep-gr-stars" role="img" aria-label="<?php /* translators: %d: star rating out of 5 */ echo esc_attr(sprintf(__('%d out of 5 stars', 'embedpress'), $rating)); ?>">
                            <?php echo self::render_star_row((float) $rating); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($args['show_date'] && ($relative !== '' || $time)) : ?>
                        <time class="ep-gr-date"<?php echo $time ? ' datetime="' . esc_attr(gmdate('c', $time)) . '"' : ''; ?>><?php
                            echo esc_html($relative !== '' ? $relative : date_i18n(get_option('date_format'), $time));
                        ?></time>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($text) : ?>
                <div class="ep-gr-body">
                    <div class="ep-gr-text" itemprop="reviewBody"><?php echo esc_html($text); ?></div>
                    <?php /* JS reveals this only when the text actually overflows the clamp. */ ?>
                    <button type="button" class="ep-gr-readmore" hidden aria-expanded="false">
                        <span class="ep-gr-readmore-more"><?php esc_html_e('Read more', 'embedpress'); ?></span>
                        <span class="ep-gr-readmore-less"><?php esc_html_e('Show less', 'embedpress'); ?></span>
                    </button>
                </div>
            <?php endif; ?>

            <?php if (!empty($images) && !empty($args['show_images'])) : ?>
                <?php
                // Cap rendered thumbnails; show a "+N" overlay on the last tile
                // when there are more. data-count drives the CSS grid layout.
                $max_thumbs = 4;
                $total_imgs = count($images);
                $shown      = array_slice($images, 0, $max_thumbs);
                $overflow   = $total_imgs - count($shown);
                ?>
                <div class="ep-gr-photos" data-count="<?php echo esc_attr((string) count($shown)); ?>">
                    <?php foreach ($shown as $idx => $img) : ?>
                        <?php $is_last = ($idx === count($shown) - 1); ?>
                        <span class="ep-gr-photo<?php echo ($is_last && $overflow > 0) ? ' ep-gr-photo--more' : ''; ?>"<?php
                            echo ($is_last && $overflow > 0) ? ' data-more="+' . esc_attr((string) $overflow) . '"' : '';
                        ?>>
                            <img src="<?php echo esc_url($img); ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.parentNode.remove()" />
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($owner_resp !== '') : ?>
                <div class="ep-gr-owner">
                    <div class="ep-gr-owner-head"><?php esc_html_e('Response from the owner', 'embedpress'); ?></div>
                    <div class="ep-gr-owner-text"><?php echo wp_kses(nl2br(esc_html($owner_resp)), ['br' => []]); ?></div>
                </div>
            <?php endif; ?>

            <?php /* Like / thanks action row — mirrors Google's review footer. Static
                     (display only), like the rest of an embedded review. */ ?>
            <div class="ep-gr-rev-actions" aria-hidden="true">
                <span class="ep-gr-rev-action ep-gr-rev-like" title="<?php esc_attr_e('Helpful', 'embedpress'); ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 1 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78z"/></svg>
                </span>
                <span class="ep-gr-rev-action ep-gr-rev-thanks">
                    <span class="ep-gr-thanks-icon" aria-hidden="true">🙏</span>
                    <?php if ($likes > 0) : ?><span class="ep-gr-thanks-count"><?php echo esc_html(number_format_i18n($likes)); ?></span><?php endif; ?>
                </span>
            </div>
        </article>
        <?php
        return ob_get_clean();
    }

    /**
     * Float-aware star row, Google style: a full gray 5-star track with a gold
     * fill overlay clipped to the exact rating (so 4.5 shows half a star). The
     * rating drives the fill width via the `--ep-gr-rating` custom property; CSS
     * does `width: calc(var(--ep-gr-rating)/5*100%)`. Glyphs are aria-hidden —
     * the accessible label lives on the wrapping element (caller supplies it).
     *
     * Back-compat: the inner `.ep-gr-star.is-filled/.is-empty` spans are kept in
     * the track so existing Pro CSS that colors `.is-filled` still applies.
     */
    private static function render_star_row(float $rating): string
    {
        $rating = max(0.0, min(5.0, $rating));
        // Crisp SVG star (one per slot) instead of a font glyph, so the shape is
        // identical across platforms/fonts. The gold fill is clipped to the
        // rating percentage for half-star precision (CSS overflow:hidden).
        $star   = '<svg class="ep-gr-star-svg" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>';
        $glyphs = str_repeat($star, 5);
        $pct    = ($rating / 5) * 100;
        return '<span class="ep-gr-starrow" style="--ep-gr-rating:' . esc_attr((string) $rating) . '" aria-hidden="true">'
            . '<span class="ep-gr-starrow-track">' . $glyphs . '</span>'
            . '<span class="ep-gr-starrow-fill" style="width:' . esc_attr((string) round($pct, 2)) . '%">' . $glyphs . '</span>'
            . '</span>';
    }

    /** The Google "G" logo as inline SVG (brand 4-color mark). */
    private static function google_g_svg(): string
    {
        return '<svg viewBox="0 0 48 48" width="18" height="18" aria-hidden="true">'
            . '<path fill="#4285F4" d="M45.12 24.5c0-1.56-.14-3.06-.4-4.5H24v8.51h11.84c-.51 2.75-2.06 5.08-4.39 6.64v5.52h7.11c4.16-3.83 6.56-9.47 6.56-16.17z"/>'
            . '<path fill="#34A853" d="M24 46c5.94 0 10.92-1.97 14.56-5.33l-7.11-5.52c-1.97 1.32-4.49 2.1-7.45 2.1-5.73 0-10.58-3.87-12.31-9.07H4.34v5.7C7.96 41.07 15.4 46 24 46z"/>'
            . '<path fill="#FBBC05" d="M11.69 28.18A13.6 13.6 0 0 1 10.96 24c0-1.45.25-2.86.69-4.18v-5.7H4.34A21.99 21.99 0 0 0 2 24c0 3.55.85 6.91 2.34 9.88l7.35-5.7z"/>'
            . '<path fill="#EA4335" d="M24 10.75c3.23 0 6.13 1.11 8.41 3.29l6.31-6.31C34.91 4.18 29.93 2 24 2 15.4 2 7.96 6.93 4.34 14.12l7.35 5.7c1.73-5.2 6.58-9.07 12.31-9.07z"/>'
            . '</svg>';
    }

    /**
     * Whole-star renderer (kept for back-compat / any caller that wants discrete
     * stars). New code should use render_star_row() for half-star support.
     */
    private static function render_stars(int $rating): string
    {
        $rating = max(0, min(5, $rating));
        $out    = '';
        for ($i = 1; $i <= 5; $i++) {
            $out .= '<span class="ep-gr-star ' . ($i <= $rating ? 'is-filled' : 'is-empty') . '" aria-hidden="true">★</span>';
        }
        return $out;
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last  = isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '';
        return strtoupper($first . $last);
    }

    /**
     * Sort + keyword + hide-empty filtering — FREE (mirrors Essential Addons'
     * free Business Reviews widget). Moved here from the Pro plugin so free
     * installs get the same behaviour. Pure array transforms, no network.
     *
     * @param array $reviews Normalized review arrays.
     * @param array $args    Render args (sort / keyword / hide_empty).
     * @return array
     */
    /**
     * Build AggregateRating + Review JSON-LD — FREE (mirrors Essential Addons'
     * free Local Business schema). Returns $html unchanged when the schema
     * toggle is off or there are no reviews. Ported from the Pro plugin.
     *
     * @param string $html    Existing markup to append to.
     * @param array  $reviews Normalized reviews.
     * @param array  $meta    Place meta (name/rating/total).
     * @param array  $args    Render args (schema toggle).
     * @return string
     */
    private static function build_schema_jsonld(string $html, array $reviews, array $meta, array $args): string
    {
        if (empty($args['schema']) || empty($reviews)) {
            return $html;
        }

        $name   = $meta['name'] ?? ($args['place_name'] ?? '');
        $rating = isset($meta['rating']) ? (float) $meta['rating'] : 0.0;
        $total  = isset($meta['total']) ? (int) $meta['total'] : count($reviews);

        $review_nodes = [];
        foreach ($reviews as $r) {
            $body = trim((string) ($r['text'] ?? ''));
            $node = [
                '@type'        => 'Review',
                'author'       => [
                    '@type' => 'Person',
                    'name'  => (string) ($r['author_name'] ?? __('Anonymous', 'embedpress')),
                ],
                'reviewRating' => [
                    '@type'       => 'Rating',
                    'ratingValue' => (string) (int) ($r['rating'] ?? 0),
                    'bestRating'  => '5',
                    'worstRating' => '1',
                ],
            ];
            if ($body !== '') {
                $node['reviewBody'] = $body;
            }
            if (!empty($r['time'])) {
                $node['datePublished'] = gmdate('Y-m-d', (int) $r['time']);
            }
            $review_nodes[] = $node;
        }

        $data = [
            '@context' => 'https://schema.org',
            '@type'    => 'LocalBusiness',
            'name'     => $name !== '' ? $name : __('Business', 'embedpress'),
        ];
        if ($rating > 0 && $total > 0) {
            $data['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (string) $rating,
                'reviewCount' => (string) $total,
                'bestRating'  => '5',
                'worstRating' => '1',
            ];
        }
        if (!empty($review_nodes)) {
            $data['review'] = $review_nodes;
        }

        $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!$json) {
            return $html;
        }

        return $html . '<script type="application/ld+json" class="ep-gr-schema">' . $json . '</script>';
    }

    private static function apply_review_filters(array $reviews, array $args): array
    {
        // Hide reviews with no text.
        if (!empty($args['hide_empty'])) {
            $reviews = array_values(array_filter($reviews, function ($r) {
                return trim((string) ($r['text'] ?? '')) !== '';
            }));
        }

        // Keyword filter (case-insensitive substring on review text).
        $keyword = isset($args['keyword']) ? trim((string) $args['keyword']) : '';
        if ($keyword !== '') {
            $needle  = function_exists('mb_strtolower') ? mb_strtolower($keyword) : strtolower($keyword);
            $reviews = array_values(array_filter($reviews, function ($r) use ($needle) {
                $hay = (string) ($r['text'] ?? '');
                $hay = function_exists('mb_strtolower') ? mb_strtolower($hay) : strtolower($hay);
                return $hay !== '' && strpos($hay, $needle) !== false;
            }));
        }

        // Sort order.
        $sort = isset($args['sort']) ? (string) $args['sort'] : 'newest';
        switch ($sort) {
            case 'highest':
                usort($reviews, function ($a, $b) {
                    return (int) ($b['rating'] ?? 0) <=> (int) ($a['rating'] ?? 0);
                });
                break;
            case 'lowest':
                usort($reviews, function ($a, $b) {
                    return (int) ($a['rating'] ?? 0) <=> (int) ($b['rating'] ?? 0);
                });
                break;
            case 'newest':
                usort($reviews, function ($a, $b) {
                    return (int) ($b['time'] ?? 0) <=> (int) ($a['time'] ?? 0);
                });
                break;
            case 'relevant':
            default:
                // Leave Google's native ordering.
                break;
        }

        return $reviews;
    }

    private static function render_empty(): string
    {
        return '<div class="ep-google-reviews ep-google-reviews--empty">'
            . esc_html__('No reviews to display yet.', 'embedpress')
            . '</div>';
    }

    /**
     * Reviews for a freshly-added place are still being fetched in the
     * background (status running/queued). Render a friendly "loading"
     * placeholder — a skeleton + spinner + a message making clear it can take a
     * little while — carrying `data-ep-gr-poll` with the place_id so the
     * frontend poller (static/js/google-reviews-status.js) can poll the public
     * status endpoint and swap in the reviews the moment the job finishes, with
     * NO manual reload. The poller reloads the wrapper region when done.
     *
     * @param string $place_id The place whose fetch we're waiting on.
     */
    private static function render_loading(string $place_id): string
    {
        // Three shimmer skeleton cards approximate the incoming review list so
        // the block reserves space + reads as "working", not "empty/broken".
        $skeleton_card =
            '<div class="ep-gr-skel-card" aria-hidden="true">'
            . '<div class="ep-gr-skel-row">'
            . '<span class="ep-gr-skel ep-gr-skel-avatar"></span>'
            . '<span class="ep-gr-skel-lines"><span class="ep-gr-skel ep-gr-skel-line ep-gr-skel-line--sm"></span>'
            . '<span class="ep-gr-skel ep-gr-skel-line ep-gr-skel-line--xs"></span></span>'
            . '</div>'
            . '<span class="ep-gr-skel ep-gr-skel-line"></span>'
            . '<span class="ep-gr-skel ep-gr-skel-line"></span>'
            . '<span class="ep-gr-skel ep-gr-skel-line ep-gr-skel-line--md"></span>'
            . '</div>';

        $poll_url = esc_url_raw(rest_url(GoogleReviewsRestController::NS . '/google-reviews/public-status'));

        return '<div class="ep-google-reviews ep-google-reviews--loading" '
            . 'data-ep-gr-poll="' . esc_attr($place_id) . '" '
            . 'data-ep-gr-poll-url="' . esc_url($poll_url) . '" '
            . 'data-ep-gr-poll-interval="5000" role="status" aria-live="polite">'
            . '<div class="ep-gr-loading-head">'
            . '<span class="ep-gr-spinner" aria-hidden="true"></span>'
            . '<div class="ep-gr-loading-text">'
            . '<strong>' . esc_html__('Loading Google reviews…', 'embedpress') . '</strong>'
            . '<span>' . esc_html__('Fetching the latest reviews for this place. This can take a little while the first time — they’ll appear here automatically when ready, no need to refresh.', 'embedpress') . '</span>'
            . '</div>'
            . '</div>'
            . '<div class="ep-gr-skeletons">' . $skeleton_card . $skeleton_card . $skeleton_card . '</div>'
            . '</div>';
    }

    /**
     * The background fetch for this place failed (e.g. the scraper was gated, or
     * a network error). Visitors see NOTHING (an empty string — the block just
     * doesn't render, so the public page never shows a broken state). Logged-in
     * editors see an actionable notice pointing at the settings page where they
     * can Refetch.
     *
     * @param string $place_id The place whose fetch failed.
     */
    private static function render_fetch_failed(string $place_id): string
    {
        if (!current_user_can('edit_posts')) {
            return ''; // visitors: render nothing rather than an error
        }
        $settings_url = admin_url('admin.php?page=embedpress-google-reviews');
        return '<div class="ep-google-reviews ep-google-reviews--error ep-google-reviews--fetch-failed">'
            . '<p><strong>' . esc_html__('Couldn’t load this place’s reviews.', 'embedpress') . '</strong></p>'
            . '<p>' . esc_html__('The last attempt to fetch reviews for this place didn’t finish. Open EmbedPress → Google Reviews and use “Refetch” to try again.', 'embedpress') . '</p>'
            . '<p><a href="' . esc_url($settings_url) . '">' . esc_html__('Open Google Reviews settings →', 'embedpress') . '</a></p>'
            . '<p class="ep-gr-admin-only-note"><em>' . esc_html__('Only you (an editor) can see this message — visitors see nothing.', 'embedpress') . '</em></p>'
            . '</div>';
    }

    /**
     * A Pro layout was selected without Pro active. Editors see an actionable
     * upsell (so they understand why their chosen layout isn't rendering); public
     * visitors see nothing (the caller falls back to a free layout for them, so
     * the front-end never looks broken to real users).
     *
     * @return string Upsell markup for editors, '' for visitors (→ free fallback).
     */
    private static function render_pro_layout_upsell(string $layout): string
    {
        if (!current_user_can('edit_posts')) {
            return ''; // visitor → caller renders the free fallback layout
        }
        $labels = [
            'masonry'   => __('Masonry', 'embedpress'),
            'badge'     => __('Compact badge', 'embedpress'),
            'spotlight' => __('Spotlight', 'embedpress'),
            'knowledge' => __('Knowledge panel', 'embedpress'),
            'marquee'   => __('Marquee', 'embedpress'),
            'bubble'    => __('Bubble', 'embedpress'),
        ];
        $name = $labels[$layout] ?? ucfirst($layout);
        $url  = 'https://wpdeveloper.com/in/upgrade-embedpress';

        // Reuse EmbedPress's canonical Pro-card visual language (pro__alert__card)
        // so this matches the upgrade UI used across the plugin. Rendered inline
        // (not as the hidden modal overlay) since it stands in for content.
        $icon = '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">'
            . '<path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke="#5b4e96" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        return '<div class="ep-google-reviews ep-gr-pro-upsell">'
            . '<div class="pro__alert__card ep-gr-pro-upsell__card">'
            . '<div class="ep-gr-pro-upsell__icon">' . $icon . '</div>'
            . '<h2>' . esc_html(sprintf(/* translators: %s: layout name */ __('“%s” is a Pro layout', 'embedpress'), $name)) . '</h2>'
            . '<p>' . esc_html__('Upgrade to EmbedPress Pro to use this layout, or pick a free one (List, Grid, Card, Carousel).', 'embedpress') . '</p>'
            . '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" class="pro__alert__btn ep-gr-pro-upsell__btn">'
            . esc_html__('Upgrade to Pro', 'embedpress') . '</a>'
            . '<p class="ep-gr-pro-upsell__note">' . esc_html__('Visitors see the List layout until you upgrade or switch.', 'embedpress') . '</p>'
            . '</div></div>';
    }

    private static function render_error(string $message): string
    {
        if (!current_user_can('edit_posts')) {
            return '';
        }
        return '<div class="ep-google-reviews ep-google-reviews--error">'
            . esc_html(sprintf(/* translators: %s: error message from Google API */ __('Google Reviews error: %s', 'embedpress'), $message))
            . '</div>';
    }

    /**
     * Friendly "no place chosen yet" placeholder for a freshly added block /
     * widget. This is the normal starting state, not an error — so it's a calm
     * prompt that tells the editor what to do next, not a red error box.
     *
     * Editor-only: visitors must never see setup instructions, so on the front
     * end (no edit_posts cap) we render nothing at all.
     *
     * @return string
     */
    private static function render_pick_place_prompt(): string
    {
        if (!current_user_can('edit_posts')) {
            return '';
        }
        return '<div class="ep-google-reviews ep-google-reviews--placeholder">'
            . '<span class="ep-gr-placeholder-icon" aria-hidden="true">'
            . '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">'
            . '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" stroke="currentColor" stroke-width="1.6"/>'
            . '<circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="1.6"/></svg>'
            . '</span>'
            . '<strong>' . esc_html__('Choose a Google Business to show its reviews', 'embedpress') . '</strong>'
            . '<span>' . esc_html__('Search for a place in the panel, or paste a Place ID, to display its reviews here.', 'embedpress') . '</span>'
            . '</div>';
    }

    /**
     * Fetch reviews for a place_id from the Google Places API, with a
     * transient cache to stay under quota. Returns up to 5 reviews
     * (Places API hard cap) or a WP_Error if the request fails.
     *
     * Routes through self::dispatch() so we transparently support both the
     * legacy Places API and Places API (New). The dispatcher probes once,
     * persists the working mode, and falls back if the stored mode goes
     * stale (e.g. legacy gets disabled mid-project).
     *
     * @return array|\WP_Error
     */
    public static function fetch_reviews(string $place_id, array $args = [])
    {
        // Back-compat alias. Pro's multi-place merge calls this to get a single
        // place's ≤5 reviews; it's now a direct (uncached) API fetch — the DB
        // store is the cache layer, so no transient here.
        return self::fetch_from_api($place_id);
    }

    /**
     * Raw fetch of a single place's reviews from the Google Places API (≤5,
     * the API hard cap). No caching — callers (the DB store / fetch_into_store)
     * own persistence. Routes through self::dispatch() for legacy/new API
     * auto-detection.
     *
     * @return array|\WP_Error {reviews, meta}
     */
    public static function fetch_from_api(string $place_id)
    {
        if ($place_id === '') {
            return new \WP_Error('embedpress_gr_missing_place', __('No place selected.', 'embedpress'));
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $place_id)) {
            return new \WP_Error('embedpress_gr_invalid_place', __('Invalid place identifier.', 'embedpress'));
        }

        $api_key = self::get_api_key();
        if ($api_key === '') {
            // No Google key. If an Apify token is set the caller should have
            // routed through Apify already (see maybe_prefer_apify_fetch); reaching
            // here means no provider is usable, so give a provider-neutral message.
            $message = self::get_apify_token() !== ''
                ? __('Could not load reviews. Open EmbedPress → Google Reviews and use “Refetch” for this place.', 'embedpress')
                : __('Not connected to the EmbedPress API. Open EmbedPress → Google Reviews and click “Connect to EmbedPress API” to start fetching reviews.', 'embedpress');
            return new \WP_Error('embedpress_gr_missing_key', $message);
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('embedpress-gr: API call for place_id=' . $place_id);
        }

        $result = self::dispatch('details', $api_key, ['place_id' => $place_id]);
        if (is_wp_error($result)) {
            return $result;
        }

        return self::normalize_result($result);
    }

    /**
     * Accept both the legacy flat-array review shape (older caches) and the
     * current `{reviews, meta}` shape, and always return the latter. Keeps
     * pre-existing transients valid after the meta/header change.
     */
    private static function normalize_result($data): array
    {
        if (isset($data['reviews']) && is_array($data['reviews'])) {
            return [
                'reviews' => array_values($data['reviews']),
                'meta'    => isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : [],
            ];
        }
        // Legacy flat list of reviews (no meta available).
        return ['reviews' => array_values((array) $data), 'meta' => []];
    }

    /**
     * Run a Places autocomplete query, normalized across legacy + New API.
     * Returns a list of `{place_id, description, main_text, secondary_text}`
     * arrays or a WP_Error. Caller is responsible for caching.
     *
     * @return array|\WP_Error
     */
    public static function autocomplete(string $q)
    {
        $api_key = self::get_api_key();
        if ($api_key === '') {
            // Reached the Google path with no key. If Apify is connected the
            // search controller already tried Apify; a genuine timeout is handled
            // earlier (returned directly), so reaching here means Apify simply
            // found no match for this query — tell the user that, not to add a
            // Google key.
            $message = self::get_apify_token() !== ''
                ? __('No places matched that search. Try a more specific name (including the city), or paste the Place ID directly using “Have a Place ID? Enter manually”.', 'embedpress')
                : __('Not connected to the EmbedPress API. Open EmbedPress → Google Reviews and click “Connect to EmbedPress API” to start fetching reviews.', 'embedpress');
            return new \WP_Error('embedpress_gr_no_key', $message, ['status' => 400]);
        }
        return self::dispatch('autocomplete', $api_key, ['q' => $q]);
    }

    /**
     * Verify a Google Places key works WITHOUT saving it — used by the settings
     * "Connect" flow. Runs a tiny autocomplete probe with the supplied key.
     * Returns true on success or a WP_Error with an actionable message.
     *
     * @return true|\WP_Error
     */
    public static function verify_google_key(string $api_key)
    {
        $api_key = trim($api_key);
        if ($api_key === '') {
            return new \WP_Error('embedpress_gr_no_key', __('No key provided.', 'embedpress'));
        }
        // Probe both API variants with the given key (not the stored mode).
        $res = self::call_new_autocomplete($api_key, 'coffee');
        if (!is_wp_error($res)) {
            return true;
        }
        if (self::is_api_not_enabled_error($res)) {
            $legacy = self::call_legacy_autocomplete($api_key, 'coffee');
            if (!is_wp_error($legacy)) {
                return true;
            }
            return $legacy;
        }
        return $res;
    }

    /**
     * Dispatch a Places API call against whichever variant is enabled for
     * the user's key. Tries the stored mode first; on a permission-class
     * failure, transparently tries the other and updates the stored mode
     * so future calls go direct.
     *
     * $op is 'autocomplete' or 'details'.
     *
     * @return array|\WP_Error
     */
    private static function dispatch(string $op, string $api_key, array $params)
    {
        $mode = self::get_api_mode();

        $try = function (string $variant) use ($op, $api_key, $params) {
            if ($variant === 'new') {
                return $op === 'autocomplete'
                    ? self::call_new_autocomplete($api_key, (string) ($params['q'] ?? ''))
                    : self::call_new_details($api_key, (string) ($params['place_id'] ?? ''));
            }
            return $op === 'autocomplete'
                ? self::call_legacy_autocomplete($api_key, (string) ($params['q'] ?? ''))
                : self::call_legacy_details($api_key, (string) ($params['place_id'] ?? ''));
        };

        // Order: try stored mode first; if 'auto', try New first (it's the
        // only variant available to GCP projects created after 2025-03-01).
        $first  = in_array($mode, ['new', 'legacy'], true) ? $mode : 'new';
        $second = $first === 'new' ? 'legacy' : 'new';

        $result = $try($first);
        if (!is_wp_error($result)) {
            self::set_api_mode($first);
            return $result;
        }

        if (!self::is_api_not_enabled_error($result)) {
            // A real error (bad query, network, etc.) — don't waste a second call.
            return $result;
        }

        $alt = $try($second);
        if (!is_wp_error($alt)) {
            self::set_api_mode($second);
            return $alt;
        }

        // Both failed. Surface whichever message is more informative.
        return self::is_api_not_enabled_error($alt) ? $result : $alt;
    }

    /**
     * Heuristic: does this WP_Error look like "the API variant isn't enabled
     * for this project" (vs. a key being invalid, quota exceeded, etc.)?
     */
    private static function is_api_not_enabled_error(\WP_Error $err): bool
    {
        $code = strtolower((string) $err->get_error_code());
        if (
            str_contains($code, 'request_denied')
            || str_contains($code, 'permission_denied')
            || str_contains($code, 'failed_precondition')
            || str_contains($code, 'http_403')
        ) {
            return true;
        }
        $msg = strtolower((string) $err->get_error_message());
        return str_contains($msg, 'legacy api') || str_contains($msg, 'has not been used in project') || str_contains($msg, 'is not enabled');
    }

    /**
     * Legacy Places API: autocomplete. Returns normalized predictions.
     *
     * @return array|\WP_Error
     */
    private static function call_legacy_autocomplete(string $api_key, string $q)
    {
        $url = add_query_arg([
            'input' => $q,
            'types' => 'establishment',
            'key'   => $api_key,
        ], self::ENDPOINT_LEGACY_AUTOCOMPLETE);

        $response = wp_remote_get($url, ['timeout' => 6]);
        if (is_wp_error($response)) {
            return new \WP_Error('embedpress_gr_http', $response->get_error_message(), ['status' => 502]);
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $err  = self::legacy_status_error($body);
        if ($err) return $err;

        $predictions = [];
        foreach (($body['predictions'] ?? []) as $p) {
            $predictions[] = [
                'place_id'       => isset($p['place_id']) ? (string) $p['place_id'] : '',
                'description'    => isset($p['description']) ? (string) $p['description'] : '',
                'main_text'      => isset($p['structured_formatting']['main_text']) ? (string) $p['structured_formatting']['main_text'] : '',
                'secondary_text' => isset($p['structured_formatting']['secondary_text']) ? (string) $p['structured_formatting']['secondary_text'] : '',
            ];
        }
        return $predictions;
    }

    /**
     * Legacy Places API: place details (reviews only). Returns normalized
     * review list.
     *
     * @return array|\WP_Error
     */
    private static function call_legacy_details(string $api_key, string $place_id)
    {
        $url = add_query_arg([
            'place_id'     => $place_id,
            // Pull place meta (name + overall rating + total + address) alongside
            // the reviews so the summary header + Saved-places list need no extra
            // API call.
            'fields'       => 'name,rating,user_ratings_total,formatted_address,reviews',
            'reviews_sort' => 'newest',
            'key'          => $api_key,
        ], self::ENDPOINT_LEGACY_DETAILS);

        $response = wp_remote_get($url, ['timeout' => 8]);
        if (is_wp_error($response)) {
            return new \WP_Error('embedpress_gr_http', $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new \WP_Error('embedpress_gr_http_' . $code, sprintf(/* translators: %d: HTTP status code */ __('Google Places returned HTTP %d.', 'embedpress'), $code));
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $err  = self::legacy_status_error($body);
        if ($err) return $err;

        $result = $body['result'] ?? [];
        $reviews = [];
        foreach (($result['reviews'] ?? []) as $r) {
            $reviews[] = [
                'author_name'       => isset($r['author_name']) ? (string) $r['author_name'] : '',
                'rating'            => isset($r['rating']) ? (int) $r['rating'] : 0,
                'text'              => isset($r['text']) ? (string) $r['text'] : '',
                'time'              => isset($r['time']) ? (int) $r['time'] : 0,
                // Google's own "a week ago" phrasing — preferred over an
                // absolute date for review recency.
                'relative_time'     => isset($r['relative_time_description']) ? (string) $r['relative_time_description'] : '',
                'profile_photo_url' => isset($r['profile_photo_url']) ? esc_url_raw($r['profile_photo_url']) : '',
            ];
        }
        return [
            'reviews' => $reviews,
            'meta'    => [
                'name'    => isset($result['name']) ? (string) $result['name'] : '',
                'rating'  => isset($result['rating']) ? (float) $result['rating'] : 0.0,
                'total'   => isset($result['user_ratings_total']) ? (int) $result['user_ratings_total'] : 0,
                // Location address for the summary header + Saved-places list.
                'address' => isset($result['formatted_address']) ? (string) $result['formatted_address'] : '',
            ],
        ];
    }

    /**
     * Convert a legacy Places response body into a WP_Error if its `status`
     * indicates failure. Returns null on success.
     */
    private static function legacy_status_error($body): ?\WP_Error
    {
        if (!is_array($body)) {
            return new \WP_Error('embedpress_gr_bad_response', __('Invalid response from Google Places.', 'embedpress'));
        }
        $status = $body['status'] ?? 'UNKNOWN_ERROR';
        if ($status === 'OK' || $status === 'ZERO_RESULTS') return null;
        $error_message = isset($body['error_message']) ? (string) $body['error_message'] : '';
        $msg = $error_message !== ''
            ? sprintf(/* translators: 1: Google API error status, 2: Google API error message */ __('Google Places error: %1$s — %2$s', 'embedpress'), $status, $error_message)
            : sprintf(/* translators: %s: Google API error status */ __('Google Places error: %s', 'embedpress'), $status);
        $msg .= self::friendly_api_hint($status, $error_message);
        return new \WP_Error('embedpress_gr_api_' . strtolower($status), $msg, ['status' => 502]);
    }

    /**
     * Translate Google's terse/cryptic API statuses into an actionable hint
     * for the site admin. Google often returns a bare "The caller does not
     * have permission" with no remediation; this appends the concrete fix
     * (which is almost always a Cloud Console setting, not a plugin bug).
     *
     * Returns an empty string for non-error / unknown statuses so the base
     * message is unchanged.
     */
    private static function friendly_api_hint(string $status, string $message = ''): string
    {
        $status  = strtoupper($status);
        $message = strtolower($message);

        // Billing not enabled — Places API (New) requires an active billing account.
        if (strpos($message, 'billing') !== false) {
            return ' ' . __('Enable billing for your project in the Google Cloud Console — the Places API requires an active billing account.', 'embedpress');
        }

        // API not enabled on the project, or the legacy API is deprecated.
        if (
            strpos($message, 'has not been used') !== false
            || strpos($message, 'is not enabled') !== false
            || strpos($message, 'not activated') !== false
            || strpos($message, 'legacy api') !== false
            || $status === 'SERVICE_DISABLED'
        ) {
            return ' ' . __('Enable "Places API (New)" for your project in the Google Cloud Console (APIs & Services → Library), then wait a few minutes for it to take effect.', 'embedpress');
        }

        // Permission denied with no detail — almost always API-not-enabled or a
        // key restriction blocking the Places API.
        if ($status === 'PERMISSION_DENIED' || $status === 'REQUEST_DENIED') {
            return ' ' . __('Check that "Places API (New)" is enabled for your project and that your API key is not restricted from calling it (APIs & Services → Credentials → your key → API restrictions). Server-side calls also require the key to allow application restriction "None" or your server IP.', 'embedpress');
        }

        // Quota / rate limit.
        if ($status === 'RESOURCE_EXHAUSTED' || $status === 'OVER_QUERY_LIMIT') {
            return ' ' . __('Your Google Places API quota has been exceeded. Check your usage and quotas in the Google Cloud Console.', 'embedpress');
        }

        // Bad / unauthorized key.
        if ($status === 'UNAUTHENTICATED' || $status === 'INVALID_ARGUMENT' || strpos($message, 'api key not valid') !== false) {
            return ' ' . __('Your Google Places API key appears to be invalid. Re-copy it from the Google Cloud Console (APIs & Services → Credentials).', 'embedpress');
        }

        return '';
    }

    /**
     * Places API (New): autocomplete. Returns normalized predictions.
     *
     * @return array|\WP_Error
     */
    private static function call_new_autocomplete(string $api_key, string $q)
    {
        $response = wp_remote_post(self::ENDPOINT_NEW_AUTOCOMPLETE, [
            'timeout' => 6,
            'headers' => [
                'Content-Type'    => 'application/json',
                'X-Goog-Api-Key'  => $api_key,
            ],
            'body' => wp_json_encode([
                'input'                 => $q,
                'includedPrimaryTypes'  => ['establishment'],
            ]),
        ]);
        if (is_wp_error($response)) {
            return new \WP_Error('embedpress_gr_http', $response->get_error_message(), ['status' => 502]);
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = (int) wp_remote_retrieve_response_code($response);
        $err  = self::new_api_error($body, $code);
        if ($err) return $err;

        $predictions = [];
        foreach (($body['suggestions'] ?? []) as $s) {
            $p = $s['placePrediction'] ?? null;
            if (!$p) continue;
            $predictions[] = [
                'place_id'       => isset($p['placeId']) ? (string) $p['placeId'] : '',
                'description'    => isset($p['text']['text']) ? (string) $p['text']['text'] : '',
                'main_text'      => isset($p['structuredFormat']['mainText']['text']) ? (string) $p['structuredFormat']['mainText']['text'] : '',
                'secondary_text' => isset($p['structuredFormat']['secondaryText']['text']) ? (string) $p['structuredFormat']['secondaryText']['text'] : '',
            ];
        }
        return $predictions;
    }

    /**
     * Places API (New): place details (reviews). Returns normalized review list.
     *
     * @return array|\WP_Error
     */
    private static function call_new_details(string $api_key, string $place_id)
    {
        // The New API uses place resource names (`places/PLACE_ID`); a bare
        // place_id is also accepted at this endpoint.
        $response = wp_remote_get(self::ENDPOINT_NEW_DETAILS . rawurlencode($place_id), [
            'timeout' => 8,
            'headers' => [
                'X-Goog-Api-Key'    => $api_key,
                // Place meta (name + overall rating + total) alongside reviews.
                'X-Goog-FieldMask'  => 'displayName,rating,userRatingCount,formattedAddress,reviews',
            ],
        ]);
        if (is_wp_error($response)) {
            return new \WP_Error('embedpress_gr_http', $response->get_error_message());
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = (int) wp_remote_retrieve_response_code($response);
        $err  = self::new_api_error($body, $code);
        if ($err) return $err;

        $reviews = [];
        foreach (($body['reviews'] ?? []) as $r) {
            $time = 0;
            if (!empty($r['publishTime'])) {
                $t    = strtotime((string) $r['publishTime']);
                $time = $t ? $t : 0;
            }
            $reviews[] = [
                'author_name'       => isset($r['authorAttribution']['displayName']) ? (string) $r['authorAttribution']['displayName'] : '',
                'rating'            => isset($r['rating']) ? (int) $r['rating'] : 0,
                'text'              => isset($r['text']['text']) ? (string) $r['text']['text'] : (isset($r['originalText']['text']) ? (string) $r['originalText']['text'] : ''),
                'time'              => $time,
                // Google's own "a week ago" phrasing — preferred over an
                // absolute date for review recency.
                'relative_time'     => isset($r['relativePublishTimeDescription']) ? (string) $r['relativePublishTimeDescription'] : '',
                'profile_photo_url' => isset($r['authorAttribution']['photoUri']) ? esc_url_raw((string) $r['authorAttribution']['photoUri']) : '',
            ];
        }
        return [
            'reviews' => $reviews,
            'meta'    => [
                'name'    => isset($body['displayName']['text']) ? (string) $body['displayName']['text'] : '',
                'rating'  => isset($body['rating']) ? (float) $body['rating'] : 0.0,
                'total'   => isset($body['userRatingCount']) ? (int) $body['userRatingCount'] : 0,
                // Location address for the summary header + Saved-places list.
                'address' => isset($body['formattedAddress']) ? (string) $body['formattedAddress'] : '',
            ],
        ];
    }

    /**
     * Convert a Places API (New) response into a WP_Error if it indicates
     * failure. The new API uses Google's standard error envelope
     * `{ error: { code, message, status } }` plus a non-200 HTTP status.
     */
    private static function new_api_error($body, int $http_code): ?\WP_Error
    {
        if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
            $status  = (string) ($body['error']['status'] ?? 'UNKNOWN_ERROR');
            $message = (string) ($body['error']['message'] ?? '');
            $msg = $message !== ''
                ? sprintf(/* translators: 1: Google API error status, 2: Google API error message */ __('Google Places error: %1$s — %2$s', 'embedpress'), $status, $message)
                : sprintf(/* translators: %s: Google API error status */ __('Google Places error: %s', 'embedpress'), $status);
            $msg .= self::friendly_api_hint($status, $message);
            return new \WP_Error('embedpress_gr_api_' . strtolower($status), $msg, ['status' => 502]);
        }
        if ($http_code !== 200) {
            return new \WP_Error('embedpress_gr_http_' . $http_code, sprintf(/* translators: %d: HTTP status code */ __('Google Places returned HTTP %d.', 'embedpress'), $http_code));
        }
        if (!is_array($body)) {
            return new \WP_Error('embedpress_gr_bad_response', __('Invalid response from Google Places.', 'embedpress'));
        }
        return null;
    }

    /**
     * Cache an error briefly so we don't hammer the API while the user fixes
     * the underlying problem (bad key, quota exceeded, etc.).
     */
    private static function cache_error(string $cache_key, string $message)
    {
        set_transient($cache_key . '_err', $message, 5 * MINUTE_IN_SECONDS);
    }

    public static function get_api_key(): string
    {
        if (defined('EMBEDPRESS_GOOGLE_REVIEWS_API_KEY') && EMBEDPRESS_GOOGLE_REVIEWS_API_KEY) {
            return (string) EMBEDPRESS_GOOGLE_REVIEWS_API_KEY;
        }
        $key = get_option(self::OPT_API_KEY, '');
        return is_string($key) ? trim($key) : '';
    }

    /**
     * Apify API token for the Pro "fetch all reviews" provider. Prefers a
     * wp-config constant (most secure), then the saved option.
     */
    public static function get_apify_token(): string
    {
        if (defined('EMBEDPRESS_APIFY_TOKEN') && EMBEDPRESS_APIFY_TOKEN) {
            return (string) EMBEDPRESS_APIFY_TOKEN;
        }
        $token = get_option(self::OPT_APIFY_TOKEN, '');
        return is_string($token) ? trim($token) : '';
    }

    /**
     * Which provider powers place SEARCH. 'auto' (default) prefers Google when a
     * key is set, else Apify; 'google'/'apify' force a provider. Resolves 'auto'
     * to the concrete provider that's actually usable given configured creds.
     */
    public static function get_search_provider(): string
    {
        $pref = (string) get_option(self::OPT_SEARCH_PROVIDER, 'auto');
        $pref = in_array($pref, ['auto', 'google', 'apify'], true) ? $pref : 'auto';

        $has_google = self::get_api_key() !== '';
        $has_apify  = self::get_apify_token() !== '';

        if ($pref === 'google') {
            return $has_google ? 'google' : ($has_apify ? 'apify' : 'managed');
        }
        if ($pref === 'apify') {
            return $has_apify ? 'apify' : ($has_google ? 'google' : 'managed');
        }
        // auto: user's own Google key first, then the hosted EmbedPress proxy
        // (session-billed Google Places API — fast, ~1.4s, zero setup).
        //
        // Apify is LAST on purpose: its place-search actor is a ~20s LIVE
        // SCRAPE, far slower than the Google Places Autocomplete API. Having
        // an Apify token (set up for the Pro "fetch all reviews" feature)
        // must NOT drag the picker onto that slow path. The managed proxy
        // gives instant session-based autocomplete; Apify search stays only
        // as a final fallback if the proxy is unreachable.
        if ($has_google) return 'google';
        return 'managed';
    }

    /**
     * Hosted-proxy place SEARCH via api.embedpress.com/google-places.php.
     * Zero user setup: EmbedPress's server holds the Google Places key + does
     * the Autocomplete call. The proxy caches + rate-limits per IP.
     *
     * Why this exists: most users don't want to set up a Google Cloud project
     * just to search for a place. Hosted search gives the Trustindex-style
     * "type and pick" UX out of the box; user keys remain optional for
     * higher quotas or full control.
     *
     * @return array|\WP_Error list of {place_id, main_text, secondary_text, description}
     */
    public static function managed_search(string $q, string $session_token = '')
    {
        $endpoint = (string) apply_filters(
            'embedpress/google_reviews/managed_search_endpoint',
            'https://api.embedpress.com/google-places.php'
        );
        $params = [
            'action' => 'autocomplete',
            'q'      => $q,
        ];
        // Passing the same token across N autocompletes + the final details
        // call lets Google bill the whole sequence as ONE session — major
        // savings on the proxy's free Places API tier.
        if ($session_token !== '') {
            $params['session_token'] = $session_token;
        }
        $url = add_query_arg($params, $endpoint);

        $response = wp_remote_get($url, [
            'timeout' => (int) apply_filters('embedpress/google_reviews/managed_search_timeout', 8, $q),
            'headers' => [
                'Accept'             => 'application/json',
                'X-EmbedPress-Site'  => home_url(),
            ],
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('embedpress_gr_managed_search', $response->get_error_message(), ['status' => 502]);
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code === 429) {
            return new \WP_Error(
                'embedpress_gr_managed_rate_limited',
                __('Search is busy right now — please try again in a moment.', 'embedpress'),
                ['status' => 429]
            );
        }
        if ($code < 200 || $code >= 300 || !is_array($body)) {
            $msg = is_array($body) && !empty($body['message']) ? $body['message'] : __('Search service unavailable.', 'embedpress');
            return new \WP_Error('embedpress_gr_managed_search', $msg, ['status' => 502]);
        }

        $predictions = [];
        foreach (($body['predictions'] ?? []) as $p) {
            if (empty($p['place_id'])) continue;
            $predictions[] = [
                'place_id'       => (string) $p['place_id'],
                'main_text'      => (string) ($p['main_text'] ?? ''),
                'secondary_text' => (string) ($p['secondary_text'] ?? ''),
                'description'    => (string) ($p['description'] ?? ''),
                // Surface rating + review count so the picker can show how many
                // reviews each result has — disambiguates same-named places.
                'rating'         => isset($p['rating']) ? (float) $p['rating'] : null,
                'review_count'   => isset($p['review_count']) ? (int) $p['review_count'] : null,
            ];
        }
        return $predictions;
    }

    /**
     * Apify-backed place SEARCH (the block's place picker — no Google key
     * needed). This is FREE: searching/picking a place is how you configure the
     * block at all, so it must not depend on Pro being active. (Pro owns only the
     * heavy "fetch all reviews" bulk scrape, not search.)
     *
     * Calls the crawler-google-places actor synchronously and returns predictions
     * in EmbedPress's picker shape, or a WP_Error the REST layer can surface.
     *
     * @return array|\WP_Error list of {place_id, main_text, secondary_text, description}
     */
    public static function apify_search(string $q)
    {
        $token = self::get_apify_token();
        $q     = trim($q);
        if ($token === '') {
            return new \WP_Error('embedpress_gr_no_apify_token', __('Place search isn’t available right now. Paste the Place ID directly using “Have a Place ID? Enter manually”.', 'embedpress'), ['status' => 400]);
        }
        if ($q === '') {
            return [];
        }

        // Pin a small memory footprint. The crawler-google-places actor DEFAULTS
        // to 4096MB, which on Apify's free 8192MB plan gets rejected ("you will
        // exceed the memory limit") whenever another run is using/releasing
        // memory. A 5-result place search needs nowhere near 4GB — 1024MB runs
        // fine. Filterable for users on larger plans who want faster runs.
        $memory   = (int) apply_filters('embedpress/google_reviews/apify_search_memory', 1024, $q);
        $endpoint = 'https://api.apify.com/v2/acts/compass~crawler-google-places/run-sync-get-dataset-items?token=' . rawurlencode($token);
        if ($memory > 0) {
            $endpoint .= '&memory=' . $memory;
        }
        $payload  = [
            'searchStringsArray'        => [$q],
            'maxCrawledPlacesPerSearch' => (int) apply_filters('embedpress/google_reviews/apify_search_max', 5, $q),
            'language'                  => 'en',
            'skipClosedPlaces'          => false,
        ];

        // The crawler-google-places actor is a live scrape: a warm run answers in
        // ~20s, a cold start can take longer. Give it a generous budget and, on a
        // connection timeout, retry once — the actor is usually warm by the second
        // attempt and answers fast.
        $timeout = (int) apply_filters('embedpress/google_reviews/apify_search_timeout', 90, $q);
        $args    = [
            'timeout' => $timeout,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
        ];
        $response = wp_remote_post($endpoint, $args);
        if (is_wp_error($response) && self::is_timeout_error($response)) {
            $response = wp_remote_post($endpoint, $args);
        }
        if (is_wp_error($response)) {
            if (self::is_timeout_error($response)) {
                return new \WP_Error(
                    'embedpress_gr_apify_timeout',
                    __('Place search took too long to respond. Try searching again, or paste the Place ID directly using “Have a Place ID? Enter manually”.', 'embedpress'),
                    ['status' => 504]
                );
            }
            return new \WP_Error('embedpress_gr_apify_search', $response->get_error_message(), ['status' => 502]);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200 && $code !== 201) {
            // Surface the actual Apify reason (e.g. 402 out-of-credit, 401 bad
            // token) so the picker shows something actionable.
            $body   = json_decode(wp_remote_retrieve_body($response), true);
            // Keep the user-facing message provider-neutral — don't surface raw
            // upstream (Apify) text or billing links to the end user. The
            // technical detail stays in the error code for debugging.
            $type   = is_array($body) && isset($body['error']['type']) ? (string) $body['error']['type'] : '';
            $reason = __('Place search is temporarily unavailable. Try again, or paste the Place ID directly using “Have a Place ID? Enter manually”.', 'embedpress');
            if ($type === 'not-enough-usage-to-run-paid-actor') {
                $reason = __('Place search is temporarily unavailable. Paste the Place ID directly using “Have a Place ID? Enter manually”.', 'embedpress');
            }
            return new \WP_Error('embedpress_gr_apify_search', $reason, ['status' => 502]);
        }

        $items = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($items)) {
            return new \WP_Error('embedpress_gr_apify_search', __('Place search returned an unexpected response. Try again, or paste the Place ID directly using “Have a Place ID? Enter manually”.', 'embedpress'), ['status' => 502]);
        }

        $out = [];
        foreach ($items as $it) {
            if (!is_array($it) || empty($it['placeId'])) {
                continue;
            }
            $title = (string) ($it['title'] ?? '');
            $addr  = (string) ($it['address'] ?? '');
            $out[] = [
                'place_id'       => (string) $it['placeId'],
                'main_text'      => $title,
                'secondary_text' => $addr,
                'description'    => trim($title . ($addr ? ', ' . $addr : '')),
            ];
        }
        return $out;
    }

    /**
     * Is this WP_Error a connection/operation timeout (cURL 28 / "timed out")
     * rather than some other transport failure? Used to decide whether a retry is
     * worthwhile and how to label the error for the user.
     */
    private static function is_timeout_error($err): bool
    {
        if (!is_wp_error($err)) {
            return false;
        }
        $msg = strtolower($err->get_error_message());
        return strpos($msg, 'timed out') !== false
            || strpos($msg, 'timeout') !== false
            || strpos($msg, 'operation too slow') !== false;
    }

    /**
     * Which Places API variant the user's key is enabled for.
     * 'new' | 'legacy' | 'auto'. 'auto' means we'll probe on the next call.
     */
    public static function get_api_mode(): string
    {
        $mode = (string) get_option(self::OPT_API_MODE, 'auto');
        return in_array($mode, ['new', 'legacy', 'auto'], true) ? $mode : 'auto';
    }

    public static function set_api_mode(string $mode): void
    {
        if (!in_array($mode, ['new', 'legacy', 'auto'], true)) return;
        if ($mode === self::get_api_mode()) return;
        update_option(self::OPT_API_MODE, $mode);
    }

    /**
     * Return the list of recently-used and explicitly-saved places. Each
     * entry is `{place_id, place_name, used_at|saved_at}`. Saved entries
     * persist indefinitely; recent rotates at RECENT_MAX.
     */
    public static function get_places_lists(): array
    {
        $recent = get_option(self::OPT_RECENT, []);
        $saved  = get_option(self::OPT_SAVED, []);
        return [
            'recent' => is_array($recent) ? array_values($recent) : [],
            'saved'  => is_array($saved) ? array_values($saved) : [],
        ];
    }

    /**
     * Push a place to the head of the "recent" list, deduped by place_id.
     * No-op if place_id is empty. Trims to RECENT_MAX.
     */
    public static function remember_recent_place(string $place_id, string $place_name): void
    {
        $place_id = trim($place_id);
        if ($place_id === '') return;
        $recent = get_option(self::OPT_RECENT, []);
        if (!is_array($recent)) $recent = [];
        $recent = array_values(array_filter($recent, function ($p) use ($place_id) {
            return is_array($p) && ($p['place_id'] ?? '') !== $place_id;
        }));
        array_unshift($recent, [
            'place_id'   => $place_id,
            'place_name' => sanitize_text_field($place_name),
            'used_at'    => time(),
        ]);
        if (count($recent) > self::RECENT_MAX) {
            $recent = array_slice($recent, 0, self::RECENT_MAX);
        }
        update_option(self::OPT_RECENT, $recent);
    }

    /**
     * Add or remove a place from the explicit "saved" list.
     */
    public static function toggle_saved_place(string $place_id, string $place_name, bool $save): void
    {
        $place_id = trim($place_id);
        if ($place_id === '') return;
        $saved = get_option(self::OPT_SAVED, []);
        if (!is_array($saved)) $saved = [];
        $saved = array_values(array_filter($saved, function ($p) use ($place_id) {
            return is_array($p) && ($p['place_id'] ?? '') !== $place_id;
        }));
        if ($save) {
            array_unshift($saved, [
                'place_id'   => $place_id,
                'place_name' => sanitize_text_field($place_name),
                'saved_at'   => time(),
            ]);
        }
        update_option(self::OPT_SAVED, $saved);
    }

    public static function get_cache_ttl(): int
    {
        $ttl = (int) get_option(self::OPT_CACHE_TTL, 6 * HOUR_IN_SECONDS);
        return $ttl > 0 ? $ttl : 6 * HOUR_IN_SECONDS;
    }

    /**
     * Flush all Google Reviews transients (the cache + error markers).
     * Returns the number of rows deleted.
     */
    public static function clear_cache(): int
    {
        global $wpdb;
        $like = $wpdb->esc_like('_transient_' . self::CACHE_PREFIX) . '%';
        $like_timeout = $wpdb->esc_like('_transient_timeout_' . self::CACHE_PREFIX) . '%';
        $a = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
        $b = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout));
        return $a + $b;
    }

    /**
     * Enqueue the frontend stylesheet. Safe to call multiple times. Also
     * registers an `init` hook so the Gutenberg editor pulls the same CSS
     * into the editor iframe (ServerSideRender returns raw HTML so the
     * editor needs our stylesheet to render the cards correctly).
     */
    public static function enqueue_assets()
    {
        if (!wp_style_is('embedpress-google-reviews', 'registered')) {
            wp_register_style(
                'embedpress-google-reviews',
                // assets/ (build output) — NOT static/ (source). static/ is
                // excluded from the dist build (.distignore /static), so a
                // shipped/built plugin 404s on static/css/google-reviews.css.
                // The build copies static/ → assets/, so enqueue from assets.
                EMBEDPRESS_URL_ASSETS . 'css/google-reviews.css',
                [],
                EMBEDPRESS_VERSION
            );
        }
        wp_enqueue_style('embedpress-google-reviews');

        // Read-more toggle (vanilla, no deps). Reveals the button only when the
        // review text actually overflows the CSS clamp; re-inits on editor SSR
        // re-render via a MutationObserver.
        if (!wp_script_is('embedpress-google-reviews', 'registered')) {
            wp_register_script(
                'embedpress-google-reviews',
                EMBEDPRESS_URL_ASSETS . 'js/google-reviews.js',
                [],
                EMBEDPRESS_VERSION,
                true
            );
        }
        wp_enqueue_script('embedpress-google-reviews');

        // Content-gated Pro hook: fires only when a GR block/shortcode actually
        // renders. Pro enqueues its `embedpress-google-reviews-pro` assets here
        // (register happens earlier, on wp_enqueue_scripts), so Pro CSS/JS load
        // ONLY on pages that contain Google Reviews — not on every page.
        do_action('embedpress/google_reviews/enqueue_assets');
    }

    /**
     * Hook for `enqueue_block_editor_assets` — load the frontend stylesheet
     * inside the block editor so ServerSideRender output renders correctly.
     *
     * `enqueue_block_editor_assets` only reaches the editor's TOP document, not
     * the iframed block canvas — and the device/responsive preview (Tablet /
     * Mobile) always renders inside that iframe. Without the stylesheet there,
     * `.ep-gr-star-svg { width:1em }` is lost and the SVG (viewBox, no intrinsic
     * size) balloons to fill its container (the "giant black star" bug).
     * `wp_enqueue_block_style()` (WP 5.9+) is the API that injects a per-block
     * stylesheet into the iframed canvas as well, so it renders correctly in
     * both the normal view and every device preview.
     */
    public static function enqueue_editor_assets()
    {
        self::enqueue_assets();

        if (function_exists('wp_enqueue_block_style')) {
            if (!wp_style_is('embedpress-google-reviews', 'registered')) {
                wp_register_style(
                    'embedpress-google-reviews',
                    EMBEDPRESS_URL_ASSETS . 'css/google-reviews.css',
                    [],
                    EMBEDPRESS_VERSION
                );
            }
            wp_enqueue_block_style('embedpress/google-reviews', [
                'handle' => 'embedpress-google-reviews',
            ]);
        }
    }
}
