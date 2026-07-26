<?php

namespace EmbedPress\Includes\Classes;

/**
 * Feature Notices Registration
 * 
 * This file contains all feature notice registrations for EmbedPress.
 * Add your feature notices here to keep them organized in one place.
 * 
 * @package EmbedPress
 * @since 4.1.0
 */

(defined('ABSPATH') && defined('EMBEDPRESS_IS_LOADED')) or die("No direct script access allowed.");

class FeatureNotices
{

    /**
     * Singleton instance
     * @var FeatureNotices
     */
    private static $instance = null;

    /**
     * Get singleton instance
     * 
     * @return FeatureNotices
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        // Register all notices
        add_action('init', [$this, 'register_all_notices']);
    }

    /**
     * Register all feature notices
     * 
     * Add your feature notices here. Each notice should have a unique ID.
     * 
     * @return void
     */
    public function register_all_notices()
    {
        $notice_manager = FeatureNoticeManager::get_instance();

        // ========================================
        // ACTIVE NOTICES
        // ========================================
        // Note: Notices only show on Dashboard page (/wp-admin/index.php)
        // Any action (Skip, Close, or Click Button) = Permanently dismiss

        // Analytics Dashboard Feature Notice
        $notice_manager->register_notice('analytics_dashboard_2024', [
            'title' => 'New Features',
            'icon' => '<svg width="11" height="13" viewBox="0 0 11 13" fill="none" xmlns="http://www.w3.org/2000/svg">
<path d="M0 1.61956C1.26645e-05 1.53091 0.0177073 1.44315 0.0520487 1.36142C0.0863901 1.27968 0.136689 1.20562 0.2 1.14356C0.926563 0.431321 1.89723 0.0227172 2.91444 0.000919081C3.93164 -0.0208791 4.91893 0.345767 5.67533 1.02623L5.90933 1.2449C6.39562 1.67096 7.02013 1.90585 7.66667 1.90585C8.3132 1.90585 8.93771 1.67096 9.424 1.2449L9.59 1.09356C9.99667 0.771563 10.608 1.0289 10.6633 1.54423L10.6667 1.61956V7.61956C10.6667 7.70822 10.649 7.79598 10.6146 7.87771C10.5803 7.95944 10.53 8.03351 10.4667 8.09556C9.7401 8.8078 8.76943 9.21641 7.75223 9.23821C6.73502 9.26 5.74774 8.89336 4.99133 8.2129L4.75733 7.99423C4.28636 7.58157 3.68518 7.3478 3.05915 7.33391C2.43311 7.32001 1.82216 7.52687 1.33333 7.91823V12.2862C1.33314 12.4561 1.26808 12.6196 1.15143 12.7431C1.03479 12.8667 0.875365 12.9411 0.705737 12.951C0.536109 12.961 0.36908 12.9058 0.238778 12.7967C0.108476 12.6877 0.0247357 12.533 0.00466665 12.3642L0 12.2862V1.61956Z" fill="#25396F"/>
</svg>',
            'message' => '🥳 New In EmbedPress: Introducing, Analytics dashboard to track every embed performance; see total counts, views, clicks, geo insights, etc.',
            'button_text' => 'View Analytics',
            'button_url' => admin_url('admin.php?page=embedpress-analytics'),
            'skip_text' => 'Skip',
            'screens' => [], // Empty = show on all admin pages
            'capability' => 'manage_options',
            'start_date' => '2024-01-01',
            'end_date' => '2025-10-31',
            'priority' => 10,
            // 'dismissible' => false,
            'type' => 'info', // info, success, warning, error
        ]);

        // ========================================
        // EXAMPLE NOTICES (Commented Out)
        // ========================================

        /*
        // Example: New Feature Announcement
        $notice_manager->register_notice('new_feature_example', [
            'title' => 'New Features',
            'icon' => '🚀',
            'message' => '<strong>Exciting Update!</strong> We\'ve added amazing new features to EmbedPress.',
            'button_text' => 'Learn More',
            'button_url' => 'https://embedpress.com/features',
            'button_target' => '_blank',
            'skip_text' => 'Maybe Later',
            'screens' => ['toplevel_page_embedpress'], // Show only on EmbedPress pages
            'capability' => 'manage_options',
            'priority' => 5,
            'type' => 'success',
        ]);
        */

        /*
        // Example: Limited Time Offer
        $notice_manager->register_notice('black_friday_2024', [
            'title' => 'Limited Time Offer',
            'icon' => '🎁',
            'message' => '<strong>Black Friday Sale!</strong> Get 50% off on EmbedPress Pro. Offer ends soon!',
            'button_text' => 'Claim Discount',
            'button_url' => 'https://embedpress.com/pricing',
            'button_target' => '_blank',
            'skip_text' => 'Not Interested',
            'start_date' => '2024-11-25',
            'end_date' => '2024-12-02',
            'priority' => 1, // Higher priority (lower number = shown first)
            'type' => 'warning',
        ]);
        */

        /*
        // Example: Important Update Notice
        $notice_manager->register_notice('important_update_2024', [
            'title' => 'Important Update',
            'icon' => '⚠️',
            'message' => '<strong>Action Required:</strong> Please update your settings to ensure compatibility with the latest version.',
            'button_text' => 'Update Settings',
            'button_url' => admin_url('admin.php?page=embedpress#/settings'),
            'skip_text' => 'Remind Me Later',
            'priority' => 1,
            'type' => 'warning',
        ]);
        */

        /*
        // Example: Success/Milestone Notice
        $notice_manager->register_notice('milestone_100k', [
            'title' => 'Thank You!',
            'icon' => '🎊',
            'message' => '<strong>We did it!</strong> EmbedPress has reached 100,000+ active installations. Thank you for being part of our journey!',
            'button_text' => 'Share Your Feedback',
            'button_url' => 'https://wordpress.org/support/plugin/embedpress/reviews/',
            'button_target' => '_blank',
            'skip_text' => 'Close',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'priority' => 15,
            'type' => 'success',
        ]);
        */

        // ========================================
        // "WHAT'S NEW" FEATURE PREVIEW MODAL
        // ========================================
        // The big post-update split modal (preview + copy). One feature set
        // per release; edit copy/CTA/media here without touching the renderer.
        $this->register_feature_previews();
    }

    /**
     * Register the post-update "What's New" feature preview modal sets.
     *
     * One entry per release. The modal fires once on the dashboard when the
     * set's `version` is newer than the user's seen-version, then stamps the
     * version so it never re-shows. Single feature => no nav; 2+ => carousel.
     *
     * Media `type` accepts: 'image', 'gif', 'video' (relative paths resolve
     * against assets/images/), or 'html' for an inline CSS demo.
     *
     * @return void
     */
    public function register_feature_previews()
    {
        if (!class_exists('\\EmbedPress\\Includes\\Classes\\FeaturePreviewModal')) {
            return;
        }

        $version = defined('EMBEDPRESS_VERSION') ? EMBEDPRESS_VERSION : '0.0.0';
        $modal   = FeaturePreviewModal::get_instance();

        // Which releases actually announce something. The modal is opt-IN per
        // version: a release only shows it when its version is listed here AND
        // the $features array below describes that release.
        //
        // Why an allowlist instead of a bare `'show_if' => true`: the feature
        // slides are hand-written per release, so a patch that ships no new
        // features (4.6.1, 4.6.2 — bug fixes and security hardening) would
        // otherwise re-announce the PREVIOUS release's copy under a new version
        // number. Defaulting to "off" makes the safe case automatic and the
        // announcement deliberate.
        //
        // To announce a new release: rewrite $features for it, then add its
        // version here. Patch releases with no user-facing features: add
        // nothing — they stay silent, along with the menu "New" badge.
        $announced_versions = [
            '4.6.0', // Google Reviews + PDF flipbook Highlight Links
        ];

        $is_announced = in_array($version, $announced_versions, true);

        // Feature slides for this release. Two slides → the modal auto-switches
        // to a carousel (dots + Back/Next). Demos are inline HTML (no assets).
        $features = [
            [
                'eyebrow' => sprintf(__('New in %s', 'embedpress'), $version),
                'title'   => __('Embed Google Business reviews', 'embedpress'),
                'desc'    => __('Showcase your Google reviews anywhere — Gutenberg block, Elementor widget, or shortcode. Pick a place, choose a layout, and you’re live. No Google API keys required.', 'embedpress'),
                'media'   => [
                    'type'  => 'html',
                    'html'  => $this->preview_demo_google_reviews(),
                ],
                'cta'     => [
                    'label' => __('Set up Google Reviews →', 'embedpress'),
                    'url'   => admin_url('admin.php?page=embedpress-google-reviews'),
                ],
            ],
            [
                'eyebrow' => sprintf(__('New in %s', 'embedpress'), $version),
                'title'   => __('Highlight links in 3D PDF Flipbooks', 'embedpress'),
                'desc'    => __('Make hyperlinks inside your PDF 3D Flipbooks stand out, so readers never miss a clickable link.', 'embedpress'),
                'media'   => [
                    // Real product capture of link-highlighting in a 3D
                    // Flipbook, hosted on the CDN. Full URL passes through
                    // resolve_media_src() unchanged; preloaded in <head> and
                    // shown behind a shimmer while it downloads.
                    'type' => 'gif',
                    'src'  => 'https://cloud.zoobbe.com/backgrounds/pdf-3d-flipbook-d.gif',
                ],
                'cta'     => [
                    'label'    => __('View documentation →', 'embedpress'),
                    'url'      => 'https://embedpress.com/docs/turn-embedded-pdf-into-a-3d-flip-book/',
                    'external' => true,
                ],
            ],
        ];

        // 4.6.0 release set. Versioned id so a fresh release is a fresh
        // announcement. Demos are inline, on-brand HTML (no asset files).
        $modal->register('whatsnew_' . str_replace('.', '_', $version), [
            'version'       => $version,
            // Master switch for THIS release's modal. Set to false to ship the
            // release without the modal (e.g. a refactor-only version), or pass
            // a callable for conditional gating, e.g.:
            //   'show_if' => function () { return ! function_exists('embedpress_pro_load'); }, // non-Pro only
            //
            // Driven by the $announced_versions allowlist above — true only for
            // releases that actually ship user-facing features. Bug-fix and
            // security patches (4.6.1, 4.6.2) resolve to false, so both the
            // modal and the menu "New" badge stay silent.
            'show_if'       => $is_announced,
            'changelog_url' => 'https://embedpress.com/changelog/',
            // When usage tracking is NOT yet enabled, the bottom link becomes
            // "What we collect" → the privacy policy (replacing the changelog
            // link). Users who already opted in keep the normal changelog link.
            'whatwe_collect_url' => FeaturePreviewModal::is_tracking_enabled()
                ? ''
                : 'https://wpdeveloper.com/privacy-policy',
            'features'      => $features,
        ]);
    }

    /**
     * Inline placeholder demo for the sample feature — a clean, on-brand mock
     * of the "Display Rules" control (role / login / schedule toggles).
     *
     * Uses EmbedPress brand colors only: brand purple #5945b0 (+ #7c3aed
     * gradient), success green #00cc76 for the "on" schedule toggle, and the
     * dark preview-panel tones. Real releases should swap this for a screenshot
     * or GIF via 'media' => ['type' => 'image', 'src' => '…'].
     *
     * @return string
     */
    private function preview_demo_display_rules()
    {
        $row = function ($on, $label, $color, $pill = '') {
            $knob = $on
                ? 'right:2px'
                : 'left:2px';
            $bg = $on ? $color : '#3a3550';
            $pill_html = $pill
                ? '<span style="margin-left:auto;font-size:11px;font-weight:600;color:#9a8be0;background:rgba(89,69,176,.22);padding:3px 9px;border-radius:999px">' . esc_html($pill) . '</span>'
                : '';
            return '<div style="display:flex;align-items:center;gap:10px;background:#1b1928;border:1px solid #2a2740;border-radius:10px;padding:11px 13px;margin-bottom:9px">'
                . '<span style="width:30px;height:17px;border-radius:9px;background:' . $bg . ';position:relative;flex-shrink:0"><span style="position:absolute;top:2px;' . $knob . ';width:13px;height:13px;border-radius:50%;background:#fff"></span></span>'
                . '<span style="font-size:12px;color:#dfe3f0">' . esc_html($label) . '</span>'
                . $pill_html
                . '</div>';
        };

        return '<div style="width:262px;font-family:inherit">'
            . '<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;color:#c7cce0;font-size:12px;font-weight:600">'
            . '<span style="width:18px;height:18px;border-radius:5px;background:#5945b0;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:10px">&#10022;</span>'
            . esc_html__('Display Rules', 'embedpress') . '</div>'
            . $row(true, __('User role', 'embedpress'), '#5945b0', __('Members', 'embedpress'))
            . $row(false, __('Logged-in only', 'embedpress'), '#5945b0')
            . $row(true, __('Schedule by date', 'embedpress'), '#00cc76')
            . '</div>';
    }

    /**
     * Animated, LIGHT demo for the Google Reviews feature — a 3-step loop that
     * tells the feature story: (1) search for a place, (2) click the result,
     * (3) the reviews animate in. The keyframe animations live in
     * static/css/feature-preview-modal.css (.ep-grdemo-* classes) because the
     * modal runs this markup through wp_kses_post, which strips <style>; class
     * attributes survive, so the CSS does the motion.
     *
     * @return string
     */
    private function preview_demo_google_reviews()
    {
        $stars = '<span class="ep-grdemo-stars">'
            . str_repeat('<span>&#9733;</span>', 5) . '</span>';

        // The same icons our place picker uses (GoogleReviews.jsx). Inline SVG
        // survives because the modal allows a small SVG tag set for plugin-built
        // demo markup (FeaturePreviewModal::demo_allowed_html()).
        $search_icon = '<svg class="ep-grdemo-search-ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>';
        $pin_icon = '<svg class="ep-grdemo-pin" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>';

        // Per-card stagger is done via CSS (adjacent-sibling chains) — NOT an
        // inline animation-delay, which kses strips.
        $review = function ($initial, $name, $text) use ($stars) {
            return '<div class="ep-grdemo-review">'
                . '<div class="ep-grdemo-review-head">'
                . '<span class="ep-grdemo-avatar">' . esc_html($initial) . '</span>'
                . '<span class="ep-grdemo-name">' . esc_html($name) . '</span>'
                . $stars
                . '</div>'
                . '<div class="ep-grdemo-text">' . esc_html($text) . '</div>'
                . '</div>';
        };

        return '<div class="ep-grdemo">'
            // ── Phase A: our place-search picker — input (text types in) +
            //    the suggestion dropdown that gets clicked. Mirrors the real
            //    .ep-gr-search / .ep-gr-suggestion markup. ──
            . '<div class="ep-grdemo-phase ep-grdemo-phase--search">'
            . '<div class="ep-grdemo-label">' . esc_html__('Search for a place', 'embedpress') . '</div>'
            . '<div class="ep-grdemo-search">'
            . $search_icon
            . '<span class="ep-grdemo-typed">' . esc_html__('Sydney Opera House', 'embedpress') . '</span>'
            . '<span class="ep-grdemo-caret"></span>'
            . '</div>'
            . '<div class="ep-grdemo-suggest">'
            . '<div class="ep-grdemo-result">'
            . $pin_icon
            . '<span class="ep-grdemo-result-text"><strong>' . esc_html__('Sydney Opera House', 'embedpress') . '</strong>'
            . '<small>' . esc_html__('Bennelong Point, Sydney NSW, Australia', 'embedpress') . '</small></span>'
            . '<span class="ep-grdemo-cursor"></span>'
            . '</div>'
            . '</div>'
            . '</div>'
            // ── Phase B: the reviews our block renders — summary + cards. ──
            . '<div class="ep-grdemo-phase ep-grdemo-phase--reviews">'
            . '<div class="ep-grdemo-summary"><b>4.8</b>' . $stars
            . '<span class="ep-grdemo-count">2,341 ' . esc_html__('reviews', 'embedpress') . '</span></div>'
            . $review('A', __('Aisha K.', 'embedpress'), __('Breathtaking venue and a flawless experience — the guided tour was the trip highlight.', 'embedpress'))
            . $review('M', __('Marco T.', 'embedpress'), __('A must-see. The architecture is stunning from every angle, day or night.', 'embedpress'))
            . $review('L', __('Liam P.', 'embedpress'), __('Incredible acoustics and a stunning harbour view — booked tickets for the evening show.', 'embedpress'))
            . '</div>'
            . '</div>';
    }

    /**
     * Helper method to get admin URL for EmbedPress pages
     * 
     * @param string $page_type The page type (e.g., 'analytics', 'settings')
     * @return string
     */
    private function get_embedpress_url($page_type = '')
    {
        $url = admin_url('admin.php?page=embedpress');
        if (!empty($page_type)) {
            $url .= '&page_type=' . $page_type;
        }
        return $url;
    }
}

// Initialize the class
FeatureNotices::get_instance();
