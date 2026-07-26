<?php

namespace EmbedPress\Includes\Classes;

/**
 * Feature Preview Modal — the post-update "What's New" announcement layer.
 *
 * A centered, split-panel modal shown once in wp-admin after an EmbedPress
 * version bump. Left panel = animated feature preview (image / GIF / MP4 /
 * inline HTML demo) inside faux browser chrome; right panel = eyebrow +
 * headline + description + primary CTA + "see the full changelog" link.
 *
 * Adaptive: a single feature renders with no navigation; multiple features
 * become a carousel with pager dots + Back/Next (Next → "Done ✓" on the last
 * step) and an "n of N" counter in the eyebrow.
 *
 * Data-driven: each release registers a `feature_set` (an array of slides)
 * via {@see FeaturePreviewModal::register()}; non-devs edit copy/CTA/media
 * per release without touching the renderer or the JS.
 *
 * Gating mirrors the existing notice conventions:
 *   - Admin capability only (`manage_options` by default).
 *   - EmbedPress admin pages only (`?page=embedpress*`) — the modal fires where
 *     the user already has EmbedPress context, NOT on the generic WP dashboard.
 *     A blinking "New" indicator on the EmbedPress menu (shown on every admin
 *     page while a release is unseen) is what draws the user in; the modal then
 *     opens in-context.
 *   - Auto-open vs. click-to-open is a developer switch — the
 *     `embedpress_whatsnew_autoopen` filter (default true). When false, the
 *     modal does not auto-open; clicking the flagged EmbedPress menu opens it.
 *   - Fires once per version: the trigger version on the feature set must be
 *     newer than the per-user `embedpress_whatsnew_seen_version` option.
 *   - Dismissal (close / ESC / CTA / "Done") writes the trigger version to
 *     that option so the modal never re-shows for that release (and clears the
 *     menu indicator).
 *
 * This class does NOT introduce a parallel version-tracking system — it reuses
 * the same "show once per version bump" idea the release-notes tooltip uses,
 * keyed by its own option so the two surfaces don't fight over one flag.
 *
 * @package EmbedPress
 * @since 4.5.7
 */

(defined('ABSPATH') && defined('EMBEDPRESS_IS_LOADED')) or die("No direct script access allowed.");

class FeaturePreviewModal
{
    /**
     * Option storing the highest release version a user has already seen the
     * modal for. Per-version gate: the modal only fires when a registered
     * feature set's trigger version is newer than this.
     */
    const SEEN_VERSION_OPTION = 'embedpress_whatsnew_seen_version';

    /**
     * Option storing the highest release version the user has OPENED the modal
     * for at least once. Gates the menu "New" indicator only — the badge clears
     * as soon as the modal has been opened once, even if the user didn't dismiss
     * it. The modal itself keeps re-showing until a real dismiss stamps
     * {@see SEEN_VERSION_OPTION}. Two flags so "seen the badge" and "finished
     * the modal" are independent.
     */
    const OPENED_VERSION_OPTION = 'embedpress_whatsnew_opened_version';

    /**
     * AJAX action used to persist a dismissal.
     */
    const DISMISS_ACTION = 'embedpress_dismiss_whatsnew_modal';

    /**
     * AJAX action used to persist a first-open (clears the menu indicator).
     */
    const OPENED_ACTION = 'embedpress_opened_whatsnew_modal';

    /**
     * AJAX action fired when the user interacts with any button in the modal —
     * treated as consent to opt into WP-Insights usage tracking (unless the
     * user has previously explicitly opted out).
     */
    const CONSENT_ACTION = 'embedpress_whatsnew_consent';

    /**
     * Nonce action / handle name.
     */
    const NONCE_ACTION = 'embedpress_whatsnew_modal';

    /**
     * Registered feature sets, keyed by an arbitrary id.
     * @var array<string,array>
     */
    private $feature_sets = [];

    /**
     * Singleton instance.
     * @var FeaturePreviewModal|null
     */
    private static $instance = null;

    /**
     * @return FeaturePreviewModal
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Top-level EmbedPress admin menu slug. The modal arms on this page and its
     * `?page=embedpress&page_type=…` / `embedpress-…` submenus; the "New" menu
     * indicator is appended to this menu item.
     */
    const MENU_SLUG = 'embedpress';

    private function __construct()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_head', [$this, 'preload_media']);
        add_action('admin_footer', [$this, 'render']);
        add_action('wp_ajax_' . self::DISMISS_ACTION, [$this, 'ajax_dismiss']);
        add_action('wp_ajax_' . self::OPENED_ACTION, [$this, 'ajax_opened']);
        add_action('wp_ajax_' . self::CONSENT_ACTION, [$this, 'ajax_consent']);
        // Blinking "New" indicator on the EmbedPress menu — visible from ANY
        // admin page while there's an unseen release, so the announcement reaches
        // the user wherever they are, then opens in-context when they enter
        // EmbedPress. Late priority so it runs after the menu is registered.
        add_action('admin_menu', [$this, 'add_menu_indicator'], 999);
        // The menu bubble shows on every admin page, but the modal stylesheet
        // only loads on EmbedPress pages — so print the tiny pulse CSS inline in
        // the head wherever the bubble is present.
        add_action('admin_head', [$this, 'print_menu_indicator_style']);
    }

    /**
     * Append a blinking "New" bubble to the top-level EmbedPress menu title
     * while there's an unseen release for the current user.
     *
     * Runs on every admin page (the indicator's whole job is to reach the user
     * wherever they are), gated only by {@see resolve_unseen_set()} — NOT by the
     * EmbedPress-page screen check, unlike the modal itself. The bubble reuses
     * WP's native `.update-plugins` count styling plus an `.ep-whatsnew-badge`
     * hook for the pulse animation (CSS lives in feature-preview-modal.css, which
     * only loads on EmbedPress pages — the bubble is styled inline-safe elsewhere
     * via the core class, and the pulse is a progressive enhancement).
     *
     * @return void
     */
    public function add_menu_indicator()
    {
        global $menu;

        if (empty($menu) || !is_array($menu)) {
            return;
        }

        // Badge gate: hide once the modal has been opened at least once.
        if (!$this->has_unopened_release()) {
            return;
        }

        // $menu is a list of [0=>title, 1=>cap, 2=>slug, …]. Find EmbedPress by
        // its slug and append the bubble to its title, mirroring how WP renders
        // the plugin-update count.
        foreach ($menu as $key => $item) {
            if (!isset($item[2]) || $item[2] !== self::MENU_SLUG) {
                continue;
            }

            // Inline green "NEW" pill with a white-gradient shine. NOT WP's
            // `.update-plugins` count bubble (that drops to its own line with a
            // word). All layout is in the inline stylesheet so core menu CSS
            // can't drag it around; the label uses nowrap to keep it on one line.
            $bubble = ' <span class="ep-whatsnew-badge" aria-hidden="true">' . esc_html__('New', 'embedpress') . '</span>';
            $menu[$key][0] .= $bubble;
            break;
        }
    }

    /**
     * Print the pulse animation for the menu bubble, inline in <head>.
     *
     * The bubble appears on every admin page while a release is unseen, but the
     * modal's stylesheet only enqueues on EmbedPress pages — so this small,
     * self-contained rule keeps the blink working globally. Freezes under
     * `prefers-reduced-motion`.
     *
     * @return void
     */
    public function print_menu_indicator_style()
    {
        if (!$this->has_unopened_release()) {
            return;
        }
        ?>
<style id="ep-whatsnew-badge-style">
#adminmenu li.menu-top:has(.ep-whatsnew-badge) .wp-menu-name{white-space:nowrap}
#adminmenu .ep-whatsnew-badge{position:relative;overflow:hidden;display:inline-flex;align-items:center;justify-content:center;vertical-align:middle;width:28px;height:18px;margin:-2px 0 0 0;padding:0;border-radius:9px;background:#d63637;color:#fff;font-size:8px;font-weight:700;line-height:1.6;letter-spacing:.04em;text-transform:uppercase;text-align:center;box-shadow:none!important;animation:ep-whatsnew-glow 1.8s ease-in-out infinite}
#adminmenu .ep-whatsnew-badge::after{content:"";position:absolute;top:0;left:0;width:45%;height:100%;background:linear-gradient(100deg,transparent 0%,rgba(255,255,255,.85) 50%,transparent 100%);animation:ep-whatsnew-shine 1.8s ease-in-out infinite}
@keyframes ep-whatsnew-shine{0%{transform:translateX(-160%) skewX(-18deg)}55%,100%{transform:translateX(320%) skewX(-18deg)}}
@keyframes ep-whatsnew-glow{0%,100%{box-shadow:0 1px 4px rgba(0,185,105,.5)}50%{box-shadow:0 1px 9px rgba(0,185,105,.85)}}
@media (prefers-reduced-motion: reduce){#adminmenu .ep-whatsnew-badge{animation:none}#adminmenu .ep-whatsnew-badge::after{display:none}}
</style>
        <?php
    }

    /**
     * Warm the modal's media in the background.
     *
     * The modal opens shortly after the dashboard loads; if a feature uses a
     * large image/GIF (e.g. a full product animation), fetching it only when
     * the modal opens leaves the stage blank while it downloads. Emit a
     * `<link rel="preload">` for each image/gif src in the active set so the
     * browser starts fetching immediately on page load — by the time the modal
     * appears the asset is warm (or already cached). Only fires on an
     * EmbedPress admin page when a set is actually queued.
     *
     * @return void
     */
    public function preload_media()
    {
        if (!$this->is_target_screen()) {
            return;
        }

        $set = $this->get_active_set();
        if (!$set) {
            return;
        }

        $seen = [];
        foreach ($set['features'] as $feature) {
            $media = isset($feature['media']) && is_array($feature['media']) ? $feature['media'] : [];
            $type  = isset($media['type']) ? sanitize_key($media['type']) : '';
            // Only still images/GIFs benefit from rel=preload as=image. Video is
            // streamed by the <video> element; html demos have no external asset.
            if (!in_array($type, ['image', 'gif'], true) || empty($media['src'])) {
                continue;
            }
            $src = $this->resolve_media_src($media['src']);
            if ($src === '' || isset($seen[$src])) {
                continue;
            }
            $seen[$src] = true;
            printf(
                '<link rel="preload" as="image" href="%s" fetchpriority="low" />' . "\n",
                esc_url($src)
            );
        }
    }

    /**
     * Whether the site owner has already opted into WP-Insights usage tracking
     * for EmbedPress.
     *
     * Mirrors EmbedPress_Plugin_Usage_Tracker::is_tracking_allowed() — tracking
     * is on when the plugin's key exists in the `wpins_allow_tracking` option.
     * Used to SKIP the "What we collect" consent slide for users who already
     * opted in (they've seen/accepted this already).
     *
     * @return bool
     */
    public static function is_tracking_enabled()
    {
        $allow = get_option('wpins_allow_tracking');
        return is_array($allow) && isset($allow[self::MENU_SLUG]);
    }

    /**
     * Register a release's feature set.
     *
     * Call this from {@see FeatureNotices::register_all_notices()} (or any
     * `init`-time hook) — one call per release.
     *
     * @param string $id   Unique id for the set (e.g. 'whatsnew_4_5_7').
     * @param array  $args {
     *     @type string $version       Trigger version (e.g. '4.5.7'). The modal
     *                                 fires when this is newer than the user's
     *                                 seen-version. Defaults to EMBEDPRESS_VERSION.
     *     @type string $capability    Required capability. Default 'manage_options'.
     *     @type string $changelog_url URL for the "see the full changelog" link.
     *     @type array  $features      List of slides. Each slide:
     *         @type string $eyebrow Small label above the title (e.g. 'New in 4.5.7').
     *         @type string $title   Headline.
     *         @type string $desc    1–2 sentence description.
     *         @type array  $media {
     *             @type string $type  'image' | 'gif' | 'video' | 'html'.
     *             @type string $src   URL (image/gif/video) — relative paths are
     *                                  resolved against EMBEDPRESS_URL_ASSETS . 'images/'.
     *             @type string $html  Raw inline demo markup (when type = 'html').
     *             @type string $badge Corner badge text. Default 'PREVIEW'.
     *         }
     *         @type array  $cta {
     *             @type string $label    Button label.
     *             @type string $url      Button URL.
     *             @type bool   $external Open in new tab. Default false.
     *         }
     *     }
     * }
     * @return void
     */
    public function register($id, array $args = [])
    {
        $defaults = [
            'version'       => defined('EMBEDPRESS_VERSION') ? EMBEDPRESS_VERSION : '0.0.0',
            'capability'    => 'manage_options',
            'changelog_url' => 'https://embedpress.com/changelog/',
            // Whether this set is allowed to show. Accepts a bool, or a callable
            // that returns a bool for conditional gating (e.g. non-Pro only).
            // `false` = registered but never shown (mirrors the admin-notice
            // `display_if` convention).
            'show_if'       => true,
            'features'      => [],
        ];

        $set = wp_parse_args($args, $defaults);
        $set['id'] = $id;
        $this->feature_sets[$id] = $set;
    }

    /**
     * Whether the current request is on an EmbedPress admin page.
     *
     * The modal (and its assets/preload) fire on the EmbedPress menu and its
     * submenus — `?page=embedpress`, `?page=embedpress&page_type=…`, and the
     * sibling `?page=embedpress-…` pages — never on the generic WP dashboard.
     * The screen id for the top-level menu is `toplevel_page_embedpress`; the
     * `$_GET['page']` prefix is the reliable common signal across all of them.
     *
     * @return bool
     */
    private function is_target_screen()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing, no state change.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return $page !== '' && strpos($page, self::MENU_SLUG) === 0;
    }

    /**
     * Resolve the newest qualifying feature set for the current user, ignoring
     * the screen. Shared gate (capability + per-set enable + version) behind
     * both the menu indicator and the modal — they differ only in WHICH
     * per-user version option they compare against:
     *
     *  - the MODAL passes SEEN_VERSION_OPTION → keeps re-showing until a real
     *    dismiss/Done stamps it;
     *  - the BADGE passes OPENED_VERSION_OPTION → clears as soon as the modal
     *    has been opened once.
     *
     * @param string $option Per-user version option to compare against.
     * @return array|null The set, or null when nothing qualifies.
     */
    private function resolve_unseen_set($option = self::SEEN_VERSION_OPTION)
    {
        $marker = get_option($option, '0.0.0');
        $candidate = null;

        foreach ($this->feature_sets as $set) {
            if (empty($set['features']) || !current_user_can($set['capability'])) {
                continue;
            }

            // Per-set enable/disable. `show_if` may be a bool or a callable.
            if (!$this->is_set_enabled($set)) {
                continue;
            }

            // Only fire when this release is newer than the compared marker.
            if (version_compare($set['version'], $marker, '<=')) {
                continue;
            }

            // Prefer the newest registered release if several qualify.
            if ($candidate === null || version_compare($set['version'], $candidate['version'], '>')) {
                $candidate = $set;
            }
        }

        return $candidate;
    }

    /**
     * Whether the menu "New" indicator should show — i.e. there's a release the
     * user hasn't OPENED the modal for yet. Independent of the modal's own
     * re-show gate (which uses the seen/dismissed marker).
     *
     * @return bool
     */
    private function has_unopened_release()
    {
        return $this->resolve_unseen_set(self::OPENED_VERSION_OPTION) !== null;
    }

    /**
     * Resolve which feature set (if any) the MODAL should render on this request.
     *
     * Adds the EmbedPress-page screen gate on top of {@see resolve_unseen_set()},
     * so the modal only mounts in-context — never on the WP dashboard. Uses the
     * SEEN marker, so the modal re-shows until the user dismisses/finishes it.
     *
     * @return array|null The set, or null when nothing should show here.
     */
    private function get_active_set()
    {
        if (!$this->is_target_screen()) {
            return null;
        }

        return $this->resolve_unseen_set(self::SEEN_VERSION_OPTION);
    }

    /**
     * Resolve a feature set's `show_if` switch.
     *
     * Accepts a literal bool or a callable (called with the set array, must
     * return truthy to show). A global `embedpress_whatsnew_enabled` filter is
     * also honoured as a site-wide kill switch — return false from it to
     * disable every modal regardless of per-set config.
     *
     * @param array $set
     * @return bool
     */
    private function is_set_enabled($set)
    {
        $show = isset($set['show_if']) ? $set['show_if'] : true;

        if (is_callable($show)) {
            $show = (bool) call_user_func($show, $set);
        } else {
            $show = (bool) $show;
        }

        /**
         * Filter whether a "What's New" feature set may show.
         *
         * @param bool   $show Resolved per-set show_if value.
         * @param array  $set  The feature set being evaluated.
         */
        return (bool) apply_filters('embedpress_whatsnew_enabled', $show, $set);
    }

    /**
     * Whether a modal is queued to show on this request.
     *
     * Used by FeatureNoticeManager to suppress the small menu tooltip when the
     * bigger modal is about to take over the same dashboard view — so a release
     * is never announced twice at once.
     *
     * @return bool
     */
    public function has_active_modal()
    {
        return $this->get_active_set() !== null;
    }

    /**
     * Enqueue the modal CSS/JS on an EmbedPress admin page when a set is queued.
     *
     * @param string $hook Current admin page hook (unused — screen is resolved
     *                     via is_target_screen()/get_active_set()).
     * @return void
     */
    public function enqueue_assets($hook)
    {
        if (!$this->get_active_set()) {
            return;
        }

        $version = defined('EMBEDPRESS_VERSION') ? EMBEDPRESS_VERSION : false;

        // Cache-bust by file mtime so edits invalidate the browser cache even
        // when the plugin version is unchanged (versions bump only at release).
        // Falls back to the plugin version if the file can't be stat'd.
        $css_path = defined('EMBEDPRESS_PATH_BASE') ? EMBEDPRESS_PATH_BASE . 'assets/css/feature-preview-modal.css' : '';
        $js_path  = defined('EMBEDPRESS_PATH_BASE') ? EMBEDPRESS_PATH_BASE . 'assets/js/feature-preview-modal.js' : '';
        $css_ver  = ($css_path && file_exists($css_path)) ? (string) filemtime($css_path) : $version;
        $js_ver   = ($js_path && file_exists($js_path)) ? (string) filemtime($js_path) : $version;

        wp_enqueue_style(
            'embedpress-whatsnew-modal',
            EMBEDPRESS_URL_ASSETS . 'css/feature-preview-modal.css',
            [],
            $css_ver
        );

        wp_enqueue_script(
            'embedpress-whatsnew-modal',
            EMBEDPRESS_URL_ASSETS . 'js/feature-preview-modal.js',
            [],
            $js_ver,
            true
        );

        wp_localize_script('embedpress-whatsnew-modal', 'EmbedPressWhatsNew', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce(self::NONCE_ACTION),
            'action'       => self::DISMISS_ACTION,
            // Separate action fired on first open — clears the menu indicator
            // without dismissing the modal.
            'openedAction' => self::OPENED_ACTION,
            // Action fired on the FIRST button interaction in the modal — opts
            // the user into WP-Insights usage tracking (consent-on-engage).
            // Only wired when tracking isn't already enabled, so the beacon is
            // a no-op for users who've already consented.
            'consentAction'  => self::CONSENT_ACTION,
            'consentNeeded'  => !self::is_tracking_enabled(),
            'logoUrl'      => EMBEDPRESS_URL_ASSETS . 'images/logo.svg',
            /**
             * Developer switch — auto-open the modal on an EmbedPress page (true,
             * default) vs. wait for the user to click the flagged EmbedPress menu
             * (false). No settings UI; filter-only by design.
             *
             * @param bool $autoopen
             */
            'autoOpen' => (bool) apply_filters('embedpress_whatsnew_autoopen', true),
            /* Slug used by the JS to bind click-to-open on the menu in click-mode. */
            'menuSlug' => self::MENU_SLUG,
            'i18n'    => [
                'close'     => __('Close', 'embedpress'),
                'back'      => __('← Back', 'embedpress'),
                'next'      => __('Next →', 'embedpress'),
                'done'      => __('Done ✓', 'embedpress'),
                'changelog' => __('See the full changelog', 'embedpress'),
                'skip'      => __('Skip · See the full changelog', 'embedpress'),
                'whatWeCollect' => __('What we collect', 'embedpress'),
                /* translators: 1: current step number, 2: total steps. */
                'counter'   => __('%1$d of %2$d', 'embedpress'),
            ],
        ]);
    }

    /**
     * Print the modal data + mount node in the admin footer.
     *
     * The JS reads the JSON payload and builds the DOM — no markup is rendered
     * server-side beyond the empty mount node, keeping escaping concerns in one
     * place (wp_json_encode) and the structure entirely in the JS.
     *
     * @return void
     */
    public function render()
    {
        $set = $this->get_active_set();
        if (!$set) {
            return;
        }

        $payload = $this->prepare_payload($set);
        ?>
        <div id="embedpress-whatsnew-root" aria-hidden="true"></div>
        <script type="application/json" id="embedpress-whatsnew-data">
            <?php echo wp_json_encode($payload); ?>
        </script>
        <?php
    }

    /**
     * Sanitise + normalise a feature set into the shape the JS expects.
     *
     * @param array $set
     * @return array
     */
    private function prepare_payload($set)
    {
        $features = [];

        foreach ($set['features'] as $feature) {
            $media = isset($feature['media']) && is_array($feature['media']) ? $feature['media'] : [];
            $type  = isset($media['type']) ? sanitize_key($media['type']) : 'image';

            $resolved_media = [
                'type'  => in_array($type, ['image', 'gif', 'video', 'html'], true) ? $type : 'image',
                'badge' => isset($media['badge']) ? sanitize_text_field($media['badge']) : '',
            ];

            if ($resolved_media['type'] === 'html') {
                $html = isset($media['html']) ? (string) $media['html'] : '';
                // Inline demo markup is plugin-built (never user input). It may
                // include inline SVG icons, which wp_kses_post strips — so allow
                // a small SVG tag set on top of the post allowlist. Class/style
                // are kept so the animation CSS can hook on.
                $resolved_media['html'] = wp_kses($html, $this->demo_allowed_html());
            } else {
                $resolved_media['src'] = isset($media['src']) ? $this->resolve_media_src($media['src']) : '';
                if ($resolved_media['type'] === 'video') {
                    $resolved_media['poster'] = isset($media['poster']) ? $this->resolve_media_src($media['poster']) : '';
                }
            }

            $cta = isset($feature['cta']) && is_array($feature['cta']) ? $feature['cta'] : [];

            $features[] = [
                'eyebrow' => isset($feature['eyebrow']) ? sanitize_text_field($feature['eyebrow']) : '',
                'title'   => isset($feature['title']) ? sanitize_text_field($feature['title']) : '',
                'desc'    => isset($feature['desc']) ? wp_kses_post($feature['desc']) : '',
                'media'   => $resolved_media,
                'cta'     => [
                    'label'    => isset($cta['label']) ? sanitize_text_field($cta['label']) : '',
                    'url'      => isset($cta['url']) ? esc_url_raw($cta['url']) : '',
                    'external' => !empty($cta['external']),
                ],
            ];
        }

        return [
            'id'           => $set['id'],
            'version'      => $set['version'],
            'changelogUrl' => esc_url_raw($set['changelog_url']),
            // When set (tracking not yet opted-in), the bottom link becomes
            // "What we collect" → this privacy-policy URL instead of the
            // changelog link. Empty string = keep the changelog link.
            'whatWeCollectUrl' => !empty($set['whatwe_collect_url'])
                ? esc_url_raw($set['whatwe_collect_url'])
                : '',
            'features'     => $features,
        ];
    }

    /**
     * Allowed-HTML map for inline demo markup. Extends the post allowlist with
     * the small inline-SVG tag set the demos use for icons. Demo markup is
     * always plugin-built (never user input), so this is safe.
     *
     * @return array
     */
    private function demo_allowed_html()
    {
        $allowed = wp_kses_allowed_html('post');

        $svg_attrs = [
            'class' => true, 'style' => true, 'width' => true, 'height' => true,
            'viewbox' => true, 'fill' => true, 'stroke' => true,
            'stroke-width' => true, 'stroke-linecap' => true,
            'stroke-linejoin' => true, 'aria-hidden' => true,
        ];

        $allowed['svg']    = $svg_attrs;
        $allowed['path']   = ['d' => true] + $svg_attrs;
        $allowed['circle'] = ['cx' => true, 'cy' => true, 'r' => true] + $svg_attrs;
        $allowed['line']   = ['x1' => true, 'y1' => true, 'x2' => true, 'y2' => true] + $svg_attrs;

        return $allowed;
    }

    /**
     * Resolve a media src — pass through full URLs, prefix bare paths with the
     * plugin's assets/images/ directory (same rule the release-notes page uses).
     *
     * @param string $src
     * @return string
     */
    private function resolve_media_src($src)
    {
        $src = trim((string) $src);
        if ($src === '') {
            return '';
        }
        if (preg_match('#^(https?:)?//#', $src) || strpos($src, 'data:') === 0) {
            return esc_url_raw($src);
        }
        return esc_url_raw(EMBEDPRESS_URL_ASSETS . 'images/' . ltrim($src, '/'));
    }

    /**
     * AJAX: persist the dismissal by stamping the seen-version option.
     *
     * Any close path (X, ESC, CTA click, "Done") calls this with the set's
     * trigger version, so the modal won't fire again for that release.
     *
     * @return void
     */
    public function ajax_dismiss()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'embedpress')], 403);
        }

        $version = isset($_POST['version']) ? sanitize_text_field(wp_unslash($_POST['version'])) : '';
        if ($version === '') {
            wp_send_json_error(['message' => __('Missing version.', 'embedpress')]);
        }

        $seen = get_option(self::SEEN_VERSION_OPTION, '0.0.0');
        // Never move the marker backwards.
        if (version_compare($version, $seen, '>')) {
            update_option(self::SEEN_VERSION_OPTION, $version);
        }

        // A dismiss implies the modal was opened — stamp the opened marker too,
        // so the "New" badge can never linger after a dismiss even if the
        // separate open() beacon was lost (e.g. the "What we collect" link
        // navigates a new tab and closes the modal before the open-beacon
        // lands). Keeps opened >= seen as an invariant.
        $opened = get_option(self::OPENED_VERSION_OPTION, '0.0.0');
        if (version_compare($version, $opened, '>')) {
            update_option(self::OPENED_VERSION_OPTION, $version);
        }

        wp_send_json_success([
            'seen'   => get_option(self::SEEN_VERSION_OPTION),
            'opened' => get_option(self::OPENED_VERSION_OPTION),
        ]);
    }

    /**
     * AJAX: persist a first-open by stamping the opened-version option.
     *
     * Called once when the modal opens. This clears the menu "New" indicator
     * (which gates on the opened marker) WITHOUT dismissing the modal — the
     * modal keeps re-showing until a real dismiss stamps the seen marker.
     *
     * @return void
     */
    public function ajax_opened()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'embedpress')], 403);
        }

        $version = isset($_POST['version']) ? sanitize_text_field(wp_unslash($_POST['version'])) : '';
        if ($version === '') {
            wp_send_json_error(['message' => __('Missing version.', 'embedpress')]);
        }

        $opened = get_option(self::OPENED_VERSION_OPTION, '0.0.0');
        // Never move the marker backwards.
        if (version_compare($version, $opened, '>')) {
            update_option(self::OPENED_VERSION_OPTION, $version);
        }

        wp_send_json_success(['opened' => get_option(self::OPENED_VERSION_OPTION)]);
    }

    /**
     * AJAX: opt the user into WP-Insights usage tracking.
     *
     * Fired when the user clicks any button in the What's New modal — treating
     * that engagement as consent to enable usage tracking. Grants consent the
     * same way the onboarding wizard does (see EmbedpressSettings): add the
     * plugin to `wpins_allow_tracking`, suppress the legacy opt-in notice via
     * `wpins_block_notice`, then schedule + fire an initial payload.
     *
     * Guarded:
     *   - nonce + `manage_options`;
     *   - a no-op if tracking is already enabled (idempotent).
     *
     * This mirrors the onboarding wizard's grant path, which is likewise an
     * unconditional opt-in on the user's affirmative action.
     *
     * @return void
     */
    public function ajax_consent()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'embedpress')], 403);
        }

        // Already opted in — nothing to do.
        if (self::is_tracking_enabled()) {
            wp_send_json_success(['tracking' => true, 'changed' => false]);
        }

        // Add this plugin to the allow-tracking list (mirrors the tracker's own
        // set_is_tracking_allowed(), which is protected).
        $allow_tracking = get_option('wpins_allow_tracking');
        if (empty($allow_tracking) || !is_array($allow_tracking)) {
            $allow_tracking = [self::MENU_SLUG => self::MENU_SLUG];
        } else {
            $allow_tracking[self::MENU_SLUG] = self::MENU_SLUG;
        }
        update_option('wpins_allow_tracking', $allow_tracking);

        // Suppress the legacy opt-in admin notice now that consent is captured.
        $block_notice = get_option('wpins_block_notice', []);
        if (!is_array($block_notice)) {
            $block_notice = [];
        }
        $block_notice[self::MENU_SLUG] = self::MENU_SLUG;
        update_option('wpins_block_notice', $block_notice);

        // Register the daily cron and fire an initial payload immediately, so
        // data reaches wpinsight.com without waiting for the first cron tick.
        if (class_exists('\\EmbedPress\\Includes\\Classes\\EmbedPress_Plugin_Usage_Tracker') && defined('EMBEDPRESS_FILE')) {
            $tracker = EmbedPress_Plugin_Usage_Tracker::get_instance(EMBEDPRESS_FILE, [
                'opt_in'       => true,
                'goodbye_form' => true,
                'item_id'      => '98ba0ac16a4f7b3b940d',
            ]);
            $tracker->schedule_tracking();
            $tracker->do_tracking(true);
        }

        wp_send_json_success(['tracking' => true, 'changed' => true]);
    }
}
