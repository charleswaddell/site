<?php

/**
 * Display class for SiteMedia using Video.js.
 *
 * @copyright 2026 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
class SiteVideoJsMediaDisplay extends SiteAbstractMediaDisplay
{
    public $valid_mime_types;
    public $start_position = 0;
    public $record_end_point = false;
    public $on_complete_message;
    public $playback_rate_controls;
    public $has_captions = false;

    public function getRestartVideoText(): string
    {
        return 'Start From the Beginning';
    }

    public function getResumeVideoText(int $position): string
    {
        $minutes = floor(intdiv($position, 60));
        $seconds = $position % 60;

        return str_replace(
            ['%minutes%', '%seconds%'],
            [$minutes, ($seconds < 10 ? '0' : '') . $seconds],
            'Resume Where You Left Off (%minutes%:%seconds%)'
        );
    }

    /*
     * Whether or not to show the on-complete-message when the video loads
     *
     * This is useful if you want to remind the user they've seen the video
     * before.
     *
     * @var boolean
     */
    public $display_on_complete_message_on_load = false;

    protected $media;
    protected $sources = [];
    protected $images = [];
    protected $session;
    protected $aspect_ratio = [];

    protected $container_id;
    protected $player_id;
    protected $javascript_variable_name;

    /**
     * Creates a new widget.
     *
     * @param string $id a non-visible unique id for this widget
     */
    public function __construct($id = null)
    {
        parent::__construct();

        $yui = new SwatYUI(['swf', 'event', 'cookie']);
        $this->html_head_entry_set->addEntrySet($yui->getHtmlHeadEntrySet());

        // TODO: Make this work better
        $this->html_head_entry_set->addEntry(
            // new SpecialHeadEntry('packages/site/javascript/videojs/video.js'),
            new SpecialHeadEntry('packages/site/javascript/player.js'),
        );

        $this->addJavascript(
            'packages/site/javascript/site-video-js-media-display.js'
        );

        $this->addStylesheet(
            'packages/site/styles/site-jw-player-media-display.css'
        );
    }

    public function setMedia(SiteVideoMedia $media)
    {
        $this->media = $media;

        $binding = $media->getLargestVideoEncodingBinding();

        if ($binding === null) {
            throw new SiteException('No media encodings found');
        }

        if (count($this->aspect_ratio) == 0) {
            $this->setAspectRatio($binding->width, $binding->height);
        }

        if ($this->skin === null) {
            $this->setSkin($media->media_set->skin);
        }

        if ($this->vtt_uri === null) {
            $this->setVttUri('vtt/' . $media->id . '.vtt');
        }
    }

    public function setAspectRatio($width, $height)
    {
        $this->aspect_ratio = ['width' => $width, 'height' => $height];
    }

    public function addSource($uri, $width = '', $label = '')
    {
        $source = [];
        $source['uri'] = $uri;
        $source['width'] = $width;
        $source['label'] = $label;

        $this->sources[] = $source;
    }

    public function addImage($uri, $width)
    {
        $image = [];
        $image['uri'] = $uri;
        $image['width'] = $width;
        $this->images[] = $image;
    }

    public function setSession(SiteSessionModule $session)
    {
        $this->session = $session;
    }

    public function display()
    {
        parent::display();

        if ($this->media === null) {
            throw new SwatException('Media must be specified');
        }
        if ($this->media->media_set->private) {
            if ($this->session === null) {
                throw new SwatException('Private video, session must be set');
            }
            if (!$this->session->isActive()) {
                throw new SwatException(
                    'Private video, session must be active'
                );
            }
        }

        if ($this->session !== null && $this->media->media_set->private) {
            if (!isset($this->session->media_access)) {
                $this->session->media_access = new ArrayObject();
            }

            $this->session->media_access[$this->media->id] = true;
        }

        if ($this->valid_mime_types === null) {
            $this->valid_mime_types = $this->media->getMimeTypes();
        }

        if ($this->record_end_point) {
            $ajax = new XML_RPCAjax();
            $this->html_head_entry_set->addEntrySet(
                $ajax->getHtmlHeadEntrySet()
            );
        }

        echo '<video-player class="video-player-container">';

        $container_div = new SwatHtmlTag('div');
        $container_div->class = 'video-player';

        // Safari (iOS and OS X) will show a CC icon even if the SMIL file
        // only contains the scrubber image. Us a css class to hide it.
        $container_div->class .= ($this->has_captions)
            ? ' has-captions'
            : ' no-captions';

        $container_div->id = $this->getContainerId();
        $container_div->open();

        if ($this->controls) {
            echo '<video-compat-skin style="display: block; width: 100%; aspect-ratio: 16 / 9;">';
        }

        if (isset($this->sources[0])) {
            $source = $this->sources[0];

            $video = new SwatHtmlTag('hlsjs-video');
            $video->src = $source['uri'];

            if ($this->mute) {
                $video->muted = true;
            }

            if ($this->auto_start) {
                $video->autoplay = true;
            }

            if ($this->repeat) {
                $video->loop = true;
            }

            $video->open();
            $video->close();
        }

        foreach ($this->images as $image) {
            $img = new SwatHtmlTag('img');
            $img->src = $image['uri'];
            // $img->display();
        }

        if ($this->controls) {
            echo '</video-compat-skin>';
        }

        if (
            $this->start_position > 0
            && $this->start_position > $this->media->duration - 60
        ) {
            echo '<media-complete-container show_on_load>';
        } else {
            echo '<media-complete-container>';
        }

        echo '<media-resume-button resume_at="0">Watch Again</media-resume-button>';
        echo '</media-complete-container>';

        if (
            $this->start_position > 10
            && $this->start_position < $this->media->duration - 60
        ) {
            echo '<media-resume-container>';

            $resume = new SwatHtmlTag('media-resume-button');
            $resume->resume_at = $this->start_position;

            $resume->open();
            echo $this->getResumeVideoText($this->start_position);
            $resume->close();

            echo '<media-play-button>';
            echo $this->getRestartVideoText();
            echo '</media-play-button>';

            echo '</media-resume-container>';
        }

        $container_div->close();
        echo '</video-player>';

        Swat::displayInlineJavaScript($this->getJavascript());
    }

    public function getJavascriptVariableName()
    {
        if ($this->javascript_variable_name == '') {
            $this->setJavascriptVariableName(
                // TODO: Make this value better.
                sprintf('site_%s_new_media', $this->media->id)
            );
        }

        return $this->javascript_variable_name;
    }

    public function setJavascriptVariableName($javascript_variable_name)
    {
        $this->javascript_variable_name = $javascript_variable_name;
    }

    public function getContainerId()
    {
        if ($this->container_id == '') {
            $this->setContainerId('new_media_display_' . $this->media->id);
        }

        return $this->container_id;
    }

    public function setContainerId($container_id)
    {
        $this->container_id = $container_id;
    }

    public function getPlayerId()
    {
        if ($this->player_id == '') {
            $this->setPlayerId('new_media_display_container_' . $this->media->id);
        }

        return $this->player_id;
    }

    public function setPlayerId($player_id)
    {
        $this->player_id = $player_id;
    }

    protected function getJavascript()
    {
        $javascript = sprintf(
            "\tvar %s = new %s(%d, %s);\n",
            $this->getJavascriptVariableName(),
            $this->getJavascriptClassName(),
            $this->media->id,
            SwatString::quoteJavaScriptString($this->getContainerId())
        );

        $javascript .= sprintf(
            "\t%s.aspect_ratio = [%d, %d];\n",
            $this->getJavascriptVariableName(),
            $this->aspect_ratio['width'],
            $this->aspect_ratio['height']
        );

        if ($this->media->getInternalValue('scrubber_image') !== null) {
            $javascript .= sprintf(
                "\t%s.vtt_uri = %s;\n",
                $this->getJavascriptVariableName(),
                SwatString::quoteJavaScriptString(
                    $this->getVttUri()
                )
            );
        }

        foreach ($this->sources as $source) {
            $javascript .= sprintf(
                "\t%s.addSource(%s, %s, %s);\n",
                $this->getJavascriptVariableName(),
                SwatString::quoteJavaScriptString($source['uri']),
                ($source['width'] == '') ? "''" : $source['width'],
                SwatString::quoteJavaScriptString($source['label'])
            );
        }

        if ($this->skin !== null) {
            $javascript .= sprintf(
                "\t%s.skin = %s;\n",
                $this->getJavascriptVariableName(),
                SwatString::quoteJavaScriptString($this->skin)
            );
        }

        if ($this->stretching !== null) {
            $javascript .= sprintf(
                "\t%s.stretching = %s;\n",
                $this->getJavascriptVariableName(),
                SwatString::quoteJavaScriptString($this->stretching)
            );
        }

        if ($this->playback_rate_controls !== null) {
            $javascript .= sprintf(
                "\t%s.playback_rate_controls = %s;\n",
                $this->getJavascriptVariableName(),
                $this->playback_rate_controls ? 'true' : 'false'
            );
        }

        foreach ($this->images as $image) {
            $javascript .= sprintf(
                "\t%s.addImage(%s, %d);\n",
                $this->getJavascriptVariableName(),
                SwatString::quoteJavaScriptString($image['uri']),
                $image['width']
            );
        }

        if ($this->session !== null && $this->session->isActive()) {
            $javascript .= sprintf(
                "\t%s.record_end_point = %s;\n",
                $this->getJavascriptVariableName(),
                ($this->record_end_point) ? 'true' : 'false'
            );
        }

        if ($this->on_complete_message !== null) {
            $javascript .= sprintf(
                "\t%s.on_complete_message = %s;\n",
                $this->getJavascriptVariableName(),
                SwatString::quoteJavascriptString($this->on_complete_message)
            );
        }

        $javascript .= sprintf(
            "\t%s.upgrade_message = %s;\n",
            $this->getJavascriptVariableName(),
            SwatString::quoteJavascriptString(
                $this->getBrowserNotSupportedMessage(
                    $this->valid_mime_types
                )
            )
        );

        if ($this->display_on_complete_message_on_load) {
            $javascript .= sprintf(
                "\t%s."
                . "display_on_complete_message_on_load = true;\n",
                $this->getJavascriptVariableName()
            );
        }

        foreach ($this->valid_mime_types as $mime_type) {
            $javascript .= sprintf(
                "\t%s.addValidMimeType(%s);\n",
                $this->getJavascriptVariableName(),
                SwatString::quoteJavaScriptString($mime_type)
            );
        }

        return $javascript;
    }

    protected function getJavascriptClassName()
    {
        return 'SiteVideoJsMediaDisplay';
    }

    protected function getVttUri()
    {
        return $this->vtt_uri;
    }

    protected function getBrowserNotSupportedMessage($mime_types)
    {
        $codecs = [];
        foreach ($mime_types as $type) {
            $exploded_type = explode('/', $type);
            $codecs[] = array_pop($exploded_type);
        }

        return sprintf(
            'Videos on this site require either '
            . '<a href="https://en.wikipedia.org/wiki/HTML5_video" '
            . 'target="_blank">HTML5 video support</a> (%s %s) or '
            . '<a href="https://get.adobe.com/flashplayer/" target="_blank">'
            . 'Adobe Flash Player</a> (version 18 or higher). '
            . 'Please upgrade your browser and try again.',
            SwatString::toList($codecs, 'or'),
            ngettext('codec', 'codecs', count($codecs))
        );
    }
}
