<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Theme library functions.
 *
 * Contains SCSS injection callbacks and file serving functions.
 *
 * @package    theme_elby
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Convert hex color to RGB values string.
 *
 * @param string $hex Hex color code (e.g., #1e3a8a or 1e3a8a).
 * @return string RGB values as "r, g, b" string.
 */
function theme_elby_hex2rgb($hex) {
    $hex = str_replace('#', '', $hex);

    if (strlen($hex) == 3) {
        $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
        $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
        $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
    } else {
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
    }

    return "$r, $g, $b";
}

/**
 * Get the main SCSS content.
 *
 * Loads the preset file which imports Bootstrap and Moodle core styles.
 *
 * @param theme_config $theme The theme configuration object.
 * @return string SCSS content.
 */
function theme_elby_get_main_scss_content($theme) {
    global $CFG;

    $scss = '';
    $themedir = __DIR__;

    // Load Elby's preset (includes Bootstrap, Moodle, FontAwesome via @import).
    $boostpreset = $themedir . '/scss/preset/default.scss';
    if (file_exists($boostpreset)) {
        $scss .= file_get_contents($boostpreset);
    }

    // Load theme partials directly (bypassing @import which may not resolve).
    $partials = ['_variables', '_navigation', '_frontpage', '_login', '_footer'];
    foreach ($partials as $partial) {
        $partialfile = $themedir . '/scss/' . $partial . '.scss';
        if (file_exists($partialfile)) {
            $scss .= "\n" . file_get_contents($partialfile);
        }
    }

    // Load our custom post styles.
    $postfile = $themedir . '/scss/post.scss';
    if (file_exists($postfile)) {
        $scss .= "\n" . file_get_contents($postfile);
    }

    // Load navigation styles directly (CSS is valid SCSS).
    $navfile = $themedir . '/styles/navigation.css';
    if (file_exists($navfile)) {
        $scss .= "\n" . file_get_contents($navfile);
    }

    return $scss;
}

/**
 * Get SCSS to prepend - converts admin settings to SCSS variables.
 *
 * Because Bootstrap variables use !default, our injected values override them.
 * This propagates brand colors to every Bootstrap component automatically.
 *
 * @param theme_config $theme The theme configuration object.
 * @return string SCSS content to prepend.
 */
function theme_elby_get_pre_scss($theme) {
    $scss = '';
    $settings = $theme->settings;

    // Brand/Primary Color.
    if (!empty($settings->brandcolor)) {
        $scss .= '$primary: ' . $settings->brandcolor . ";\n";
    }

    // Secondary Color.
    if (!empty($settings->secondarycolor)) {
        $scss .= '$secondary: ' . $settings->secondarycolor . ";\n";
    }

    // Semantic Colors.
    if (!empty($settings->successcolor)) {
        $scss .= '$success: ' . $settings->successcolor . ";\n";
    }
    if (!empty($settings->infocolor)) {
        $scss .= '$info: ' . $settings->infocolor . ";\n";
    }
    if (!empty($settings->warningcolor)) {
        $scss .= '$warning: ' . $settings->warningcolor . ";\n";
    }
    if (!empty($settings->dangercolor)) {
        $scss .= '$danger: ' . $settings->dangercolor . ";\n";
    }

    // Body Colors.
    if (!empty($settings->bodybgcolor)) {
        $scss .= '$body-bg: ' . $settings->bodybgcolor . ";\n";
    }
    if (!empty($settings->bodytextcolor)) {
        $scss .= '$body-color: ' . $settings->bodytextcolor . ";\n";
    }

    // Link Color.
    if (!empty($settings->linkcolor)) {
        $scss .= '$link-color: ' . $settings->linkcolor . ";\n";
    }

    // Typography.
    if (!empty($settings->fontbody) && $settings->fontbody !== 'inherit') {
        $scss .= '$font-family-base: "' . $settings->fontbody . '", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;' . "\n";
    }
    if (!empty($settings->fontheadings) && $settings->fontheadings !== 'inherit') {
        $scss .= '$headings-font-family: "' . $settings->fontheadings . '", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;' . "\n";
    }
    if (!empty($settings->fontsize)) {
        $scss .= '$font-size-base: ' . $settings->fontsize . ";\n";
    }

    // Border Radius.
    if (!empty($settings->borderradius)) {
        $scss .= '$border-radius: ' . $settings->borderradius . ";\n";
        $scss .= '$border-radius-sm: calc(' . $settings->borderradius . ' * 0.5);' . "\n";
        $scss .= '$border-radius-lg: calc(' . $settings->borderradius . ' * 1.5);' . "\n";
    }

    // Raw Pre-SCSS from admin.
    if (!empty($settings->scsspre)) {
        $scss .= $settings->scsspre . "\n";
    }

    return $scss;
}

/**
 * Get SCSS to append - includes background images and raw SCSS.
 *
 * Loaded after Bootstrap and Moodle, so can override anything via cascade.
 *
 * @param theme_config $theme The theme configuration object.
 * @return string SCSS content to append.
 */
function theme_elby_get_extra_scss($theme) {
    $scss = '';
    $settings = $theme->settings;

    // Login Background Image.
    $loginbg = $theme->setting_file_url('loginbackgroundimage', 'loginbackgroundimage');
    if (!empty($loginbg)) {
        $scss .= '
        body.pagelayout-login {
            background-image: url("' . $loginbg . '");
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }
        body.pagelayout-login #page-wrapper {
            background-color: rgba(0, 0, 0, 0.5);
        }
        ';
    }

    // CSS Custom Properties for JavaScript access.
    $scss .= '
    :root {
        --elby-primary: #{$primary};
        --elby-secondary: #{$secondary};
    }
    ';

    // Get navigation color from brand color setting (default to dark blue).
    $navcolor = get_config('theme_elby', 'brandcolor') ?: '#1e3a8a';

    // Category header colors.
    $categoryheaderbg = get_config('theme_elby', 'categoryheaderbg') ?: '#1e293b';
    $categoryheadertextcolor = get_config('theme_elby', 'categoryheadertextcolor') ?: '#ffffff';

    // Navigation styles (added directly to ensure compilation).
    //
    // Fixed-top navbar offset: drive both the navbar height AND the
    // page-wrapper top padding off a single CSS variable so they stay in
    // sync on every layout. Excluding popup/login/maintenance/embedded/print
    // pagelayouts via :not() leaves chromeless flows untouched.
    $scss .= '
    :root {
        --elby-navbar-height: 80px;
    }

    @media (max-width: 991.98px) {
        :root {
            --elby-navbar-height: 70px;
        }
    }

    /* Push content below the fixed-top navbar via #page’s margin (which
       Boost already uses for its own 60px navbar). Setting it to our
       variable replaces Boost’s value, so the offset is the elby navbar
       height — not Boost’s 60px + our padding stacked on top of each other. */
    body:not(.pagelayout-popup):not(.pagelayout-login):not(.pagelayout-maintenance):not(.pagelayout-embedded):not(.pagelayout-print) #page {
        margin-top: var(--elby-navbar-height) !important;
    }

    /* Ensure #page-wrapper itself has no extra top padding (an earlier
       version of this fix put the offset here, which stacked with #page’s
       margin and produced double spacing). */
    body:not(.pagelayout-popup):not(.pagelayout-login):not(.pagelayout-maintenance):not(.pagelayout-embedded):not(.pagelayout-print) #page-wrapper {
        padding-top: 0;
    }

    /* Header wrapper */
    .elby-header {
        z-index: 1030;
    }

    /* Navbar - white background with subtle shadow.
       Lock height to --elby-navbar-height so the page-wrapper offset above
       always matches the actual rendered navbar height. */
    .elby-navbar {
        background-color: #ffffff !important;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        padding: 0 0;
        height: var(--elby-navbar-height);
        min-height: var(--elby-navbar-height);
    }

    nav.navbar.fixed-top.elby-navbar {
        padding-top: 0 !important;
        padding-bottom: 0 !important;
        height: var(--elby-navbar-height) !important;
        min-height: var(--elby-navbar-height) !important;
    }

    nav.navbar.fixed-top {
        min-height: var(--elby-navbar-height);
    }

    /* Logo and Brand - Left side */
    .elby-navbar-brand {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        text-decoration: none !important;
        flex-shrink: 0;
    }

    .elby-logo {
        max-height: 80px;
        width: auto;
    }

    .elby-sitename {
        font-size: 1.25rem;
        font-weight: 700;
        color: ' . $navcolor . ';
        white-space: nowrap;
    }

    /* Centered Navigation container */
    .elby-nav-center {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    /* Nav links - Brand color */
    .elby-navbar .nav-link {
        color: ' . $navcolor . ' !important;
        font-weight: 600;
        font-size: 0.9375rem;
        padding: 0.5rem 1.25rem;
        position: relative;
        text-decoration: none !important;
    }

    .elby-navbar .nav-link:hover,
    .elby-navbar .nav-link:focus {
        color: #1e40af !important;
    }

    /* Active state with underline */
    .elby-navbar .nav-link.active {
        color: ' . $navcolor . ' !important;
    }

    .elby-navbar .nav-link.active::after {
        content: "";
        position: absolute;
        bottom: 0;
        left: 1.25rem;
        right: 1.25rem;
        height: 2px;
        background-color: ' . $navcolor . ';
        border-radius: 1px;
    }

    /* Dropdown menus */
    .elby-navbar .dropdown-menu {
        border: none;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.12);
        border-radius: 12px;
        padding: 0.75rem;
        margin-top: 0.5rem;
    }

    .elby-navbar .dropdown-menu .dropdown-item {
        color: ' . $navcolor . ';
        border-radius: 8px;
        padding: 0.625rem 1rem;
        font-weight: 500;
    }

    .elby-navbar .dropdown-menu .dropdown-item:hover {
        background-color: rgba(37, 99, 235, 0.08);
        color: #2563eb;
    }

    /* Login button - Outline style (brand color border, transparent bg) */
    .elby-login-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        color: ' . $navcolor . ' !important;
        background: transparent !important;
        border: 2px solid ' . $navcolor . ' !important;
        border-radius: 8px;
        padding: 0.5rem 1.25rem;
        font-weight: 600;
        font-size: 0.9375rem;
        text-decoration: none !important;
        white-space: nowrap;
    }

    .elby-login-btn i {
        font-size: 0.875rem;
    }

    .elby-login-btn:hover {
        background: ' . $navcolor . ' !important;
        color: #ffffff !important;
    }

    /* Navbar spacer (legacy: kept so any layout that still renders
       <div class="elby-navbar-spacer"> does not add double spacing). The
       real offset comes from the #page-wrapper rule above. */
    .elby-navbar-spacer {
        height: 0;
    }

    /* Navbar actions container - Right side */
    .elby-navbar-actions {
        display: flex;
        align-items: center;
        flex-shrink: 0;
    }

    /* Mobile navbar */
    @media (max-width: 991.98px) {
        .elby-navbar {
            padding: 0.75rem 0;
        }

        .elby-logo {
            max-height: 35px;
        }

        .elby-sitename {
            font-size: 1.125rem;
        }

        .elby-navbar .navbar-collapse {
            background-color: #ffffff;
            padding: 1.5rem;
            margin-top: 1rem;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            position: absolute;
            top: 100%;
            left: 1rem;
            right: 1rem;
        }

        .elby-nav-center {
            flex-direction: column;
            align-items: stretch;
            gap: 0;
        }

        .elby-navbar .nav-link {
            padding: 0.875rem 1rem;
            border-radius: 8px;
        }

        .elby-navbar .nav-link:hover {
            background: rgba(37, 99, 235, 0.05);
        }

        .elby-navbar .nav-link.active::after {
            display: none;
        }

        .elby-navbar .nav-link.active {
            background: rgba(37, 99, 235, 0.08);
        }

        .elby-navbar-actions {
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
            margin-top: 1rem;
            justify-content: center;
        }

        .elby-login-btn {
            width: 100%;
            justify-content: center;
        }

        .elby-navbar-spacer {
            height: 0;
        }
    }

    /* User menu adjustments (when logged in) */
    .elby-user-menu .usermenu .dropdown-toggle {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: ' . $navcolor . ';
        text-decoration: none;
        padding: 0.375rem 0.75rem;
        border-radius: 8px;
    }

    .elby-user-menu .usermenu .dropdown-toggle:hover {
        background: rgba(37, 99, 235, 0.05);
    }

    .elby-user-menu .usermenu .userpicture {
        width: 36px;
        height: 36px;
        border-radius: 50%;
    }

    /* Navbar toggler (hamburger menu) styling */
    .elby-navbar .navbar-toggler {
        border: none;
        padding: 0.5rem;
    }

    .elby-navbar .navbar-toggler:focus {
        box-shadow: none;
    }

    .elby-navbar .navbar-toggler .navbar-toggler-icon {
        background-image: url("data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 30 30\'%3e%3cpath stroke=\'' . str_replace('#', '%23', $navcolor) . '\' stroke-linecap=\'round\' stroke-miterlimit=\'10\' stroke-width=\'2\' d=\'M4 7h22M4 15h22M4 23h22\'/%3e%3c/svg%3e");
    }

    /* ============================================= */
    /* BOOST NAVBAR OVERRIDE - Apply elby styling to all pages */
    /* ============================================= */

    /* Main navbar container (Boost pages) */
    nav.navbar.fixed-top {
        background-color: #ffffff !important;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08) !important;
        border-bottom: none !important;
    }

    /* Center the primary navigation */
    nav.navbar.fixed-top .container-fluid {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    nav.navbar.fixed-top .primary-navigation {
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
    }

    nav.navbar.fixed-top.bg-body {
        --bs-bg-opacity: 1;
        background-color: #ffffff !important;
    }

    /* Primary navigation links */
    .navbar.fixed-top .primary-navigation .nav-link {
        color: ' . $navcolor . ' !important;
        font-weight: 600;
        font-size: 0.9375rem;
    }

    .navbar.fixed-top .primary-navigation .nav-link:hover,
    .navbar.fixed-top .primary-navigation .nav-link:focus {
        color: #1e40af !important;
    }

    /* Active nav item */
    .navbar.fixed-top .primary-navigation .nav-link.active {
        color: ' . $navcolor . ' !important;
    }

    /* Dropdown toggles in primary nav */
    .navbar.fixed-top .primary-navigation .dropdown-toggle {
        color: ' . $navcolor . ' !important;
    }

    /* Navbar brand / site name */
    .navbar.fixed-top .navbar-brand {
        color: ' . $navcolor . ' !important;
    }

    /* User menu dropdown */
    .navbar.fixed-top .usermenu .dropdown-toggle {
        color: ' . $navcolor . ' !important;
    }

    /* Notification/message icons */
    .navbar.fixed-top .popover-region-toggle {
        color: ' . $navcolor . ' !important;
    }

    /* Edit mode switch */
    .navbar.fixed-top .editmode-switch-form label {
        color: ' . $navcolor . ' !important;
    }

    /* Custom menu items */
    .navbar.fixed-top .custom-menu .nav-link {
        color: ' . $navcolor . ' !important;
    }

    /* Dividers */
    .navbar.fixed-top .divider {
        background-color: #e2e8f0 !important;
    }

    /* Navbar toggler icon for mobile (Boost) */
    .navbar.fixed-top .navbar-toggler-icon {
        background-image: url("data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 30 30\'%3e%3cpath stroke=\'' . str_replace('#', '%23', $navcolor) . '\' stroke-linecap=\'round\' stroke-miterlimit=\'10\' stroke-width=\'2\' d=\'M4 7h22M4 15h22M4 23h22\'/%3e%3c/svg%3e") !important;
    }

    /* ============================================= */
    /* HERO SECTION - Edwiser Style */
    /* ============================================= */

    .elby-hero {
        position: relative;
        min-height: 550px;
        display: flex;
        align-items: center;
        padding: 40px 0 60px;
        overflow: hidden;
        background: #fff;
    }

    /* Decorative Elements Container */
    .elby-hero-decorations {
        position: absolute;
        inset: 0;
        pointer-events: none;
        overflow: hidden;
        z-index: 1;
    }

    /* Colored Blobs */
    .elby-deco-blob {
        position: absolute;
        border-radius: 50%;
    }

    .elby-deco-blob-1 {
        width: 100px;
        height: 100px;
        background: rgba(226, 232, 240, 0.6);
        top: 30%;
        left: 2%;
    }

    .elby-deco-blob-2 {
        width: 60px;
        height: 60px;
        background: rgba(236, 72, 153, 0.12);
        bottom: 20%;
        right: 5%;
    }

    .elby-deco-blob-3 {
        width: 180px;
        height: 180px;
        background: rgba(236, 72, 153, 0.08);
        bottom: -40px;
        right: 20%;
    }

    .elby-deco-blob-4 {
        width: 50px;
        height: 50px;
        background: rgba(226, 232, 240, 0.5);
        top: 55%;
        left: 42%;
    }

    /* Dot Grid Patterns */
    .elby-deco-dots {
        position: absolute;
        width: 60px;
        height: 60px;
        background-image: radial-gradient(circle, ' . $navcolor . ' 2px, transparent 2px);
        background-size: 10px 10px;
        opacity: 0.2;
    }

    .elby-deco-dots-1 {
        top: 15%;
        left: 4%;
    }

    .elby-deco-dots-2 {
        top: 10%;
        right: 38%;
    }

    .elby-deco-dots-3 {
        bottom: 30%;
        right: 3%;
    }

    /* Small Accent Dots */
    .elby-deco-dot {
        position: absolute;
        border-radius: 50%;
        background: ' . $navcolor . ';
    }

    .elby-deco-dot-1 {
        width: 8px;
        height: 8px;
        top: 35%;
        left: 10%;
    }

    .elby-deco-dot-2 {
        width: 6px;
        height: 6px;
        top: 18%;
        right: 44%;
    }

    .elby-deco-dot-3 {
        width: 5px;
        height: 5px;
        bottom: 40%;
        left: 46%;
        opacity: 0.5;
    }

    /* Navigation Arrows */
    .elby-hero-nav {
        position: absolute;
        top: 50%;
        transform: translateY(-50%) scale(1);
        z-index: 10;
        width: 48px;
        height: 48px;
        border: 2px solid ' . $navcolor . ';
        background: transparent;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        color: ' . $navcolor . ';
        font-size: 1rem;
        opacity: 0.8;
    }

    .elby-hero-nav:hover {
        background: ' . $navcolor . ';
        color: #fff;
        transform: translateY(-50%) scale(1.1);
        opacity: 1;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
    }

    .elby-hero-nav:active {
        transform: translateY(-50%) scale(0.95);
    }

    .elby-hero-nav-prev {
        left: 30px;
    }

    .elby-hero-nav-next {
        right: 30px;
    }

    @media (max-width: 1200px) {
        .elby-hero-nav-prev {
            left: 15px;
        }
        .elby-hero-nav-next {
            right: 15px;
        }
    }

    @media (max-width: 991.98px) {
        .elby-hero-nav {
            display: none;
        }
    }

    /* Hero Content */
    .elby-hero-content {
        position: relative;
        z-index: 2;
    }

    .elby-hero-heading {
        font-size: 2.5rem;
        font-weight: 800;
        color: #1e293b;
        line-height: 1.2;
        margin-bottom: 1rem;
        text-transform: uppercase;
    }

    @media (max-width: 1199.98px) {
        .elby-hero-heading {
            font-size: 2rem;
        }
    }

    @media (max-width: 767.98px) {
        .elby-hero-heading {
            font-size: 1.75rem;
        }
    }

    .elby-hero-subheading {
        font-size: 1rem;
        color: #64748b;
        line-height: 1.7;
        margin-bottom: 1.5rem;
        max-width: 400px;
    }

    .elby-hero-cta {
        display: inline-block;
        background: ' . $navcolor . ';
        color: #fff !important;
        padding: 0.75rem 1.75rem;
        font-weight: 600;
        font-size: 0.9375rem;
        border-radius: 8px;
        text-decoration: none !important;
        transition: all 0.3s ease;
        margin-bottom: 1.5rem;
    }

    .elby-hero-cta:hover {
        background: #1e40af;
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(37, 99, 235, 0.3);
    }

    /* Pagination Dots */
    .elby-hero-pagination {
        display: flex;
        gap: 10px;
        align-items: center;
        margin-top: 1.5rem;
    }

    .elby-hero-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: #d1d5db;
        border: none;
        padding: 0;
        cursor: pointer;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
    }

    .elby-hero-dot::after {
        content: "";
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) scale(0);
        width: 20px;
        height: 20px;
        border-radius: 50%;
        border: 2px solid ' . $navcolor . ';
        opacity: 0;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .elby-hero-dot.active {
        background: ' . $navcolor . ';
        transform: scale(1.2);
    }

    .elby-hero-dot.active::after {
        transform: translate(-50%, -50%) scale(1);
        opacity: 0.3;
    }

    .elby-hero-dot:hover:not(.active) {
        background: ' . $navcolor . ';
        opacity: 0.6;
        transform: scale(1.1);
    }

    .elby-hero-dot:focus {
        outline: 2px solid ' . $navcolor . ';
        outline-offset: 2px;
    }

    /* Carousel Slides Container */
    .elby-hero-slides-content {
        position: relative;
        min-height: 250px;
    }

    .elby-hero-slides-images {
        position: relative;
        height: 420px;
    }

    @media (max-width: 1199.98px) {
        .elby-hero-slides-images {
            height: 350px;
        }
    }

    /* Hero Slide (content) */
    .elby-hero-slide {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        opacity: 0;
        visibility: hidden;
        transform: translateX(30px);
        transition: opacity 0.6s cubic-bezier(0.4, 0, 0.2, 1),
                    visibility 0.6s cubic-bezier(0.4, 0, 0.2, 1),
                    transform 0.6s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .elby-hero-slide.active {
        position: relative;
        opacity: 1;
        visibility: visible;
        transform: translateX(0);
    }

    .elby-hero-slide.fade-out {
        opacity: 0;
        visibility: hidden;
        transform: translateX(-30px);
    }

    .elby-hero-slide.fade-in {
        opacity: 1;
        visibility: visible;
        transform: translateX(0);
    }

    /* Staggered content animation */
    .elby-hero-slide.active .elby-hero-heading {
        animation: slideInUp 0.5s cubic-bezier(0.4, 0, 0.2, 1) 0.1s both;
    }

    .elby-hero-slide.active .elby-hero-subheading {
        animation: slideInUp 0.5s cubic-bezier(0.4, 0, 0.2, 1) 0.2s both;
    }

    .elby-hero-slide.active .elby-hero-cta {
        animation: slideInUp 0.5s cubic-bezier(0.4, 0, 0.2, 1) 0.3s both;
    }

    @keyframes slideInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Hero Image Collage */
    .elby-hero-collage {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        z-index: 2;
        height: 420px;
        opacity: 0;
        visibility: hidden;
        transform: scale(0.95) translateX(20px);
        transition: opacity 0.7s cubic-bezier(0.4, 0, 0.2, 1),
                    visibility 0.7s cubic-bezier(0.4, 0, 0.2, 1),
                    transform 0.7s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .elby-hero-collage.active {
        opacity: 1;
        visibility: visible;
        transform: scale(1) translateX(0);
    }

    .elby-hero-collage.fade-out {
        opacity: 0;
        visibility: hidden;
        transform: scale(0.95) translateX(-20px);
    }

    .elby-hero-collage.fade-in {
        opacity: 1;
        visibility: visible;
        transform: scale(1) translateX(0);
    }

    /* Staggered image animation */
    .elby-hero-collage.active .elby-hero-main-image {
        animation: zoomIn 0.6s cubic-bezier(0.4, 0, 0.2, 1) 0.15s both;
    }

    .elby-hero-collage.active .elby-hero-secondary-image {
        animation: zoomIn 0.6s cubic-bezier(0.4, 0, 0.2, 1) 0.3s both;
    }

    @keyframes zoomIn {
        from {
            opacity: 0;
            transform: scale(0.9);
        }
        to {
            opacity: 1;
            transform: scale(1);
        }
    }

    @media (max-width: 1199.98px) {
        .elby-hero-collage {
            height: 350px;
        }
    }

    /* Main Blob-Shaped Image */
    .elby-hero-main-image {
        position: absolute;
        top: 0;
        right: 0;
        width: 90%;
        height: 100%;
        z-index: 2;
    }

    .elby-hero-image-blob {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 60% 40% 55% 45% / 55% 60% 40% 45%;
    }

    .elby-hero-placeholder-blob {
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, rgba(37, 99, 235, 0.08) 0%, rgba(236, 72, 153, 0.1) 100%);
        border-radius: 60% 40% 55% 45% / 55% 60% 40% 45%;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .elby-hero-placeholder-blob i {
        font-size: 3.5rem;
        color: ' . $navcolor . ';
        opacity: 0.2;
    }

    /* Default SVG illustrations */
    .elby-hero-image-default {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }

    /* Secondary Circular Image */
    .elby-hero-secondary-image {
        position: absolute;
        bottom: 0;
        left: 0;
        width: 160px;
        height: 160px;
        z-index: 3;
    }

    @media (max-width: 1199.98px) {
        .elby-hero-secondary-image {
            width: 130px;
            height: 130px;
        }
    }

    .elby-hero-image-circle {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 50%;
        border: 4px solid #fff;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
    }

    .elby-hero-placeholder-circle {
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, rgba(37, 99, 235, 0.1) 0%, rgba(236, 72, 153, 0.12) 100%);
        border-radius: 50%;
        border: 4px solid #fff;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .elby-hero-placeholder-circle i {
        font-size: 2rem;
        color: ' . $navcolor . ';
        opacity: 0.3;
    }

    /* Default SVG for secondary image */
    .elby-hero-image-circle-default {
        width: 100%;
        height: 100%;
        object-fit: contain;
        border-radius: 50%;
        border: 4px solid #fff;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
        background: #fff;
        padding: 10px;
    }

    /* Background Decorative Shapes */
    .elby-hero-shape {
        position: absolute;
        border-radius: 50%;
    }

    .elby-hero-shape-1 {
        width: 200px;
        height: 200px;
        background: rgba(236, 72, 153, 0.1);
        bottom: -20px;
        right: -20px;
        z-index: 1;
    }

    .elby-hero-shape-2 {
        width: 80px;
        height: 80px;
        background: rgba(226, 232, 240, 0.5);
        top: 15%;
        left: 12%;
        z-index: 1;
    }

    /* Hero Features Cards */
    .elby-hero-features {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        margin-top: 1.5rem;
    }

    .elby-hero-feature-card {
        display: flex;
        align-items: flex-start;
        gap: 0.875rem;
        background: #fff;
        padding: 1.25rem;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
        border: 1px solid rgba(0, 0, 0, 0.04);
        flex: 1;
        min-width: 200px;
        max-width: 280px;
        transition: all 0.3s ease;
    }

    .elby-hero-feature-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.1);
    }

    .elby-hero-feature-icon {
        width: 48px;
        height: 48px;
        background: linear-gradient(135deg, ' . $navcolor . ' 0%, #3b82f6 100%);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .elby-hero-feature-icon i {
        font-size: 1.25rem;
        color: #fff;
    }

    .elby-hero-feature-content h4 {
        font-size: 0.9375rem;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 0.25rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .elby-hero-feature-content p {
        font-size: 0.8125rem;
        color: #64748b;
        margin-bottom: 0;
        line-height: 1.5;
    }

    /* =========================================== */
    /* FULLWIDTH HERO VARIANT                     */
    /* =========================================== */

    .elby-hero.elby-hero--fullwidth {
        min-height: 600px;
        padding: 0;
        background: #1a1a2e;
        width: 100vw;
        max-width: 100vw;
        margin-left: calc(-50vw + 50%);
    }

    @media (max-width: 991.98px) {
        .elby-hero.elby-hero--fullwidth {
            min-height: 450px;
        }
    }

    @media (max-width: 575.98px) {
        .elby-hero.elby-hero--fullwidth {
            min-height: 350px;
        }
    }

    .elby-hero-fullwidth-slides {
        position: absolute;
        inset: 0;
        z-index: 0;
    }

    .elby-hero--fullwidth .elby-hero-slide {
        position: absolute;
        inset: 0;
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
        transform: none;
        transition: opacity 0.5s ease, visibility 0.5s ease;
        display: flex;
        align-items: center;
    }

    .elby-hero--fullwidth .elby-hero-slide.active {
        position: absolute;
        opacity: 1;
        visibility: visible;
        transform: none;
    }

    .elby-hero--fullwidth .elby-hero-slide.fade-out {
        transform: none;
    }

    .elby-hero--fullwidth .elby-hero-slide.fade-in {
        transform: none;
    }

    .elby-hero-fullwidth-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(to right, rgba(0, 0, 0, 0.65) 35%, rgba(0, 0, 0, 0.2) 100%);
        z-index: 1;
    }

    .elby-hero-fullwidth-content {
        max-width: 620px;
        padding: 120px 0 80px;
        position: relative;
        z-index: 2;
    }

    @media (max-width: 991.98px) {
        .elby-hero-fullwidth-content {
            padding: 100px 0 60px;
        }
    }

    @media (max-width: 575.98px) {
        .elby-hero-fullwidth-content {
            padding: 80px 0 50px;
            max-width: 100%;
        }
    }

    .elby-hero--fullwidth .elby-hero-heading {
        color: #fff;
        font-size: 3rem;
        font-weight: 700;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        line-height: 1.2;
        margin-bottom: 1.25rem;
    }

    @media (max-width: 1199.98px) {
        .elby-hero--fullwidth .elby-hero-heading {
            font-size: 2.5rem;
        }
    }

    @media (max-width: 767.98px) {
        .elby-hero--fullwidth .elby-hero-heading {
            font-size: 2rem;
            letter-spacing: 0.1em;
        }
    }

    @media (max-width: 575.98px) {
        .elby-hero--fullwidth .elby-hero-heading {
            font-size: 1.5rem;
        }
    }

    .elby-hero--fullwidth .elby-hero-subheading {
        color: rgba(255, 255, 255, 0.85);
        font-size: 1rem;
        line-height: 1.6;
        margin-bottom: 1.75rem;
        max-width: 500px;
    }

    @media (max-width: 575.98px) {
        .elby-hero--fullwidth .elby-hero-subheading {
            font-size: 0.9rem;
        }
    }

    .elby-hero--fullwidth .elby-hero-cta {
        padding: 0.75rem 2rem;
        font-size: 1rem;
        font-weight: 600;
        border-radius: 6px;
        margin-bottom: 0;
    }

    .elby-hero--fullwidth .elby-hero-nav {
        border-color: rgba(255, 255, 255, 0.5);
        color: #fff;
        z-index: 3;
    }

    .elby-hero--fullwidth .elby-hero-nav:hover {
        background: rgba(255, 255, 255, 0.2);
        border-color: #fff;
    }

    .elby-hero--fullwidth .elby-hero-pagination {
        position: absolute;
        bottom: 30px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 3;
    }

    .elby-hero--fullwidth .elby-hero-dot {
        background: rgba(255, 255, 255, 0.5);
    }

    .elby-hero--fullwidth .elby-hero-dot.active {
        background: #fff;
    }

    /* =========================================== */
    /* COURSE CATEGORIES SECTION - Udemy Style    */
    /* =========================================== */

    .elby-categories {
        background: #f8fafc;
        padding: 80px 0;
    }

    @media (max-width: 991.98px) {
        .elby-categories {
            padding: 60px 0;
        }
    }

    /* Breadcrumb navigation */
    .elby-categories-breadcrumb {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
        font-size: 0.9375rem;
    }

    .elby-categories-breadcrumb a {
        color: ' . $navcolor . ';
        text-decoration: none;
        transition: color 0.2s ease;
    }

    .elby-categories-breadcrumb a:hover {
        text-decoration: underline;
    }

    .elby-categories-breadcrumb a.active {
        color: #64748b;
        pointer-events: none;
    }

    .elby-categories-breadcrumb .separator {
        color: #94a3b8;
        margin: 0 0.25rem;
    }

    /* Category header - full width banner */
    .elby-category-header {
        position: relative;
        background: ' . $categoryheaderbg . ' !important;
        color: ' . $categoryheadertextcolor . ' !important;
        overflow: hidden;
        min-height: 200px;
        display: flex;
        align-items: center;
    }

    .elby-category-header-image {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: 0;
    }

    .elby-category-header-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        opacity: 0.3;
    }

    .elby-category-header::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(to right, rgba(0, 0, 0, 0.7) 0%, rgba(0, 0, 0, 0.3) 100%);
        z-index: 0;
    }

    .elby-category-header > .container {
        position: relative;
        z-index: 1;
    }

    .elby-category-header-content {
        padding: 3rem 0;
    }

    .elby-category-header .elby-categories-breadcrumb a {
        color: ' . $categoryheadertextcolor . ' !important;
        opacity: 0.8;
    }

    .elby-category-header .elby-categories-breadcrumb a:hover {
        opacity: 1;
    }

    .elby-category-header .elby-categories-breadcrumb a.active {
        opacity: 0.6;
    }

    .elby-category-header .elby-categories-breadcrumb .separator {
        color: ' . $categoryheadertextcolor . ' !important;
        opacity: 0.5;
    }

    .elby-category-header-title {
        font-size: 2rem;
        font-weight: 700;
        margin: 0;
        color: ' . $categoryheadertextcolor . ' !important;
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
    }

    .elby-category-header-desc {
        font-size: 1rem;
        color: ' . $categoryheadertextcolor . ' !important;
        opacity: 0.85;
        margin: 0.5rem 0 0 0;
        line-height: 1.6;
        max-width: 600px;
    }

    @media (max-width: 767.98px) {
        .elby-category-header-content {
            padding: 2rem 0;
        }

        .elby-category-header-title {
            font-size: 1.5rem;
        }
    }

    /* Category card */
    .elby-category-card {
        display: block;
        background: #fff;
        border-radius: 12px;
        overflow: hidden;
        height: 100%;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        border: 1px solid rgba(0, 0, 0, 0.04);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        text-decoration: none !important;
    }

    .elby-category-card:hover {
        transform: translateY(-6px);
        box-shadow: 0 12px 24px rgba(0, 0, 0, 0.12);
    }

    /* Card image */
    .elby-category-image {
        position: relative;
        width: 100%;
        padding-top: 56.25%; /* 16:9 aspect ratio */
        background: #e2e8f0;
        overflow: hidden;
    }

    .elby-category-image img {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.4s ease;
    }

    .elby-category-card:hover .elby-category-image img {
        transform: scale(1.05);
    }

    /* Placeholder for categories without images */
    .elby-category-placeholder {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, ' . $navcolor . ' 0%, #3b82f6 100%);
    }

    .elby-category-placeholder i {
        font-size: 3rem;
        color: rgba(255, 255, 255, 0.9);
    }

    /* Card content */
    .elby-category-content {
        padding: 1.25rem;
    }

    .elby-category-title {
        font-size: 1rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0 0 0.5rem 0;
        line-height: 1.4;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .elby-category-card:hover .elby-category-title {
        color: ' . $navcolor . ';
    }

    .elby-category-desc {
        font-size: 0.875rem;
        color: #64748b;
        line-height: 1.5;
        margin: 0 0 1rem 0;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    /* Stats badges */
    .elby-category-stats {
        display: flex;
        align-items: center;
        gap: 1rem;
        font-size: 0.8125rem;
        color: #64748b;
    }

    .elby-stat-item {
        display: flex;
        align-items: center;
        gap: 0.375rem;
    }

    .elby-stat-item i {
        font-size: 0.875rem;
        color: ' . $navcolor . ';
    }

    /* Empty state */
    .elby-categories-empty {
        padding: 3rem 1rem;
    }

    .elby-categories-empty i {
        display: block;
        margin-bottom: 1rem;
    }

    /* Button styles (reusable) */
    .elby-btn-primary {
        display: inline-block;
        background: ' . $navcolor . ';
        color: #fff !important;
        border: 2px solid ' . $navcolor . ';
        padding: 0.625rem 1.5rem;
        font-weight: 600;
        font-size: 0.875rem;
        border-radius: 6px;
        text-decoration: none !important;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .elby-btn-primary:hover {
        filter: brightness(0.9);
        color: #fff !important;
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
    }

    .elby-btn-outline {
        display: inline-block;
        background: transparent;
        color: ' . $navcolor . ' !important;
        border: 2px solid ' . $navcolor . ';
        padding: 0.625rem 1.5rem;
        font-weight: 600;
        font-size: 0.875rem;
        border-radius: 6px;
        text-decoration: none !important;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .elby-btn-outline:hover {
        background: ' . $navcolor . ';
        color: #fff !important;
        transform: translateY(-2px);
    }

    /* =========================================== */
    /* ANNOUNCEMENTS SECTION - Edwiser Style      */
    /* =========================================== */

    .elby-announcements {
        position: relative;
        background-color: #fff !important;
        background-image: none !important;
        padding: 80px 0;
        overflow: visible;
        min-height: 200px;
        display: block;
    }

    .elby-announcements > .container {
        background: transparent !important;
        position: relative;
        z-index: 2;
    }

    .elby-announcements .elby-btn-primary {
        background: ' . $navcolor . ' !important;
        color: #fff !important;
        border-color: ' . $navcolor . ' !important;
    }

    @media (max-width: 991.98px) {
        .elby-announcements {
            padding: 60px 0;
        }
    }

    .elby-announcements-decorations {
        position: absolute;
        top: 0;
        left: 0;
        width: 200px;
        height: 100%;
        pointer-events: none;
        z-index: 0;
    }

    .elby-announce-deco {
        position: absolute;
    }

    .elby-announce-deco-dot-1 {
        width: 12px;
        height: 12px;
        background: ' . $navcolor . ';
        opacity: 0.3;
        border-radius: 50%;
        top: 20%;
        left: 80px;
    }

    .elby-announce-deco-dot-2 {
        width: 8px;
        height: 8px;
        background: ' . $navcolor . ';
        opacity: 0.2;
        border-radius: 50%;
        top: 30%;
        left: 60px;
    }

    .elby-announce-deco-dots {
        width: 50px;
        height: 50px;
        background-image: radial-gradient(circle, ' . $navcolor . ' 2px, transparent 2px);
        background-size: 12px 12px;
        opacity: 0.2;
        top: 45%;
        left: 40px;
    }

    .elby-announce-deco-circle {
        width: 120px;
        height: 120px;
        background: rgba(236, 72, 153, 0.12);
        border-radius: 50%;
        top: 25%;
        left: 100px;
    }

    @media (max-width: 991.98px) {
        .elby-announcements-decorations {
            display: none;
        }
    }

    .elby-announcements .container {
        position: relative;
        z-index: 1;
    }

    .elby-announcement-wrapper {
        text-align: center;
        max-width: 800px;
        margin: 0 auto;
        padding: 0 1rem;
        background: transparent !important;
        position: relative;
        z-index: 1;
    }

    .elby-announcement-title {
        font-size: 2.25rem;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 1.25rem;
        line-height: 1.3;
    }

    @media (max-width: 767.98px) {
        .elby-announcement-title {
            font-size: 1.75rem;
        }
    }

    .elby-announcement-content {
        font-size: 1rem;
        color: #64748b;
        line-height: 1.8;
        margin-bottom: 2rem;
    }

    .elby-announcement-content p {
        margin-bottom: 0;
    }

    /* =========================================== */
    /* FEATURE SECTION - Edwiser Split Layout     */
    /* =========================================== */

    .elby-feature-section {
        background: #fff;
        padding: 80px 0;
    }

    @media (max-width: 991.98px) {
        .elby-feature-section {
            padding: 60px 0;
        }
    }

    .elby-feature-image-wrapper {
        position: relative;
        padding: 30px;
    }

    .elby-feature-deco {
        position: absolute;
        z-index: 0;
    }

    .elby-feature-deco-dots {
        width: 60px;
        height: 60px;
        background-image: radial-gradient(circle, ' . $navcolor . ' 2px, transparent 2px);
        background-size: 12px 12px;
        opacity: 0.25;
        top: 0;
        left: 0;
    }

    .elby-feature-deco-circle {
        width: 140px;
        height: 140px;
        background: rgba(236, 72, 153, 0.1);
        border-radius: 50%;
        top: 50px;
        left: 60px;
    }

    .elby-feature-deco-dot-1 {
        width: 10px;
        height: 10px;
        background: ' . $navcolor . ';
        opacity: 0.4;
        border-radius: 50%;
        top: 20px;
        right: 30%;
    }

    .elby-feature-deco-dot-2 {
        width: 6px;
        height: 6px;
        background: ' . $navcolor . ';
        opacity: 0.25;
        border-radius: 50%;
        bottom: 40px;
        left: 20px;
    }

    .elby-feature-image {
        position: relative;
        z-index: 1;
        width: 100%;
        height: auto;
        border-radius: 16px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
    }

    .elby-feature-placeholder {
        position: relative;
        z-index: 1;
        width: 100%;
        height: 300px;
        background: linear-gradient(135deg, rgba(37, 99, 235, 0.05) 0%, rgba(236, 72, 153, 0.08) 100%);
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .elby-feature-placeholder i {
        font-size: 4rem;
        color: ' . $navcolor . ';
        opacity: 0.15;
    }

    .elby-feature-content {
        padding-left: 2rem;
    }

    @media (max-width: 991.98px) {
        .elby-feature-content {
            padding-left: 0;
            padding-top: 2rem;
            text-align: center;
        }
    }

    .elby-feature-title {
        font-size: 2rem;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 1.25rem;
        line-height: 1.3;
    }

    @media (max-width: 767.98px) {
        .elby-feature-title {
            font-size: 1.5rem;
        }
    }

    .elby-feature-text {
        font-size: 1rem;
        color: #64748b;
        line-height: 1.8;
        margin-bottom: 1.5rem;
    }

    .elby-feature-text p {
        margin-bottom: 0;
    }

    .elby-feature-link {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        color: #1e293b;
        font-weight: 600;
        font-size: 0.9375rem;
        text-decoration: none;
        transition: all 0.3s ease;
    }

    .elby-feature-link:hover {
        color: ' . $navcolor . ';
        gap: 0.75rem;
    }

    .elby-feature-link i {
        font-size: 0.875rem;
        transition: transform 0.3s ease;
    }

    .elby-feature-link:hover i {
        transform: translateX(4px);
    }

    /* Responsive */
    @media (max-width: 991.98px) {
        .elby-hero {
            text-align: center;
            padding: 30px 0 40px;
            min-height: auto;
        }

        .elby-hero-subheading {
            margin-left: auto;
            margin-right: auto;
        }

        .elby-hero-pagination {
            justify-content: center;
        }

        .elby-hero-features {
            justify-content: center;
        }

        .elby-hero-feature-card {
            max-width: none;
            flex: 1 1 calc(50% - 0.5rem);
        }
    }

    @media (max-width: 575.98px) {
        .elby-hero-feature-card {
            flex: 1 1 100%;
        }
    }

    /* =========================================== */
    /* CAMPUS LIFE GALLERY - Edwiser Style Grid   */
    /* =========================================== */

    .elby-campus-life {
        background: #fff;
        padding: 80px 0;
    }

    @media (max-width: 991.98px) {
        .elby-campus-life {
            padding: 60px 0;
        }
    }

    .elby-campus-gallery-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.5rem;
    }

    @media (max-width: 1199.98px) {
        .elby-campus-gallery-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    @media (max-width: 991.98px) {
        .elby-campus-gallery-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 575.98px) {
        .elby-campus-gallery-grid {
            grid-template-columns: 1fr;
        }
    }

    .elby-campus-card {
        position: relative;
        border-radius: 16px;
        overflow: hidden;
    }

    .elby-campus-image-wrapper {
        position: relative;
        aspect-ratio: 4 / 3;
        overflow: hidden;
    }

    .elby-campus-image {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.4s ease;
    }

    .elby-campus-card:hover .elby-campus-image {
        transform: scale(1.08);
    }

    .elby-campus-overlay {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        padding: 1.25rem;
        background: linear-gradient(transparent, rgba(0, 0, 0, 0.8));
        display: flex;
        align-items: flex-end;
    }

    .elby-campus-label {
        color: #fff;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: ' . $navcolor . ';
        padding: 0.4rem 0.85rem;
        border-radius: 4px;
        display: inline-block;
    }

    /* Navigation */
    .elby-campus-nav {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 1rem;
        margin-top: 2rem;
    }

    .elby-campus-nav-btn {
        width: 40px;
        height: 40px;
        border: 2px solid ' . $navcolor . ';
        background: transparent;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s ease;
        color: ' . $navcolor . ';
    }

    .elby-campus-nav-btn:hover {
        background: ' . $navcolor . ';
        color: #fff;
    }

    .elby-campus-nav-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: ' . $navcolor . ';
    }

    /* =========================================== */
    /* TESTIMONIALS SECTION                        */
    /* =========================================== */

    .elby-testimonials {
        background: #f8fafc;
        padding: 80px 0;
    }

    @media (max-width: 991.98px) {
        .elby-testimonials {
            padding: 60px 0;
        }
    }

    .elby-testimonial-card {
        background: #fff;
        border-radius: 16px;
        padding: 2rem;
        height: 100%;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
        border: 1px solid rgba(0, 0, 0, 0.04);
        transition: all 0.3s ease;
        text-align: center;
    }

    .elby-testimonial-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.12);
    }

    .elby-testimonial-header {
        display: flex;
        flex-direction: column;
        align-items: center;
        margin-bottom: 1.25rem;
    }

    .elby-testimonial-image {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        object-fit: cover;
        margin-bottom: 1rem;
        border: 3px solid #fff;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }

    .elby-testimonial-avatar {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background: linear-gradient(135deg, ' . $navcolor . ' 0%, #3b82f6 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        font-weight: 700;
        color: #fff;
        margin-bottom: 1rem;
    }

    .elby-testimonial-info {
        text-align: center;
    }

    .elby-testimonial-name {
        font-size: 1.125rem;
        font-weight: 700;
        color: ' . $navcolor . ';
        margin-bottom: 0.25rem;
    }

    .elby-testimonial-role {
        font-size: 0.875rem;
        color: #64748b;
        margin-bottom: 0;
    }

    .elby-testimonial-quote {
        font-size: 0.9375rem;
        color: #64748b;
        line-height: 1.7;
        margin: 0;
        font-style: normal;
    }

    .elby-quote-icon {
        color: ' . $navcolor . ';
        opacity: 0.2;
        font-size: 1.5rem;
        display: block;
        margin-bottom: 0.5rem;
    }

    /* =========================================== */
    /* STATISTICS SECTION                          */
    /* =========================================== */

    .elby-statistics {
        background: #fff;
        padding: 60px 0;
    }

    .elby-stat-item {
        text-align: center;
        padding: 1.5rem;
    }

    .elby-stat-icon {
        display: none;
    }

    .elby-stat-value {
        font-size: 3.5rem;
        font-weight: 800;
        color: ' . $navcolor . ';
        line-height: 1;
        margin-bottom: 0.75rem;
    }

    @media (max-width: 767.98px) {
        .elby-stat-value {
            font-size: 2.5rem;
        }
    }

    @media (max-width: 575.98px) {
        .elby-stat-value {
            font-size: 2rem;
        }
    }

    .elby-stat-number {
        color: ' . $navcolor . ';
    }

    .elby-stat-suffix {
        color: ' . $navcolor . ';
    }

    .elby-stat-label {
        font-size: 0.9375rem;
        color: #64748b;
        font-weight: 500;
    }

    /* =========================================== */
    /* EVENTS SECTION                              */
    /* =========================================== */

    .elby-events {
        background: #f8fafc;
        padding: 80px 0;
    }

    @media (max-width: 991.98px) {
        .elby-events {
            padding: 60px 0;
        }
    }

    .elby-event-card {
        display: flex;
        flex-direction: column;
        background: #fff;
        border-radius: 16px;
        overflow: hidden;
        text-decoration: none !important;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
        border: 1px solid rgba(0, 0, 0, 0.04);
        transition: all 0.3s ease;
        height: 100%;
    }

    .elby-event-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.12);
    }

    .elby-event-image-wrapper {
        height: 200px;
        overflow: hidden;
        background: linear-gradient(135deg, rgba(37, 99, 235, 0.1) 0%, rgba(236, 72, 153, 0.1) 100%);
    }

    .elby-event-image {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.4s ease;
    }

    .elby-event-card:hover .elby-event-image {
        transform: scale(1.08);
    }

    .elby-event-content {
        padding: 1.5rem;
        display: flex;
        gap: 1.25rem;
        align-items: flex-start;
        flex: 1;
    }

    .elby-event-date {
        flex-shrink: 0;
        text-align: center;
        color: ' . $navcolor . ';
    }

    .elby-event-day {
        display: block;
        font-size: 1.75rem;
        font-weight: 800;
        line-height: 1;
    }

    .elby-event-month {
        display: block;
        font-size: 0.8125rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-top: 0.25rem;
    }

    .elby-event-info {
        flex: 1;
    }

    .elby-event-title {
        font-size: 1.125rem;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 0.5rem;
        line-height: 1.4;
    }

    .elby-event-description {
        font-size: 0.875rem;
        color: #64748b;
        margin-bottom: 0;
        line-height: 1.6;
    }

    @media (max-width: 575.98px) {
        .elby-event-content {
            flex-direction: column;
            gap: 1rem;
        }
        .elby-event-date {
            display: flex;
            gap: 0.5rem;
            align-items: baseline;
        }
        .elby-event-month {
            margin-top: 0;
        }
    }

    /* =========================================== */
    /* FOOTER                                      */
    /* =========================================== */

    .elby-footer {
        background-color: #111827;
        color: #e5e7eb;
    }

    .elby-footer-main {
        padding: 4rem 0 3rem;
    }

    @media (max-width: 768px) {
        .elby-footer-main {
            padding: 3rem 0 2rem;
        }
    }

    .elby-footer-brand {
        margin-bottom: 1.5rem;
    }

    .elby-footer-logo {
        max-height: 50px;
        width: auto;
        filter: brightness(0) invert(1);
    }

    .elby-footer-sitename {
        color: #fff;
        font-weight: 700;
    }

    .elby-footer-description {
        color: #e5e7eb;
        font-size: 0.9375rem;
        line-height: 1.7;
    }

    .elby-footer-description p {
        margin-bottom: 0;
        color: #e5e7eb;
    }

    .elby-social-links {
        display: flex;
        gap: 0.75rem;
    }

    .elby-social-link {
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: rgba(255, 255, 255, 0.1);
        color: #fff;
        border-radius: 50%;
        transition: all 0.3s ease;
        text-decoration: none;
    }

    .elby-social-link:hover {
        background-color: ' . $navcolor . ';
        color: #fff;
        transform: translateY(-3px);
    }

    .elby-social-link i {
        font-size: 1rem;
    }

    .elby-footer-heading {
        color: #fff;
        font-size: 1rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 1.5rem;
    }

    .elby-footer-links ul {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .elby-footer-links li {
        margin-bottom: 0.75rem;
    }

    .elby-footer-links a {
        color: #d1d5db;
        text-decoration: none;
        transition: all 0.3s ease;
        font-size: 0.9375rem;
    }

    .elby-footer-links a:hover {
        color: #fff;
        padding-left: 5px;
    }

    .elby-footer-links p {
        margin-bottom: 0.75rem;
        color: #d1d5db;
        font-size: 0.9375rem;
    }

    .elby-footer-links p a {
        color: #d1d5db;
    }

    .elby-footer-links p a:hover {
        color: #fff;
    }

    .elby-footer-contact {
        color: #d1d5db;
    }

    .elby-footer-contact .small {
        font-size: 0.875rem;
        color: #d1d5db !important;
    }

    .elby-footer-contact p {
        color: #d1d5db !important;
    }

    .elby-footer-contact .text-muted {
        color: #d1d5db !important;
    }

    .elby-footer-bottom {
        padding: 1.5rem 0;
        border-top: 1px solid rgba(255, 255, 255, 0.15);
        background-color: rgba(0, 0, 0, 0.2);
    }

    .elby-copyright {
        color: #d1d5db;
        font-size: 0.875rem;
    }

    .elby-powered {
        color: #d1d5db;
        font-size: 0.875rem;
    }

    .elby-powered a {
        color: #fff;
        text-decoration: none;
    }

    .elby-powered a:hover {
        color: ' . $navcolor . ';
    }

    /* Footer overrides for Moodle default elements */
    .elby-footer .popover-region,
    .elby-footer a:not(.elby-social-link) {
        color: #d1d5db;
    }

    .elby-footer a:not(.elby-social-link):hover {
        color: #fff;
    }

    /* Data retention link styling */
    .elby-footer .footer-content-debugging,
    .elby-footer .tool_dataprivacy {
        color: #d1d5db !important;
    }

    /* Moodle standard footer elements */
    .elby-footer #page-footer,
    .elby-footer .footer-link-login,
    .elby-footer .logininfo,
    .elby-footer .tool_dataprivacy-footer,
    .elby-footer .footer-popover {
        color: #d1d5db !important;
    }

    .elby-footer .tool_dataprivacy-footer a,
    .elby-footer .footer-link-login a {
        color: #d1d5db !important;
    }

    .elby-footer .tool_dataprivacy-footer a:hover,
    .elby-footer .footer-link-login a:hover {
        color: #fff !important;
    }

    /* Any text-muted overrides in footer */
    .elby-footer .text-muted {
        color: #d1d5db !important;
    }

    /* Global footer link styles outside the themed footer (Moodle standard) */
    #page-wrapper > .tool_dataprivacy,
    #page-wrapper .footer-content-debugging {
        background-color: #111827;
        color: #d1d5db;
        padding: 0.75rem 0;
        text-align: center;
    }

    #page-wrapper > .tool_dataprivacy a,
    #page-wrapper .footer-content-debugging a {
        color: #d1d5db;
    }

    #page-wrapper > .tool_dataprivacy a:hover,
    #page-wrapper .footer-content-debugging a:hover {
        color: #fff;
    }

    @media (max-width: 768px) {
        .elby-footer-main {
            text-align: center;
        }
        .elby-footer-main .col-lg-4,
        .elby-footer-main .col-lg-2 {
            margin-bottom: 2rem;
        }
        .elby-social-links {
            justify-content: center;
        }
        .elby-footer-bottom {
            text-align: center;
        }
        .elby-footer-bottom .col-md-6 {
            margin-bottom: 0.5rem;
        }
        .elby-footer-bottom .text-md-end {
            text-align: center !important;
        }
    }
    ';

    // =============================================
    // COURSE STYLING - Dynamic Branding
    // =============================================

    // Course Card Style.
    $coursecardstyle = get_config('theme_elby', 'coursecardstyle') ?: 'default';
    $coursecardshadow = get_config('theme_elby', 'coursecardshadow');
    $coursecardhover = get_config('theme_elby', 'coursecardhover');
    $courseprogresscolor = get_config('theme_elby', 'courseprogresscolor') ?: $navcolor;
    $coursecompletioncolor = get_config('theme_elby', 'coursecompletioncolor') ?: '#22c55e';
    $activityiconcolor = get_config('theme_elby', 'activityiconcolor') ?: $navcolor;

    // Course Cards Base Styles.
    $scss .= '
    /* Course Cards - Site-wide Branding */
    .card.dashboard-card,
    .coursebox,
    .course-info-container,
    .course-card {
        border-radius: 12px;
        overflow: hidden;
        transition: all 0.3s ease;
    }
    ';

    // Card style variants.
    switch ($coursecardstyle) {
        case 'bordered':
            $scss .= '
            .card.dashboard-card,
            .coursebox,
            .course-card {
                border: 2px solid ' . $navcolor . ';
                background: #fff;
            }
            .dashboard-card .card-img-top,
            .coursebox .courseimage {
                border-bottom: 2px solid ' . $navcolor . ';
            }
            ';
            break;
        case 'gradient':
            $scss .= '
            .dashboard-card .card-img-top,
            .coursebox .courseimage,
            .course-card .courseimage {
                position: relative;
            }
            .dashboard-card .card-img-top::after,
            .coursebox .courseimage::after {
                content: "";
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: linear-gradient(135deg, rgba(' . theme_elby_hex2rgb($navcolor) . ', 0.1) 0%, rgba(' . theme_elby_hex2rgb($navcolor) . ', 0.25) 100%);
                pointer-events: none;
            }
            ';
            break;
        case 'minimal':
            $scss .= '
            .card.dashboard-card,
            .coursebox,
            .course-card {
                border: none;
                box-shadow: none;
                background: transparent;
            }
            .dashboard-card .card-body,
            .coursebox .content {
                padding: 1rem 0;
            }
            ';
            break;
        default:
            // Default style - clean with subtle shadow.
            $scss .= '
            .card.dashboard-card,
            .coursebox,
            .course-card {
                border: 1px solid rgba(0, 0, 0, 0.08);
                background: #fff;
            }
            ';
    }

    // Card shadow.
    if ($coursecardshadow !== '0') {
        $scss .= '
        .card.dashboard-card,
        .coursebox,
        .course-card {
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        }
        ';
    }

    // Card hover effect.
    if ($coursecardhover !== '0') {
        $scss .= '
        .card.dashboard-card:hover,
        .coursebox:hover,
        .course-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
        }
        ';
    }

    // Progress Bars.
    $scss .= '
    /* Progress Bars - Brand Color */
    .progress-bar,
    .progress-bar-animated,
    .completion-progress .progress-bar,
    .completionprogress .progress-bar,
    .course-progressbar .progress-bar {
        background-color: ' . $courseprogresscolor . ' !important;
    }

    .progress {
        border-radius: 50px;
        height: 8px;
        background-color: #e5e7eb;
    }

    .course-progress-text {
        color: ' . $courseprogresscolor . ';
        font-weight: 600;
    }
    ';

    // Completion Badges.
    $scss .= '
    /* Completion Badges */
    .badge-success,
    .badge.badge-success,
    .completion-complete,
    .completion-y,
    .completionstate-y {
        background-color: ' . $coursecompletioncolor . ' !important;
        color: #fff !important;
    }

    .activity-complete .activityiconcontainer,
    .activity-complete .activityicon {
        position: relative;
    }

    .activity-complete .activityiconcontainer::after {
        content: "✓";
        position: absolute;
        bottom: -4px;
        right: -4px;
        width: 18px;
        height: 18px;
        background: ' . $coursecompletioncolor . ';
        color: #fff;
        border-radius: 50%;
        font-size: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
    }
    ';

    // Activity Icons.
    $scss .= '
    /* Activity Icons - Brand Color */
    .activityiconcontainer,
    .activity-icon,
    .modicon {
        background-color: rgba(' . theme_elby_hex2rgb($activityiconcolor) . ', 0.1) !important;
    }

    .activityiconcontainer .activityicon,
    .activity-icon img,
    .modicon img {
        filter: none;
    }

    .activityiconcontainer.courseicon,
    .activityiconcontainer.modicon_assign,
    .activityiconcontainer.modicon_quiz,
    .activityiconcontainer.modicon_forum,
    .activityiconcontainer.modicon_resource,
    .activityiconcontainer.modicon_page,
    .activityiconcontainer.modicon_url,
    .activityiconcontainer.modicon_folder,
    .activityiconcontainer.modicon_book,
    .activityiconcontainer.modicon_glossary,
    .activityiconcontainer.modicon_wiki,
    .activityiconcontainer.modicon_data,
    .activityiconcontainer.modicon_lesson,
    .activityiconcontainer.modicon_workshop,
    .activityiconcontainer.modicon_feedback,
    .activityiconcontainer.modicon_choice,
    .activityiconcontainer.modicon_survey,
    .activityiconcontainer.modicon_scorm,
    .activityiconcontainer.modicon_h5pactivity,
    .activityiconcontainer.modicon_lti {
        background-color: rgba(' . theme_elby_hex2rgb($activityiconcolor) . ', 0.12) !important;
    }

    /* Activity names on hover */
    .aalink:hover .instancename,
    .activity-item:hover .activityname a {
        color: ' . $activityiconcolor . ';
    }
    ';

    // =============================================
    // BUTTONS & FORMS - Dynamic Branding
    // =============================================

    $buttonradius = get_config('theme_elby', 'buttonradius') ?: 'rounded';
    $buttonstyle = get_config('theme_elby', 'buttonstyle') ?: 'solid';
    $inputfocuscolor = get_config('theme_elby', 'inputfocuscolor') ?: $navcolor;
    $inputradius = get_config('theme_elby', 'inputradius') ?: 'rounded';

    // Button Radius.
    $btnRadiusValue = '6px';
    switch ($buttonradius) {
        case 'sharp':
            $btnRadiusValue = '0';
            break;
        case 'pill':
            $btnRadiusValue = '50px';
            break;
        default:
            $btnRadiusValue = '6px';
    }

    $scss .= '
    /* Buttons - Border Radius */
    .btn,
    .btn-primary,
    .btn-secondary,
    .btn-success,
    .btn-danger,
    .btn-warning,
    .btn-info,
    .btn-outline-primary,
    .btn-outline-secondary,
    button[type="submit"],
    input[type="submit"],
    .form-submit {
        border-radius: ' . $btnRadiusValue . ' !important;
    }
    ';

    // Button Style.
    switch ($buttonstyle) {
        case 'gradient':
            $scss .= '
            /* Buttons - Gradient Style */
            .btn-primary {
                background: linear-gradient(135deg, ' . $navcolor . ' 0%, #3b82f6 100%) !important;
                border: none !important;
                box-shadow: 0 4px 15px rgba(' . theme_elby_hex2rgb($navcolor) . ', 0.3);
            }
            .btn-primary:hover {
                background: linear-gradient(135deg, #3b82f6 0%, ' . $navcolor . ' 100%) !important;
                box-shadow: 0 6px 20px rgba(' . theme_elby_hex2rgb($navcolor) . ', 0.4);
                transform: translateY(-2px);
            }
            .btn-secondary {
                background: linear-gradient(135deg, #64748b 0%, #475569 100%) !important;
                border: none !important;
            }
            ';
            break;
        case 'outline':
            $scss .= '
            /* Buttons - Outline Style */
            .btn-primary {
                background: transparent !important;
                border: 2px solid ' . $navcolor . ' !important;
                color: ' . $navcolor . ' !important;
            }
            .btn-primary:hover {
                background: ' . $navcolor . ' !important;
                color: #fff !important;
            }
            .btn-secondary {
                background: transparent !important;
                border: 2px solid #64748b !important;
                color: #64748b !important;
            }
            .btn-secondary:hover {
                background: #64748b !important;
                color: #fff !important;
            }
            ';
            break;
        default:
            // Solid style - default Bootstrap behavior enhanced.
            $scss .= '
            /* Buttons - Solid Style */
            .btn-primary {
                background-color: ' . $navcolor . ' !important;
                border-color: ' . $navcolor . ' !important;
                transition: all 0.3s ease;
            }
            .btn-primary:hover {
                filter: brightness(0.9);
                transform: translateY(-1px);
            }
            ';
    }

    // Input Radius.
    $inputRadiusValue = '6px';
    switch ($inputradius) {
        case 'sharp':
            $inputRadiusValue = '0';
            break;
        case 'pill':
            $inputRadiusValue = '50px';
            break;
        default:
            $inputRadiusValue = '6px';
    }

    $scss .= '
    /* Form Inputs - Border Radius */
    .form-control,
    .form-select,
    .custom-select,
    input[type="text"],
    input[type="email"],
    input[type="password"],
    input[type="search"],
    input[type="number"],
    input[type="tel"],
    input[type="url"],
    textarea,
    select {
        border-radius: ' . $inputRadiusValue . ' !important;
    }

    /* Form Inputs - Focus States */
    .form-control:focus,
    .form-select:focus,
    .custom-select:focus,
    input[type="text"]:focus,
    input[type="email"]:focus,
    input[type="password"]:focus,
    input[type="search"]:focus,
    input[type="number"]:focus,
    textarea:focus,
    select:focus {
        border-color: ' . $inputfocuscolor . ' !important;
        box-shadow: 0 0 0 0.2rem rgba(' . theme_elby_hex2rgb($inputfocuscolor) . ', 0.25) !important;
        outline: none;
    }

    /* Checkbox & Radio - Brand Color */
    .form-check-input:checked {
        background-color: ' . $navcolor . ' !important;
        border-color: ' . $navcolor . ' !important;
    }

    .form-check-input:focus {
        border-color: ' . $inputfocuscolor . ' !important;
        box-shadow: 0 0 0 0.2rem rgba(' . theme_elby_hex2rgb($inputfocuscolor) . ', 0.25) !important;
    }

    /* Validation States */
    .is-valid,
    .was-validated .form-control:valid {
        border-color: ' . $coursecompletioncolor . ' !important;
    }
    .is-valid:focus,
    .was-validated .form-control:valid:focus {
        box-shadow: 0 0 0 0.2rem rgba(34, 197, 94, 0.25) !important;
    }
    ';

    // =============================================
    // NAVIGATION - Dynamic Branding
    // =============================================

    $breadcrumbstyle = get_config('theme_elby', 'breadcrumbstyle') ?: 'default';
    $breadcrumbcolor = get_config('theme_elby', 'breadcrumbcolor') ?: $navcolor;
    $navbadgecolor = get_config('theme_elby', 'navbadgecolor') ?: '#ef4444';
    $dashboardcardstyle = get_config('theme_elby', 'dashboardcardstyle') ?: 'default';

    // Breadcrumb Styles.
    $scss .= '
    /* Breadcrumbs - Base Styles */
    .breadcrumb {
        background: transparent;
        padding: 0.75rem 0;
        margin-bottom: 1rem;
    }

    .breadcrumb-item a {
        color: #64748b;
        text-decoration: none;
        transition: color 0.2s ease;
    }

    .breadcrumb-item a:hover {
        color: ' . $breadcrumbcolor . ';
    }

    .breadcrumb-item.active {
        color: ' . $breadcrumbcolor . ' !important;
        font-weight: 600;
    }
    ';

    switch ($breadcrumbstyle) {
        case 'arrows':
            $scss .= '
            /* Breadcrumbs - Arrow Style */
            .breadcrumb-item + .breadcrumb-item::before {
                content: "→" !important;
                color: #94a3b8;
                padding-right: 0.75rem;
            }
            ';
            break;
        case 'pills':
            $scss .= '
            /* Breadcrumbs - Pills Style */
            .breadcrumb {
                gap: 0.5rem;
            }
            .breadcrumb-item {
                background: #f1f5f9;
                padding: 0.375rem 0.875rem;
                border-radius: 50px;
            }
            .breadcrumb-item.active {
                background: ' . $breadcrumbcolor . ';
                color: #fff !important;
            }
            .breadcrumb-item + .breadcrumb-item::before {
                display: none;
            }
            .breadcrumb-item a {
                color: #475569;
            }
            ';
            break;
        default:
            // Default breadcrumb separator.
            $scss .= '
            .breadcrumb-item + .breadcrumb-item::before {
                content: "/" !important;
                color: #cbd5e1;
            }
            ';
    }

    // Notification Badges.
    $scss .= '
    /* Notification Badges */
    .badge-primary,
    .popover-region-toggle .count-container,
    .count-container,
    .badge-notification {
        background-color: ' . $navbadgecolor . ' !important;
        color: #fff !important;
    }

    .popover-region-toggle .count-container {
        border-radius: 50%;
        min-width: 18px;
        height: 18px;
        font-size: 11px;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    /* Message notification */
    .message-drawer .badge,
    .messaging-area-container .badge {
        background-color: ' . $navbadgecolor . ' !important;
    }
    ';

    // Dashboard Card Styles.
    switch ($dashboardcardstyle) {
        case 'bordered':
            $scss .= '
            /* Dashboard Cards - Bordered */
            .block,
            .card:not(.dashboard-card),
            .block_myoverview .card {
                border: 2px solid #e2e8f0 !important;
                border-radius: 12px;
                box-shadow: none;
            }
            .block .card-header,
            .block .header {
                border-bottom: 2px solid #e2e8f0;
                background: transparent;
            }
            ';
            break;
        case 'elevated':
            $scss .= '
            /* Dashboard Cards - Elevated */
            .block,
            .card:not(.dashboard-card),
            .block_myoverview .card {
                border: none !important;
                border-radius: 16px;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            }
            .block:hover,
            .card:not(.dashboard-card):hover {
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.12);
                transform: translateY(-2px);
            }
            ';
            break;
        default:
            // Default dashboard card style.
            $scss .= '
            /* Dashboard Cards - Default */
            .block,
            .card:not(.dashboard-card) {
                border: 1px solid #e5e7eb;
                border-radius: 12px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            }
            ';
    }

    // Additional dashboard styling.
    $scss .= '
    /* Dashboard Overview - Brand Colors */
    .block_myoverview .nav-tabs .nav-link.active {
        color: ' . $navcolor . ';
        border-bottom-color: ' . $navcolor . ';
    }

    .block_myoverview .dropdown-item.active,
    .block_myoverview .dropdown-item:active {
        background-color: ' . $navcolor . ';
    }

    /* Timeline - Brand Color */
    .block_timeline .event .icon {
        color: ' . $navcolor . ';
    }

    /* Calendar - Brand Color */
    .calendar_event_course,
    .calendar_event_site {
        border-left-color: ' . $navcolor . ' !important;
    }

    .calendar-controls .arrow_link {
        color: ' . $navcolor . ';
    }

    .calendar-controls .arrow_link:hover {
        color: ' . $navcolor . ';
        filter: brightness(0.8);
    }
    ';

    // Raw SCSS from admin (highest cascade priority).
    if (!empty($settings->scss)) {
        $scss .= $settings->scss;
    }

    return $scss;
}

/**
 * Serve theme plugin files.
 *
 * @param stdClass $course Course object.
 * @param stdClass $cm Course module object.
 * @param context $context Context object.
 * @param string $filearea File area name.
 * @param array $args Path arguments.
 * @param bool $forcedownload Force download flag.
 * @param array $options Additional options.
 * @return bool|void
 */
function theme_elby_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {

    if ($context->contextlevel != CONTEXT_SYSTEM) {
        send_file_not_found();
    }

    $theme = theme_config::load('elby');

    // Valid file areas.
    $validareas = [
        'logo',
        'logocompact',
        'favicon',
        'loginbackgroundimage',
        'herobackgroundimage',
        'herosecondaryimage',
        'featuresectionimage',
        // Hero slide images (1-10).
        'heroslide1mainimage',
        'heroslide1secondaryimage',
        'heroslide2mainimage',
        'heroslide2secondaryimage',
        'heroslide3mainimage',
        'heroslide3secondaryimage',
        'heroslide4mainimage',
        'heroslide4secondaryimage',
        'heroslide5mainimage',
        'heroslide5secondaryimage',
        'heroslide6mainimage',
        'heroslide6secondaryimage',
        'heroslide7mainimage',
        'heroslide7secondaryimage',
        'heroslide8mainimage',
        'heroslide8secondaryimage',
        'heroslide9mainimage',
        'heroslide9secondaryimage',
        'heroslide10mainimage',
        'heroslide10secondaryimage',
        // Campus life images.
        'campuslife1image',
        'campuslife2image',
        'campuslife3image',
        'campuslife4image',
        // Event images.
        'event1image',
        'event2image',
        'event3image',
        // Testimonial images.
        'testimonial1image',
        'testimonial2image',
        'testimonial3image',
        // Category placeholder image.
        'categoriesplaceholder',
    ];

    if (in_array($filearea, $validareas)) {
        $options['cacheability'] = 'public';
        return $theme->setting_file_serve($filearea, $args, $forcedownload, $options);
    }

    send_file_not_found();
}

/**
 * Inject Google Fonts into page head.
 *
 * @return string HTML to inject into <head>.
 */
function theme_elby_before_standard_head_html_generation() {
    $theme = theme_config::load('elby');
    $html = '';

    $fonts = [];
    $fontbody = $theme->settings->fontbody ?? '';
    $fontheadings = $theme->settings->fontheadings ?? '';

    $googlefonts = [
        'Open Sans', 'Roboto', 'Poppins', 'Inter', 'Lato',
        'Montserrat', 'Nunito', 'Raleway', 'Source Sans Pro', 'Work Sans',
    ];

    if (!empty($fontbody) && in_array($fontbody, $googlefonts)) {
        $fonts[] = str_replace(' ', '+', $fontbody) . ':wght@400;500;600;700';
    }

    if (!empty($fontheadings) && in_array($fontheadings, $googlefonts) && $fontheadings !== $fontbody) {
        $fonts[] = str_replace(' ', '+', $fontheadings) . ':wght@400;500;600;700';
    }

    if (!empty($fonts)) {
        $fonturl = 'https://fonts.googleapis.com/css2?family=' . implode('&family=', $fonts) . '&display=swap';
        $html .= '<link rel="preconnect" href="https://fonts.googleapis.com">';
        $html .= '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
        $html .= '<link href="' . $fonturl . '" rel="stylesheet">';
    }

    return $html;
}

/**
 * Page init callback - loads JavaScript for admin settings page.
 *
 * @param moodle_page $page The moodle page object.
 */
function theme_elby_page_init(moodle_page $page) {
    // Load slide settings JS on the theme settings page.
    if (strpos($page->pagetype, 'admin-setting-themesettingelby') !== false) {
        $page->requires->js_call_amd('theme_elby/slidesettings', 'init');
    }
}
