<?php

namespace EmbedPress\Includes\Classes;

(defined('ABSPATH')) or die("No direct script access allowed.");

/**
 * Handles PDF thumbnail generation and upload via AJAX.
 * Extracted from Embedpress_Pdf_Gallery to avoid Elementor dependency.
 */
class Pdf_Thumbnail_Handler
{
    /**
     * Attachment meta recording the last failed poster attempt.
     */
    const FAILED_META = '_ep_pdf_thumbnail_failed';

    /**
     * Transient caching whether this host's Imagick can rasterise PDFs.
     */
    const SUPPORT_TRANSIENT = 'embedpress_pdf_rasterise_support';

    /**
     * How long to wait before retrying a failed poster generation.
     */
    const RETRY_AFTER = WEEK_IN_SECONDS;

    /**
     * Cron hook that generates a poster out of band.
     */
    const BACKFILL_HOOK = 'embedpress_generate_pdf_poster';

    /**
     * Attachment meta marking a backfill as already queued.
     */
    const QUEUED_META = '_ep_pdf_thumbnail_queued';

    /**
     * Register the upload-time hook.
     *
     * Generating the poster once, when the PDF is uploaded, is what lets every
     * render surface (Gutenberg block, Elementor widget, shortcode, gallery,
     * lightbox) just read a cached image instead of each solving the problem
     * itself. Before this, nothing generated a poster at upload: the front end
     * fell back to rendering page 1 with PDF.js in the visitor's browser on
     * every page load — measured at 18 requests / 19.16MB / ~7s for a single
     * 18MB PDF, with no cache. See FB #84167.
     *
     * @return void
     */
    public static function init()
    {
        // Priority 20: after WP has built its own attachment metadata (which
        // already rasterises page 1 for PDFs on most hosts), so the common case
        // is a no-op and we only do work when WP produced nothing usable.
        add_filter('wp_generate_attachment_metadata', [__CLASS__, 'ensure_pdf_poster'], 20, 2);

        // Backfill for PDFs that predate upload-time generation. Runs out of
        // band so no visitor ever waits on a rasterise.
        add_action(self::BACKFILL_HOOK, [__CLASS__, 'generate_poster_attachment']);
    }

    /**
     * Ensure an uploaded PDF has a usable poster image.
     *
     * Hooked to wp_generate_attachment_metadata, so it runs once per upload
     * rather than per page view. Returns $metadata untouched in every case —
     * this only ever adds a cached thumbnail alongside it, and must never
     * interfere with WP's own metadata.
     *
     * @param array $metadata      Attachment metadata as built by WordPress.
     * @param int   $attachment_id Attachment being processed.
     * @return array The unmodified metadata.
     */
    public static function ensure_pdf_poster($metadata, $attachment_id)
    {
        if ('application/pdf' !== get_post_mime_type($attachment_id)) {
            return $metadata;
        }

        // WordPress already rasterised page 1 (Imagick present, PDF readable).
        // That is the normal path and needs nothing from us.
        if (!empty($metadata['sizes'])) {
            return $metadata;
        }

        // Already generated on a previous run.
        if (get_post_meta($attachment_id, '_ep_pdf_thumbnail_id', true)) {
            return $metadata;
        }

        self::generate_poster_attachment($attachment_id);

        return $metadata;
    }

    /**
     * Return an existing poster attachment ID, generating one if needed.
     *
     * The upload-time hook only covers PDFs uploaded after this feature
     * shipped. Every PDF already in the media library — and any whose upload
     * ran on a host where rasterising was momentarily unavailable — would
     * otherwise never get a poster, and every render surface would fall back
     * to rendering page 1 with PDF.js in the visitor's browser. That fallback
     * is what produced the torn/vertically-flipped preview in FB #83295 on
     * large documents, so backfilling here fixes the reported bug for existing
     * content rather than only for new uploads.
     *
     * Generation itself is NOT performed inline. Rasterising a large PDF takes
     * seconds (measured at 8.2s for an 18MB/29-page document), and this runs
     * during a front-end render, so doing it here would stall the visitor's
     * page load — a worse symptom than the flipped preview it fixes. Instead
     * the work is queued and this returns false for that first render, which
     * simply keeps the existing canvas fallback for one page view.
     *
     * Queueing is attempted at most once per RETRY_AFTER per attachment, so a
     * host that genuinely cannot rasterise PDFs does not schedule an event on
     * every page view.
     *
     * @param int $attachment_id PDF attachment ID.
     * @return int|false Poster attachment ID when one already exists, else false.
     */
    public static function get_or_create_poster($attachment_id)
    {
        $existing = get_post_meta($attachment_id, '_ep_pdf_thumbnail_id', true);
        if ($existing && wp_get_attachment_url($existing)) {
            return (int) $existing;
        }

        if ($existing) {
            // Stale pointer — the poster attachment was deleted.
            delete_post_meta($attachment_id, '_ep_pdf_thumbnail_id');
        }

        if ('application/pdf' !== get_post_mime_type($attachment_id)) {
            return false;
        }

        self::queue_backfill($attachment_id);

        return false;
    }

    /**
     * Schedule out-of-band poster generation for an existing PDF.
     *
     * @param int $attachment_id PDF attachment ID.
     * @return void
     */
    private static function queue_backfill($attachment_id)
    {
        $failed = get_post_meta($attachment_id, self::FAILED_META, true);
        if (!empty($failed['time']) && (time() - (int) $failed['time']) < self::RETRY_AFTER) {
            return;
        }

        $queued = (int) get_post_meta($attachment_id, self::QUEUED_META, true);
        if ($queued && (time() - $queued) < HOUR_IN_SECONDS) {
            return;
        }

        if (wp_next_scheduled(self::BACKFILL_HOOK, [$attachment_id])) {
            return;
        }

        // Cheap enough to run inline (cached for a day) and avoids scheduling
        // work that can never succeed on this host.
        if (!self::can_rasterise_pdf()) {
            return;
        }

        update_post_meta($attachment_id, self::QUEUED_META, time());
        wp_schedule_single_event(time(), self::BACKFILL_HOOK, [$attachment_id]);
    }

    /**
     * Rasterise page 1 of a PDF to a PNG attachment and cache its ID.
     *
     * This is the Imagick fallback that previously lived inline in
     * ajax_generate_pdf_thumbnail(), lifted out so it can be called from the
     * upload hook as well as the AJAX endpoint. Unlike the AJAX version it
     * returns a value instead of emitting JSON, and performs no capability or
     * nonce check — callers are responsible for authorisation. The upload hook
     * is inherently authorised (the user just uploaded the file).
     *
     * @param int $attachment_id PDF attachment ID.
     * @return int|false Thumbnail attachment ID, or false on failure.
     */
    public static function generate_poster_attachment($attachment_id)
    {
        if (!self::can_rasterise_pdf()) {
            self::mark_failed($attachment_id, 'Imagick cannot decode PDF (missing Ghostscript delegate or blocked by policy.xml)');
            return false;
        }

        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            self::mark_failed($attachment_id, 'Source file missing on disk');
            return false;
        }

        try {
            $imagick = new \Imagick();
            $imagick->setResolution(200, 200);
            $imagick->readImage($file_path . '[0]');
            $imagick->setImageFormat('png');
            $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            $imagick->setImageBackgroundColor('white');
            $imagick = self::flatten($imagick);
            $imagick->thumbnailImage(800, 0);

            $upload_dir = wp_upload_dir();
            $base_name  = pathinfo(basename($file_path), PATHINFO_FILENAME);
            $thumb_file = 'pdf-thumb-' . sanitize_file_name($base_name) . '.png';
            $thumb_path = trailingslashit($upload_dir['path']) . $thumb_file;

            $imagick->writeImage($thumb_path);
            $imagick->destroy();

            $thumb_id = wp_insert_attachment([
                'post_mime_type' => 'image/png',
                'post_title'     => sanitize_file_name($base_name) . ' - PDF Thumbnail',
                'post_content'   => '',
                'post_status'    => 'inherit',
            ], $thumb_path);

            if (is_wp_error($thumb_id)) {
                self::mark_failed($attachment_id, 'wp_insert_attachment failed: ' . $thumb_id->get_error_message());
                return false;
            }

            require_once ABSPATH . 'wp-admin/includes/image.php';
            wp_update_attachment_metadata($thumb_id, wp_generate_attachment_metadata($thumb_id, $thumb_path));
            update_post_meta($attachment_id, '_ep_pdf_thumbnail_id', $thumb_id);
            delete_post_meta($attachment_id, self::FAILED_META);
            delete_post_meta($attachment_id, self::QUEUED_META);

            return $thumb_id;
        } catch (\Exception $e) {
            self::mark_failed($attachment_id, $e->getMessage());
            return false;
        }
    }

    /**
     * Flatten the page onto its background.
     *
     * Imagick::flattenImages() is deprecated in ImageMagick 7 and emits a
     * deprecation notice on some builds; mergeImageLayers() is the supported
     * equivalent. Both are kept because the minimum supported Imagick version
     * predates mergeImageLayers().
     *
     * @param \Imagick $imagick
     * @return \Imagick
     */
    private static function flatten($imagick)
    {
        // setIteratorIndex is required before merging: readImage('file[0]') can
        // leave the iterator past page 0 on some Ghostscript builds, which
        // merges the wrong page.
        if (method_exists($imagick, 'setIteratorIndex')) {
            $imagick->setIteratorIndex(0);
        }

        if (method_exists($imagick, 'mergeImageLayers')) {
            return $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
        }

        return $imagick->flattenImages();
    }

    /**
     * Whether this host's Imagick can actually rasterise a PDF.
     *
     * class_exists('Imagick') is not sufficient: rasterising a PDF needs the
     * Ghostscript delegate, and many shared hosts ship Imagick but disable PDF
     * decoding in policy.xml (the CVE-2018-16509 hardening). On those hosts
     * readImage() throws NoDecodeDelegateForThisImageFormat, so every PDF fell
     * through to the browser <canvas> renderer. Result is cached for a day —
     * this shells out through Ghostscript and is far too slow to run per call.
     *
     * @return bool
     */
    public static function can_rasterise_pdf()
    {
        if (!class_exists('Imagick')) {
            return false;
        }

        $cached = get_transient(self::SUPPORT_TRANSIENT);
        if (false !== $cached) {
            return 'yes' === $cached;
        }

        $supported = false;
        try {
            $formats = \Imagick::queryFormats('PDF');
            if (!empty($formats)) {
                // queryFormats() only reports the coder is compiled in, not that
                // the delegate works, so actually rasterise a minimal PDF.
                $probe = new \Imagick();
                $probe->setResolution(18, 18);
                $probe->readImageBlob(self::probe_pdf());
                $supported = $probe->getImageWidth() > 0;
                $probe->destroy();
            }
        } catch (\Exception $e) {
            $supported = false;
        }

        set_transient(self::SUPPORT_TRANSIENT, $supported ? 'yes' : 'no', DAY_IN_SECONDS);

        return $supported;
    }

    /**
     * Smallest valid one-page PDF, used to probe the Ghostscript delegate.
     *
     * @return string
     */
    private static function probe_pdf()
    {
        return "%PDF-1.4\n"
            . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 9 9]>>endobj\n"
            . "trailer<</Root 1 0 R>>\n";
    }

    /**
     * Record why poster generation failed.
     *
     * Stored on the attachment (not just logged) so the retry path can tell
     * "never attempted" from "attempted and impossible on this host" and stop
     * re-running an expensive rasterise on every page view.
     *
     * @param int    $attachment_id
     * @param string $reason
     * @return void
     */
    private static function mark_failed($attachment_id, $reason)
    {
        update_post_meta($attachment_id, self::FAILED_META, [
            'reason' => substr((string) $reason, 0, 500),
            'time'   => time(),
        ]);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('EmbedPress: PDF poster generation failed for attachment %d — %s', $attachment_id, $reason));
        }
    }

    /**
     * AJAX handler: generate a thumbnail for a PDF attachment.
     */
    public static function ajax_generate_pdf_thumbnail()
    {
        check_ajax_referer('ep_pdf_gallery_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;
        if (!$attachment_id) {
            wp_send_json_error(['message' => 'Invalid attachment ID']);
        }

        // Verify it's a PDF
        if (get_post_mime_type($attachment_id) !== 'application/pdf') {
            wp_send_json_error(['message' => 'Not a PDF file']);
        }

        // Check cached thumbnail first (stored as post meta on the PDF attachment)
        $cached_thumb_id = get_post_meta($attachment_id, '_ep_pdf_thumbnail_id', true);
        if ($cached_thumb_id) {
            $cached_url = wp_get_attachment_url($cached_thumb_id);
            if ($cached_url) {
                wp_send_json_success([
                    'url' => $cached_url,
                    'id'  => (int) $cached_thumb_id,
                ]);
                return;
            }
            // Cached thumbnail attachment no longer exists, clear stale meta
            delete_post_meta($attachment_id, '_ep_pdf_thumbnail_id');
        }

        // Method 1: WordPress already generated a preview during upload
        $thumb_src = wp_get_attachment_image_src($attachment_id, 'medium');
        if ($thumb_src && !empty($thumb_src[0])) {
            wp_send_json_success([
                'url' => $thumb_src[0],
                'id'  => $attachment_id,
            ]);
            return;
        }

        // Method 2: Try regenerating attachment metadata (triggers WP PDF preview)
        $file_path = get_attached_file($attachment_id);
        if ($file_path && file_exists($file_path)) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
            if (!empty($metadata)) {
                wp_update_attachment_metadata($attachment_id, $metadata);
                $thumb_src = wp_get_attachment_image_src($attachment_id, 'medium');
                if ($thumb_src && !empty($thumb_src[0])) {
                    wp_send_json_success([
                        'url' => $thumb_src[0],
                        'id'  => $attachment_id,
                    ]);
                    return;
                }
            }
        }

        // Method 3: Direct Imagick rendering as last resort. Shares the single
        // generator so hardening applied there (delegate probe, iterator reset,
        // failure logging) covers this path too.
        $thumb_id = self::generate_poster_attachment($attachment_id);
        if ($thumb_id) {
            wp_send_json_success([
                'url' => wp_get_attachment_url($thumb_id),
                'id'  => $thumb_id,
            ]);
            return;
        }

        wp_send_json_error(['message' => 'Could not generate thumbnail']);
    }

    /**
     * AJAX handler: receive a client-rendered thumbnail (base64 PNG) and save as WP attachment.
     */
    public static function ajax_upload_pdf_thumbnail()
    {
        check_ajax_referer('ep_pdf_gallery_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $image_data    = isset($_POST['image_data']) ? $_POST['image_data'] : '';
        $pdf_url       = isset($_POST['pdf_url']) ? esc_url_raw($_POST['pdf_url']) : '';
        $file_name     = isset($_POST['file_name']) ? sanitize_file_name($_POST['file_name']) : '';
        $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;

        if (empty($image_data) || empty($pdf_url)) {
            wp_send_json_error(['message' => 'Missing data']);
        }

        // Check cached thumbnail for this PDF attachment
        if ($attachment_id) {
            $cached_thumb_id = get_post_meta($attachment_id, '_ep_pdf_thumbnail_id', true);
            if ($cached_thumb_id) {
                $cached_url = wp_get_attachment_url($cached_thumb_id);
                if ($cached_url) {
                    wp_send_json_success([
                        'url' => $cached_url,
                        'id'  => (int) $cached_thumb_id,
                    ]);
                    return;
                }
                // Stale meta, clear it
                delete_post_meta($attachment_id, '_ep_pdf_thumbnail_id');
            }
        }

        // Strip data-URI prefix
        $image_data = preg_replace('/^data:image\/\w+;base64,/', '', $image_data);
        $decoded    = base64_decode($image_data);
        if (!$decoded || strlen($decoded) < 8) {
            wp_send_json_error(['message' => 'Invalid image data']);
        }

        // Detect MIME from raw bytes
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->buffer($decoded);
        if ($mime !== 'image/png' && $mime !== 'image/jpeg') {
            wp_send_json_error(['message' => 'Invalid image format']);
        }

        $ext        = ($mime === 'image/jpeg') ? '.jpg' : '.png';
        $upload_dir = wp_upload_dir();
        $base_name  = $file_name ? pathinfo($file_name, PATHINFO_FILENAME) : md5($pdf_url);
        $thumb_file = 'pdf-thumb-' . sanitize_file_name($base_name) . $ext;
        $thumb_path = trailingslashit($upload_dir['path']) . $thumb_file;

        // Avoid overwriting existing files
        $counter = 0;
        while (file_exists($thumb_path)) {
            $counter++;
            $thumb_file = 'pdf-thumb-' . sanitize_file_name($base_name) . '-' . $counter . $ext;
            $thumb_path = trailingslashit($upload_dir['path']) . $thumb_file;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($thumb_path, $decoded);

        $attachment = [
            'post_mime_type' => $mime,
            'post_title'    => sanitize_file_name($base_name) . ' - PDF Thumbnail',
            'post_content'  => '',
            'post_status'   => 'inherit',
        ];

        $thumb_id = wp_insert_attachment($attachment, $thumb_path);
        if (is_wp_error($thumb_id)) {
            wp_send_json_error(['message' => 'Failed to create attachment']);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $meta = wp_generate_attachment_metadata($thumb_id, $thumb_path);
        wp_update_attachment_metadata($thumb_id, $meta);

        // Cache thumbnail ID on the PDF attachment for future lookups
        if ($attachment_id) {
            update_post_meta($attachment_id, '_ep_pdf_thumbnail_id', $thumb_id);
        }

        wp_send_json_success([
            'url' => wp_get_attachment_url($thumb_id),
            'id'  => $thumb_id,
        ]);
    }
}
