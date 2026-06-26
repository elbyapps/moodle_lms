<?php

namespace filter_buku_oembed;

/**
 * Filter for processing HTML content containing links to media from buku.app
 *
 * @package filter_buku_oembed
 */
class text_filter extends \core_filters\text_filter
{
    public function filter($text, array $options = [])
    {
        if ($this->hasPerformanceShortcut($text)) {
            return $text;
        }

        return $this->applyFilter($text);
    }

    private function applyFilter($text)
    {
        $filteredText = $text;

        if (get_config('filter_buku_oembed', 'buku')) {
		// $search = '/<a\s[^>]*href="(https?:\/\/(www\.)?buku\.(io|app)\/(book|details|readinglist)\/([^"]+))[^>]*>(.*?)<\/a>/is';
            $search = '/<a\s[^>]*href="(https?:\/\/(www\.)?buku\.(io|app)\/(book|details|readinglist)\/([^"]+)|https?:\/\/buku\.(app)\/profile\/(?P<username>[^\/]+)\/readinglists\/(?P<readinglistId>\d+))[^>]*>(.*?)<\/a>/is';
            $filteredText = preg_replace_callback($search, array(&$this, 'filterOembedCallback'), $filteredText);
        }

        if (empty($filteredText) || $filteredText === $text) {

            unset($filteredText);
            return $text;
        }

        return $filteredText;
    }

    private function hasPerformanceShortcut($text): bool
    {
        if (empty($text) || !is_string($text)) {
            return true;
        }

        if (stripos($text, '</a>') === false) {
            return true;
        }

        return false;
    }

    private function filterOembedCallback($link)
    {
        $url = trim($link[1]);

        if (strpos($url, 'buku.app') !== false) {
            $json = $this->curlCall($url);


            $error = $this->handleErrors($json);
            if ($error === false) {
                $embedCode = $this->getEmbedCode($json);
                return $embedCode;
            }

            return $error;
        }

        return $link[0]; // Return the original link if not from buku.app
    }

    /**
     * Makes the OEmbed request to the service that supports the protocol.
     *
     * @param $url
     * @return mixed|null|string The HTTP response object from the OEmbed request.
     */
    private function curlCall($url)
    {
        global $CFG;
        $url = urlencode($url);
        $url = "https://api.buku.io/oembed?url=".$url."&format=json";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // return output as string.
        curl_setopt($ch, CURLOPT_REFERER, $CFG->wwwroot);
        $ret = curl_exec($ch);
        // Check if curl call fails.
        if (curl_error($ch)) {
            curl_close($ch);
            return null;
        }
        curl_close($ch);
        $result = json_decode($ret, true);
        return $result;
    }

    /**
     * Handles if the oembed service returned any error.
     *
     * @param $json
     * @return bool|string
     */
    private function handleErrors($json)
    {
        if ($json === null) {
            return '<h3>'. get_string('connection_error', 'filter_buku_oembed') .'</h3>';
        }

        if (!is_array($json) && preg_match('#^404|401|501#', $json)) {
            return "Resource could not be displayed: ".$json;
        }

        return false;
    }

    /**
     * Return the HTML content to be embedded given the response from the OEmbed request.
     * It returns the embeddable HTML from the OEmbed request.
     *
     * @param array $json Response object returned from the OEmbed request.
     * @return string The HTML content to be embedded in the page.
     */
    private function getEmbedCode($json)
    {
        return $json['html'];
    }
}
