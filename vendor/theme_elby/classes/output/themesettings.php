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
 * Theme settings helper - prepares data for templates.
 *
 * @package    theme_elby
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace theme_elby\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Theme settings helper class.
 *
 * Retrieves theme settings and formats them for use in Mustache templates.
 */
class themesettings {

    /** @var \theme_config The theme configuration object. */
    protected $theme;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->theme = \theme_config::load('elby');
    }

    /**
     * Get hero section data.
     *
     * @return array Hero section template data.
     */
    public function hero_section(): array {
        $settings = $this->theme->settings;

        if (empty($settings->heroenabled)) {
            return ['hashero' => false];
        }

        // Get carousel settings.
        $herostyle = $settings->herostyle ?? 'collage';
        $slidecount = max(1, min(10, (int)($settings->heroslidecount ?? 1)));
        $autorotate = !empty($settings->heroautorotate);
        $interval = (int)($settings->herointerval ?? 5000);

        // Build slides array - include ALL slides up to the configured count.
        $slides = [];
        for ($i = 1; $i <= $slidecount; $i++) {
            $heading = $settings->{"heroslide{$i}heading"} ?? '';
            $subheading = $settings->{"heroslide{$i}subheading"} ?? '';
            $mainimage = $this->theme->setting_file_url("heroslide{$i}mainimage", "heroslide{$i}mainimage");
            $secondaryimage = $this->theme->setting_file_url("heroslide{$i}secondaryimage", "heroslide{$i}secondaryimage");
            $ctabutton = $settings->{"heroslide{$i}ctabutton"} ?? '';
            $ctaurl = $settings->{"heroslide{$i}ctaurl"} ?? '';

            // Use defaults for slide 1 if not set.
            if ($i === 1 && empty($heading)) {
                $heading = "LET'S DESIGN YOUR FUTURE!";
            }
            if ($i === 1 && empty($subheading)) {
                $subheading = 'Our platform inspires a love of learning, encouraging creative thinking across all subjects.';
            }

            $slides[] = [
                'index' => $i - 1,
                'slidenum' => $i,
                'active' => ($i === 1),
                'heading' => $heading,
                'subheading' => format_text($subheading, FORMAT_HTML),
                'ctabutton' => $ctabutton,
                'ctaurl' => !empty($ctaurl) ? $ctaurl : '#',
                'hascta' => !empty($ctabutton),
                'mainimage' => $mainimage,
                'hasmainimage' => !empty($mainimage),
                'secondaryimage' => $secondaryimage,
                'hassecondaryimage' => !empty($secondaryimage),
            ];
        }

        // Ensure at least one slide.
        if (empty($slides)) {
            $slides[] = [
                'index' => 0,
                'slidenum' => 1,
                'active' => true,
                'heading' => "LET'S DESIGN YOUR FUTURE!",
                'subheading' => 'Our platform inspires a love of learning, encouraging creative thinking across all subjects.',
                'ctabutton' => 'Explore Academics',
                'ctaurl' => '/course/',
                'hascta' => true,
                'mainimage' => '',
                'hasmainimage' => false,
                'secondaryimage' => '',
                'hassecondaryimage' => false,
            ];
        }

        $hasmultipleslides = count($slides) > 1;

        return [
            'hashero' => true,
            'herostyle' => $herostyle,
            'herostyle_collage' => ($herostyle === 'collage'),
            'herostyle_fullwidth' => ($herostyle === 'fullwidth'),
            'heroslides' => $slides,
            'heroslidecount' => count($slides),
            'hasmultipleslides' => $hasmultipleslides,
            'heroautorotate' => $autorotate,
            'herointerval' => $interval,
            // Keep legacy single-slide variables for backwards compatibility.
            'heroheading' => $slides[0]['heading'] ?? '',
            'herosubheading' => $slides[0]['subheading'] ?? '',
            'heroctabutton' => $slides[0]['ctabutton'] ?? '',
            'heroctaurl' => $slides[0]['ctaurl'] ?? '#',
            'hascta' => $slides[0]['hascta'] ?? false,
            'heroimage' => $slides[0]['mainimage'] ?? '',
            'hasheroimage' => $slides[0]['hasmainimage'] ?? false,
            'herosecondaryimage' => $slides[0]['secondaryimage'] ?? '',
            'hasherosecondaryimage' => $slides[0]['hassecondaryimage'] ?? false,
        ];
    }

    /**
     * Get course categories data for frontpage display.
     *
     * @return array Course categories template data.
     */
    public function course_categories(): array {
        global $DB, $CFG;
        $settings = $this->theme->settings;

        if (empty($settings->categoriesenabled)) {
            return ['hascategories' => false];
        }

        // Get current category from URL parameter.
        $categoryid = optional_param('category', 0, PARAM_INT);

        $items = [];
        $breadcrumbs = [];
        $currentcategory = null;
        $showcourses = false;
        $isfiltered = false;

        if ($categoryid > 0) {
            // Viewing a specific category - show its children or courses.
            $isfiltered = true;
            try {
                $category = \core_course_category::get($categoryid);
                $categoryimage = $this->get_category_image($category);
                $currentcategory = [
                    'id' => $category->id,
                    'name' => $category->name,
                    'description' => $category->description ? trim(strip_tags(format_text($category->description, $category->descriptionformat, ['context' => \context_coursecat::instance($category->id)]))) : '',
                    'image' => $categoryimage,
                    'hasimage' => !empty($categoryimage),
                ];

                // Build breadcrumb trail.
                $breadcrumbs[] = [
                    'name' => get_string('allcategories', 'theme_elby'),
                    'url' => $CFG->wwwroot . '/?redirect=0',
                    'active' => false,
                    'last' => false,
                ];

                // Add parent categories to breadcrumb.
                $parents = $category->get_parents();
                foreach ($parents as $parentid) {
                    $parent = \core_course_category::get($parentid);
                    $breadcrumbs[] = [
                        'name' => $parent->name,
                        'url' => $CFG->wwwroot . '/?category=' . $parent->id . '&redirect=0',
                        'active' => false,
                        'last' => false,
                    ];
                }

                // Current category in breadcrumb.
                $breadcrumbs[] = [
                    'name' => $category->name,
                    'url' => $CFG->wwwroot . '/?category=' . $category->id . '&redirect=0',
                    'active' => true,
                    'last' => true,
                ];

                // Get subcategories.
                $subcategories = $category->get_children();

                if (!empty($subcategories)) {
                    // Has subcategories - show them.
                    foreach ($subcategories as $subcat) {
                        if (!$subcat->visible) {
                            continue;
                        }
                        $items[] = $this->format_category_item($subcat);
                    }
                } else {
                    // No subcategories - show courses.
                    $showcourses = true;
                    $courses = $category->get_courses(['recursive' => false]);
                    foreach ($courses as $course) {
                        if (!$course->visible) {
                            continue;
                        }
                        $items[] = $this->format_course_item($course);
                    }
                }
            } catch (\Exception $e) {
                // Invalid category, fall back to top-level.
                $isfiltered = false;
                $categoryid = 0;
            }
        }

        if (!$isfiltered) {
            // Show top-level categories.
            $topcategories = \core_course_category::get(0)->get_children();
            foreach ($topcategories as $cat) {
                if (!$cat->visible) {
                    continue;
                }
                $items[] = $this->format_category_item($cat);
            }
        }

        return [
            'hascategories' => !empty($items) || $isfiltered,
            'categoriestitle' => $settings->categoriestitle ?? get_string('explorecourses', 'theme_elby'),
            'categoriessubtitle' => $settings->categoriessubtitle ?? '',
            'currentcategory' => $currentcategory,
            'categoryimage' => $currentcategory['image'] ?? '',
            'hascategoryimage' => !empty($currentcategory['hasimage']),
            'categorydescription' => $currentcategory['description'] ?? '',
            'breadcrumbs' => $breadcrumbs,
            'items' => $items,
            'isfiltered' => $isfiltered,
            'showcourses' => $showcourses,
        ];
    }

    /**
     * Format a category for display as a card.
     *
     * @param \core_course_category $category The category object.
     * @return array Formatted category data.
     */
    protected function format_category_item(\core_course_category $category): array {
        global $DB, $CFG, $OUTPUT;

        // Count courses in this category (including subcategories).
        $coursecount = $category->get_courses_count();

        // Count subcategories.
        $subcatcount = count($category->get_children());

        // Count enrolled users in all courses under this category.
        $enrolledcount = $this->get_category_enrolled_count($category->id);

        // Get category description (truncated).
        $description = $category->description;
        if (!empty($description)) {
            $context = \context_coursecat::instance($category->id);
            $description = file_rewrite_pluginfile_urls(
                $description,
                'pluginfile.php',
                $context->id,
                'coursecat',
                'description',
                null
            );
            $description = format_text($description, $category->descriptionformat, ['context' => $context]);
            $description = strip_tags($description);
            if (strlen($description) > 120) {
                $description = substr($description, 0, 117) . '...';
            }
        }

        // Try to get category image (if available via customfields or stored file).
        $thumbnail = $this->get_category_image($category);

        // Determine item count label.
        $itemcount = $subcatcount > 0 ? $subcatcount : $coursecount;
        $itemcountlabel = $subcatcount > 0 ?
            get_string('subcategories', 'theme_elby') :
            get_string('courses');

        return [
            'id' => $category->id,
            'type' => 'category',
            'name' => $category->name,
            'description' => $description,
            'thumbnail' => $thumbnail,
            'hasthumbnail' => !empty($thumbnail),
            'itemcount' => $itemcount,
            'itemcountlabel' => $itemcountlabel,
            'enrolledcount' => $enrolledcount,
            'url' => $CFG->wwwroot . '/?category=' . $category->id . '&redirect=0',
            'icon' => 'fa-folder-open',
        ];
    }

    /**
     * Format a course for display as a card.
     *
     * @param \stdClass $course The course object.
     * @return array Formatted course data.
     */
    protected function format_course_item($course): array {
        global $DB, $CFG, $OUTPUT;

        // Get enrolled user count.
        $context = \context_course::instance($course->id);
        $enrolledcount = count_enrolled_users($context);

        // Get course description (truncated).
        $description = $course->summary;
        if (!empty($description)) {
            $description = format_text($description, $course->summaryformat);
            $description = strip_tags($description);
            if (strlen($description) > 120) {
                $description = substr($description, 0, 117) . '...';
            }
        }

        // Get course image.
        $thumbnail = $this->get_course_image($course);

        // Count activities/sections as "lessons".
        $modulecount = $DB->count_records('course_modules', ['course' => $course->id, 'visible' => 1]);

        return [
            'id' => $course->id,
            'type' => 'course',
            'name' => $course->fullname,
            'description' => $description,
            'thumbnail' => $thumbnail,
            'hasthumbnail' => !empty($thumbnail),
            'itemcount' => $modulecount,
            'itemcountlabel' => get_string('activities'),
            'enrolledcount' => $enrolledcount,
            'url' => $CFG->wwwroot . '/course/view.php?id=' . $course->id,
            'icon' => 'fa-book',
        ];
    }

    /**
     * Get total enrolled users count for a category.
     *
     * @param int $categoryid The category ID.
     * @return int Total enrolled users.
     */
    protected function get_category_enrolled_count(int $categoryid): int {
        global $DB;

        $sql = "SELECT COUNT(DISTINCT ue.userid)
                FROM {user_enrolments} ue
                JOIN {enrol} e ON ue.enrolid = e.id
                JOIN {course} c ON e.courseid = c.id
                WHERE c.category = :categoryid
                AND ue.status = 0";

        return (int) $DB->count_records_sql($sql, ['categoryid' => $categoryid]);
    }

    /**
     * Get category image URL.
     *
     * @param \core_course_category $category The category.
     * @return string|null Image URL or null.
     */
    protected function get_category_image(\core_course_category $category): ?string {
        global $CFG;

        // Try to get image from category files (custom field or description files).
        $context = \context_coursecat::instance($category->id);
        $fs = get_file_storage();

        // Check for image in description files.
        $files = $fs->get_area_files($context->id, 'coursecat', 'description', 0, 'sortorder', false);
        foreach ($files as $file) {
            if ($file->is_valid_image()) {
                return \moodle_url::make_pluginfile_url(
                    $file->get_contextid(),
                    $file->get_component(),
                    $file->get_filearea(),
                    null,
                    $file->get_filepath(),
                    $file->get_filename()
                )->out();
            }
        }

        // Return placeholder from theme settings if available.
        $placeholder = $this->theme->setting_file_url('categoriesplaceholder', 'categoriesplaceholder');
        return $placeholder ?: null;
    }

    /**
     * Get course image URL.
     *
     * @param \stdClass $course The course.
     * @return string|null Image URL or null.
     */
    protected function get_course_image($course): ?string {
        global $CFG;

        // Wrap in core_course_list_element if not already.
        if (!($course instanceof \core_course_list_element)) {
            $course = new \core_course_list_element($course);
        }

        // Get course overview files (images).
        foreach ($course->get_course_overviewfiles() as $file) {
            if ($file->is_valid_image()) {
                return \moodle_url::make_pluginfile_url(
                    $file->get_contextid(),
                    $file->get_component(),
                    $file->get_filearea(),
                    null,
                    $file->get_filepath(),
                    $file->get_filename()
                )->out();
            }
        }

        // Return placeholder from theme settings if available.
        $placeholder = $this->theme->setting_file_url('categoriesplaceholder', 'categoriesplaceholder');
        return $placeholder ?: null;
    }

    /**
     * Get statistics section data.
     *
     * @return array Statistics template data.
     */
    public function statistics(): array {
        global $DB;
        $settings = $this->theme->settings;

        if (empty($settings->statsenabled)) {
            return ['hasstats' => false];
        }

        // Count active users (exclude deleted, suspended, and guest).
        $totalusers = $DB->count_records_select('user',
            'deleted = 0 AND suspended = 0 AND id > 1');

        // Count visible courses (exclude site course).
        $totalcourses = $DB->count_records_select('course', 'id > 1');

        // Count schools and students from elby_dashboard tables (if installed).
        $totalschools = 0;
        $totalstudents = 0;
        $totalteachers = 0;
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('elby_schools')) {
            $totalschools = $DB->count_records('elby_schools');
        }
        if ($dbman->table_exists('elby_sdms_users')) {
            $totalstudents = $DB->count_records('elby_sdms_users',
                ['user_type' => 'student']);
            $totalteachers = $DB->count_records('elby_sdms_users',
                ['user_type' => 'teacher']);
        }

        $stats = [
            [
                'value' => $totalusers,
                'suffix' => '+',
                'label' => get_string('stat_users', 'theme_elby'),
                'icon' => 'fa-users',
            ],
            [
                'value' => $totalschools,
                'suffix' => '',
                'label' => get_string('stat_schools', 'theme_elby'),
                'icon' => 'fa-building',
            ],
            [
                'value' => $totalstudents,
                'suffix' => '+',
                'label' => get_string('stat_students', 'theme_elby'),
                'icon' => 'fa-user-graduate',
            ],
            [
                'value' => $totalteachers,
                'suffix' => '+',
                'label' => get_string('stat_teachers', 'theme_elby'),
                'icon' => 'fa-chalkboard-teacher',
            ],
            [
                'value' => $totalcourses,
                'suffix' => '',
                'label' => get_string('stat_courses', 'theme_elby'),
                'icon' => 'fa-book',
            ],
        ];

        return [
            'hasstats' => true,
            'statstitle' => $settings->statstitle ?? get_string('stat_section_title', 'theme_elby'),
            'statssubtitle' => $settings->statssubtitle ?? '',
            'stats' => $stats,
        ];
    }

    /**
     * Get testimonials section data.
     *
     * @return array Testimonials template data.
     */
    public function testimonials(): array {
        $settings = $this->theme->settings;

        if (empty($settings->testimonialsenabled)) {
            return ['hastestimonials' => false];
        }

        $testimonials = [];
        $count = (int)($settings->testimonialcount ?? 3);

        for ($i = 1; $i <= $count; $i++) {
            $name = $settings->{"testimonial{$i}name"} ?? '';
            if (!empty($name)) {
                $image = $this->theme->setting_file_url("testimonial{$i}image", "testimonial{$i}image");
                $testimonials[] = [
                    'image' => $image,
                    'hasimage' => !empty($image),
                    'name' => $name,
                    'role' => $settings->{"testimonial{$i}role"} ?? '',
                    'quote' => format_text($settings->{"testimonial{$i}quote"} ?? '', FORMAT_HTML),
                ];
            }
        }

        return [
            'hastestimonials' => !empty($testimonials),
            'testimonialstitle' => $settings->testimonialstitle ?? '',
            'testimonialssubtitle' => $settings->testimonialssubtitle ?? '',
            'testimonials' => $testimonials,
        ];
    }

    /**
     * Get footer data.
     *
     * @return array Footer template data.
     */
    public function footer(): array {
        $settings = $this->theme->settings;

        $copyright = str_replace(
            '{year}',
            date('Y'),
            $settings->copyrighttext ?? '© {year} All Rights Reserved.'
        );

        $sociallinks = [];
        $socialicons = [
            'facebook' => 'fa-facebook-f',
            'twitter' => 'fa-x-twitter',
            'instagram' => 'fa-instagram',
            'linkedin' => 'fa-linkedin-in',
            'youtube' => 'fa-youtube',
        ];

        foreach ($socialicons as $network => $icon) {
            $url = $settings->{$network . 'url'} ?? '';
            if (!empty($url)) {
                $sociallinks[] = [
                    'url' => $url,
                    'icon' => $icon,
                    'name' => ucfirst($network),
                ];
            }
        }

        return [
            'footercontent' => format_text($settings->footercontent ?? '', FORMAT_HTML),
            'hasfootercontent' => !empty(trim(strip_tags($settings->footercontent ?? ''))),
            'copyrighttext' => $copyright,
            'sociallinks' => $sociallinks,
            'hassocialmedia' => !empty($sociallinks),
            'footercolumn1title' => $settings->footercolumn1title ?? '',
            'footercolumn1content' => format_text($settings->footercolumn1content ?? '', FORMAT_HTML),
            'footercolumn2title' => $settings->footercolumn2title ?? '',
            'footercolumn2content' => format_text($settings->footercolumn2content ?? '', FORMAT_HTML),
            'footercolumn3title' => $settings->footercolumn3title ?? '',
            'footercolumn3content' => format_text($settings->footercolumn3content ?? '', FORMAT_HTML),
        ];
    }

    /**
     * Get campus life section data.
     *
     * @return array Campus life template data.
     */
    public function campus_life(): array {
        global $SITE;
        $settings = $this->theme->settings;

        if (empty($settings->campuslifeenabled)) {
            return ['hascampuslife' => false];
        }

        $title = str_replace('{sitename}', $SITE->shortname, $settings->campuslifetitle ?? 'Campus life');

        $categories = [];
        for ($i = 1; $i <= 4; $i++) {
            $label = $settings->{"campuslife{$i}label"} ?? '';
            $image = $this->theme->setting_file_url("campuslife{$i}image", "campuslife{$i}image");
            if (!empty($label) && !empty($image)) {
                $categories[] = [
                    'label' => $label,
                    'image' => $image,
                    'active' => ($i === 1),
                    'index' => $i,
                ];
            }
        }

        return [
            'hascampuslife' => !empty($categories),
            'campuslifetitle' => $title,
            'campuslifecategories' => $categories,
        ];
    }

    /**
     * Get announcements section data.
     *
     * @return array Announcements template data.
     */
    public function announcements(): array {
        $settings = $this->theme->settings;

        if (empty($settings->announcementsenabled)) {
            return ['hasannouncements' => false];
        }

        $content = format_text($settings->announcementscontent ?? '', FORMAT_HTML);

        return [
            'hasannouncements' => !empty(trim(strip_tags($content))),
            'announcementstitle' => $settings->announcementstitle ?? '',
            'announcementscontent' => $content,
            'announcementslinktext' => $settings->announcementslinktext ?? 'View all',
            'announcementslinkurl' => $settings->announcementslinkurl ?? '#',
            'hasannouncementslink' => !empty($settings->announcementslinkurl),
        ];
    }

    /**
     * Get events section data.
     *
     * @return array Events template data.
     */
    public function events(): array {
        $settings = $this->theme->settings;

        if (empty($settings->eventsenabled)) {
            return ['hasevents' => false];
        }

        $events = [];
        for ($i = 1; $i <= 3; $i++) {
            $title = $settings->{"event{$i}title"} ?? '';
            $date = $settings->{"event{$i}date"} ?? '';
            if (!empty($title)) {
                $image = $this->theme->setting_file_url("event{$i}image", "event{$i}image");

                // Parse date for display (day number and month).
                $day = '';
                $month = '';
                if (!empty($date)) {
                    $timestamp = strtotime($date);
                    if ($timestamp) {
                        $day = date('d', $timestamp);
                        $month = date('M', $timestamp);
                    }
                }

                $events[] = [
                    'title' => $title,
                    'date' => $date,
                    'day' => $day,
                    'month' => $month,
                    'hasdate' => !empty($day),
                    'description' => format_text($settings->{"event{$i}description"} ?? '', FORMAT_HTML),
                    'url' => $settings->{"event{$i}url"} ?? '#',
                    'image' => $image,
                    'hasimage' => !empty($image),
                ];
            }
        }

        return [
            'hasevents' => !empty($events),
            'eventstitle' => $settings->eventstitle ?? 'Upcoming events',
            'events' => $events,
        ];
    }

    /**
     * Get feature section data.
     *
     * @return array Feature section template data.
     */
    public function feature_section(): array {
        global $SITE;
        $settings = $this->theme->settings;

        if (empty($settings->featuresectionenabled)) {
            return ['hasfeaturesection' => false];
        }

        $title = str_replace('{sitename}', $SITE->shortname, $settings->featuresectiontitle ?? '');
        $image = $this->theme->setting_file_url('featuresectionimage', 'featuresectionimage');

        return [
            'hasfeaturesection' => true,
            'featuresectiontitle' => $title,
            'featuresectioncontent' => format_text($settings->featuresectioncontent ?? '', FORMAT_HTML),
            'featuresectionimage' => $image,
            'hasfeaturesectionimage' => !empty($image),
            'featuresectionbuttontext' => $settings->featuresectionbuttontext ?? 'Explore More',
            'featuresectionbuttonurl' => $settings->featuresectionbuttonurl ?? '#',
            'hasfeaturesectionbutton' => !empty($settings->featuresectionbuttontext),
        ];
    }

    /**
     * Get login page data.
     *
     * @return array Login page template data.
     */
    public function login_page(): array {
        $settings = $this->theme->settings;

        $loginbg = $this->theme->setting_file_url('loginbackgroundimage', 'loginbackgroundimage');

        return [
            'loginboxposition' => $settings->loginboxposition ?? 'center',
            'logintext' => format_text($settings->logintext ?? '', FORMAT_HTML),
            'haslogintext' => !empty(trim(strip_tags($settings->logintext ?? ''))),
            'loginbackgroundimage' => $loginbg,
            'hasloginbackground' => !empty($loginbg),
            'showsplitlayout' => ($settings->loginboxposition ?? 'center') !== 'center',
        ];
    }

    /**
     * Get all frontpage section data combined.
     *
     * @return array All frontpage data for template.
     */
    public function get_frontpage_data(): array {
        return array_merge(
            $this->hero_section(),
            $this->course_categories(),
            $this->announcements(),
            $this->campus_life(),
            $this->feature_section(),
            $this->testimonials(),
            $this->statistics(),
            $this->events(),
            $this->footer()
        );
    }
}
