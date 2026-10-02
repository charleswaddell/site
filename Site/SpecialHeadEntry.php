<?php

class SpecialHeadEntry extends SwatHtmlHeadEntry
{
    protected function displayInternal($uri_prefix = '', $tag = null)
    {
        $uri = $this->uri;

        // append tag if it is set
        if ($tag !== null) {
            $uri
                = mb_strpos($uri, '?') === false
                    ? $uri . '?' . $tag
                    : $uri . '&' . $tag;
        }

        printf(
            '<script type="module" src="%s%s"></script>',
            $uri_prefix,
            $uri,
        );
    }

    protected function displayInlineInternal($path)
    {
        echo '<script type="module">';
        readfile($path . $this->getUri());
        echo '</script>';
    }
}
