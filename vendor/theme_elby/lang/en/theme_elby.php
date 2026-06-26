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
 * Language strings for theme_elby.
 *
 * @package    theme_elby
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Plugin metadata.
$string['pluginname'] = 'Elby';
$string['configtitle'] = 'Elby Settings';
$string['choosereadme'] = 'Elby is a modern, highly customizable theme for Moodle 5.0+ with a clean SaaS-style aesthetic.';

// General settings.
$string['generalsettings'] = 'General';
$string['logo'] = 'Logo';
$string['logo_desc'] = 'Upload your site logo (recommended: SVG or PNG with transparent background).';
$string['logocompact'] = 'Compact logo';
$string['logocompact_desc'] = 'A smaller version of your logo for the navbar.';
$string['favicon'] = 'Favicon';
$string['favicon_desc'] = 'Upload a favicon (ICO or PNG format).';
$string['showsitename'] = 'Show site name';
$string['showsitename_desc'] = 'Display the site name next to the logo.';

// Color settings.
$string['colorsettings'] = 'Colors';
$string['brandcolor'] = 'Brand color';
$string['brandcolor_desc'] = 'Primary brand color used throughout the theme for buttons, links, and accents.';
$string['secondarycolor'] = 'Secondary color';
$string['secondarycolor_desc'] = 'Secondary accent color for less prominent elements.';
$string['successcolor'] = 'Success color';
$string['successcolor_desc'] = 'Color for success states and messages.';
$string['infocolor'] = 'Info color';
$string['infocolor_desc'] = 'Color for informational elements.';
$string['warningcolor'] = 'Warning color';
$string['warningcolor_desc'] = 'Color for warnings.';
$string['dangercolor'] = 'Danger color';
$string['dangercolor_desc'] = 'Color for errors and dangerous actions.';
$string['bodybgcolor'] = 'Body background color';
$string['bodybgcolor_desc'] = 'Background color for the page body.';
$string['bodytextcolor'] = 'Body text color';
$string['bodytextcolor_desc'] = 'Main text color.';
$string['linkcolor'] = 'Link color';
$string['linkcolor_desc'] = 'Color for hyperlinks. Leave empty to use brand color.';

// Typography settings.
$string['typographysettings'] = 'Typography';
$string['fontbody'] = 'Body font';
$string['fontbody_desc'] = 'Font family for body text. Select a Google Font or use system default.';
$string['fontheadings'] = 'Headings font';
$string['fontheadings_desc'] = 'Font family for headings. Select a Google Font or use system default.';
$string['fontsize'] = 'Base font size';
$string['fontsize_desc'] = 'Base font size for the theme.';
$string['font_inherit'] = 'System default';
$string['fontsize_default'] = 'Default';
$string['fontsize_small'] = 'Small (14px)';
$string['fontsize_medium'] = 'Medium (15px)';
$string['fontsize_large'] = 'Large (16px)';

// Hero settings.
$string['herosettings'] = 'Hero Section';
$string['heroenabled'] = 'Enable hero section';
$string['heroenabled_desc'] = 'Show the hero section on the frontpage.';
$string['herostyle'] = 'Hero style';
$string['herostyle_desc'] = 'Choose the layout style for the hero carousel.';
$string['herostyle_collage'] = 'Collage (default)';
$string['herostyle_fullwidth'] = 'Full-width background';
$string['herocarouselsettings'] = 'Carousel Settings';
$string['heroslidecount'] = 'Number of slides';
$string['heroslidecount_desc'] = 'Enter the number of hero slides to display (1-10). Default is 1.';
$string['heroautorotate'] = 'Auto-rotate slides';
$string['heroautorotate_desc'] = 'Automatically rotate through slides.';
$string['herointerval'] = 'Rotation interval';
$string['herointerval_desc'] = 'Time between automatic slide transitions.';
$string['seconds'] = 'seconds';
$string['heroslide'] = 'Slide';
$string['heroslideimage'] = 'Main image';
$string['heroslideimage_desc'] = 'Main hero image displayed in a blob shape. Recommended size: 800x600px or larger.';
$string['heroslidesecondaryimage'] = 'Secondary image';
$string['heroslidesecondaryimage_desc'] = 'Secondary circular image that overlaps the main image. Recommended size: 300x300px.';
$string['heroslideheading'] = 'Heading';
$string['heroslidesubheading'] = 'Subheading';
$string['heroslidectabutton'] = 'CTA button text';
$string['heroslidectaurl'] = 'CTA button URL';
$string['gotoslide'] = 'Go to slide {$a}';
$string['previousslide'] = 'Previous slide';
$string['nextslide'] = 'Next slide';

// Legacy hero settings (for backwards compatibility).
$string['herobackgroundimage'] = 'Hero main image';
$string['herobackgroundimage_desc'] = 'Main hero image displayed in a blob shape. Recommended size: 800x600px or larger.';
$string['herosecondaryimage'] = 'Hero secondary image';
$string['herosecondaryimage_desc'] = 'Secondary circular image that overlaps the main image. Recommended size: 300x300px.';
$string['heroheading'] = 'Hero heading';
$string['heroheading_desc'] = 'Main heading text displayed in the hero section.';
$string['herosubheading'] = 'Hero subheading';
$string['herosubheading_desc'] = 'Supporting text displayed below the main heading.';
$string['heroctabutton'] = 'CTA button text';
$string['heroctabutton_desc'] = 'Text for the call-to-action button. Leave empty to hide the button.';
$string['heroctaurl'] = 'CTA button URL';
$string['heroctaurl_desc'] = 'Link destination for the call-to-action button.';

// Course categories settings.
$string['categoriessettings'] = 'Course Categories';
$string['categoriesenabled'] = 'Enable categories section';
$string['categoriesenabled_desc'] = 'Show course categories as cards on the frontpage.';
$string['categoriestitle'] = 'Section title';
$string['categoriestitle_desc'] = 'Heading for the categories section.';
$string['categoriessubtitle'] = 'Section subtitle';
$string['categoriessubtitle_desc'] = 'Subtitle text below the section heading.';
$string['categoryheaderbg'] = 'Category header background color';
$string['categoryheaderbg_desc'] = 'Background color for the category banner header when viewing a specific category.';
$string['categoryheadertextcolor'] = 'Category header text color';
$string['categoryheadertextcolor_desc'] = 'Text color for the category banner header title, breadcrumbs, and description.';
$string['categoriesplaceholder'] = 'Placeholder image';
$string['categoriesplaceholder_desc'] = 'Default image for categories and courses without their own image.';
$string['explorecourses'] = 'Explore Our Programs';
$string['allcategories'] = 'All Categories';
$string['subcategories'] = 'subcategories';
$string['enrolled'] = 'enrolled';
$string['nocategories'] = 'No categories available.';
$string['nocontent'] = 'No content found in this category.';

// Statistics settings.
$string['statisticssettings'] = 'Statistics';
$string['statsenabled'] = 'Enable statistics';
$string['statsenabled_desc'] = 'Show statistics counters on the frontpage.';
$string['statstitle'] = 'Section title';
$string['statstitle_desc'] = 'Heading for the statistics section.';
$string['statistic'] = 'Statistic';
$string['statvalue'] = 'Value';
$string['statsuffix'] = 'Suffix';
$string['statlabel'] = 'Label';
$string['staticon'] = 'Icon';

// Testimonials settings.
$string['testimonialsettings'] = 'Testimonials';
$string['testimonialsenabled'] = 'Enable testimonials';
$string['testimonialsenabled_desc'] = 'Show testimonials section on the frontpage.';
$string['testimonialstitle'] = 'Section title';
$string['testimonialstitle_desc'] = 'Heading for the testimonials section.';
$string['testimonialcount'] = 'Number of testimonials';
$string['testimonialcount_desc'] = 'How many testimonials to display (1-6).';
$string['testimonial'] = 'Testimonial';
$string['testimonialimage'] = 'Photo';
$string['testimonialname'] = 'Name';
$string['testimonialrole'] = 'Role/Title';
$string['testimonialquote'] = 'Quote';

// Campus Life settings.
$string['campuslifesettings'] = 'Campus Life';
$string['campuslifeenabled'] = 'Enable campus life section';
$string['campuslifeenabled_desc'] = 'Show the campus life gallery on the frontpage.';
$string['campuslifetitle'] = 'Section title';
$string['campuslifetitle_desc'] = 'Heading for the campus life section. Use {sitename} for site name.';
$string['campuslifecategory'] = 'Category';
$string['campuslifelabel'] = 'Category label';
$string['campuslifeimage'] = 'Category image';

// Announcements settings.
$string['announcementsettings'] = 'Announcements';
$string['announcementsenabled'] = 'Enable announcements section';
$string['announcementsenabled_desc'] = 'Show the announcements section on the frontpage.';
$string['announcementstitle'] = 'Section title';
$string['announcementstitle_desc'] = 'Heading for the announcements section.';
$string['announcementscontent'] = 'Announcement content';
$string['announcementscontent_desc'] = 'Main content for the announcement.';
$string['announcementslinktext'] = 'Link text';
$string['announcementslinkurl'] = 'Link URL';

// Events settings.
$string['eventssettings'] = 'Events';
$string['eventsenabled'] = 'Enable events section';
$string['eventsenabled_desc'] = 'Show the upcoming events section on the frontpage.';
$string['eventstitle'] = 'Section title';
$string['eventstitle_desc'] = 'Heading for the events section.';
$string['event'] = 'Event';
$string['eventtitle'] = 'Event title';
$string['eventdate'] = 'Event date';
$string['eventdate_desc'] = 'Format: YYYY-MM-DD (e.g., 2025-01-15)';
$string['eventdescription'] = 'Event description';
$string['eventurl'] = 'Event URL';
$string['eventimage'] = 'Event image';

// Feature Section settings.
$string['featuresectionsettings'] = 'Feature Section';
$string['featuresectionenabled'] = 'Enable feature section';
$string['featuresectionenabled_desc'] = 'Show the feature section on the frontpage.';
$string['featuresectiontitle'] = 'Section title';
$string['featuresectiontitle_desc'] = 'Heading for the feature section. Use {sitename} for site name.';
$string['featuresectioncontent'] = 'Section content';
$string['featuresectioncontent_desc'] = 'Main content for the feature section.';
$string['featuresectionimage'] = 'Section image';
$string['featuresectionimage_desc'] = 'Image displayed on the left side of the section.';
$string['featuresectionbuttontext'] = 'Button text';
$string['featuresectionbuttonurl'] = 'Button URL';

// Footer settings.
$string['footersettings'] = 'Footer';
$string['footercontent'] = 'Footer content';
$string['footercontent_desc'] = 'Main content/description for the footer area.';
$string['copyrighttext'] = 'Copyright text';
$string['copyrighttext_desc'] = 'Copyright notice displayed in the footer. Use {year} for the current year.';
$string['socialmedia'] = 'Social Media';
$string['socialmedia_desc'] = 'Enter your social media profile URLs. Leave empty to hide.';
$string['facebookurl'] = 'Facebook URL';
$string['twitterurl'] = 'Twitter/X URL';
$string['instagramurl'] = 'Instagram URL';
$string['linkedinurl'] = 'LinkedIn URL';
$string['youtubeurl'] = 'YouTube URL';

// Login settings.
$string['loginsettings'] = 'Login Page';
$string['loginbackgroundimage'] = 'Login background image';
$string['loginbackgroundimage_desc'] = 'Background image displayed on the right side of the login page. If set, this overrides the gradient colors.';
$string['logingradient'] = 'Gradient colors';
$string['logingradient_desc'] = 'Configure the gradient colors for the login page right side (used when no background image is set).';
$string['logingradient1'] = 'Gradient color 1 (start)';
$string['logingradient1_desc'] = 'First color in the gradient (top-left).';
$string['logingradient2'] = 'Gradient color 2 (middle)';
$string['logingradient2_desc'] = 'Middle color in the gradient.';
$string['logingradient3'] = 'Gradient color 3 (end)';
$string['logingradient3_desc'] = 'Last color in the gradient (bottom-right).';
$string['loginboxposition'] = 'Login box position';
$string['loginboxposition_desc'] = 'Position of the login form on the page.';
$string['loginboxposition_center'] = 'Center';
$string['loginboxposition_left'] = 'Left (split layout)';
$string['loginboxposition_right'] = 'Right (split layout)';
$string['logintext'] = 'Custom login text';
$string['logintext_desc'] = 'Welcome message displayed on the login page.';

// Advanced settings.
$string['advancedsettings'] = 'Advanced';
$string['rawscsspre'] = 'Raw initial SCSS';
$string['rawscsspre_desc'] = 'SCSS code added before Bootstrap. Use this for custom variables.';
$string['rawscss'] = 'Raw SCSS';
$string['rawscss_desc'] = 'SCSS code added at the end. This has the highest cascade priority.';

// Template strings.
$string['togglenavigation'] = 'Toggle navigation';
$string['connect'] = 'Connect With Us';
$string['stayconnected'] = 'Stay connected with our community.';
$string['poweredbymoodle'] = 'Powered by Moodle';
$string['welcomeback'] = 'Welcome Back';
$string['signinmessage'] = 'Sign in to continue to your account.';
$string['backtohome'] = 'Back to Home';

// Course Styling settings.
$string['coursestylingsettings'] = 'Course Styling';
$string['coursecardstyle'] = 'Course card style';
$string['coursecardstyle_desc'] = 'Choose the visual style for course cards on the dashboard and course listings.';
$string['coursecardstyle_default'] = 'Default';
$string['coursecardstyle_bordered'] = 'Bordered';
$string['coursecardstyle_gradient'] = 'Gradient header';
$string['coursecardstyle_minimal'] = 'Minimal';
$string['coursecardshadow'] = 'Course card shadow';
$string['coursecardshadow_desc'] = 'Enable subtle shadow effect on course cards.';
$string['coursecardhover'] = 'Course card hover effect';
$string['coursecardhover_desc'] = 'Enable lift/scale effect when hovering over course cards.';
$string['courseprogresscolor'] = 'Progress bar color';
$string['courseprogresscolor_desc'] = 'Color for course progress bars. Leave empty to use brand color.';
$string['coursecompletioncolor'] = 'Completion badge color';
$string['coursecompletioncolor_desc'] = 'Color for activity completion badges and checkmarks.';
$string['activityiconcolor'] = 'Activity icon color';
$string['activityiconcolor_desc'] = 'Tint color for activity icons. Leave empty to use brand color.';

// Buttons & Forms settings.
$string['buttonsformssettings'] = 'Buttons & Forms';
$string['buttonradius'] = 'Button border radius';
$string['buttonradius_desc'] = 'Border radius style for buttons throughout the site.';
$string['buttonradius_sharp'] = 'Sharp (0px)';
$string['buttonradius_rounded'] = 'Rounded (6px)';
$string['buttonradius_pill'] = 'Pill (50px)';
$string['buttonstyle'] = 'Primary button style';
$string['buttonstyle_desc'] = 'Visual style for primary buttons.';
$string['buttonstyle_solid'] = 'Solid color';
$string['buttonstyle_gradient'] = 'Gradient';
$string['inputfocuscolor'] = 'Input focus color';
$string['inputfocuscolor_desc'] = 'Border color when form inputs are focused. Leave empty to use brand color.';
$string['inputradius'] = 'Input border radius';
$string['inputradius_desc'] = 'Border radius style for form inputs.';
$string['inputradius_sharp'] = 'Sharp (0px)';
$string['inputradius_rounded'] = 'Rounded (6px)';
$string['inputradius_pill'] = 'Pill (50px)';

// Navigation settings.
$string['navigationsettings'] = 'Navigation';
$string['breadcrumbstyle'] = 'Breadcrumb style';
$string['breadcrumbstyle_desc'] = 'Visual style for breadcrumb navigation.';
$string['breadcrumbstyle_default'] = 'Default (slashes)';
$string['breadcrumbstyle_arrows'] = 'Arrows';
$string['breadcrumbstyle_pills'] = 'Pills/badges';
$string['breadcrumbcolor'] = 'Active breadcrumb color';
$string['breadcrumbcolor_desc'] = 'Color for the active/current page in breadcrumbs. Leave empty to use brand color.';
$string['navbadgecolor'] = 'Notification badge color';
$string['navbadgecolor_desc'] = 'Background color for notification count badges.';
$string['dashboardcardstyle'] = 'Dashboard card style';
$string['dashboardcardstyle_desc'] = 'Layout style for course cards on the dashboard.';
$string['dashboardcardstyle_default'] = 'Default grid';
$string['dashboardcardstyle_compact'] = 'Compact list';
$string['dashboardcardstyle_detailed'] = 'Detailed cards';

// Dynamic statistics strings.
$string['stat_section_title'] = 'RTB by the Numbers';
$string['stat_users'] = 'Users';
$string['stat_schools'] = 'Schools';
$string['stat_students'] = 'Students';
$string['stat_teachers'] = 'Teachers';
$string['stat_courses'] = 'Courses';

// Privacy.
$string['privacy:metadata'] = 'The Elby theme does not store any personal data.';
