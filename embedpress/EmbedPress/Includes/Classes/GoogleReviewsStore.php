<?php

namespace EmbedPress\Includes\Classes;

(defined('ABSPATH') && defined('EMBEDPRESS_IS_LOADED')) or die("No direct script access allowed.");

/**
 * DB-backed store for Google Reviews "places".
 *
 * One row per place (per entry). Adding a place anywhere — settings page, block,
 * or Elementor widget — saves it here globally, so a search that's already saved
 * just selects the existing row instead of re-adding/re-fetching.
 *
 * Reviews are FETCHED into this table explicitly (settings "Refresh", or a
 * one-time auto-fetch on first render) and READ from here on every render —
 * rendering does NO network calls. This decouples expensive fetching (rare,
 * deliberate) from cheap displaying (every page view).
 *
 * Schema (wp_embedpress_gr_places):
 *   id              PK
 *   place_id        Google Place ID (unique)
 *   place_name      display name
 *   meta            JSON {name, rating, total}
 *   reviews         JSON [ {author_name, rating, text, time, relative_time, profile_photo_url}, … ]
 *   review_count    cached count(reviews)
 *   source          'api' (≤5) | 'apify' (all) | '' (never fetched)
 *   last_fetched_at datetime, NULL = never fetched
 *   created_at      datetime
 *   updated_at      datetime
 */
class GoogleReviewsStore
{
    const DB_VERSION    = '1.1.0';
    const DB_VERSION_OPT = 'embedpress_gr_store_db_version';

    // fetch_status values
    const STATUS_IDLE    = 'idle';
    const STATUS_QUEUED  = 'queued';
    const STATUS_RUNNING = 'running';
    const STATUS_DONE    = 'done';
    const STATUS_FAILED  = 'failed';

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'embedpress_gr_places';
    }

    /**
     * Create/upgrade the table. Idempotent — safe to call on every request; it
     * short-circuits once the stored DB version matches.
     */
    public static function ensure_table(): void
    {
        if (get_option(self::DB_VERSION_OPT) === self::DB_VERSION) {
            return;
        }

        global $wpdb;
        $table           = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            place_id varchar(191) NOT NULL,
            place_name varchar(255) DEFAULT '',
            meta longtext DEFAULT NULL,
            reviews longtext DEFAULT NULL,
            review_count int(10) unsigned DEFAULT 0,
            source varchar(20) DEFAULT '',
            last_fetched_at datetime DEFAULT NULL,
            fetch_status varchar(20) DEFAULT 'idle',
            fetch_run_id varchar(191) DEFAULT NULL,
            fetch_message varchar(255) DEFAULT NULL,
            fetched_so_far int(10) unsigned DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_place_id (place_id),
            KEY idx_last_fetched_at (last_fetched_at),
            KEY idx_fetch_status (fetch_status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // dbDelta can skip column adds on existing tables in some configs — add
        // the job columns explicitly for upgrades from the 1.0.0 schema.
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `$table`");
        if (is_array($cols)) {
            $add = [];
            if (!in_array('fetch_status', $cols, true))   $add[] = "ADD COLUMN fetch_status varchar(20) DEFAULT 'idle' AFTER last_fetched_at";
            if (!in_array('fetch_run_id', $cols, true))   $add[] = "ADD COLUMN fetch_run_id varchar(191) DEFAULT NULL AFTER fetch_status";
            if (!in_array('fetch_message', $cols, true))  $add[] = "ADD COLUMN fetch_message varchar(255) DEFAULT NULL AFTER fetch_run_id";
            if (!in_array('fetched_so_far', $cols, true)) $add[] = "ADD COLUMN fetched_so_far int(10) unsigned DEFAULT 0 AFTER fetch_message";
            if (!empty($add)) {
                $wpdb->query("ALTER TABLE `$table` " . implode(', ', $add));
            }
        }

        update_option(self::DB_VERSION_OPT, self::DB_VERSION);
    }

    /**
     * Get one place row (assoc array) by place_id, or null.
     */
    public static function get(string $place_id): ?array
    {
        $place_id = trim($place_id);
        if ($place_id === '') {
            return null;
        }
        self::ensure_table();
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . self::table() . " WHERE place_id = %s", $place_id),
            ARRAY_A
        );
        return $row ? self::hydrate($row) : null;
    }

    public static function exists(string $place_id): bool
    {
        return self::get($place_id) !== null;
    }

    /**
     * Add a place if it doesn't already exist (lookup-first). Returns the row.
     * Does NOT fetch reviews — that's a separate, explicit step.
     */
    public static function add(string $place_id, string $place_name = '', string $address = ''): ?array
    {
        $place_id = trim($place_id);
        if ($place_id === '') {
            return null;
        }
        self::ensure_table();
        $address = sanitize_text_field($address);

        $existing = self::get($place_id);
        if ($existing) {
            global $wpdb;
            $patch = [];
            // Keep a friendlier name if we now have one and the row lacked it.
            if ($place_name !== '' && ($existing['place_name'] ?? '') === '') {
                $patch['place_name'] = sanitize_text_field($place_name);
                $existing['place_name'] = $patch['place_name'];
            }
            // Backfill the address into meta if we now have one and it's missing.
            if ($address !== '') {
                $meta = is_array($existing['meta'] ?? null) ? $existing['meta'] : [];
                if (empty($meta['address'])) {
                    $meta['address'] = $address;
                    $patch['meta'] = wp_json_encode($meta);
                    $existing['meta'] = $meta;
                }
            }
            if ($patch) {
                $wpdb->update(self::table(), $patch, ['place_id' => $place_id]);
            }
            return $existing;
        }

        global $wpdb;
        $wpdb->insert(self::table(), [
            'place_id'   => $place_id,
            'place_name' => sanitize_text_field($place_name),
            // Seed the address (location description) into meta on add so the
            // saved-place lists can show it without a fetch.
            'meta'       => wp_json_encode($address !== '' ? ['address' => $address] : []),
            'reviews'    => wp_json_encode([]),
            'review_count' => 0,
            'source'     => '',
            'created_at' => current_time('mysql'),
        ]);

        return self::get($place_id);
    }

    /**
     * Display fields captured from the place SEARCH result (name, address, total
     * review count, rating). These describe the place itself and are the source
     * of truth for the saved-place row — a later reviews FETCH (which only
     * supplies the review list) must never overwrite them. Used by merge_meta().
     */
    const SEEDED_META_KEYS = ['address', 'total', 'rating'];

    /**
     * Seed search-result display fields (total/rating/…) into a place's meta
     * without clobbering values already present. Called on add() so the row
     * shows the real numbers immediately, before any fetch runs.
     *
     * @param array $seed e.g. ['total' => 109, 'rating' => 4.0]
     */
    public static function seed_meta(string $place_id, array $seed): void
    {
        $place_id = trim($place_id);
        if ($place_id === '' || !self::exists($place_id)) {
            return;
        }
        $row  = self::get($place_id);
        $meta = (is_array($row) && is_array($row['meta'] ?? null)) ? $row['meta'] : [];
        $changed = false;
        foreach ($seed as $k => $v) {
            // Only fill blanks — never overwrite an existing non-empty value.
            if (($v !== null && $v !== '' && $v !== 0 && $v !== 0.0) && empty($meta[$k])) {
                $meta[$k] = $v;
                $changed = true;
            }
        }
        if ($changed) {
            global $wpdb;
            $wpdb->update(self::table(), ['meta' => wp_json_encode($meta)], ['place_id' => $place_id]);
        }
    }

    /**
     * Merge a FETCH's meta onto the stored meta, protecting the search-seeded
     * display fields (SEEDED_META_KEYS). The fetch supplies reviews; the place's
     * own info (address, total review count, rating) comes from the search
     * result and stays authoritative. A seeded field is only filled from the
     * fetch when it isn't already stored.
     *
     * @param array $incoming  meta from the fetch payload
     * @param array $existing  meta already stored for the place
     * @return array           the meta to persist
     */
    private static function merge_meta(array $incoming, array $existing): array
    {
        foreach (self::SEEDED_META_KEYS as $k) {
            if (!empty($existing[$k])) {
                // We already have it (from search/seed) — keep ours.
                $incoming[$k] = $existing[$k];
            }
            // else: leave whatever the fetch provided (may be empty — fine).
        }
        return $incoming;
    }

    /**
     * Persist a fetched review set + meta against a place. Creates the row if
     * needed. Called by the fetch service (settings refresh / first-render).
     */
    public static function save_reviews(string $place_id, array $reviews, array $meta, string $source): ?array
    {
        $place_id = trim($place_id);
        if ($place_id === '') {
            return null;
        }
        self::ensure_table();

        $name = (string) ($meta['name'] ?? '');
        // Defense in depth: scrapers occasionally pick up a page-region
        // header instead of the place name (e.g. "Results" when the
        // reviews tab takes a moment to hydrate). Don't overwrite a good
        // stored name with one of these obvious junk values.
        $junk_names = [
            'results', 'search', 'google maps', 'maps', 'google',
            'résultats', 'ergebnisse', 'risultati', 'resultados',
        ];
        $is_junk = in_array(strtolower(trim($name)), $junk_names, true);
        if ($is_junk) {
            $name = '';
        }

        if (!self::exists($place_id)) {
            self::add($place_id, $name);
        }

        // Look up the existing row so we can preserve the name + address through
        // a payload that doesn't carry them (above).
        $existing_name = '';
        $existing_meta = [];
        if (function_exists('current_time')) {
            $row = self::get($place_id);
            $existing_name = is_array($row) ? (string) ($row['place_name'] ?? '') : '';
            $existing_meta = (is_array($row) && is_array($row['meta'] ?? null)) ? $row['meta'] : [];
        }

        // Protect the search-seeded display fields (address, total, rating): the
        // fetch only supplies the review list — the place's own info comes from
        // the search result and stays authoritative. Without this, a fetch whose
        // meta omits the address/total/rating would WIPE what we captured on add
        // (the picker's secondary_text + review_count + rating).
        $meta = self::merge_meta($meta, $existing_meta);

        global $wpdb;
        $wpdb->update(self::table(), [
            'place_name'      => $name !== ''
                                    ? sanitize_text_field($name)
                                    : ($existing_name !== '' ? $existing_name : null),
            'meta'            => wp_json_encode($meta),
            'reviews'         => wp_json_encode(array_values($reviews)),
            'review_count'    => count($reviews),
            'source'          => sanitize_key($source),
            'last_fetched_at' => current_time('mysql'),
            'fetch_status'    => self::STATUS_DONE,
            'fetch_run_id'    => null,
            'fetch_message'   => null,
            'fetched_so_far'  => count($reviews),
        ], ['place_id' => $place_id]);

        return self::get($place_id);
    }

    /**
     * Mark a place's background fetch job state (status + optional run id /
     * message / progress count). Used by the Pro batch-fetch service + cron.
     */
    public static function set_job(string $place_id, string $status, array $extra = []): void
    {
        $place_id = trim($place_id);
        if ($place_id === '') {
            return;
        }
        self::ensure_table();
        if (!self::exists($place_id)) {
            self::add($place_id, (string) ($extra['place_name'] ?? ''));
        }
        $data = ['fetch_status' => sanitize_key($status)];
        if (array_key_exists('run_id', $extra))   $data['fetch_run_id']   = $extra['run_id'] !== null ? sanitize_text_field((string) $extra['run_id']) : null;
        if (array_key_exists('message', $extra))   $data['fetch_message']  = $extra['message'] !== null ? sanitize_text_field((string) $extra['message']) : null;
        if (array_key_exists('so_far', $extra))    $data['fetched_so_far'] = max(0, (int) $extra['so_far']);
        global $wpdb;
        $wpdb->update(self::table(), $data, ['place_id' => $place_id]);
    }

    /**
     * Append a batch of reviews to a place's stored set (deduped by the caller),
     * bumping review_count + fetched_so_far. For incremental background fetch.
     * Pass $final=true with meta/source to finalize the job.
     */
    public static function append_reviews(string $place_id, array $batch, array $meta = [], string $source = 'apify', bool $final = false): void
    {
        $place_id = trim($place_id);
        if ($place_id === '' || !self::exists($place_id)) {
            return;
        }
        $row     = self::get($place_id);
        $current = is_array($row['reviews']) ? $row['reviews'] : [];
        $merged  = self::dedupe(array_merge($current, array_values($batch)));

        // TIER-AWARE HARD CAP on the stored set. The worker overshoots its soft
        // `max` target (it scrolls in ~10-card batches), and the merge unions
        // across fetches — so without a clamp HERE a FREE place ends up storing
        // more than the free cap, and any place could exceed 1000. Clamp to the
        // effective tier cap: 10 for free, 1000 for Pro.
        // Keep the newest (tail = most recently appended).
        $is_pro   = class_exists('\\EmbedPress\\Includes\\Classes\\Helper')
            && \EmbedPress\Includes\Classes\Helper::is_pro_active();
        $free_cap = (int) apply_filters('embedpress/google_reviews/free_max_reviews', 10);
        $pro_cap  = (int) apply_filters('embedpress/google_reviews/max_stored_reviews', 1000);
        $cap      = $is_pro ? $pro_cap : $free_cap;
        if (count($merged) > $cap) {
            $merged = array_slice($merged, -$cap);
        }

        global $wpdb;
        $data = [
            'reviews'        => wp_json_encode($merged),
            'review_count'   => count($merged),
            'fetched_so_far' => count($merged),
        ];
        if (!empty($meta)) {
            // Protect search-seeded display fields (address/total/rating) — the
            // batched fetch supplies reviews, not the place's own info.
            $existing_meta = (is_array($row) && is_array($row['meta'] ?? null)) ? $row['meta'] : [];
            $meta = self::merge_meta($meta, $existing_meta);
            $data['meta'] = wp_json_encode($meta);
            if (!empty($meta['name'])) {
                $data['place_name'] = sanitize_text_field((string) $meta['name']);
            }
        }
        if ($final) {
            $data['source']          = sanitize_key($source);
            $data['last_fetched_at'] = current_time('mysql');
            $data['fetch_status']    = self::STATUS_DONE;
            $data['fetch_run_id']    = null;
        }
        $wpdb->update(self::table(), $data, ['place_id' => $place_id]);
    }

    /**
     * Clear a place's stored reviews (used when a fresh background fetch starts,
     * so a refresh REPLACES the set instead of stacking duplicates on top).
     */
    public static function reset_reviews(string $place_id): void
    {
        $place_id = trim($place_id);
        if ($place_id === '' || !self::exists($place_id)) {
            return;
        }
        global $wpdb;
        $wpdb->update(self::table(), [
            'reviews'        => wp_json_encode([]),
            'review_count'   => 0,
            'fetched_so_far' => 0,
        ], ['place_id' => $place_id]);
    }

    /**
     * Compute a stable per-review identity hash. Used to detect duplicates and
     * to early-stop incremental refetches (the first already-seen fingerprint
     * = we've caught up with Google).
     *
     * Inputs are deliberately stable across edits to the surrounding metadata
     * but DO change if the user edits their review text — that's the right
     * behaviour for "new review detection" but means an edited review looks
     * new (a periodic full refresh reconciles edits/deletes).
     */
    public static function fingerprint_of(array $review): string
    {
        return md5(
            ($review['author_name'] ?? '')
            . '|' . ($review['time'] ?? '')
            . '|' . substr((string) ($review['text'] ?? ''), 0, 80)
        );
    }

    /** Dedupe a review list by author + time + text fingerprint. */
    private static function dedupe(array $reviews): array
    {
        $seen = [];
        $out  = [];
        foreach ($reviews as $r) {
            if (!is_array($r)) {
                continue;
            }
            $key = self::fingerprint_of($r);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Return the set of fingerprints currently stored for a place. Used by the
     * incremental refetch to detect "we've caught up" — the moment a batch
     * contains a fingerprint already in this set, everything after it is
     * already in our DB (Apify returns newest-first).
     *
     * @return array<string, true> map of fingerprint → true (cheap set lookup)
     */
    public static function fingerprints_for(string $place_id): array
    {
        $row = self::get($place_id);
        $stored = ($row && is_array($row['reviews'] ?? null)) ? $row['reviews'] : [];
        $set = [];
        foreach ($stored as $r) {
            if (is_array($r)) {
                $set[self::fingerprint_of($r)] = true;
            }
        }
        return $set;
    }

    public static function remove(string $place_id): bool
    {
        $place_id = trim($place_id);
        if ($place_id === '') {
            return false;
        }
        self::ensure_table();
        global $wpdb;
        return (bool) $wpdb->delete(self::table(), ['place_id' => $place_id]);
    }

    /**
     * All saved places, newest first. Each row hydrated (reviews/meta decoded).
     * For the settings manager list.
     */
    public static function all(): array
    {
        self::ensure_table();
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM " . self::table() . " ORDER BY created_at DESC", ARRAY_A);
        return is_array($rows) ? array_map([self::class, 'hydrate'], $rows) : [];
    }

    /** Decode JSON columns into arrays. */
    private static function hydrate(array $row): array
    {
        $row['meta']    = isset($row['meta']) ? (json_decode($row['meta'], true) ?: []) : [];
        $row['reviews'] = isset($row['reviews']) ? (json_decode($row['reviews'], true) ?: []) : [];
        $row['review_count'] = (int) ($row['review_count'] ?? 0);
        return $row;
    }

    /** Drop the table (uninstall). */
    public static function drop_table(): void
    {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS " . self::table());
        delete_option(self::DB_VERSION_OPT);
    }
}
