<?php

/**
 * Filter for component 'filter_buku_oembed'
 *
 * @package   filter_buku_oembed
 * @copyright 2024 BUKU B.V.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrades the OEmbed filter.
 *
 * @param $oldversion Version to be upgraded from.
 * @return bool Success.
 */
function xmldb_filter_buku_oembed_upgrade($oldversion) {
    global $CFG, $DB, $OUTPUT;

    return true;
}
