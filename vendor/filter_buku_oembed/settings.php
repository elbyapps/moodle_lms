<?php

/**
 * Filter for component 'filter_buku_oembed'
 *
 * @package   filter_buku_oembed
 * @copyright 2024 BUKU B.V.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once(__DIR__.'/filter.php');

if ($ADMIN->fulltree) {
    $torf = array('1' => new lang_string('yes'), '0' => new lang_string('no'));
    $item = new admin_setting_configselect('filter_buku_oembed/buku', new lang_string('buku', 'filter_buku_oembed'), '', 1, $torf);
    $settings->add($item);
}
