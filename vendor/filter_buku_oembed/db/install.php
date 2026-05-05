<?php

/**
 * Filter for component 'filter_buku_oembed'
 *
 * @package   filter_buku_oembed
 * @copyright 2024 BUKU B.V.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Installs the OEmbed filter.
 */
function xmldb_filter_buku_oembed_install() {
    global $CFG;

    filter_set_global_state('filter/buku_oembed', TEXTFILTER_ON);
}

