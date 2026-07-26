<?php

namespace EmbedPress\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use EmbedPress\Includes\Classes\GoogleReviewsRenderer;
use EmbedPress\Elementor\Controls\Place_Picker as GR_Place_Picker;

(defined('ABSPATH')) or die("No direct script access allowed.");

/**
 * Elementor widget for embedding Google Business reviews.
 *
 * v1: Place ID input + a clear pointer to the Settings → Google Reviews picker
 * for search UX. Elementor's controls API doesn't natively support a remote
 * autocomplete; rather than ship a fragile custom control in v1 we route users
 * to the existing searchable picker and copy the place_id back here.
 *
 * Output uses the shared GoogleReviewsRenderer so widget + block + shortcode
 * all emit identical markup.
 */
class Embedpress_Google_Reviews extends Widget_Base
{
    public function get_name()
    {
        return 'embedpress-google-reviews';
    }

    public function get_title()
    {
        return esc_html__('EmbedPress Google Reviews', 'embedpress');
    }

    public function get_categories()
    {
        return ['embedpress'];
    }

    public function get_icon()
    {
        return 'eicon-favorite';
    }

    public function get_keywords()
    {
        return ['embedpress', 'google', 'reviews', 'places', 'business', 'rating'];
    }

    public function get_custom_help_url()
    {
        return 'https://embedpress.com/docs/embed-google-reviews-in-wordpress/';
    }

    /** Pro-control marking (canonical EmbedPress pattern). Empty when Pro is
     *  active (Pro hooks these filters in includes/Filters/Utility.php), so the
     *  greyed overlay + "(Pro)" label drop and the controls become live. */
    protected $pro_class = '';
    protected $pro_text  = '';

    /**
     * Per-layout capability matrix — MUST stay identical to LAYOUT_CAPS in the
     * Gutenberg block (src/Blocks/google-reviews/src/edit.js) and the renderer's
     * GoogleReviewsRenderer::layout_caps(). Single source of truth for which
     * controls each layout supports. See the JS copy for the flag meanings.
     */
    // Keep identical to LAYOUT_CAPS in GoogleReviewsRenderer.php + edit.js.
    const LAYOUT_CAPS = [
        'list'      => ['reviews' => true,  'header' => 'optional', 'columns' => false, 'gap' => false, 'max_width' => true, 'slider' => false, 'autoplay' => false, 'speed' => false, 'load_more' => true,  'images' => true,  'write_review' => true],
        'grid'      => ['reviews' => true,  'header' => 'optional', 'columns' => true,  'gap' => true,  'max_width' => true, 'slider' => false, 'autoplay' => false, 'speed' => false, 'load_more' => true,  'images' => true,  'write_review' => true],
        'card'      => ['reviews' => true,  'header' => 'optional', 'columns' => true,  'gap' => true,  'max_width' => true, 'slider' => false, 'autoplay' => false, 'speed' => false, 'load_more' => true,  'images' => true,  'write_review' => true],
        'carousel'  => ['reviews' => true,  'header' => 'optional', 'columns' => true,  'gap' => true,  'max_width' => true, 'slider' => true,  'autoplay' => true,  'speed' => false, 'load_more' => false, 'images' => false, 'write_review' => true],
        'masonry'   => ['reviews' => true,  'header' => 'optional', 'columns' => true,  'gap' => true,  'max_width' => true, 'slider' => false, 'autoplay' => false, 'speed' => false, 'load_more' => true,  'images' => true,  'write_review' => true],
        'badge'     => ['reviews' => false, 'header' => 'forced',   'columns' => false, 'gap' => false, 'max_width' => true, 'slider' => false, 'autoplay' => false, 'speed' => false, 'load_more' => false, 'images' => false, 'write_review' => false],
        'spotlight' => ['reviews' => true,  'header' => 'optional', 'columns' => false, 'gap' => false, 'max_width' => true, 'slider' => false, 'autoplay' => true,  'speed' => false, 'load_more' => false, 'images' => true,  'write_review' => true],
        'knowledge' => ['reviews' => false, 'header' => 'forced',   'columns' => false, 'gap' => false, 'max_width' => true, 'slider' => false, 'autoplay' => false, 'speed' => false, 'load_more' => false, 'images' => false, 'write_review' => false],
        'marquee'   => ['reviews' => true,  'header' => 'optional', 'columns' => true,  'gap' => true,  'max_width' => true, 'slider' => false, 'autoplay' => false, 'speed' => true,  'load_more' => false, 'images' => false, 'write_review' => true],
        'bubble'    => ['reviews' => true,  'header' => 'optional', 'columns' => true,  'gap' => true,  'max_width' => true, 'slider' => false, 'autoplay' => false, 'speed' => false, 'load_more' => true,  'images' => true,  'write_review' => true],
    ];

    /** Layouts that render a per-review list (not summary-only). */
    protected static function review_layouts()
    {
        return self::caps_layouts('reviews');
    }

    /** Layouts whose header is optional (has a "Show header" toggle). */
    protected static function header_optional_layouts()
    {
        $out = [];
        foreach (self::LAYOUT_CAPS as $slug => $caps) {
            if (($caps['header'] ?? 'optional') === 'optional') {
                $out[] = $slug;
            }
        }
        return $out;
    }

    /** Layouts whose "columns" control is a slides-per-view count, not a grid. */
    const SLIDES_PER_VIEW_LAYOUTS = ['carousel', 'marquee'];

    /** List of layout slugs whose caps[$flag] is true — for Elementor conditions. */
    protected static function caps_layouts($flag)
    {
        $out = [];
        foreach (self::LAYOUT_CAPS as $slug => $caps) {
            if (!empty($caps[$flag])) {
                $out[] = $slug;
            }
        }
        return $out;
    }

    /** Resolve the effective columns value — slider layouts use ep_gr_slides. */
    protected static function columns_arg($s)
    {
        $layout = isset($s['ep_gr_layout']) ? sanitize_key($s['ep_gr_layout']) : 'list';
        if (in_array($layout, self::SLIDES_PER_VIEW_LAYOUTS, true) && isset($s['ep_gr_slides'])) {
            return (int) $s['ep_gr_slides'];
        }
        return isset($s['ep_gr_columns']) ? (int) $s['ep_gr_columns'] : 3;
    }

    /**
     * Resolve the speed arg. Marquee uses the 1–10 "Scroll speed" SLIDER (returns
     * ['size' => N]); carousel/spotlight use the "Autoplay speed" NUMBER (seconds).
     * Marquee's JS reads this as a 1–10 scale; carousel/spotlight as seconds.
     */
    protected static function speed_arg($s)
    {
        $layout = isset($s['ep_gr_layout']) ? sanitize_key($s['ep_gr_layout']) : 'list';
        $caps   = self::LAYOUT_CAPS[$layout] ?? self::LAYOUT_CAPS['list'];
        if (!empty($caps['speed'])) { // marquee — 1–10 slider
            $size = isset($s['ep_gr_scroll_speed']['size']) ? (int) $s['ep_gr_scroll_speed']['size'] : 4;
            return max(1, min(10, $size ?: 4));
        }
        // carousel / spotlight — seconds
        return isset($s['ep_gr_autoplay_speed']) && (int) $s['ep_gr_autoplay_speed'] > 0
            ? (int) $s['ep_gr_autoplay_speed']
            : 5;
    }

    protected function register_controls()
    {
        // Same primitives the main EmbedPress widget uses: a CSS class that the
        // free el-icon.css greys out + blocks, and a "(Pro)" label suffix. Pro
        // returns '' for both, unlocking every control in place.
        $this->pro_class = apply_filters('embedpress/pro_class', 'embedpress-pro-control not-active');
        $this->pro_text  = apply_filters('embedpress/pro_text', '<sup class="embedpress-pro-label" style="color:red">' . __('(Pro)', 'embedpress') . '</sup>');

        /* ----- Place ----- */
        $this->start_controls_section('ep_gr_section_place', [
            'label' => esc_html__('Place', 'embedpress'),
        ]);

        $this->add_control('ep_gr_place', [
            'label'       => __('Search for a place', 'embedpress'),
            'type'        => GR_Place_Picker::CONTROL_TYPE,
            'label_block' => true,
            'default'     => ['place_id' => '', 'place_name' => '', 'place_address' => ''],
        ]);

        // Guidance: the quick preview shows 5 reviews; pulling more is an explicit
        // Settings action. Kept to one short line with a link. Shown once a place
        // is set.
        $gr_settings_url = admin_url('admin.php?page=embedpress-google-reviews');
        $this->add_control('ep_gr_fetch_hint', [
            'type'      => Controls_Manager::RAW_HTML,
            'raw'       => '<div class="ep-gr-elementor-hint">'
                . esc_html__('Quick preview — 5 reviews.', 'embedpress')
                . ' <a href="' . esc_url($gr_settings_url) . '" target="_blank" rel="noopener noreferrer">'
                . esc_html__('Fetch more', 'embedpress') . '</a>'
                . '</div>',
            'content_classes' => 'ep-gr-elementor-hint-wrap',
            'condition' => ['ep_gr_place[place_id]!' => ''],
        ]);

        $this->end_controls_section();

        /* Section order: Place → Layout & Display (pick a layout first) →
         * Reviews & Filtering → Review Elements → Motion → Colors & Theme → SEO
         * → Sources. Layout sits right after the place search so the filtering /
         * elements sections below adapt to the chosen layout's capabilities.
         * (sort/keyword/hide-empty, theme/accent, autoplay, schema, cache are
         * all FREE now — Pro only gates review images + multi-place + premium
         * layouts.) */
        $this->register_display_controls();

        $this->register_pro_filtering_controls();

        /* ----- Header / Summary ----- (business name, rating, star row, count,
         * alignment). Parity with the Gutenberg block's Header controls. Only for
         * layouts whose header is optional; badge/knowledge force the header on. */
        $this->start_controls_section('ep_gr_header', [
            'label'     => esc_html__('Header', 'embedpress'),
            'condition' => ['ep_gr_layout' => self::header_optional_layouts()],
        ]);

        $this->add_control('ep_gr_show_summary', [
            'label'        => __('Show header', 'embedpress'),
            'type'         => Controls_Manager::SWITCHER,
            'default'      => 'yes',
            'label_on'     => __('On', 'embedpress'),
            'label_off'    => __('Off', 'embedpress'),
            'return_value' => 'yes',
        ]);

        foreach ([
            'ep_gr_show_summary_name'   => __('Business name', 'embedpress'),
            'ep_gr_show_summary_rating' => __('Rating score', 'embedpress'),
            'ep_gr_show_summary_stars'  => __('Star row', 'embedpress'),
            'ep_gr_show_summary_count'  => __('Review count', 'embedpress'),
        ] as $key => $label) {
            $this->add_control($key, [
                'label'        => $label,
                'type'         => Controls_Manager::SWITCHER,
                'default'      => 'yes',
                'label_on'     => __('On', 'embedpress'),
                'label_off'    => __('Off', 'embedpress'),
                'return_value' => 'yes',
                // Sub-toggles only matter when the header itself is shown.
                'condition'    => ['ep_gr_show_summary' => 'yes'],
            ]);
        }

        $this->add_control('ep_gr_summary_align', [
            'label'     => __('Header alignment', 'embedpress'),
            'type'      => Controls_Manager::CHOOSE,
            'default'   => 'left',
            'options'   => [
                'left'   => ['title' => __('Left', 'embedpress'),   'icon' => 'eicon-text-align-left'],
                'center' => ['title' => __('Center', 'embedpress'), 'icon' => 'eicon-text-align-center'],
                'right'  => ['title' => __('Right', 'embedpress'),  'icon' => 'eicon-text-align-right'],
            ],
            'condition' => ['ep_gr_show_summary' => 'yes'],
        ]);

        $this->end_controls_section();

        /* ----- Review Elements ----- (per-review toggles — only for layouts that
         * render a review list; summary-only badge/knowledge show no reviews). */
        $this->start_controls_section('ep_gr_elements', [
            'label'     => esc_html__('Review Elements', 'embedpress'),
            'condition' => ['ep_gr_layout' => self::review_layouts()],
        ]);

        foreach ([
            'ep_gr_show_photo'  => __('Reviewer photo', 'embedpress'),
            'ep_gr_show_stars'  => __('Star rating', 'embedpress'),
            'ep_gr_show_date'   => __('Date', 'embedpress'),
            'ep_gr_show_images' => __('Review images', 'embedpress'),
            'ep_gr_show_link'   => __('"View on Google" link', 'embedpress'),
        ] as $key => $label) {
            $args = [
                'label'        => $label,
                'type'         => Controls_Manager::SWITCHER,
                // "View on Google" link defaults OFF; the other elements default ON.
                'default'      => $key === 'ep_gr_show_link' ? '' : 'yes',
                'label_on'     => __('On', 'embedpress'),
                'label_off'    => __('Off', 'embedpress'),
                'return_value' => 'yes',
            ];
            // Review images (Pro) only apply to layouts with room for them — hide
            // the toggle for compact/sliding layouts that never render attached
            // photos, and gate it behind Pro (greyed + locked until Pro clears the
            // class).
            if ($key === 'ep_gr_show_images') {
                $args['condition'] = ['ep_gr_layout' => self::caps_layouts('images')];
                $args['label']     = sprintf('%1$s %2$s', $label, $this->pro_text);
                $args['classes']   = $this->pro_class;
            }
            $this->add_control($key, $args);
        }

        $this->end_controls_section();

        // Colors & Theme → SEO → Sources (Motion is registered earlier, before
        // Colors, to match the Gutenberg panel order).
        $this->register_pro_appearance_controls();
    }

    /**
     * Layout & Display section — the layout-tile picker + structural controls
     * (columns/slides/gap/max-width). Registered right after Place so the user
     * picks a layout first, then the filtering/elements sections below adapt to
     * the chosen layout's capabilities.
     */
    protected function register_display_controls()
    {
        /* ----- Layout & Display ----- */
        $this->start_controls_section('ep_gr_display', [
            'label' => esc_html__('Layout & Display', 'embedpress'),
        ]);

        // Icon-tile layout picker — mirrors the Gutenberg block's LayoutPicker.
        // Uses Controls_Manager::CHOOSE (same pattern Essential Addons uses, e.g.
        // Post Grid → layout_mode) with native Elementor eicons so the tiles
        // render crisply + flip correctly in dark/light editor themes. The tile
        // grid layout is styled in static/css/ep-gr-elementor-picker.css (scoped
        // to .elementor-control-ep_gr_layout). Pro layouts keep the "(Pro)"
        // suffix in their tooltip title; selecting one without Pro falls back to
        // the base layout server-side. When Pro is active $this->pro_text is ''.
        $pro = ($this->pro_text === '') ? '' : ' ' . wp_strip_all_tags($this->pro_text);
        // When Pro is inactive, tag the control so the picker CSS can badge the
        // Pro-only layout tiles with a "PRO" pill. Pro active → no badge.
        $layout_classes = ($this->pro_text === '') ? '' : 'ep-gr-layout-choose ep-gr-layout-choose--free';
        $this->add_control('ep_gr_layout', [
            'label'   => __('Layout', 'embedpress'),
            'type'    => Controls_Manager::CHOOSE,
            'toggle'  => false,
            'default' => 'list',
            'classes' => $layout_classes,
            'options' => [
                'list'      => ['title' => __('List', 'embedpress'),                         'icon' => 'eicon-post-list'],
                'grid'      => ['title' => __('Grid', 'embedpress'),                         'icon' => 'eicon-posts-grid'],
                'card'      => ['title' => __('Card', 'embedpress'),                         'icon' => 'eicon-image-box'],
                'carousel'  => ['title' => __('Carousel', 'embedpress'),                     'icon' => 'eicon-posts-carousel'],
                'masonry'   => ['title' => __('Masonry', 'embedpress') . $pro,               'icon' => 'eicon-gallery-masonry'],
                'badge'     => ['title' => __('Compact badge', 'embedpress') . $pro,         'icon' => 'eicon-rating'],
                'spotlight' => ['title' => __('Spotlight', 'embedpress') . $pro,             'icon' => 'eicon-featured-image'],
                'knowledge' => ['title' => __('Knowledge panel', 'embedpress') . $pro,       'icon' => 'eicon-info-box'],
                'marquee'   => ['title' => __('Marquee (auto-scroll)', 'embedpress') . $pro, 'icon' => 'eicon-posts-ticker'],
                'bubble'    => ['title' => __('Bubble', 'embedpress') . $pro,                'icon' => 'eicon-testimonial'],
            ],
        ]);

        // NOTE: Minimum rating, Reviews per page, and "Load more" moved to the
        // Reviews & Filtering section (register_pro_filtering_controls) — they
        // filter/limit which reviews show, not the layout. Parity with the block.

        // Structural controls (free), each gated by the capability matrix so a
        // layout only shows the spacing controls it actually uses. Columns has a
        // dynamic label: grid-type layouts call it "Columns", sliders call it
        // "Slides per view". Elementor can't relabel one control by layout, so we
        // register two — a grid one and a slider one — both feeding the same
        // render arg (mapped in get_render_args / elementor_render_args).
        $columns_grid   = array_values(array_diff(self::caps_layouts('columns'), self::SLIDES_PER_VIEW_LAYOUTS));
        $columns_slider = array_values(array_intersect(self::caps_layouts('columns'), self::SLIDES_PER_VIEW_LAYOUTS));

        $this->add_control('ep_gr_columns', [
            'label'       => __('Columns', 'embedpress'),
            'type'        => Controls_Manager::NUMBER,
            'default'     => 3,
            'min'         => 1,
            'max'         => 6,
            'description' => __('Number of columns. Collapses on smaller screens.', 'embedpress'),
            'condition'   => ['ep_gr_layout' => $columns_grid],
        ]);
        $this->add_control('ep_gr_slides', [
            'label'       => __('Slides per view', 'embedpress'),
            'type'        => Controls_Manager::NUMBER,
            'default'     => 3,
            'min'         => 1,
            'max'         => 6,
            'description' => __('How many review cards are shown at once.', 'embedpress'),
            'condition'   => ['ep_gr_layout' => $columns_slider],
        ]);

        $this->add_control('ep_gr_gap', [
            'label'     => __('Gap (px)', 'embedpress'),
            'type'      => Controls_Manager::NUMBER,
            'default'   => 20,
            'min'       => 0,
            'max'       => 80,
            'condition' => ['ep_gr_layout' => self::caps_layouts('gap')],
        ]);

        // Max width — review-list layouts only (summary-only badge/knowledge are
        // a single compact card; their width isn't user-controlled here).
        $this->add_control('ep_gr_max_width', [
            'label'       => __('Max width (px)', 'embedpress'),
            'type'        => Controls_Manager::NUMBER,
            'default'     => 0,
            'min'         => 0,
            'max'         => 1600,
            'description' => __('Cap the block width and center it. 0 = full width.', 'embedpress'),
            'condition'   => ['ep_gr_layout' => self::review_layouts()],
        ]);

        $this->end_controls_section();
    }

    /**
     * Reviews & Filtering — Pro controls, defined in the free widget and marked
     * with $this->pro_class so they render greyed + locked until Pro clears the
     * class. Values map to renderer args in get_render_args().
     */
    protected function register_pro_filtering_controls()
    {
        $this->start_controls_section('ep_gr_pro_filtering', [
            'label'     => __('Reviews & Filtering', 'embedpress'),
            // Sort/keyword/hide-empty operate on the review list — review layouts only.
            'condition' => ['ep_gr_layout' => self::review_layouts()],
        ]);

        // The controls form a PIPELINE top to bottom: FILTER (min rating, keyword,
        // hide-empty) -> ORDER (sort) -> SHOW (reviews per page). Descriptions make
        // that explicit so the controls don't read as overlapping/conflicting.

        // 1. FILTER — Minimum rating (PRO). "Show only 5★" — the highest-demand
        // filter; exclusive Pro (free ships keyword + hide-empty). Greyed + "(Pro)"
        // until Pro clears pro_class.
        $this->add_control('ep_gr_min_rating', [
            'label'       => sprintf('%1$s %2$s', __('Minimum rating', 'embedpress'), $this->pro_text),
            'type'        => Controls_Manager::SELECT,
            'default'     => '0',
            'classes'     => $this->pro_class,
            'description' => __('Filter: only reviews at or above this rating are eligible to show.', 'embedpress'),
            'options'     => [
                '0' => __('Any', 'embedpress'),
                '3' => '3+',
                '4' => '4+',
                '5' => '5',
            ],
        ]);
        $this->add_control('ep_gr_keyword', [
            'label'       => __('Keyword filter', 'embedpress'),
            'type'        => Controls_Manager::TEXT,
            'placeholder' => __('e.g. service, staff, clean', 'embedpress'),
            'description' => __('Filter: only reviews whose text contains this word.', 'embedpress'),
        ]);
        $this->add_control('ep_gr_hide_empty', [
            'label'        => __('Hide reviews with no text', 'embedpress'),
            'type'         => Controls_Manager::SWITCHER,
            'return_value' => 'yes',
        ]);

        // 2. ORDER — Sort the eligible (filtered) reviews. FREE.
        $this->add_control('ep_gr_sort', [
            'label'       => __('Sort by', 'embedpress'),
            'type'        => Controls_Manager::SELECT,
            'default'     => 'newest',
            'description' => __('Order of the filtered reviews (applied after the filters above).', 'embedpress'),
            'options'     => [
                'newest'   => __('Newest', 'embedpress'),
                'highest'  => __('Highest rated', 'embedpress'),
                'lowest'   => __('Lowest rated', 'embedpress'),
                'relevant' => __('Most relevant', 'embedpress'),
            ],
        ]);

        // 3. SHOW — how many of the filtered + sorted reviews to render. Honored
        // only by stacked, paginatable layouts (caps.load_more). Free shows up to
        // 10; lifts to 50 once the EmbedPress API is connected.
        $reviews_max = \EmbedPress\Includes\Classes\GoogleReviewsManaged::is_connected() ? 50 : 10;
        $this->add_control('ep_gr_limit', [
            'label'       => __('Reviews per page', 'embedpress'),
            'type'        => Controls_Manager::NUMBER,
            'default'     => 10,
            'min'         => 1,
            'max'         => $reviews_max,
            'description' => $reviews_max > 10
                ? __('How many of the filtered, sorted reviews to show (the page size when “Load more” is on).', 'embedpress')
                : __('Show up to 10 reviews per place. Connect the EmbedPress API in EmbedPress → Google Reviews to show more.', 'embedpress'),
            'condition'   => ['ep_gr_layout' => self::caps_layouts('load_more')],
        ]);

        // "Load more" pairs with "Reviews per page" — the count IS the page size.
        // FREE (frontend pagination). Only for paginatable layouts.
        $this->add_control('ep_gr_load_more', [
            'label'        => __('Load More', 'embedpress'),
            'type'         => Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default'      => 'yes',
            'description'  => __('Reveals reviews a page at a time. Page size = the “Reviews per page” count above.', 'embedpress'),
            'condition'    => ['ep_gr_layout' => self::caps_layouts('load_more')],
        ]);

        $this->end_controls_section();
    }

    /**
     * Style / SEO / Sources — Pro controls, defined in the free widget, marked
     * with $this->pro_class. Mirrors the Gutenberg block's locked Pro panels.
     */
    protected function register_pro_appearance_controls()
    {
        /* Motion — caps-driven, mirrors the Gutenberg "Motion" panel. One section
         * holding the slider nav (arrows/dots/loop, free), the autoplay toggle
         * (+ speed, Pro), and the marquee scroll-speed (free). The section shows
         * only for layouts with at least one motion cap; each control inside is
         * further gated to the exact layouts that use it. */
        $motion_layouts = array_values(array_unique(array_merge(
            self::caps_layouts('slider'),
            self::caps_layouts('autoplay'),
            self::caps_layouts('speed')
        )));
        $this->start_controls_section('ep_gr_motion', [
            'label'     => __('Motion', 'embedpress'),
            'condition' => ['ep_gr_layout' => $motion_layouts],
        ]);
        // Slider navigation (free) — carousel-style arrows/dots/loop.
        $this->add_control('ep_gr_show_arrows', [
            'label'        => __('Show arrows', 'embedpress'),
            'type'         => Controls_Manager::SWITCHER,
            'default'      => 'yes',
            'return_value' => 'yes',
            'condition'    => ['ep_gr_layout' => self::caps_layouts('slider')],
        ]);
        $this->add_control('ep_gr_show_dots', [
            'label'        => __('Show dots', 'embedpress'),
            'type'         => Controls_Manager::SWITCHER,
            'default'      => 'yes',
            'return_value' => 'yes',
            'condition'    => ['ep_gr_layout' => self::caps_layouts('slider')],
        ]);
        $this->add_control('ep_gr_loop', [
            'label'        => __('Infinite loop', 'embedpress'),
            'type'         => Controls_Manager::SWITCHER,
            'default'      => 'yes',
            'return_value' => 'yes',
            'description'  => __('Slide seamlessly from the last review back to the first.', 'embedpress'),
            'condition'    => ['ep_gr_layout' => self::caps_layouts('slider')],
        ]);
        // Autoplay (FREE — mirrors Essential Addons) — auto-advance + speed.
        $this->add_control('ep_gr_autoplay', [
            'label'        => __('Autoplay', 'embedpress'),
            'type'         => Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'condition'    => ['ep_gr_layout' => self::caps_layouts('autoplay')],
        ]);
        $this->add_control('ep_gr_autoplay_speed', [
            'label'      => __('Autoplay speed (seconds)', 'embedpress'),
            'type'       => Controls_Manager::NUMBER,
            'default'    => 5,
            'min'        => 1,
            'max'        => 30,
            'condition'  => [
                'ep_gr_layout'   => self::caps_layouts('autoplay'),
                'ep_gr_autoplay' => 'yes',
            ],
        ]);
        // Marquee scroll speed (free) — intuitive 1–10 scale, higher = faster.
        $this->add_control('ep_gr_scroll_speed', [
            'label'       => __('Scroll speed', 'embedpress'),
            'type'        => Controls_Manager::SLIDER,
            'default'     => ['size' => 4],
            'range'       => ['px' => ['min' => 1, 'max' => 10, 'step' => 1]],
            'description' => __('Marquee scroll speed, from 1 (slowest) to 10 (fastest).', 'embedpress'),
            'condition'   => ['ep_gr_layout' => self::caps_layouts('speed')],
        ]);
        $this->end_controls_section();

        /* Colors & Theme — purely cosmetic skinning (theme + accent color). FREE
         * (mirrors Essential Addons). Placed AFTER Motion to match the Gutenberg
         * panel order. */
        $this->start_controls_section('ep_gr_pro_style', ['label' => __('Colors & Theme', 'embedpress')]);
        $this->add_control('ep_gr_theme', [
            'label'   => __('Theme', 'embedpress'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'light',
            'options' => ['light' => __('Light', 'embedpress'), 'dark' => __('Dark', 'embedpress')],
        ]);
        $this->add_control('ep_gr_accent_color', [
            'label'   => __('Accent color', 'embedpress'),
            'type'    => Controls_Manager::COLOR,
        ]);
        $this->end_controls_section();

        /* SEO / Rich Snippets */
        $this->start_controls_section('ep_gr_pro_seo', ['label' => __('SEO / Rich Snippets', 'embedpress')]);
        // JSON-LD schema is FREE (mirrors Essential Addons' Local Business schema).
        $this->add_control('ep_gr_schema', [
            'label'        => __('Output review schema (JSON-LD)', 'embedpress'),
            'type'         => Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'description'  => __('Adds AggregateRating + Review structured data so search engines can show star ratings.', 'embedpress'),
        ]);
        $this->end_controls_section();

        /* Sources & Caching */
        $this->start_controls_section('ep_gr_pro_sources', ['label' => __('Sources & Caching', 'embedpress')]);

        // Cache duration is FREE (mirrors Essential Addons' "Data Cache Time").
        $this->add_control('ep_gr_cache_ttl', [
            'label'       => __('Cache duration (hours)', 'embedpress'),
            'type'        => Controls_Manager::NUMBER,
            'default'     => 0,
            'min'         => 0,
            'max'         => 168,
            'description' => __('0 = use the global setting.', 'embedpress'),
        ]);

        // Multi-place merge (repeater) — PRO. Parity with the Gutenberg block.
        $place_repeater = new \Elementor\Repeater();
        $place_repeater->add_control('place_id', [
            'label'       => __('Place ID', 'embedpress'),
            'type'        => Controls_Manager::TEXT,
            'placeholder' => 'ChIJ…',
            'label_block' => true,
        ]);
        $this->add_control('ep_gr_places', [
            'label'         => sprintf('%1$s %2$s', __('Additional places', 'embedpress'), $this->pro_text),
            'type'          => Controls_Manager::REPEATER,
            'classes'       => $this->pro_class,
            'fields'        => $place_repeater->get_controls(),
            'prevent_empty' => false,
            'title_field'   => '{{{ place_id }}}',
            'description'   => __('Merge reviews from more than one Google place (by Place ID).', 'embedpress'),
        ]);
        $this->end_controls_section();
    }

    /**
     * (Removed) The old whole-section upsell builder. Pro controls are now
     * defined inline in register_pro_filtering_controls() /
     * register_pro_appearance_controls(), gated per-control by $this->pro_class —
     * the canonical EmbedPress pattern. This stub is intentionally empty and kept
     * only so any stale external reference no-ops instead of fatal-erroring.
     */
    protected function register_pro_upsell_sections()
    {
        $groups = [];

        foreach ($groups as $id => $g) {
            $this->start_controls_section($id, ['label' => $g['label']]);
            $this->add_control($id . '_note', [
                'type'            => Controls_Manager::RAW_HTML,
                'raw'             => '',
                'content_classes' => 'ep-gr-elementor-upsell-wrap',
            ]);
            $this->end_controls_section();
        }
    }

    protected function render()
    {
        $s = $this->get_settings_for_display();

        GoogleReviewsRenderer::enqueue_assets();

        $place = isset($s['ep_gr_place']) && is_array($s['ep_gr_place']) ? $s['ep_gr_place'] : [];

        $args = [
            'place_id'   => isset($place['place_id']) ? sanitize_text_field($place['place_id']) : '',
            'place_name' => isset($place['place_name']) ? sanitize_text_field($place['place_name']) : '',
            'limit'      => isset($s['ep_gr_limit']) ? (int) $s['ep_gr_limit'] : 10,
            'min_rating' => isset($s['ep_gr_min_rating']) ? (int) $s['ep_gr_min_rating'] : 0,
            'layout'     => isset($s['ep_gr_layout']) ? sanitize_key($s['ep_gr_layout']) : 'list',
            'show_photo'  => ($s['ep_gr_show_photo'] ?? 'yes') === 'yes',
            'show_stars'  => ($s['ep_gr_show_stars'] ?? 'yes') === 'yes',
            'show_date'   => ($s['ep_gr_show_date'] ?? 'yes') === 'yes',
            'show_images' => ($s['ep_gr_show_images'] ?? 'yes') === 'yes',
            'show_link'   => ($s['ep_gr_show_link'] ?? '') === 'yes',
            // Header / summary (parity with the Gutenberg block). Default ON to
            // match the renderer defaults, so existing widgets keep their header.
            'show_summary'        => ($s['ep_gr_show_summary'] ?? 'yes') === 'yes',
            'show_summary_name'   => ($s['ep_gr_show_summary_name'] ?? 'yes') === 'yes',
            'show_summary_rating' => ($s['ep_gr_show_summary_rating'] ?? 'yes') === 'yes',
            'show_summary_stars'  => ($s['ep_gr_show_summary_stars'] ?? 'yes') === 'yes',
            'show_summary_count'  => ($s['ep_gr_show_summary_count'] ?? 'yes') === 'yes',
            'summary_align'       => isset($s['ep_gr_summary_align']) ? sanitize_key($s['ep_gr_summary_align']) : 'left',
            // Columns: sliders read the "Slides per view" control, grid-type
            // layouts read "Columns" — both feed the same render arg.
            'columns'    => self::columns_arg($s),
            'gap'        => isset($s['ep_gr_gap']) ? (int) $s['ep_gr_gap'] : 20,
            'max_width'  => isset($s['ep_gr_max_width']) ? (int) $s['ep_gr_max_width'] : 0,
            // Motion (free): slider nav + scroll speed. Marquee scroll speed and
            // the autoplay speed share the renderer's `autoplay_speed` arg.
            'show_arrows'    => ($s['ep_gr_show_arrows'] ?? 'yes') === 'yes',
            'show_dots'      => ($s['ep_gr_show_dots'] ?? 'yes') === 'yes',
            'carousel_loop'  => ($s['ep_gr_loop'] ?? 'yes') === 'yes',
            // Speed: marquee uses the 1–10 "Scroll speed" SLIDER; carousel/
            // spotlight use the "Autoplay speed (seconds)" NUMBER. Both flow
            // through the renderer's autoplay_speed arg (each layout's JS reads it
            // its own way), so resolve whichever applies.
            'autoplay_speed' => self::speed_arg($s),
            // FREE (mirrors Essential Addons): sort / keyword / hide-empty,
            // theme / accent, autoplay, JSON-LD schema, cache duration, load-more.
            // These map straight onto the renderer args here in the FREE widget
            // (the free GoogleReviewsRenderer implements the behaviour). Only
            // multi-place merge (`places`) stays Pro, mapped via the filter below.
            'sort'         => isset($s['ep_gr_sort']) ? sanitize_key($s['ep_gr_sort']) : 'newest',
            'keyword'      => isset($s['ep_gr_keyword']) ? sanitize_text_field($s['ep_gr_keyword']) : '',
            'hide_empty'   => ($s['ep_gr_hide_empty'] ?? '') === 'yes',
            'load_more'    => ($s['ep_gr_load_more'] ?? '') === 'yes',
            'theme'        => isset($s['ep_gr_theme']) ? sanitize_key($s['ep_gr_theme']) : 'light',
            'accent_color' => isset($s['ep_gr_accent_color']) ? sanitize_hex_color($s['ep_gr_accent_color']) : '',
            'autoplay'     => ($s['ep_gr_autoplay'] ?? '') === 'yes',
            'schema'       => ($s['ep_gr_schema'] ?? '') === 'yes',
            'cache_ttl'    => isset($s['ep_gr_cache_ttl']) ? ((int) $s['ep_gr_cache_ttl']) * HOUR_IN_SECONDS : 0,
        ];

        /**
         * Let Pro map its remaining Elementor control values onto the renderer
         * args — now only `places` (multi-place merge). The free controls above
         * are already mapped; Pro's elementor_render_args no longer re-maps them.
         */
        $args = (array) apply_filters('embedpress/google_reviews/elementor_render_args', $args, $s, $this);

        echo GoogleReviewsRenderer::render($args);
    }
}
