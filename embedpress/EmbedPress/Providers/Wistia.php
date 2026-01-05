<?php

namespace EmbedPress\Providers;

use Embera\Provider\ProviderAdapter;
use Embera\Provider\ProviderInterface;
use Embera\Url;

(defined('ABSPATH') && defined('EMBEDPRESS_IS_LOADED')) or die("No direct script access allowed.");

/**
 * Wistia provider for EmbedPress.
 *
 * @package     EmbedPress
 * @subpackage  EmbedPress/Providers
 * @author      EmbedPress
 * @license     GPLv3 or later
 * @since       1.0.0
 */
class Wistia extends ProviderAdapter implements ProviderInterface
{
    protected static $hosts = [
        '*.wistia.com',
        'wistia.com'
    ];

    /** inline {@inheritdoc} */
    protected $allowedParams = [
        'maxwidth',
        'maxheight',
        'wstarttime',
        'wautoplay',
        'scheme',
        'captions',
        'playbutton',
        'smallplaybutton',
        'playbar',
        'resumable',
        'wistiafocus',
        'volumecontrol',
        'volume',
        'rewind',
        'wfullscreen',
    ];


    /** inline {@inheritdoc} */
    protected $httpsSupport = true;

    public function getAllowedParams()
    {
        return $this->allowedParams;
    }

    /** inline {@inheritdoc} */
    protected $responsiveSupport = true;

    public function __construct($url, array $config = [])
    {
        parent::__construct($url, $config);
        add_filter('embedpress_render_dynamic_content', [$this, 'fakeDynamicResponse'], 10, 2);
    }

    /**
     * Validates if the URL belongs to Wistia.
     *
     * @param Url $url
     * @return bool
     */
    public function validateUrl(Url $url)
    {
        $urlString = (string) $url;


        return (bool) (
            preg_match('~(?:\w+\.)?wistia\.com/embed/(iframe|playlists)/([^/]+)~i', $urlString) ||
            preg_match('~(?:\w+\.)?wistia\.com/medias/([^/]+)~i', $urlString)
        );
    }

    public function getVideoIDFromURL($url)
    {
        // https://fast.wistia.com/embed/medias/xf1edjzn92.jsonp
        // https://ostraining-1.wistia.com/medias/xf1edjzn92
        preg_match('#\/medias\\\?\/([a-z0-9]+)\.?#i', $url, $matches);

        $id = false;
        if (isset($matches[1])) {
            $id = $matches[1];
        }

        return $id;
    }

    public function enhance_wistia()
    {

        $options = $this->getParams();

        $embedOptions = new \stdClass;
        $embedOptions->videoFoam = false;

        // Fullscreen
        $embedOptions->fullscreenButton =
            isset($options['wfullscreen']) && (bool) $options['wfullscreen'];

        // Playbar
        $embedOptions->playbar =
            isset($options['playbar']) && (bool) $options['playbar'];

        // Small play button
        $embedOptions->smallPlayButton =
            isset($options['smallplaybutton']) && (bool) $options['smallplaybutton'];

        // Autoplay
        $embedOptions->autoPlay =
            isset($options['wautoplay']) && (bool) $options['wautoplay'];

        // Start time
        if (!empty($options['wstarttime'])) {
            $embedOptions->time = (int) $options['wstarttime'];
        }

        // Player color
        if (!empty($options['scheme'])) {
            $embedOptions->playerColor = $options['scheme'];
        }

        // Plugins
        $pluginsBaseURL = plugins_url(
            'assets/js/wistia/min',
            dirname(__DIR__) . '/embedpress-Wistia.php'
        );

        $pluginList = [];

        // Resumable
        $isResumableEnabled = !empty($options['resumable']);
        if ($isResumableEnabled) {
            $pluginList['resumable'] = [
                'src' => $pluginsBaseURL . '/resumable.min.js',
                'async' => false
            ];
        }

        // Autoplay + resumable fix
        if ($embedOptions->autoPlay && $isResumableEnabled) {
            $pluginList['fixautoplayresumable'] = [
                'src' => $pluginsBaseURL . '/fixautoplayresumable.min.js'
            ];
        }

        // Focus
        if (isset($options['wistiafocus'])) {
            $pluginList['dimthelights'] = [
                'src' => $pluginsBaseURL . '/dimthelights.min.js',
                'autoDim' => (bool) $options['wistiafocus']
            ];
            $embedOptions->focus = (bool) $options['wistiafocus'];
        }

        // Rewind
        if (!empty($options['rewind'])) {
            $embedOptions->rewindTime = 10;

            $pluginList['rewind'] = [
                'src' => $pluginsBaseURL . '/rewind.min.js'
            ];
        }

        $embedOptions->plugin = $pluginList;
        $embedOptions = json_encode($embedOptions);

        // Video ID
        $videoId = $this->getVideoIDFromURL($options['url']);
        $shortVideoId = substr($videoId, 0, 3);

        $class = [
            'wistia_embed',
            'wistia_async_' . $videoId
        ];

        $width  = $options['width']  ?? 640;
        $height = $options['height'] ?? 360;

        $attribs = [
            sprintf('id="wistia_%s"', $videoId),
            sprintf('class="%s"', implode(' ', $class)),
            sprintf('style="width:%spx; height:%spx;"', $width, $height)
        ];

        $html  = "<div class=\"embedpress-wrapper ose-wistia ose-uid-{$videoId} responsive we\">";
        $html .= '<script src="https://fast.wistia.com/assets/external/E-v1.js" async></script>';
        $html .= "<script>window._wq = window._wq || []; _wq.push({\"{$shortVideoId}\": {$embedOptions}});</script>";
        $html .= '<div ' . implode(' ', $attribs) . '></div>';
        $html .= '</div>';

        return $html;
    }

    public function fakeDynamicResponse($embed, $options = [])
    {


        $embedOptions = new \stdClass;
        $embedOptions->videoFoam = false;

        // Fullscreen
        $embedOptions->fullscreenButton =
            isset($options['wfullscreen']) && (bool) $options['wfullscreen'];

        // Playbar
        $embedOptions->playbar =
            isset($options['playbar']) && (bool) $options['playbar'];

        // Small play button
        $embedOptions->smallPlayButton =
            isset($options['smallplaybutton']) && (bool) $options['smallplaybutton'];

        // Autoplay
        $embedOptions->autoPlay =
            isset($options['wautoplay']) && (bool) $options['wautoplay'];

        // Start time
        if (!empty($options['wstarttime'])) {
            $embedOptions->time = (int) $options['wstarttime'];
        }

        // Player color
        if (!empty($options['scheme'])) {
            $embedOptions->playerColor = $options['scheme'];
        }

        // Plugins
        $pluginsBaseURL = plugins_url(
            'assets/js/wistia/min',
            dirname(__DIR__) . '/embedpress-Wistia.php'
        );

        $pluginList = [];

        // Resumable
        $isResumableEnabled = !empty($options['resumable']);
        if ($isResumableEnabled) {
            $pluginList['resumable'] = [
                'src' => $pluginsBaseURL . '/resumable.min.js',
                'async' => false
            ];
        }

        // Autoplay + resumable fix
        if ($embedOptions->autoPlay && $isResumableEnabled) {
            $pluginList['fixautoplayresumable'] = [
                'src' => $pluginsBaseURL . '/fixautoplayresumable.min.js'
            ];
        }

        // Focus
        if (isset($options['wistiafocus'])) {
            $pluginList['dimthelights'] = [
                'src' => $pluginsBaseURL . '/dimthelights.min.js',
                'autoDim' => (bool) $options['wistiafocus']
            ];
            $embedOptions->focus = (bool) $options['wistiafocus'];
        }

        // Rewind
        if (!empty($options['rewind'])) {
            $embedOptions->rewindTime = 10;

            $pluginList['rewind'] = [
                'src' => $pluginsBaseURL . '/rewind.min.js'
            ];
        }

        $embedOptions->plugin = $pluginList;
        $embedOptions = json_encode($embedOptions);

        // Video ID
        $videoId = $this->getVideoIDFromURL($options['url']);
        $shortVideoId = substr($videoId, 0, 3);

        $class = [
            'wistia_embed',
            'wistia_async_' . $videoId
        ];

        $attribs = [
            sprintf('id="wistia_%s"', $videoId),
            sprintf('class="%s"', implode(' ', $class)),
            sprintf('style="width:%spx; height:%spx;"', $options['width'], $options['height'])
        ];


        $html  = "<div class=\"embedpress-wrapper ose-wistia ose-uid-{$videoId} responsive we\">";
        $html .= '<script src="https://fast.wistia.com/assets/external/E-v1.js" async></script>';
        $html .= "<script>window._wq = window._wq || []; _wq.push({\"{$shortVideoId}\": {$embedOptions}});</script>";
        $html .= '<div ' . implode(' ', $attribs) . '></div>';
        $html .= '</div>';

        return $html;
    }


    /**
     * Generates a fake oEmbed response.
     *
     * @return array
     */
    public function fakeResponse()
    {

        return [
            'type'          => 'rich',
            'provider_name' => 'Wistia',
            'provider_url'  => 'https://wistia.com',
            'title'         => 'Wistia',
            'html'          => $this->enhance_wistia(),
        ];
    }

    /**
     * Fallback for modifyResponse, returns fakeResponse.
     *
     * @param array $response
     * @return array
     */
    public function modifyResponse(array $response = [])
    {
        return $this->fakeResponse();
    }
}
