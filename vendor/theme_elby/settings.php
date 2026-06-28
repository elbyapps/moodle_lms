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
 * Theme settings.
 *
 * @package    theme_elby
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    // Create tabbed settings page.
    $settings = new theme_boost_admin_settingspage_tabs('themesettingelby',
        get_string('configtitle', 'theme_elby'));

    // =========================================================================
    // TAB 1: GENERAL / BRANDING
    // =========================================================================
    $page = new admin_settingpage('theme_elby_general',
        get_string('generalsettings', 'theme_elby'));

    // Logo.
    $name = 'theme_elby/logo';
    $title = get_string('logo', 'theme_elby');
    $description = get_string('logo_desc', 'theme_elby');
    $setting = new admin_setting_configstoredfile($name, $title, $description, 'logo', 0,
        ['maxfiles' => 1, 'accepted_types' => ['.jpg', '.jpeg', '.png', '.svg', '.gif']]);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Compact Logo.
    $name = 'theme_elby/logocompact';
    $title = get_string('logocompact', 'theme_elby');
    $description = get_string('logocompact_desc', 'theme_elby');
    $setting = new admin_setting_configstoredfile($name, $title, $description, 'logocompact', 0,
        ['maxfiles' => 1, 'accepted_types' => ['.jpg', '.jpeg', '.png', '.svg', '.gif']]);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Favicon.
    $name = 'theme_elby/favicon';
    $title = get_string('favicon', 'theme_elby');
    $description = get_string('favicon_desc', 'theme_elby');
    $setting = new admin_setting_configstoredfile($name, $title, $description, 'favicon', 0,
        ['maxfiles' => 1, 'accepted_types' => ['.ico', '.png']]);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Show Site Name.
    $name = 'theme_elby/showsitename';
    $title = get_string('showsitename', 'theme_elby');
    $description = get_string('showsitename_desc', 'theme_elby');
    $setting = new admin_setting_configcheckbox($name, $title, $description, 1);
    $page->add($setting);

    $settings->add($page);

    // =========================================================================
    // TAB 2: COLORS
    // =========================================================================
    $page = new admin_settingpage('theme_elby_colors',
        get_string('colorsettings', 'theme_elby'));

    // Brand Color.
    $name = 'theme_elby/brandcolor';
    $title = get_string('brandcolor', 'theme_elby');
    $description = get_string('brandcolor_desc', 'theme_elby');
    $default = '#2563eb';
    $setting = new admin_setting_configcolourpicker($name, $title, $description, $default);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Secondary Color.
    $name = 'theme_elby/secondarycolor';
    $title = get_string('secondarycolor', 'theme_elby');
    $description = get_string('secondarycolor_desc', 'theme_elby');
    $default = '#64748b';
    $setting = new admin_setting_configcolourpicker($name, $title, $description, $default);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Success Color.
    $name = 'theme_elby/successcolor';
    $title = get_string('successcolor', 'theme_elby');
    $description = get_string('successcolor_desc', 'theme_elby');
    $default = '#22c55e';
    $setting = new admin_setting_configcolourpicker($name, $title, $description, $default);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Info Color.
    $name = 'theme_elby/infocolor';
    $title = get_string('infocolor', 'theme_elby');
    $description = get_string('infocolor_desc', 'theme_elby');
    $default = '#06b6d4';
    $setting = new admin_setting_configcolourpicker($name, $title, $description, $default);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Warning Color.
    $name = 'theme_elby/warningcolor';
    $title = get_string('warningcolor', 'theme_elby');
    $description = get_string('warningcolor_desc', 'theme_elby');
    $default = '#fbbf24';
    $setting = new admin_setting_configcolourpicker($name, $title, $description, $default);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Danger Color.
    $name = 'theme_elby/dangercolor';
    $title = get_string('dangercolor', 'theme_elby');
    $description = get_string('dangercolor_desc', 'theme_elby');
    $default = '#ef4444';
    $setting = new admin_setting_configcolourpicker($name, $title, $description, $default);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Body Background Color.
    $name = 'theme_elby/bodybgcolor';
    $title = get_string('bodybgcolor', 'theme_elby');
    $description = get_string('bodybgcolor_desc', 'theme_elby');
    $default = '#f8f9fa';
    $setting = new admin_setting_configcolourpicker($name, $title, $description, $default);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Body Text Color.
    $name = 'theme_elby/bodytextcolor';
    $title = get_string('bodytextcolor', 'theme_elby');
    $description = get_string('bodytextcolor_desc', 'theme_elby');
    $default = '#1e293b';
    $setting = new admin_setting_configcolourpicker($name, $title, $description, $default);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    $settings->add($page);

    // =========================================================================
    // TAB 3: TYPOGRAPHY
    // =========================================================================
    $page = new admin_settingpage('theme_elby_typography',
        get_string('typographysettings', 'theme_elby'));

    // Font choices (Google Fonts).
    $fontchoices = [
        'inherit' => get_string('font_inherit', 'theme_elby'),
        'Open Sans' => 'Open Sans',
        'Roboto' => 'Roboto',
        'Poppins' => 'Poppins',
        'Inter' => 'Inter',
        'Lato' => 'Lato',
        'Montserrat' => 'Montserrat',
        'Nunito' => 'Nunito',
        'Raleway' => 'Raleway',
        'Source Sans Pro' => 'Source Sans Pro',
        'Work Sans' => 'Work Sans',
    ];

    // Body Font.
    $name = 'theme_elby/fontbody';
    $title = get_string('fontbody', 'theme_elby');
    $description = get_string('fontbody_desc', 'theme_elby');
    $setting = new admin_setting_configselect($name, $title, $description, 'inherit', $fontchoices);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Heading Font.
    $name = 'theme_elby/fontheadings';
    $title = get_string('fontheadings', 'theme_elby');
    $description = get_string('fontheadings_desc', 'theme_elby');
    $setting = new admin_setting_configselect($name, $title, $description, 'inherit', $fontchoices);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Font Size.
    $name = 'theme_elby/fontsize';
    $title = get_string('fontsize', 'theme_elby');
    $description = get_string('fontsize_desc', 'theme_elby');
    $sizechoices = [
        '' => get_string('fontsize_default', 'theme_elby'),
        '0.875rem' => get_string('fontsize_small', 'theme_elby'),
        '0.9375rem' => get_string('fontsize_medium', 'theme_elby'),
        '1rem' => get_string('fontsize_large', 'theme_elby'),
    ];
    $setting = new admin_setting_configselect($name, $title, $description, '', $sizechoices);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    $settings->add($page);

    // =========================================================================
    // TAB 4: HERO SECTION
    // =========================================================================
    $page = new admin_settingpage('theme_elby_hero',
        get_string('herosettings', 'theme_elby'));

    // Enable Hero.
    $name = 'theme_elby/heroenabled';
    $title = get_string('heroenabled', 'theme_elby');
    $description = get_string('heroenabled_desc', 'theme_elby');
    $setting = new admin_setting_configcheckbox($name, $title, $description, 1);
    $page->add($setting);

    // Hero Style.
    $name = 'theme_elby/herostyle';
    $title = get_string('herostyle', 'theme_elby');
    $description = get_string('herostyle_desc', 'theme_elby');
    $choices = [
        'collage' => get_string('herostyle_collage', 'theme_elby'),
        'fullwidth' => get_string('herostyle_fullwidth', 'theme_elby'),
    ];
    $setting = new admin_setting_configselect($name, $title, $description, 'collage', $choices);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Hero Carousel Settings Heading.
    $page->add(new admin_setting_heading('theme_elby/herocarouselheading',
        get_string('herocarouselsettings', 'theme_elby'), ''));

    // Hero Slide Count.
    $name = 'theme_elby/heroslidecount';
    $title = get_string('heroslidecount', 'theme_elby');
    $description = get_string('heroslidecount_desc', 'theme_elby');
    $setting = new admin_setting_configtext($name, $title, $description, '1');
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Hero Auto-rotate.
    $name = 'theme_elby/heroautorotate';
    $title = get_string('heroautorotate', 'theme_elby');
    $description = get_string('heroautorotate_desc', 'theme_elby');
    $setting = new admin_setting_configcheckbox($name, $title, $description, 1);
    $page->add($setting);

    // Hero Interval.
    $name = 'theme_elby/herointerval';
    $title = get_string('herointerval', 'theme_elby');
    $description = get_string('herointerval_desc', 'theme_elby');
    $choices = [
        '3000' => '3 ' . get_string('seconds', 'theme_elby'),
        '5000' => '5 ' . get_string('seconds', 'theme_elby'),
        '7000' => '7 ' . get_string('seconds', 'theme_elby'),
        '10000' => '10 ' . get_string('seconds', 'theme_elby'),
    ];
    $setting = new admin_setting_configselect($name, $title, $description, '5000', $choices);
    $page->add($setting);

    // Get the current slide count from settings (default to 1).
    $slidecount = (int) get_config('theme_elby', 'heroslidecount');
    $slidecount = max(1, min(10, $slidecount ?: 1));

    // Hero Slides - only render the number of slides specified.
    for ($i = 1; $i <= $slidecount; $i++) {
        $page->add(new admin_setting_heading("theme_elby/heroslide{$i}heading",
            get_string('heroslide', 'theme_elby') . ' ' . $i, ''));

        // Slide Main Image.
        $name = "theme_elby/heroslide{$i}mainimage";
        $title = get_string('heroslideimage', 'theme_elby');
        $description = get_string('heroslideimage_desc', 'theme_elby');
        $setting = new admin_setting_configstoredfile($name, $title, $description, "heroslide{$i}mainimage", 0,
            ['maxfiles' => 1, 'accepted_types' => ['.jpg', '.jpeg', '.png', '.webp', '.svg']]);
        $setting->set_updatedcallback('theme_reset_all_caches');
        $page->add($setting);

        // Slide Secondary Image.
        $name = "theme_elby/heroslide{$i}secondaryimage";
        $title = get_string('heroslidesecondaryimage', 'theme_elby');
        $description = get_string('heroslidesecondaryimage_desc', 'theme_elby');
        $setting = new admin_setting_configstoredfile($name, $title, $description, "heroslide{$i}secondaryimage", 0,
            ['maxfiles' => 1, 'accepted_types' => ['.jpg', '.jpeg', '.png', '.webp', '.svg']]);
        $setting->set_updatedcallback('theme_reset_all_caches');
        $page->add($setting);

        // Slide Heading.
        $name = "theme_elby/heroslide{$i}heading";
        $title = get_string('heroslideheading', 'theme_elby');
        $default = ($i == 1) ? "LET'S DESIGN YOUR FUTURE!" : '';
        $setting = new admin_setting_configtext($name, $title, '', $default);
        $page->add($setting);

        // Slide Subheading.
        $name = "theme_elby/heroslide{$i}subheading";
        $title = get_string('heroslidesubheading', 'theme_elby');
        $default = ($i == 1) ? 'Our platform inspires a love of learning, encouraging creative thinking across all subjects.' : '';
        $setting = new admin_setting_configtextarea($name, $title, '', $default);
        $page->add($setting);

        // Slide CTA Button Text.
        $name = "theme_elby/heroslide{$i}ctabutton";
        $title = get_string('heroslidectabutton', 'theme_elby');
        $default = ($i == 1) ? 'Explore Academics' : '';
        $setting = new admin_setting_configtext($name, $title, '', $default);
        $page->add($setting);

        // Slide CTA URL.
        $name = "theme_elby/heroslide{$i}ctaurl";
        $title = get_string('heroslidectaurl', 'theme_elby');
        $default = ($i == 1) ? '/course/' : '';
        $setting = new admin_setting_configtext($name, $title, '', $default);
        $page->add($setting);
    }

    $settings->add($page);

    // =========================================================================
    // TAB 5: COURSE CATEGORIES
    // =========================================================================
    $page = new admin_settingpage('theme_elby_categories',
        get_string('categoriessettings', 'theme_elby'));

    // Enable Categories Section.
    $name = 'theme_elby/categoriesenabled';
    $title = get_string('categoriesenabled', 'theme_elby');
    $description = get_string('categoriesenabled_desc', 'theme_elby');
    $setting = new admin_setting_configcheckbox($name, $title, $description, 1);
    $page->add($setting);

    // Categories Section Title.
    $name = 'theme_elby/categoriestitle';
    $title = get_string('categoriestitle', 'theme_elby');
    $description = get_string('categoriestitle_desc', 'theme_elby');
    $setting = new admin_setting_configtext($name, $title, $description, 'Explore Our Programs');
    $page->add($setting);

    // Categories Section Subtitle.
    $name = 'theme_elby/categoriessubtitle';
    $title = get_string('categoriessubtitle', 'theme_elby');
    $description = get_string('categoriessubtitle_desc', 'theme_elby');
    $setting = new admin_setting_configtext($name, $title, $description, 'Choose from our diverse range of courses');
    $page->add($setting);

    // Category Header Background Color.
    $name = 'theme_elby/categoryheaderbg';
    $title = get_string('categoryheaderbg', 'theme_elby');
    $description = get_string('categoryheaderbg_desc', 'theme_elby');
    $default = '#1e293b';
    $setting = new admin_setting_configcolourpicker($name, $title, $description, $default);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Category Header Text Color.
    $name = 'theme_elby/categoryheadertextcolor';
    $title = get_string('categoryheadertextcolor', 'theme_elby');
    $description = get_string('categoryheadertextcolor_desc', 'theme_elby');
    $default = '#ffffff';
    $setting = new admin_setting_configcolourpicker($name, $title, $description, $default);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Placeholder Image for categories without images.
    $name = 'theme_elby/categoriesplaceholder';
    $title = get_string('categoriesplaceholder', 'theme_elby');
    $description = get_string('categoriesplaceholder_desc', 'theme_elby');
    $setting = new admin_setting_configstoredfile($name, $title, $description, 'categoriesplaceholder');
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    $settings->add($page);

    // =========================================================================
    // TAB 6: STATISTICS
    // =========================================================================
    $page = new admin_settingpage('theme_elby_statistics',
        get_string('statisticssettings', 'theme_elby'));

    // Enable Statistics.
    $name = 'theme_elby/statsenabled';
    $title = get_string('statsenabled', 'theme_elby');
    $description = get_string('statsenabled_desc', 'theme_elby');
    $setting = new admin_setting_configcheckbox($name, $title, $description, 1);
    $page->add($setting);

    // Statistics Section Title.
    $name = 'theme_elby/statstitle';
    $title = get_string('statstitle', 'theme_elby');
    $description = get_string('statstitle_desc', 'theme_elby');
    $setting = new admin_setting_configtext($name, $title, $description, 'Campus by the Numbers');
    $page->add($setting);

    // Statistics (4 items).
    $defaultstats = [
        ['200', '+', 'Academic Associations', 'fa-building'],
        ['100', '', 'Countries Represented', 'fa-globe'],
        ['18', '', 'Athletic Teams', 'fa-trophy'],
        ['3', '', 'Museums', 'fa-landmark'],
    ];

    for ($i = 1; $i <= 4; $i++) {
        $page->add(new admin_setting_heading("theme_elby/stat{$i}heading",
            get_string('statistic', 'theme_elby') . ' ' . $i, ''));

        $name = "theme_elby/stat{$i}value";
        $title = get_string('statvalue', 'theme_elby');
        $setting = new admin_setting_configtext($name, $title, '', $defaultstats[$i - 1][0]);
        $page->add($setting);

        $name = "theme_elby/stat{$i}suffix";
        $title = get_string('statsuffix', 'theme_elby');
        $setting = new admin_setting_configtext($name, $title, '', $defaultstats[$i - 1][1]);
        $page->add($setting);

        $name = "theme_elby/stat{$i}label";
        $title = get_string('statlabel', 'theme_elby');
        $setting = new admin_setting_configtext($name, $title, '', $defaultstats[$i - 1][2]);
        $page->add($setting);

        $name = "theme_elby/stat{$i}icon";
        $title = get_string('staticon', 'theme_elby');
        $setting = new admin_setting_configtext($name, $title, '', $defaultstats[$i - 1][3]);
        $page->add($setting);
    }

    $settings->add($page);

    // =========================================================================
    // TAB 7: TESTIMONIALS
    // =========================================================================
    $page = new admin_settingpage('theme_elby_testimonials',
        get_string('testimonialsettings', 'theme_elby'));

    // Enable Testimonials.
    $name = 'theme_elby/testimonialsenabled';
    $title = get_string('testimonialsenabled', 'theme_elby');
    $description = get_string('testimonialsenabled_desc', 'theme_elby');
    $setting = new admin_setting_configcheckbox($name, $title, $description, 1);
    $page->add($setting);

    // Testimonials Section Title.
    $name = 'theme_elby/testimonialstitle';
    $title = get_string('testimonialstitle', 'theme_elby');
    $description = get_string('testimonialstitle_desc', 'theme_elby');
    $setting = new admin_setting_configtext($name, $title, $description, 'Stories from Successful Careers');
    $page->add($setting);

    // Testimonial Count.
    $name = 'theme_elby/testimonialcount';
    $title = get_string('testimonialcount', 'theme_elby');
    $description = get_string('testimonialcount_desc', 'theme_elby');
    $choices = ['1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6'];
    $setting = new admin_setting_configselect($name, $title, $description, '3', $choices);
    $page->add($setting);

    // Testimonials (3 items for Phase 2).
    for ($i = 1; $i <= 3; $i++) {
        $page->add(new admin_setting_heading("theme_elby/testimonial{$i}heading",
            get_string('testimonial', 'theme_elby') . ' ' . $i, ''));

        $name = "theme_elby/testimonial{$i}image";
        $title = get_string('testimonialimage', 'theme_elby');
        $setting = new admin_setting_configstoredfile($name, $title, '', "testimonial{$i}image", 0,
            ['maxfiles' => 1, 'accepted_types' => ['.jpg', '.jpeg', '.png', '.webp']]);
        $page->add($setting);

        $name = "theme_elby/testimonial{$i}name";
        $title = get_string('testimonialname', 'theme_elby');
        $setting = new admin_setting_configtext($name, $title, '', '');
        $page->add($setting);

        $name = "theme_elby/testimonial{$i}role";
        $title = get_string('testimonialrole', 'theme_elby');
        $setting = new admin_setting_configtext($name, $title, '', '');
        $page->add($setting);

        $name = "theme_elby/testimonial{$i}quote";
        $title = get_string('testimonialquote', 'theme_elby');
        $setting = new admin_setting_configtextarea($name, $title, '', '');
        $page->add($setting);
    }

    $settings->add($page);

    // =========================================================================
    // TAB 8: CAMPUS LIFE
    // =========================================================================
    $page = new admin_settingpage('theme_elby_campuslife',
        get_string('campuslifesettings', 'theme_elby'));

    // Enable Campus Life.
    $name = 'theme_elby/campuslifeenabled';
    $title = get_string('campuslifeenabled', 'theme_elby');
    $description = get_string('campuslifeenabled_desc', 'theme_elby');
    $setting = new admin_setting_configcheckbox($name, $title, $description, 1);
    $page->add($setting);

    // Campus Life Title.
    $name = 'theme_elby/campuslifetitle';
    $title = get_string('campuslifetitle', 'theme_elby');
    $description = get_string('campuslifetitle_desc', 'theme_elby');
    $setting = new admin_setting_configtext($name, $title, $description, 'Campus life at {sitename}');
    $page->add($setting);

    // Campus Life Categories (4 tabs).
    for ($i = 1; $i <= 4; $i++) {
        $page->add(new admin_setting_heading("theme_elby/campuslifecategory{$i}heading",
            get_string('campuslifecategory', 'theme_elby') . ' ' . $i, ''));

        $name = "theme_elby/campuslife{$i}label";
        $title = get_string('campuslifelabel', 'theme_elby');
        $defaults = ['ATHLETICS', 'HEALTH', 'ARTS', 'COMMUNITY'];
        $setting = new admin_setting_configtext($name, $title, '', $defaults[$i - 1]);
        $page->add($setting);

        $name = "theme_elby/campuslife{$i}image";
        $title = get_string('campuslifeimage', 'theme_elby');
        $setting = new admin_setting_configstoredfile($name, $title, '', "campuslife{$i}image", 0,
            ['maxfiles' => 1, 'accepted_types' => ['.jpg', '.jpeg', '.png', '.webp']]);
        $setting->set_updatedcallback('theme_reset_all_caches');
        $page->add($setting);
    }

    $settings->add($page);

    // =========================================================================
    // TAB 9: ANNOUNCEMENTS
    // =========================================================================
    $page = new admin_settingpage('theme_elby_announcements',
        get_string('announcementsettings', 'theme_elby'));

    // Enable Announcements.
    $name = 'theme_elby/announcementsenabled';
    $title = get_string('announcementsenabled', 'theme_elby');
    $description = get_string('announcementsenabled_desc', 'theme_elby');
    $setting = new admin_setting_configcheckbox($name, $title, $description, 1);
    $page->add($setting);

    // Announcement Title.
    $name = 'theme_elby/announcementstitle';
    $title = get_string('announcementstitle', 'theme_elby');
    $description = get_string('announcementstitle_desc', 'theme_elby');
    $setting = new admin_setting_configtext($name, $title, $description, 'Announcement to students/candidates');
    $page->add($setting);

    // Announcement Content.
    $name = 'theme_elby/announcementscontent';
    $title = get_string('announcementscontent', 'theme_elby');
    $description = get_string('announcementscontent_desc', 'theme_elby');
    $setting = new admin_setting_confightmleditor($name, $title, $description, '');
    $page->add($setting);

    // Announcement Link Text.
    $name = 'theme_elby/announcementslinktext';
    $title = get_string('announcementslinktext', 'theme_elby');
    $setting = new admin_setting_configtext($name, $title, '', 'View all');
    $page->add($setting);

    // Announcement Link URL.
    $name = 'theme_elby/announcementslinkurl';
    $title = get_string('announcementslinkurl', 'theme_elby');
    $setting = new admin_setting_configtext($name, $title, '', '#');
    $page->add($setting);

    $settings->add($page);

    // =========================================================================
    // TAB 10: EVENTS
    // =========================================================================
    $page = new admin_settingpage('theme_elby_events',
        get_string('eventssettings', 'theme_elby'));

    // Enable Events.
    $name = 'theme_elby/eventsenabled';
    $title = get_string('eventsenabled', 'theme_elby');
    $description = get_string('eventsenabled_desc', 'theme_elby');
    $setting = new admin_setting_configcheckbox($name, $title, $description, 1);
    $page->add($setting);

    // Events Title.
    $name = 'theme_elby/eventstitle';
    $title = get_string('eventstitle', 'theme_elby');
    $description = get_string('eventstitle_desc', 'theme_elby');
    $setting = new admin_setting_configtext($name, $title, $description, 'Upcoming events');
    $page->add($setting);

    // Events (3 items).
    for ($i = 1; $i <= 3; $i++) {
        $page->add(new admin_setting_heading("theme_elby/event{$i}heading",
            get_string('event', 'theme_elby') . ' ' . $i, ''));

        $name = "theme_elby/event{$i}title";
        $title = get_string('eventtitle', 'theme_elby');
        $setting = new admin_setting_configtext($name, $title, '', '');
        $page->add($setting);

        $name = "theme_elby/event{$i}date";
        $title = get_string('eventdate', 'theme_elby');
        $description = get_string('eventdate_desc', 'theme_elby');
        $setting = new admin_setting_configtext($name, $title, $description, '');
        $page->add($setting);

        $name = "theme_elby/event{$i}description";
        $title = get_string('eventdescription', 'theme_elby');
        $setting = new admin_setting_configtextarea($name, $title, '', '');
        $page->add($setting);

        $name = "theme_elby/event{$i}url";
        $title = get_string('eventurl', 'theme_elby');
        $setting = new admin_setting_configtext($name, $title, '', '#');
        $page->add($setting);

        $name = "theme_elby/event{$i}image";
        $title = get_string('eventimage', 'theme_elby');
        $setting = new admin_setting_configstoredfile($name, $title, '', "event{$i}image", 0,
            ['maxfiles' => 1, 'accepted_types' => ['.jpg', '.jpeg', '.png', '.webp']]);
        $setting->set_updatedcallback('theme_reset_all_caches');
        $page->add($setting);
    }

    $settings->add($page);

    // =========================================================================
    // TAB 11: FEATURE SECTION
    // =========================================================================
    $page = new admin_settingpage('theme_elby_featuresection',
        get_string('featuresectionsettings', 'theme_elby'));

    // Enable Feature Section.
    $name = 'theme_elby/featuresectionenabled';
    $title = get_string('featuresectionenabled', 'theme_elby');
    $description = get_string('featuresectionenabled_desc', 'theme_elby');
    $setting = new admin_setting_configcheckbox($name, $title, $description, 1);
    $page->add($setting);

    // Feature Section Title.
    $name = 'theme_elby/featuresectiontitle';
    $title = get_string('featuresectiontitle', 'theme_elby');
    $description = get_string('featuresectiontitle_desc', 'theme_elby');
    $setting = new admin_setting_configtext($name, $title, $description, 'Discover the possibilities with {sitename} online');
    $page->add($setting);

    // Feature Section Content.
    $name = 'theme_elby/featuresectioncontent';
    $title = get_string('featuresectioncontent', 'theme_elby');
    $description = get_string('featuresectioncontent_desc', 'theme_elby');
    $setting = new admin_setting_confightmleditor($name, $title, $description, '');
    $page->add($setting);

    // Feature Section Image.
    $name = 'theme_elby/featuresectionimage';
    $title = get_string('featuresectionimage', 'theme_elby');
    $description = get_string('featuresectionimage_desc', 'theme_elby');
    $setting = new admin_setting_configstoredfile($name, $title, $description, 'featuresectionimage', 0,
        ['maxfiles' => 1, 'accepted_types' => ['.jpg', '.jpeg', '.png', '.webp']]);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Feature Section Button Text.
    $name = 'theme_elby/featuresectionbuttontext';
    $title = get_string('featuresectionbuttontext', 'theme_elby');
    $setting = new admin_setting_configtext($name, $title, '', 'Explore More');
    $page->add($setting);

    // Feature Section Button URL.
    $name = 'theme_elby/featuresectionbuttonurl';
    $title = get_string('featuresectionbuttonurl', 'theme_elby');
    $setting = new admin_setting_configtext($name, $title, '', '/course/');
    $page->add($setting);

    $settings->add($page);

    // =========================================================================
    // TAB 12: FOOTER
    // =========================================================================
    $page = new admin_settingpage('theme_elby_footer',
        get_string('footersettings', 'theme_elby'));

    // Footer Content.
    $name = 'theme_elby/footercontent';
    $title = get_string('footercontent', 'theme_elby');
    $description = get_string('footercontent_desc', 'theme_elby');
    $setting = new admin_setting_confightmleditor($name, $title, $description, '');
    $page->add($setting);

    // Copyright Text.
    $name = 'theme_elby/copyrighttext';
    $title = get_string('copyrighttext', 'theme_elby');
    $description = get_string('copyrighttext_desc', 'theme_elby');
    $setting = new admin_setting_configtext($name, $title, $description, '© {year} All Rights Reserved.');
    $page->add($setting);

    // Social Media URLs.
    $page->add(new admin_setting_heading('theme_elby/socialheading',
        get_string('socialmedia', 'theme_elby'), get_string('socialmedia_desc', 'theme_elby')));

    $socialnetworks = ['facebook', 'twitter', 'instagram', 'linkedin', 'youtube'];
    foreach ($socialnetworks as $network) {
        $name = "theme_elby/{$network}url";
        $title = get_string("{$network}url", 'theme_elby');
        $setting = new admin_setting_configtext($name, $title, '', '');
        $page->add($setting);
    }

    $settings->add($page);

    // =========================================================================
    // TAB 13: COURSE STYLING
    // =========================================================================
    $page = new admin_settingpage('theme_elby_coursestyling',
        get_string('coursestylingsettings', 'theme_elby'));

    // Course Card Style.
    $name = 'theme_elby/coursecardstyle';
    $title = get_string('coursecardstyle', 'theme_elby');
    $description = get_string('coursecardstyle_desc', 'theme_elby');
    $choices = [
        'default' => get_string('coursecardstyle_default', 'theme_elby'),
        'bordered' => get_string('coursecardstyle_bordered', 'theme_elby'),
        'gradient' => get_string('coursecardstyle_gradient', 'theme_elby'),
        'minimal' => get_string('coursecardstyle_minimal', 'theme_elby'),
    ];
    $setting = new admin_setting_configselect($name, $title, $description, 'default', $choices);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Course Card Shadow.
    $name = 'theme_elby/coursecardshadow';
    $title = get_string('coursecardshadow', 'theme_elby');
    $description = get_string('coursecardshadow_desc', 'theme_elby');
    $setting = new admin_setting_configcheckbox($name, $title, $description, 1);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Course Card Hover Effect.
    $name = 'theme_elby/coursecardhover';
    $title = get_string('coursecardhover', 'theme_elby');
    $description = get_string('coursecardhover_desc', 'theme_elby');
    $setting = new admin_setting_configcheckbox($name, $title, $description, 1);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Progress Bar Color.
    $name = 'theme_elby/courseprogresscolor';
    $title = get_string('courseprogresscolor', 'theme_elby');
    $description = get_string('courseprogresscolor_desc', 'theme_elby');
    $setting = new admin_setting_configcolourpicker($name, $title, $description, '');
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Completion Badge Color.
    $name = 'theme_elby/coursecompletioncolor';
    $title = get_string('coursecompletioncolor', 'theme_elby');
    $description = get_string('coursecompletioncolor_desc', 'theme_elby');
    $setting = new admin_setting_configcolourpicker($name, $title, $description, '#22c55e');
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Activity Icon Color.
    $name = 'theme_elby/activityiconcolor';
    $title = get_string('activityiconcolor', 'theme_elby');
    $description = get_string('activityiconcolor_desc', 'theme_elby');
    $setting = new admin_setting_configcolourpicker($name, $title, $description, '');
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    $settings->add($page);

    // =========================================================================
    // TAB 14: BUTTONS & FORMS
    // =========================================================================
    $page = new admin_settingpage('theme_elby_buttonsforms',
        get_string('buttonsformssettings', 'theme_elby'));

    // Button Border Radius.
    $name = 'theme_elby/buttonradius';
    $title = get_string('buttonradius', 'theme_elby');
    $description = get_string('buttonradius_desc', 'theme_elby');
    $choices = [
        'sharp' => get_string('buttonradius_sharp', 'theme_elby'),
        'rounded' => get_string('buttonradius_rounded', 'theme_elby'),
        'pill' => get_string('buttonradius_pill', 'theme_elby'),
    ];
    $setting = new admin_setting_configselect($name, $title, $description, 'rounded', $choices);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Button Style.
    $name = 'theme_elby/buttonstyle';
    $title = get_string('buttonstyle', 'theme_elby');
    $description = get_string('buttonstyle_desc', 'theme_elby');
    $choices = [
        'solid' => get_string('buttonstyle_solid', 'theme_elby'),
        'gradient' => get_string('buttonstyle_gradient', 'theme_elby'),
    ];
    $setting = new admin_setting_configselect($name, $title, $description, 'solid', $choices);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Input Focus Color.
    $name = 'theme_elby/inputfocuscolor';
    $title = get_string('inputfocuscolor', 'theme_elby');
    $description = get_string('inputfocuscolor_desc', 'theme_elby');
    $setting = new admin_setting_configcolourpicker($name, $title, $description, '');
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Input Border Radius.
    $name = 'theme_elby/inputradius';
    $title = get_string('inputradius', 'theme_elby');
    $description = get_string('inputradius_desc', 'theme_elby');
    $choices = [
        'sharp' => get_string('inputradius_sharp', 'theme_elby'),
        'rounded' => get_string('inputradius_rounded', 'theme_elby'),
        'pill' => get_string('inputradius_pill', 'theme_elby'),
    ];
    $setting = new admin_setting_configselect($name, $title, $description, 'rounded', $choices);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    $settings->add($page);

    // =========================================================================
    // TAB 15: NAVIGATION
    // =========================================================================
    $page = new admin_settingpage('theme_elby_navigation',
        get_string('navigationsettings', 'theme_elby'));

    // Breadcrumb Style.
    $name = 'theme_elby/breadcrumbstyle';
    $title = get_string('breadcrumbstyle', 'theme_elby');
    $description = get_string('breadcrumbstyle_desc', 'theme_elby');
    $choices = [
        'default' => get_string('breadcrumbstyle_default', 'theme_elby'),
        'arrows' => get_string('breadcrumbstyle_arrows', 'theme_elby'),
        'pills' => get_string('breadcrumbstyle_pills', 'theme_elby'),
    ];
    $setting = new admin_setting_configselect($name, $title, $description, 'default', $choices);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Breadcrumb Active Color.
    $name = 'theme_elby/breadcrumbcolor';
    $title = get_string('breadcrumbcolor', 'theme_elby');
    $description = get_string('breadcrumbcolor_desc', 'theme_elby');
    $setting = new admin_setting_configcolourpicker($name, $title, $description, '');
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Notification Badge Color.
    $name = 'theme_elby/navbadgecolor';
    $title = get_string('navbadgecolor', 'theme_elby');
    $description = get_string('navbadgecolor_desc', 'theme_elby');
    $setting = new admin_setting_configcolourpicker($name, $title, $description, '#ef4444');
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Dashboard Card Style.
    $name = 'theme_elby/dashboardcardstyle';
    $title = get_string('dashboardcardstyle', 'theme_elby');
    $description = get_string('dashboardcardstyle_desc', 'theme_elby');
    $choices = [
        'default' => get_string('dashboardcardstyle_default', 'theme_elby'),
        'compact' => get_string('dashboardcardstyle_compact', 'theme_elby'),
        'detailed' => get_string('dashboardcardstyle_detailed', 'theme_elby'),
    ];
    $setting = new admin_setting_configselect($name, $title, $description, 'default', $choices);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    $settings->add($page);

    // =========================================================================
    // TAB 16: LOGIN PAGE
    // =========================================================================
    $page = new admin_settingpage('theme_elby_login',
        get_string('loginsettings', 'theme_elby'));

    // Login Background Image.
    $name = 'theme_elby/loginbackgroundimage';
    $title = get_string('loginbackgroundimage', 'theme_elby');
    $description = get_string('loginbackgroundimage_desc', 'theme_elby');
    $setting = new admin_setting_configstoredfile($name, $title, $description, 'loginbackgroundimage', 0,
        ['maxfiles' => 1, 'accepted_types' => ['.jpg', '.jpeg', '.png', '.webp']]);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Login Gradient Colors heading.
    $page->add(new admin_setting_heading('theme_elby/logingradientheading',
        get_string('logingradient', 'theme_elby'), get_string('logingradient_desc', 'theme_elby')));

    // Login Gradient Color 1.
    $name = 'theme_elby/logingradient1';
    $title = get_string('logingradient1', 'theme_elby');
    $description = get_string('logingradient1_desc', 'theme_elby');
    $setting = new admin_setting_configcolourpicker($name, $title, $description, '#8b5cf6');
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Login Gradient Color 2.
    $name = 'theme_elby/logingradient2';
    $title = get_string('logingradient2', 'theme_elby');
    $description = get_string('logingradient2_desc', 'theme_elby');
    $setting = new admin_setting_configcolourpicker($name, $title, $description, '#6366f1');
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Login Gradient Color 3.
    $name = 'theme_elby/logingradient3';
    $title = get_string('logingradient3', 'theme_elby');
    $description = get_string('logingradient3_desc', 'theme_elby');
    $setting = new admin_setting_configcolourpicker($name, $title, $description, '#3b82f6');
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Login Box Position.
    $name = 'theme_elby/loginboxposition';
    $title = get_string('loginboxposition', 'theme_elby');
    $description = get_string('loginboxposition_desc', 'theme_elby');
    $choices = [
        'center' => get_string('loginboxposition_center', 'theme_elby'),
        'left' => get_string('loginboxposition_left', 'theme_elby'),
        'right' => get_string('loginboxposition_right', 'theme_elby'),
    ];
    $setting = new admin_setting_configselect($name, $title, $description, 'center', $choices);
    $page->add($setting);

    // Custom Login Text.
    $name = 'theme_elby/logintext';
    $title = get_string('logintext', 'theme_elby');
    $description = get_string('logintext_desc', 'theme_elby');
    $setting = new admin_setting_confightmleditor($name, $title, $description,
        'Sign in to access your learning dashboard and continue your educational journey.');
    $page->add($setting);

    $settings->add($page);

    // =========================================================================
    // TAB 10: ADVANCED
    // =========================================================================
    $page = new admin_settingpage('theme_elby_advanced',
        get_string('advancedsettings', 'theme_elby'));

    // Raw Pre-SCSS.
    $name = 'theme_elby/scsspre';
    $title = get_string('rawscsspre', 'theme_elby');
    $description = get_string('rawscsspre_desc', 'theme_elby');
    $setting = new admin_setting_configtextarea($name, $title, $description, '');
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Raw SCSS.
    $name = 'theme_elby/scss';
    $title = get_string('rawscss', 'theme_elby');
    $description = get_string('rawscss_desc', 'theme_elby');
    $setting = new admin_setting_configtextarea($name, $title, $description, '');
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    $settings->add($page);
}
