<?php

/**
 * Abstract display class for SiteMedia.
 *
 * @copyright 2026 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
abstract class SiteAbstractMediaDisplay extends SwatControl
{
    protected $skin;
    protected $stretching;
    protected $vtt_uri;
    protected $mute = false;
    protected $auto_start = false;
    protected $controls = true;
    protected $repeat = false;

    abstract public function setMedia(SiteVideoMedia $media);

    abstract public function setSession(SiteSessionModule $session);

    abstract public function addSource($uri, $width = '', $label = '');

    abstract public function addImage($uri, $width);

    public function setSkin($skin)
    {
        $this->skin = $skin;
    }

    public function setStretching($fit)
    {
        $valid_fits = ['none', 'exactfit', 'uniform', 'fill'];

        if ($fit !== null && $fit !== '' && !in_array($fit, $valid_fits)) {
            throw new SwatException('Stretching not valid');
        }

        $this->stretching = $fit;
    }

    public function setVttUri($uri)
    {
        $this->vtt_uri = $uri;
    }

    public function setMute($mute)
    {
        $this->mute = (bool) $mute;
    }

    public function setAutoStart($auto_start)
    {
        $this->auto_start = (bool) $auto_start;
    }

    public function setControls($controls)
    {
        $this->controls = (bool) $controls;
    }

    public function setRepeat($repeat)
    {
        $this->repeat = (bool) $repeat;
    }
}
