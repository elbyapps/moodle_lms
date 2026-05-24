<?php

/**
 * oEmbed cache definitions
 *
 * @package   filter_buku_oembed
 * @copyright 2024 BUKU B.V.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
$definitions = array(
    'embeddata' => array(
        'mode' => cache_store::MODE_APPLICATION,
        'ttl' => HOURSECS,
    ),
);

