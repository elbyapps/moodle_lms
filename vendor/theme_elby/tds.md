# theme_nexus: Complete Technical Implementation Guide for Moodle 5.0

**A Comprehensive Blueprint for AI Coding Assistants (Gemini CLI / Claude Code)**

**Version:** 1.0.0  
**Target:** Moodle 5.0+ (Bootstrap 5.3)  
**Architecture:** "Super-Child" Pattern (extends theme_boost)

---

## Table of Contents

1. [Strategic Context](#1-strategic-context)
2. [Development Environment Setup](#2-development-environment-setup)
3. [Moodle 5.0 Technical Requirements](#3-moodle-50-technical-requirements)
4. [Theme Architecture Overview](#4-theme-architecture-overview)
5. [Complete Directory Structure](#5-complete-directory-structure)
6. [Core Configuration Files](#6-core-configuration-files)
7. [Admin Settings System](#7-admin-settings-system)
8. [SCSS Architecture & Styling Pipeline](#8-scss-architecture--styling-pipeline)
9. [Layout Files](#9-layout-files)
10. [Mustache Templates](#10-mustache-templates)
11. [Helper Classes](#11-helper-classes)
12. [Custom Renderer](#12-custom-renderer)
13. [JavaScript AMD Modules](#13-javascript-amd-modules)
14. [Language Strings](#14-language-strings)
15. [Performance Optimization](#15-performance-optimization)
16. [Testing Strategy](#16-testing-strategy)
17. [Accessibility Compliance](#17-accessibility-compliance)
18. [Deployment & Plugin Directory](#18-deployment--plugin-directory)
19. [Bootstrap 5 Migration Reference](#19-bootstrap-5-migration-reference)
20. [Implementation Checklist](#20-implementation-checklist)

---

## 1. Strategic Context

### 1.1 The Moodle 5.0 Paradigm Shift

The release of **Moodle 5.0 (April 14, 2025)** represents a pivotal evolution in the LMS landscape. For theme developers, this is not merely a version increment—it is a **structural migration from Bootstrap 4 to Bootstrap 5.3**. This migration necessitates comprehensive re-engineering of theme development practices:

1. **jQuery Abandonment**: Bootstrap 5 removes jQuery as a dependency. All custom interactions must use Vanilla JavaScript or Moodle's ESM/AMD modules.

2. **Logical CSS Properties**: Direction-based classes (`.ml-*`, `.mr-*`, `.text-left`) are replaced with logical properties (`.ms-*`, `.me-*`, `.text-start`) for native RTL language support (Arabic, Hebrew, etc.) without separate stylesheets.

3. **Activity Icon System Refactor**: Individual PNG/GIF icons replaced by font-based/SVG system controlled by CSS variables (`$activity-icon-content-bg`, etc.).

4. **JavaScript Pipeline Modernization**: ES6 modules transpiled to AMD format for RequireJS compatibility.

### 1.2 Design Philosophy: The "SaaS" Aesthetic

**theme_nexus** targets a clean, modern aesthetic reminiscent of contemporary SaaS platforms:

- Ample whitespace and generous padding
- Rounded corners (`border-radius`) throughout
- Subtle drop shadows for depth hierarchy
- High-contrast typography
- Block-based, card-driven layouts
- Smooth transitions and micro-interactions

This aesthetic departs from traditional dense, data-heavy LMS interfaces while maintaining full functional compatibility with Moodle's assessment engines, gradebooks, and activity modules.

### 1.3 Architecture Components Overview

| Component | Technology | Role in Moodle 5.0 |
|-----------|------------|-------------------|
| **Configuration Engine** | PHP (`config.php`) | Theme inheritance, layout mapping, SCSS callback closures |
| **Settings Interface** | PHP (`settings.php`) | Admin UI generation, database mapping for branding controls |
| **Styling Pipeline** | SCSS + `lib.php` | Bootstrap 5 compilation with admin variable injection |
| **Layout Engine** | PHP + Mustache | HTML structure, block regions, page type mapping |
| **Helper Classes** | PHP Classes | Data preparation and formatting for templates |
| **Interactivity Layer** | ES6 → AMD (JS) | UI behaviors, animations, transpiled for browser compatibility |

---

## 2. Development Environment Setup

### 2.1 Prerequisites

| Requirement | Version | Purpose |
|-------------|---------|---------|
| PHP | 8.2.0+ (8.3.x, 8.4.x supported) | Moodle runtime |
| Node.js | LTS v18 or v20 | Grunt task runner, JavaScript transpilation |
| Database | MySQL 8.4+, MariaDB 10.11.0+, PostgreSQL 14+ | Data storage |
| Grunt CLI | Latest | SCSS linting, JS transpilation |

### 2.2 Node.js and Grunt Configuration

```bash
# Install Grunt CLI globally
npm install -g grunt-cli

# From Moodle root directory, install dependencies
cd /path/to/moodle
npm install

# Available Grunt tasks for theme development
grunt amd         # Transpile ES6 JavaScript to AMD
grunt stylelint   # Lint SCSS files for errors
grunt eslint      # Lint JavaScript files
grunt            # Run all tasks
```

**JavaScript Transpilation Pipeline:**
1. Write ES6 code in `theme/nexus/amd/src/*.js`
2. Grunt transpiles via Babel to AMD format
3. Output: `theme/nexus/amd/build/*.min.js`
4. Moodle's RequireJS loader serves minified files in production

### 2.3 Theme Designer Mode (CRITICAL)

During development, **enable Theme Designer Mode**:

**Location:** `Site administration > Appearance > Advanced theme settings`

This forces Moodle to:
- Recompile SCSS on every page request
- Reload Mustache templates without caching
- Reflect changes to `settings.php` immediately

⚠️ **Warning:** Theme Designer Mode significantly degrades performance. **Always perform final testing with it disabled** to verify cache invalidation strategies.

### 2.4 Cache Purging Commands

```bash
# Purge all caches (recommended after settings.php changes)
php admin/cli/purge_caches.php

# Purge theme cache only (after SCSS changes)
php admin/cli/purge_caches.php --theme

# Force theme recompilation
php admin/cli/build_theme_css.php --themes=nexus
```

### 2.5 Development Workflow

1. Enable Theme Designer Mode
2. Make changes to SCSS/templates/settings
3. Refresh browser to see changes
4. Run `grunt stylelint` to catch SCSS errors
5. Run `grunt amd` after JavaScript changes
6. Disable Theme Designer Mode for final testing
7. Purge caches and verify functionality

---

## 3. Moodle 5.0 Technical Requirements

### 3.1 System Requirements

| Component | Requirement |
|-----------|-------------|
| Moodle Version | 5.0+ (build 2025041400) |
| PHP | 8.2.0 minimum |
| Bootstrap | 5.3 (integrated via Boost) |
| Oracle DB | **NOT SUPPORTED** (dropped in Moodle 5.0) |

### 3.2 Bootstrap 5 Migration Imperatives

**Critical CSS Class Replacements:**

| Bootstrap 4 (DEPRECATED) | Bootstrap 5 (REQUIRED) | Usage |
|--------------------------|------------------------|-------|
| `.ml-*`, `.mr-*` | `.ms-*`, `.me-*` | Margins |
| `.pl-*`, `.pr-*` | `.ps-*`, `.pe-*` | Padding |
| `.float-left`, `.float-right` | `.float-start`, `.float-end` | Floats |
| `.text-left`, `.text-right` | `.text-start`, `.text-end` | Text alignment |
| `.badge-primary` | `.text-bg-primary` | Badge colors |
| `.sr-only` | `.visually-hidden` | Screen reader |
| `.close` | `.btn-close` | Close buttons |
| `.form-group` | `.mb-3` | Form spacing |
| `data-toggle` | `data-bs-toggle` | JS triggers |
| `data-target` | `data-bs-target` | JS targets |
| `data-dismiss` | `data-bs-dismiss` | Dismissals |
| `data-ride` | `data-bs-ride` | Carousels |
| `data-slide` | `data-bs-slide` | Carousel slides |

**Deprecated SCSS Mixins (DO NOT USE):**
- `hover`, `hover-focus`, `hover-focus-active`
- `float-left`, `float-right`
- `text-hide`, `form-control-focus`

**Replacement Pattern:**
```scss
// OLD (deprecated)
@include hover-focus() { background: $color; }

// NEW (Bootstrap 5)
&:hover, &:focus { background: $color; }
```

### 3.3 Activity Icon Color System

Moodle 5.0 introduces semantic CSS variables for activity icons:

```scss
// Default values - override in pre-SCSS callback
$activity-icon-administration-bg: #5d63f6 !default;
$activity-icon-assessment-bg: #eb66a2 !default;
$activity-icon-collaboration-bg: #f7634d !default;
$activity-icon-communication-bg: #11a676 !default;
$activity-icon-content-bg: #399be2 !default;
$activity-icon-interactivecontent-bg: #a378ff !default;
```

When admin changes the brand color, these should propagate to maintain visual coherence.

---

## 4. Theme Architecture Overview

### 4.1 The "Super-Child" Pattern

**theme_nexus** uses the "Super-Child" architecture:

```
┌─────────────────────────────────────────────────────┐
│                   theme_nexus                        │
│  ┌───────────────────────────────────────────────┐  │
│  │  Custom SCSS Variables & Overrides            │  │
│  │  Admin Settings Engine (12+ setting tabs)     │  │
│  │  Frontpage Builder (Hero, Slider, Stats...)   │  │
│  │  Custom Layouts (Login, Dashboard, Frontpage) │  │
│  │  Custom Mustache Templates                    │  │
│  │  JavaScript Modules (Counter, Scroll...)      │  │
│  └───────────────────────────────────────────────┘  │
│                         ▲                            │
│                         │ extends                    │
│  ┌───────────────────────────────────────────────┐  │
│  │               theme_boost                     │  │
│  │  Bootstrap 5.3 Full Integration               │  │
│  │  Drawer-based Navigation System               │  │
│  │  Mobile-first Responsive Layouts              │  │
│  │  FontAwesome 6 Icon System                    │  │
│  │  SCSS Compilation Pipeline                    │  │
│  │  ~600 Bootstrap Variables (all overridable)   │  │
│  └───────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────┘
```

**Benefits:**
- Inherits all Boost functionality and updates
- Bootstrap 5 integration without manual setup
- Override only what you need
- Moodle core compatibility maintained

### 4.2 SCSS Injection Workflow

The key to the "Super-Child" pattern is the **closure-based SCSS injection**:

```php
// In config.php
$THEME->scss = function($theme) {
    return theme_nexus_get_main_scss_content($theme);
};
```

**Compilation Order (Critical!):**

1. **Pre-SCSS Callback** → Admin settings converted to SCSS variables
2. **Bootstrap Loading** → Variables override `!default` values
3. **Moodle Core Styles** → Activity modules, gradebook, etc.
4. **Theme Styles** → Our custom partials
5. **Extra SCSS Callback** → Raw SCSS from admin (final cascade)

This order ensures admin color choices propagate to **every** Bootstrap component automatically.

---

## 5. Complete Directory Structure

```
moodle/theme/nexus/
├── amd/
│   ├── src/                        # ES6 JavaScript sources
│   │   ├── counter.js              # Statistics counter animation
│   │   ├── main.js                 # Theme initialization
│   │   └── scroll.js               # Scroll effects
│   └── build/                      # AUTO-GENERATED (do not edit)
│       ├── counter.min.js
│       ├── counter.min.js.map
│       ├── main.min.js
│       ├── main.min.js.map
│       ├── scroll.min.js
│       └── scroll.min.js.map
├── classes/
│   └── output/
│       ├── core_renderer.php       # Renderer overrides
│       └── themesettings.php       # Frontpage section data helper
├── db/
│   ├── install.php                 # Post-installation tasks
│   └── upgrade.php                 # Version upgrade handlers
├── fonts/                          # Custom font files (optional)
│   └── .gitkeep
├── lang/
│   └── en/
│       └── theme_nexus.php         # English language strings (REQUIRED)
├── layout/
│   ├── columns1.php                # Single column layout
│   ├── columns2.php                # Two column layout  
│   ├── drawers.php                 # Standard drawer layout (extends Boost)
│   ├── frontpage.php               # CUSTOM: Frontpage with sections
│   ├── login.php                   # CUSTOM: Branded login page
│   └── mydashboard.php             # CUSTOM: Dashboard with hero region
├── pix/
│   ├── favicon.ico                 # Default favicon
│   └── screenshot.png              # Theme preview (500×400px REQUIRED)
├── scss/
│   ├── preset/
│   │   └── default.scss            # Main preset (imports Bootstrap + Moodle)
│   ├── _variables.scss             # Additional theme variables
│   ├── _mixins.scss                # Custom SCSS mixins
│   ├── _navigation.scss            # Navbar and drawer styles
│   ├── _dashboard.scss             # Dashboard page styles
│   ├── _frontpage.scss             # Frontpage section styles
│   ├── _login.scss                 # Login page styles
│   ├── _course.scss                # Course page styles
│   ├── _blocks.scss                # Block component styles
│   ├── _cards.scss                 # Card component styles
│   ├── _buttons.scss               # Button overrides
│   ├── _footer.scss                # Footer styles
│   └── post.scss                   # Final overrides (loaded last)
├── templates/
│   ├── columns1.mustache           # Single column template
│   ├── columns2.mustache           # Two column template
│   ├── drawers.mustache            # Drawer template
│   ├── frontpage.mustache          # CUSTOM: Frontpage template
│   ├── login.mustache              # CUSTOM: Login template
│   ├── mydashboard.mustache        # CUSTOM: Dashboard template
│   ├── navbar.mustache             # Navigation partial
│   ├── footer.mustache             # Footer partial
│   ├── hero.mustache               # Hero section partial
│   ├── slider.mustache             # Slider section partial
│   ├── marketing.mustache          # Marketing blocks partial
│   ├── statistics.mustache         # Statistics section partial
│   ├── testimonials.mustache       # Testimonials partial
│   └── core/                       # Core template overrides (optional)
│       └── .gitkeep
├── config.php                      # REQUIRED: Theme configuration
├── lib.php                         # REQUIRED: Callbacks and file serving
├── settings.php                    # Admin settings definition
├── version.php                     # REQUIRED: Version and dependencies
├── LICENSE.txt                     # GPL v3+ license text
└── README.md                       # Theme documentation
```

---

## 6. Core Configuration Files

### 6.1 version.php (REQUIRED)

```php
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
 * Theme version information.
 *
 * @package    theme_nexus
 * @copyright  2025 Your Name <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'theme_nexus';      // Frankenstyle component name
$plugin->version = 2025042700;           // YYYYMMDDXX format
$plugin->requires = 2025041400;          // Moodle 5.0 minimum version
$plugin->supported = [500, 501];         // Supported Moodle versions (5.0-5.1)
$plugin->maturity = MATURITY_STABLE;     // MATURITY_ALPHA, MATURITY_BETA, MATURITY_RC, MATURITY_STABLE
$plugin->release = '1.0.0';              // Human-readable version

// Strict dependency on Boost theme
// Prevents installation if Boost is missing or outdated
$plugin->dependencies = [
    'theme_boost' => 2025041400,
];
```

### 6.2 config.php (REQUIRED)

```php
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
 * Theme configuration.
 *
 * @package    theme_nexus
 * @copyright  2025 Your Name <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Load theme library functions
require_once(__DIR__ . '/lib.php');

$THEME->name = 'nexus';

// =========================================================================
// INHERITANCE: Extend Boost (Bootstrap 5.3)
// =========================================================================
$THEME->parents = ['boost'];

// No legacy CSS sheets - SCSS only
$THEME->sheets = [];
$THEME->editor_sheets = [];

// Dock is deprecated in Moodle 5.0
$THEME->enable_dock = false;

// No legacy YUI CSS modules
$THEME->yuicssmodules = [];

// Enable renderer factory for class-based overrides
$THEME->rendererfactory = 'theme_overridden_renderer_factory';

// =========================================================================
// SCSS INJECTION: Dynamic compilation with admin settings
// =========================================================================

// Main SCSS content via closure (allows dynamic injection)
$THEME->scss = function($theme) {
    return theme_nexus_get_main_scss_content($theme);
};

// Pre-SCSS callback: Injects admin settings as SCSS variables BEFORE Bootstrap
$THEME->prescsscallback = 'theme_nexus_get_pre_scss';

// Extra SCSS callback: Appends raw SCSS and background URLs AFTER everything
$THEME->extrascsscallback = 'theme_nexus_get_extra_scss';

// =========================================================================
// ICON SYSTEM: FontAwesome 6 (Moodle 5.0 standard)
// =========================================================================
$THEME->iconsystem = \core\output\icon_system::FONTAWESOME;

// =========================================================================
// FEATURE FLAGS
// =========================================================================
$THEME->haseditswitch = true;        // Show edit mode toggle
$THEME->usescourseindex = true;      // Enable course index sidebar
$THEME->hidefromselector = false;    // Show in theme selector

// =========================================================================
// LAYOUT DEFINITIONS
// =========================================================================
$THEME->layouts = [
    // Base layout - minimal, no blocks
    'base' => [
        'file' => 'drawers.php',
        'regions' => [],
    ],
    
    // Standard layout for most pages
    'standard' => [
        'file' => 'drawers.php',
        'regions' => ['side-pre'],
        'defaultregion' => 'side-pre',
    ],
    
    // Course page layout
    'course' => [
        'file' => 'drawers.php',
        'regions' => ['side-pre'],
        'defaultregion' => 'side-pre',
        'options' => ['langmenu' => true],
    ],
    
    // Course content (activities, resources)
    'incourse' => [
        'file' => 'drawers.php',
        'regions' => ['side-pre'],
        'defaultregion' => 'side-pre',
    ],
    
    // Course category listing
    'coursecategory' => [
        'file' => 'drawers.php',
        'regions' => ['side-pre'],
        'defaultregion' => 'side-pre',
    ],
    
    // =================================================================
    // CUSTOM FRONTPAGE LAYOUT
    // Adds dedicated regions for hero, marketing sections
    // =================================================================
    'frontpage' => [
        'file' => 'frontpage.php',
        'regions' => ['side-pre', 'frontpage-top', 'frontpage-bottom'],
        'defaultregion' => 'side-pre',
        'options' => ['nonavbar' => false, 'langmenu' => true],
    ],
    
    // =================================================================
    // CUSTOM DASHBOARD LAYOUT
    // Adds 'content-top' region for hero/announcements
    // =================================================================
    'mydashboard' => [
        'file' => 'mydashboard.php',
        'regions' => ['side-pre', 'content-top', 'content-bottom'],
        'defaultregion' => 'side-pre',
        'options' => ['nonavbar' => false, 'langmenu' => true],
    ],
    
    // My public profile
    'mypublic' => [
        'file' => 'drawers.php',
        'regions' => ['side-pre'],
        'defaultregion' => 'side-pre',
    ],
    
    // =================================================================
    // CUSTOM LOGIN LAYOUT
    // Dedicated branding, split or centered options
    // =================================================================
    'login' => [
        'file' => 'login.php',
        'regions' => [],
        'options' => ['langmenu' => true, 'nonavbar' => true, 'nofooter' => true],
    ],
    
    // Admin pages
    'admin' => [
        'file' => 'drawers.php',
        'regions' => ['side-pre'],
        'defaultregion' => 'side-pre',
    ],
    
    // Popup windows (minimal chrome)
    'popup' => [
        'file' => 'columns1.php',
        'regions' => [],
        'options' => ['nofooter' => true, 'nonavbar' => true],
    ],
    
    // Maintenance mode
    'maintenance' => [
        'file' => 'drawers.php',
        'regions' => [],
        'options' => ['nofooter' => true, 'nonavbar' => true],
    ],
    
    // Embedded pages (iframes)
    'embedded' => [
        'file' => 'columns1.php',
        'regions' => [],
        'options' => ['nofooter' => true, 'nonavbar' => true],
    ],
    
    // Report pages
    'report' => [
        'file' => 'drawers.php',
        'regions' => ['side-pre'],
        'defaultregion' => 'side-pre',
    ],
    
    // Secure layout (quiz attempts)
    'secure' => [
        'file' => 'drawers.php',
        'regions' => ['side-pre'],
        'defaultregion' => 'side-pre',
        'options' => ['nofooter' => true],
    ],
];
```

### 6.3 lib.php (REQUIRED - Complete Implementation)

```php
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
 * This file acts as the bridge between admin settings (database) and
 * the browser (compiled CSS). Contains SCSS injection callbacks and
 * file serving functions.
 *
 * @package    theme_nexus
 * @copyright  2025 Your Name <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Get the main SCSS content.
 *
 * Loads the preset file which imports Bootstrap and Moodle core styles.
 *
 * @param theme_config $theme The theme configuration object.
 * @return string SCSS content.
 */
function theme_nexus_get_main_scss_content($theme) {
    global $CFG;
    
    $scss = '';
    
    // Load the selected preset (or default)
    $preset = !empty($theme->settings->preset) ? $theme->settings->preset : 'default.scss';
    $presetfile = $CFG->dirroot . '/theme/nexus/scss/preset/' . $preset;
    
    if (file_exists($presetfile)) {
        $scss .= file_get_contents($presetfile);
    } else {
        // Fallback: Load parent theme's default preset
        $scss .= file_get_contents($CFG->dirroot . '/theme/boost/scss/preset/default.scss');
    }
    
    // Load theme-specific post styles
    $postfile = $CFG->dirroot . '/theme/nexus/scss/post.scss';
    if (file_exists($postfile)) {
        $scss .= "\n" . file_get_contents($postfile);
    }
    
    return $scss;
}

/**
 * Get SCSS to prepend - converts admin settings to SCSS variables.
 *
 * CRITICAL: This is where admin settings become Bootstrap variables.
 * Because Bootstrap variables use !default, our injected values override them.
 * This propagates brand colors to EVERY Bootstrap component automatically.
 *
 * @param theme_config $theme The theme configuration object.
 * @return string SCSS content to prepend.
 */
function theme_nexus_get_pre_scss($theme) {
    $scss = '';
    $settings = $theme->settings;
    
    // =================================================================
    // COLOR SETTINGS → Bootstrap Variables
    // =================================================================
    
    // Brand/Primary Color (affects buttons, links, alerts, badges, etc.)
    if (!empty($settings->brandcolor)) {
        $scss .= '$primary: ' . $settings->brandcolor . ";\n";
    }
    
    // Secondary Color
    if (!empty($settings->secondarycolor)) {
        $scss .= '$secondary: ' . $settings->secondarycolor . ";\n";
    }
    
    // Semantic Colors
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
    
    // Body Colors
    if (!empty($settings->bodybgcolor)) {
        $scss .= '$body-bg: ' . $settings->bodybgcolor . ";\n";
    }
    if (!empty($settings->bodytextcolor)) {
        $scss .= '$body-color: ' . $settings->bodytextcolor . ";\n";
    }
    
    // Link Color
    if (!empty($settings->linkcolor)) {
        $scss .= '$link-color: ' . $settings->linkcolor . ";\n";
    }
    
    // =================================================================
    // TYPOGRAPHY SETTINGS
    // =================================================================
    
    // Body Font Family (Google Fonts loaded via HTML)
    if (!empty($settings->fontbody) && $settings->fontbody !== 'inherit') {
        $scss .= '$font-family-base: "' . $settings->fontbody . '", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;' . "\n";
    }
    
    // Heading Font Family
    if (!empty($settings->fontheadings) && $settings->fontheadings !== 'inherit') {
        $scss .= '$headings-font-family: "' . $settings->fontheadings . '", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;' . "\n";
    }
    
    // Base Font Size
    if (!empty($settings->fontsize)) {
        $scss .= '$font-size-base: ' . $settings->fontsize . ";\n";
    }
    
    // =================================================================
    // LAYOUT SETTINGS
    // =================================================================
    
    // Border Radius (cascades to cards, buttons, inputs, etc.)
    if (!empty($settings->borderradius)) {
        $scss .= '$border-radius: ' . $settings->borderradius . ";\n";
        $scss .= '$border-radius-sm: calc(' . $settings->borderradius . ' * 0.5);' . "\n";
        $scss .= '$border-radius-lg: calc(' . $settings->borderradius . ' * 1.5);' . "\n";
    }
    
    // Card Border Radius
    if (!empty($settings->cardborderradius)) {
        $scss .= '$card-border-radius: ' . $settings->cardborderradius . ";\n";
    }
    
    // Button Border Radius
    if (!empty($settings->buttonborderradius)) {
        $scss .= '$btn-border-radius: ' . $settings->buttonborderradius . ";\n";
        $scss .= '$btn-border-radius-sm: calc(' . $settings->buttonborderradius . ' * 0.75);' . "\n";
        $scss .= '$btn-border-radius-lg: calc(' . $settings->buttonborderradius . ' * 1.25);' . "\n";
    }
    
    // Drawer Width
    if (!empty($settings->drawerwidth)) {
        $scss .= '$drawer-width: ' . $settings->drawerwidth . ";\n";
    }
    
    // Navbar Background
    if (!empty($settings->navbarbg)) {
        $scss .= '$navbar-bg: ' . $settings->navbarbg . ";\n";
    }
    
    // =================================================================
    // ACTIVITY ICON COLORS (Moodle 5.0 Feature)
    // =================================================================
    
    if (!empty($settings->activityiconcoloradmin)) {
        $scss .= '$activity-icon-administration-bg: ' . $settings->activityiconcoloradmin . ";\n";
    }
    if (!empty($settings->activityiconcolorassessment)) {
        $scss .= '$activity-icon-assessment-bg: ' . $settings->activityiconcolorassessment . ";\n";
    }
    if (!empty($settings->activityiconcolorcollaboration)) {
        $scss .= '$activity-icon-collaboration-bg: ' . $settings->activityiconcolorcollaboration . ";\n";
    }
    if (!empty($settings->activityiconcolorcommunication)) {
        $scss .= '$activity-icon-communication-bg: ' . $settings->activityiconcolorcommunication . ";\n";
    }
    if (!empty($settings->activityiconcolorcontent)) {
        $scss .= '$activity-icon-content-bg: ' . $settings->activityiconcolorcontent . ";\n";
    }
    if (!empty($settings->activityiconcolorinteractive)) {
        $scss .= '$activity-icon-interactivecontent-bg: ' . $settings->activityiconcolorinteractive . ";\n";
    }
    
    // =================================================================
    // RAW PRE-SCSS FROM ADMIN (Power User Feature)
    // =================================================================
    
    if (!empty($settings->scsspre)) {
        $scss .= $settings->scsspre . "\n";
    }
    
    return $scss;
}

/**
 * Get SCSS to append - includes background images and raw SCSS.
 *
 * Loaded AFTER Bootstrap and Moodle, so can override anything via cascade.
 *
 * @param theme_config $theme The theme configuration object.
 * @return string SCSS content to append.
 */
function theme_nexus_get_extra_scss($theme) {
    $scss = '';
    $settings = $theme->settings;
    
    // =================================================================
    // BACKGROUND IMAGES (Use setting_file_url for secure URLs)
    // =================================================================
    
    // Login Background Image
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
    
    // Dashboard Background Image
    $dashboardbg = $theme->setting_file_url('dashboardbackgroundimage', 'dashboardbackgroundimage');
    if (!empty($dashboardbg)) {
        $scss .= '
        body.pagelayout-mydashboard {
            background-image: url("' . $dashboardbg . '");
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }
        ';
    }
    
    // Hero Background (handled in template, but can add fallback here)
    $herobg = $theme->setting_file_url('herobackgroundimage', 'herobackgroundimage');
    if (!empty($herobg)) {
        $scss .= '
        .hero-section {
            background-image: url("' . $herobg . '");
        }
        ';
    }
    
    // =================================================================
    // CSS CUSTOM PROPERTIES (for JavaScript access)
    // =================================================================
    
    $scss .= '
    :root {
        --nexus-primary: #{$primary};
        --nexus-secondary: #{$secondary};
    }
    ';
    
    // =================================================================
    // RAW SCSS FROM ADMIN (Final Cascade - Overrides Everything)
    // =================================================================
    
    if (!empty($settings->scss)) {
        $scss .= $settings->scss;
    }
    
    return $scss;
}

/**
 * Serve theme plugin files.
 *
 * REQUIRED: Without this function, uploaded files (logos, backgrounds)
 * would return 404 errors. This securely serves files from Moodle's file API.
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
function theme_nexus_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    
    // Only handle system context files
    if ($context->contextlevel != CONTEXT_SYSTEM) {
        send_file_not_found();
    }
    
    // Load theme config
    $theme = theme_config::load('nexus');
    
    // Define ALL valid file areas
    $validareas = [
        // Branding
        'logo',
        'logocompact',
        'favicon',
        // Backgrounds
        'loginbackgroundimage',
        'dashboardbackgroundimage',
        'herobackgroundimage',
        'footerbackgroundimage',
        // Fonts
        'fontfiles',
    ];
    
    // Slider images (up to 10 slides)
    for ($i = 1; $i <= 10; $i++) {
        $validareas[] = "slide{$i}image";
    }
    
    // Marketing block images (up to 8 blocks)
    for ($i = 1; $i <= 8; $i++) {
        $validareas[] = "marketing{$i}image";
    }
    
    // Testimonial images (up to 10 testimonials)
    for ($i = 1; $i <= 10; $i++) {
        $validareas[] = "testimonial{$i}image";
    }
    
    // Gallery images (up to 12 images)
    for ($i = 1; $i <= 12; $i++) {
        $validareas[] = "gallery{$i}image";
    }
    
    // Event images (up to 6 events)
    for ($i = 1; $i <= 6; $i++) {
        $validareas[] = "event{$i}image";
    }
    
    // Serve the file if area is valid
    if (in_array($filearea, $validareas)) {
        $options['cacheability'] = 'public';
        return $theme->setting_file_serve($filearea, $args, $forcedownload, $options);
    }
    
    send_file_not_found();
}

/**
 * Inject Google Fonts into page head.
 *
 * Called before standard head HTML generation.
 *
 * @return string HTML to inject into <head>.
 */
function theme_nexus_before_standard_head_html_generation() {
    $theme = theme_config::load('nexus');
    $html = '';
    
    // Collect required Google Fonts
    $fonts = [];
    
    $fontbody = $theme->settings->fontbody ?? '';
    $fontheadings = $theme->settings->fontheadings ?? '';
    
    // List of available Google Fonts
    $googlefonts = [
        'Open Sans', 'Roboto', 'Poppins', 'Inter', 'Lato',
        'Montserrat', 'Nunito', 'Raleway', 'Source Sans Pro', 'Work Sans'
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
```

---

## 7. Admin Settings System

### 7.1 Settings Architecture Overview

The settings are organized into **12 logical tabs** to prevent admin cognitive overload:

| Tab | Purpose | Key Settings |
|-----|---------|--------------|
| General/Branding | Logo, favicon, site name | `logo`, `logocompact`, `favicon`, `showsitename` |
| Colors | Theme colors | `brandcolor`, `secondarycolor`, semantic colors |
| Typography | Fonts and sizes | `fontbody`, `fontheadings`, `fontsize` |
| Layout | Structure options | `borderradius`, `navbarstyle`, `drawerwidth` |
| Hero Section | Frontpage hero | `heroenabled`, `heroheading`, `herobackgroundimage` |
| Slider | Frontpage carousel | `sliderenabled`, `slidercount`, individual slides |
| Marketing Blocks | Feature cards | `marketingenabled`, individual block content |
| Statistics | Counter section | `statsenabled`, values and labels |
| Testimonials | Social proof | `testimonialsenabled`, quotes and images |
| Footer | Footer content | `footerbgcolor`, `footercontent`, social links |
| Login Page | Login branding | `loginbackgroundimage`, `loginboxposition` |
| Advanced | Power user options | `scsspre`, `scss`, custom HTML |

### 7.2 Key Setting Types

```php
// Color Picker with Live Preview
$previewconfig = ['selector' => '.btn-primary', 'style' => 'background-color'];
$setting = new admin_setting_configcolourpicker($name, $title, $desc, $default, $previewconfig);

// File Upload (logos, backgrounds)
$setting = new admin_setting_configstoredfile($name, $title, $desc, 'filearea', 0, [
    'maxfiles' => 1,
    'accepted_types' => ['.jpg', '.png', '.svg', '.webp']
]);

// Dropdown Select
$setting = new admin_setting_configselect($name, $title, $desc, 'default', [
    'option1' => 'Label 1',
    'option2' => 'Label 2',
]);

// Textarea for Raw SCSS
$setting = new admin_setting_configtextarea($name, $title, $desc, '');

// HTML Editor (footer content)
$setting = new admin_setting_confightmleditor($name, $title, $desc, '');

// CRITICAL: Always set cache callback for visual settings
$setting->set_updatedcallback('theme_reset_all_caches');
```

### 7.3 settings.php (Complete Implementation)

Due to length, here's the structure with key sections:

```php
<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    
    // Create tabbed settings page (Boost admin class)
    $settings = new theme_boost_admin_settingspage_tabs('themesettingnexus',
        get_string('configtitle', 'theme_nexus'));
    
    // =================================================================
    // TAB 1: GENERAL / BRANDING
    // =================================================================
    $page = new admin_settingpage('theme_nexus_general',
        get_string('generalsettings', 'theme_nexus'));
    
    // Logo
    $name = 'theme_nexus/logo';
    $title = get_string('logo', 'theme_nexus');
    $description = get_string('logo_desc', 'theme_nexus');
    $setting = new admin_setting_configstoredfile($name, $title, $description, 'logo', 0,
        ['maxfiles' => 1, 'accepted_types' => ['.jpg', '.jpeg', '.png', '.svg', '.gif']]);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);
    
    // Compact Logo
    $name = 'theme_nexus/logocompact';
    $title = get_string('logocompact', 'theme_nexus');
    $description = get_string('logocompact_desc', 'theme_nexus');
    $setting = new admin_setting_configstoredfile($name, $title, $description, 'logocompact', 0,
        ['maxfiles' => 1, 'accepted_types' => ['.jpg', '.jpeg', '.png', '.svg', '.gif']]);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);
    
    // Favicon
    $name = 'theme_nexus/favicon';
    $title = get_string('favicon', 'theme_nexus');
    $description = get_string('favicon_desc', 'theme_nexus');
    $setting = new admin_setting_configstoredfile($name, $title, $description, 'favicon', 0,
        ['maxfiles' => 1, 'accepted_types' => ['.ico', '.png']]);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);
    
    // Show Site Name
    $name = 'theme_nexus/showsitename';
    $title = get_string('showsitename', 'theme_nexus');
    $description = get_string('showsitename_desc', 'theme_nexus');
    $setting = new admin_setting_configcheckbox($name, $title, $description, 1);
    $page->add($setting);
    
    $settings->add($page);
    
    // =================================================================
    // TAB 2: COLORS
    // =================================================================
    $page = new admin_settingpage('theme_nexus_colors',
        get_string('colorsettings', 'theme_nexus'));
    
    // Brand Color with Live Preview
    $name = 'theme_nexus/brandcolor';
    $title = get_string('brandcolor', 'theme_nexus');
    $description = get_string('brandcolor_desc', 'theme_nexus');
    $default = '#0f6cbf';
    $previewconfig = ['selector' => '.btn-primary, a', 'style' => 'color'];
    $setting = new admin_setting_configcolourpicker($name, $title, $description, $default, $previewconfig);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);
    
    // Secondary Color
    $name = 'theme_nexus/secondarycolor';
    $title = get_string('secondarycolor', 'theme_nexus');
    $description = get_string('secondarycolor_desc', 'theme_nexus');
    $default = '#6c757d';
    $setting = new admin_setting_configcolourpicker($name, $title, $description, $default);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);
    
    // ... Additional colors (success, info, warning, danger, body bg, body text, link)
    
    // Activity Icon Colors Heading
    $page->add(new admin_setting_heading('theme_nexus/activityiconheading',
        get_string('activityiconcolors', 'theme_nexus'),
        get_string('activityiconcolors_desc', 'theme_nexus')));
    
    // Activity Icon Colors (6 semantic colors)
    $activityicons = [
        'admin' => ['Administration', '#5d63f6'],
        'assessment' => ['Assessment', '#eb66a2'],
        'collaboration' => ['Collaboration', '#f7634d'],
        'communication' => ['Communication', '#11a676'],
        'content' => ['Content', '#399be2'],
        'interactive' => ['Interactive', '#a378ff'],
    ];
    
    foreach ($activityicons as $key => $data) {
        $name = "theme_nexus/activityiconcolor{$key}";
        $title = get_string("activityiconcolor{$key}", 'theme_nexus');
        $setting = new admin_setting_configcolourpicker($name, $title, '', $data[1]);
        $setting->set_updatedcallback('theme_reset_all_caches');
        $page->add($setting);
    }
    
    $settings->add($page);
    
    // =================================================================
    // TAB 3: TYPOGRAPHY
    // =================================================================
    $page = new admin_settingpage('theme_nexus_typography',
        get_string('typographysettings', 'theme_nexus'));
    
    // Font choices
    $fontchoices = [
        'inherit' => get_string('font_inherit', 'theme_nexus'),
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
    
    // Body Font
    $name = 'theme_nexus/fontbody';
    $title = get_string('fontbody', 'theme_nexus');
    $description = get_string('fontbody_desc', 'theme_nexus');
    $setting = new admin_setting_configselect($name, $title, $description, 'inherit', $fontchoices);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);
    
    // Heading Font
    $name = 'theme_nexus/fontheadings';
    $title = get_string('fontheadings', 'theme_nexus');
    $description = get_string('fontheadings_desc', 'theme_nexus');
    $setting = new admin_setting_configselect($name, $title, $description, 'inherit', $fontchoices);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);
    
    // Font Size
    $name = 'theme_nexus/fontsize';
    $title = get_string('fontsize', 'theme_nexus');
    $description = get_string('fontsize_desc', 'theme_nexus');
    $sizechoices = [
        '' => get_string('fontsize_default', 'theme_nexus'),
        '0.875rem' => get_string('fontsize_small', 'theme_nexus'),
        '0.9375rem' => get_string('fontsize_medium', 'theme_nexus'),
        '1rem' => get_string('fontsize_large', 'theme_nexus'),
    ];
    $setting = new admin_setting_configselect($name, $title, $description, '', $sizechoices);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);
    
    $settings->add($page);
    
    // =================================================================
    // TAB 4: LAYOUT
    // =================================================================
    // Border radius, navbar style, drawer width settings...
    
    // =================================================================
    // TAB 5: HERO SECTION
    // =================================================================
    // heroenabled, heroheading, herosubheading, heroctabutton, herostyle...
    
    // =================================================================
    // TAB 6: SLIDER
    // =================================================================
    // sliderenabled, slidercount, sliderinterval
    // Loop for individual slides (image, title, caption, button)
    
    // =================================================================
    // TAB 7: MARKETING BLOCKS
    // =================================================================
    // marketingenabled, marketingcount, marketingtitle
    // Loop for individual blocks (icon, title, content, button)
    
    // =================================================================
    // TAB 8: STATISTICS
    // =================================================================
    // statsenabled, statstitle, statssubtitle
    // Loop for 4 statistics (value, suffix, label, icon)
    
    // =================================================================
    // TAB 9: TESTIMONIALS
    // =================================================================
    // testimonialsenabled, testimonialstitle, testimonialcount
    // Loop for testimonials (image, name, role, quote)
    
    // =================================================================
    // TAB 10: FOOTER
    // =================================================================
    // footerbgcolor, footertextcolor, footercontent, copyrighttext
    // Social media URLs (facebook, twitter, instagram, linkedin, youtube)
    
    // =================================================================
    // TAB 11: LOGIN PAGE
    // =================================================================
    // loginbackgroundimage, loginboxposition, logintext
    
    // =================================================================
    // TAB 12: ADVANCED
    // =================================================================
    $page = new admin_settingpage('theme_nexus_advanced',
        get_string('advancedsettings', 'theme_nexus'));
    
    // Raw Pre-SCSS (Variables - loaded before Bootstrap)
    $name = 'theme_nexus/scsspre';
    $title = get_string('rawscsspre', 'theme_nexus');
    $description = get_string('rawscsspre_desc', 'theme_nexus');
    $setting = new admin_setting_configtextarea($name, $title, $description, '');
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);
    
    // Raw SCSS (Rules - loaded after everything, highest cascade priority)
    $name = 'theme_nexus/scss';
    $title = get_string('rawscss', 'theme_nexus');
    $description = get_string('rawscss_desc', 'theme_nexus');
    $setting = new admin_setting_configtextarea($name, $title, $description, '');
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);
    
    // Custom Head HTML
    $name = 'theme_nexus/customheadhtml';
    $title = get_string('customheadhtml', 'theme_nexus');
    $description = get_string('customheadhtml_desc', 'theme_nexus');
    $setting = new admin_setting_configtextarea($name, $title, $description, '');
    $page->add($setting);
    
    // Custom Footer HTML
    $name = 'theme_nexus/customfooterhtml';
    $title = get_string('customfooterhtml', 'theme_nexus');
    $description = get_string('customfooterhtml_desc', 'theme_nexus');
    $setting = new admin_setting_configtextarea($name, $title, $description, '');
    $page->add($setting);
    
    $settings->add($page);
}
```

---

## 8. SCSS Architecture & Styling Pipeline

### 8.1 SCSS Compilation Order

Understanding this order is **critical** for proper theme development:

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. PRE-SCSS CALLBACK (theme_nexus_get_pre_scss)                │
│    - Admin settings converted to SCSS variables                 │
│    - e.g., $primary: #ff0000;                                   │
│    - Raw pre-SCSS from admin textarea                           │
├─────────────────────────────────────────────────────────────────┤
│ 2. MAIN SCSS CONTENT (preset/default.scss)                     │
│    a) FontAwesome import                                        │
│    b) Bootstrap 5.3 (uses !default, so our vars override)       │
│    c) Moodle core styles                                        │
├─────────────────────────────────────────────────────────────────┤
│ 3. THEME PARTIALS (post.scss)                                   │
│    - _navigation.scss, _frontpage.scss, etc.                    │
│    - Theme-specific component styles                            │
├─────────────────────────────────────────────────────────────────┤
│ 4. EXTRA SCSS CALLBACK (theme_nexus_get_extra_scss)            │
│    - Background image URLs                                       │
│    - Raw SCSS from admin textarea (highest cascade priority)    │
└─────────────────────────────────────────────────────────────────┘
```

### 8.2 scss/preset/default.scss

```scss
// ============================================================================
// theme_nexus - Default Preset
// ============================================================================
// Main entry point for SCSS compilation.
// Order: Variables → Imports → Custom Rules
// ============================================================================

// ============================================================================
// SECTION 1: VARIABLE OVERRIDES (BEFORE BOOTSTRAP)
// ============================================================================
// These use !default, allowing pre-SCSS callback to override them.
// Admin color choices will replace these values.

// Brand Colors
$primary: #0f6cbf !default;
$secondary: #6c757d !default;
$success: #357a32 !default;
$info: #17a2b8 !default;
$warning: #f0ad4e !default;
$danger: #ca3120 !default;

// Body Colors
$body-bg: #f8f9fa !default;
$body-color: #1d2125 !default;

// Typography
$font-family-base: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, 
    "Helvetica Neue", Arial, sans-serif !default;
$font-size-base: 0.9375rem !default;
$line-height-base: 1.6 !default;
$headings-font-family: null !default;
$headings-font-weight: 600 !default;

// Border Radius
$border-radius: 0.375rem !default;
$border-radius-sm: 0.25rem !default;
$border-radius-lg: 0.5rem !default;
$card-border-radius: 0.5rem !default;
$btn-border-radius: 0.375rem !default;

// Shadows (SaaS aesthetic - soft, layered)
$box-shadow-sm: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075) !default;
$box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.1) !default;
$box-shadow-lg: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.15) !default;

// Drawer Configuration
$drawer-width: 285px !default;
$drawer-bg: #ffffff !default;

// Links
$link-decoration: none !default;
$link-hover-decoration: underline !default;

// Activity Icon Colors (Moodle 5.0)
$activity-icon-administration-bg: #5d63f6 !default;
$activity-icon-assessment-bg: #eb66a2 !default;
$activity-icon-collaboration-bg: #f7634d !default;
$activity-icon-communication-bg: #11a676 !default;
$activity-icon-content-bg: #399be2 !default;
$activity-icon-interactivecontent-bg: #a378ff !default;

// ============================================================================
// SECTION 2: IMPORT BOOTSTRAP & MOODLE
// ============================================================================

// FontAwesome 6
@import "fontawesome";

// Bootstrap 5.3 (variables use !default, so ours override)
@import "bootstrap";

// Moodle Core Styles
@import "moodle";

// ============================================================================
// SECTION 3: THEME PARTIAL IMPORTS
// ============================================================================

@import "../_variables";
@import "../_mixins";
@import "../_navigation";
@import "../_dashboard";
@import "../_frontpage";
@import "../_login";
@import "../_course";
@import "../_blocks";
@import "../_cards";
@import "../_buttons";
@import "../_footer";
```

### 8.3 scss/_mixins.scss

```scss
// ============================================================================
// Custom SCSS Mixins
// ============================================================================

// Smooth transitions
@mixin transition($properties: all, $duration: 0.2s, $timing: ease-in-out) {
    transition: $properties $duration $timing;
}

// Card hover lift effect (SaaS aesthetic)
@mixin card-hover-lift($distance: -4px) {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    
    &:hover {
        transform: translateY($distance);
        box-shadow: $box-shadow-lg;
    }
}

// Section padding (responsive)
@mixin section-padding($vertical: 4rem) {
    padding: $vertical 0;
    
    @include media-breakpoint-down(md) {
        padding: calc($vertical * 0.75) 0;
    }
}

// Text truncate (multi-line)
@mixin text-truncate-multiline($lines: 2) {
    display: -webkit-box;
    -webkit-line-clamp: $lines;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

// Focus visible ring (accessibility)
@mixin focus-ring($color: $primary) {
    &:focus-visible {
        outline: 2px solid $color;
        outline-offset: 2px;
    }
}

// Gradient background
@mixin gradient-bg($start: $primary, $end: darken($primary, 15%)) {
    background: linear-gradient(135deg, $start 0%, $end 100%);
}

// Overlay effect
@mixin overlay($color: rgba(0, 0, 0, 0.5)) {
    position: relative;
    
    &::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: $color;
        z-index: 1;
    }
    
    > * {
        position: relative;
        z-index: 2;
    }
}
```

### 8.4 scss/_frontpage.scss

```scss
// ============================================================================
// Frontpage Sections
// ============================================================================

// Hero Section
.hero-section {
    position: relative;
    min-height: 500px;
    display: flex;
    align-items: center;
    background-size: cover;
    background-position: center;
    
    .hero-overlay {
        position: absolute;
        inset: 0;  // Bootstrap 5 logical property
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 1;
    }
    
    .hero-content {
        position: relative;
        z-index: 2;
        color: #ffffff;
        
        .hero-heading {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            
            @include media-breakpoint-down(md) {
                font-size: 2rem;
            }
        }
        
        .hero-subheading {
            font-size: 1.25rem;
            margin-bottom: 2rem;
            opacity: 0.9;
            max-width: 600px;
        }
    }
}

// Marketing Blocks
.marketing-section {
    @include section-padding;
    
    .marketing-block {
        @include card-hover-lift;
        text-align: center;
        padding: 2rem 1.5rem;
        background: #ffffff;
        border-radius: $card-border-radius;
        border: none;
        box-shadow: $box-shadow-sm;
        
        .marketing-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: rgba($primary, 0.1);
            border-radius: 50%;
            
            i {
                font-size: 2rem;
                color: $primary;
            }
        }
    }
}

// Statistics Section
.statistics-section {
    @include section-padding;
    background-color: $primary;
    color: #ffffff;
    
    .stat-item {
        text-align: center;
        
        .stat-value {
            font-size: 3rem;
            font-weight: 700;
            line-height: 1;
        }
        
        .stat-label {
            opacity: 0.9;
        }
    }
}

// Testimonials Section
.testimonials-section {
    @include section-padding;
    
    .testimonial-card {
        text-align: center;
        
        .testimonial-image {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 1.5rem;
            border: 4px solid rgba($primary, 0.1);
        }
        
        .testimonial-name {
            font-weight: 600;
            color: $primary;
        }
        
        .testimonial-quote {
            font-style: italic;
            
            &::before, &::after {
                content: '"';
                font-size: 2rem;
                color: $primary;
                line-height: 0;
                vertical-align: -0.3em;
            }
        }
    }
}
```

---

## 9. Layout Files

### 9.1 layout/frontpage.php

```php
<?php
defined('MOODLE_INTERNAL') || die();

// Get theme settings helper
$themesettings = new \theme_nexus\output\themesettings();

// Standard body attributes
$bodyattributes = $OUTPUT->body_attributes([]);

// Block regions
$blockshtml = $OUTPUT->blocks('side-pre');
$hasblocks = strpos($blockshtml, 'data-block=') !== false;
$frontpagetopblocks = $OUTPUT->blocks('frontpage-top');
$frontpagebottomblocks = $OUTPUT->blocks('frontpage-bottom');

// Build template context
$templatecontext = [
    'sitename' => format_string($SITE->shortname, true, 
        ['context' => context_course::instance(SITEID)]),
    'output' => $OUTPUT,
    'bodyattributes' => $bodyattributes,
    'sidepreblocks' => $blockshtml,
    'hasblocks' => $hasblocks,
    'frontpagetopblocks' => $frontpagetopblocks,
    'frontpagebottomblocks' => $frontpagebottomblocks,
    'hasfrontpagetopblocks' => strpos($frontpagetopblocks, 'data-block=') !== false,
    'hasfrontpagebottomblocks' => strpos($frontpagebottomblocks, 'data-block=') !== false,
];

// Merge frontpage section data from settings
$templatecontext = array_merge($templatecontext, $themesettings->hero_section());
$templatecontext = array_merge($templatecontext, $themesettings->slider());
$templatecontext = array_merge($templatecontext, $themesettings->marketing_blocks());
$templatecontext = array_merge($templatecontext, $themesettings->statistics());
$templatecontext = array_merge($templatecontext, $themesettings->testimonials());
$templatecontext = array_merge($templatecontext, $themesettings->footer());

echo $OUTPUT->render_from_template('theme_nexus/frontpage', $templatecontext);
```

### 9.2 layout/login.php

```php
<?php
defined('MOODLE_INTERNAL') || die();

$theme = theme_config::load('nexus');

// Get login box position setting
$loginboxposition = $theme->settings->loginboxposition ?? 'center';

// Build body classes
$bodyclasses = ['login-position-' . $loginboxposition];
$bodyattributes = $OUTPUT->body_attributes($bodyclasses);

// Get branding
$logo = $theme->setting_file_url('logo', 'logo');
$logintext = format_text($theme->settings->logintext ?? '', FORMAT_HTML);

$templatecontext = [
    'sitename' => format_string($SITE->fullname, true, 
        ['context' => context_course::instance(SITEID)]),
    'output' => $OUTPUT,
    'bodyattributes' => $bodyattributes,
    'logo' => $logo,
    'haslogo' => !empty($logo),
    'logintext' => $logintext,
    'haslogintext' => !empty(trim(strip_tags($logintext))),
    'loginboxposition' => $loginboxposition,
    'showsplitlayout' => ($loginboxposition !== 'center'),
];

echo $OUTPUT->render_from_template('theme_nexus/login', $templatecontext);
```

### 9.3 layout/drawers.php & mydashboard.php

```php
<?php
// layout/drawers.php - Extends Boost's drawer layout
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/theme/boost/layout/drawers.php');

// layout/mydashboard.php - Can customize or extend
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/theme/boost/layout/drawers.php');
```

---

## 10. Mustache Templates

### 10.1 templates/frontpage.mustache

```mustache
{{!
    @template theme_nexus/frontpage
    
    Frontpage layout with configurable sections.
    
    Context variables:
    * hashero, hasslides, hasmarketing, hasstats, hastestimonials
    * Section-specific data arrays
}}
{{{ output.doctype }}}
<html {{{ output.htmlattributes }}}>
<head>
    <title>{{{ output.page_title }}}</title>
    <link rel="shortcut icon" href="{{{ output.favicon }}}" />
    {{{ output.standard_head_html }}}
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

<body {{{ bodyattributes }}}>
{{{ output.standard_top_of_body_html }}}

<div id="page-wrapper" class="d-flex flex-column min-vh-100">
    
    {{> theme_nexus/navbar }}
    
    {{#hashero}}
    <section class="hero-section">
        <div class="hero-overlay"></div>
        <div class="container">
            <div class="hero-content text-center py-5">
                <h1 class="hero-heading">{{{ heroheading }}}</h1>
                <div class="hero-subheading mx-auto">{{{ herosubheading }}}</div>
                {{#hascta}}
                <a href="{{{ heroctaurl }}}" class="btn btn-primary btn-lg mt-3">
                    {{{ heroctabutton }}}
                </a>
                {{/hascta}}
            </div>
        </div>
    </section>
    {{/hashero}}
    
    {{#hasslides}}
    <section class="slider-section">
        <div id="frontpage-carousel" class="carousel slide" 
             data-bs-ride="carousel" data-bs-interval="{{{ sliderinterval }}}">
            <div class="carousel-inner">
                {{#slides}}
                <div class="carousel-item {{#isactive}}active{{/isactive}}">
                    <img src="{{{ image }}}" class="d-block w-100" alt="{{{ title }}}">
                    <div class="carousel-caption d-none d-md-block">
                        <h2>{{{ title }}}</h2>
                        <p>{{{ caption }}}</p>
                        {{#hasbutton}}
                        <a href="{{{ buttonurl }}}" class="btn btn-primary">
                            {{{ buttontext }}}
                        </a>
                        {{/hasbutton}}
                    </div>
                </div>
                {{/slides}}
            </div>
            <button class="carousel-control-prev" type="button" 
                    data-bs-target="#frontpage-carousel" data-bs-slide="prev">
                <span class="carousel-control-prev-icon"></span>
                <span class="visually-hidden">{{#str}}previous{{/str}}</span>
            </button>
            <button class="carousel-control-next" type="button" 
                    data-bs-target="#frontpage-carousel" data-bs-slide="next">
                <span class="carousel-control-next-icon"></span>
                <span class="visually-hidden">{{#str}}next{{/str}}</span>
            </button>
        </div>
    </section>
    {{/hasslides}}
    
    {{#hasmarketing}}
    <section class="marketing-section">
        <div class="container">
            {{#marketingtitle}}
            <h2 class="text-center mb-5">{{{ marketingtitle }}}</h2>
            {{/marketingtitle}}
            <div class="row">
                {{#marketingblocks}}
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="marketing-block card h-100">
                        <div class="marketing-icon">
                            <i class="fa {{{ icon }}}"></i>
                        </div>
                        <div class="card-body">
                            <h4 class="card-title">{{{ title }}}</h4>
                            <p class="card-text">{{{ content }}}</p>
                            {{#hasbutton}}
                            <a href="{{{ buttonurl }}}" class="btn btn-outline-primary">
                                {{{ buttontext }}}
                            </a>
                            {{/hasbutton}}
                        </div>
                    </div>
                </div>
                {{/marketingblocks}}
            </div>
        </div>
    </section>
    {{/hasmarketing}}
    
    {{#hasstats}}
    <section class="statistics-section">
        <div class="container">
            <h2 class="text-center mb-5">{{{ statstitle }}}</h2>
            <div class="row">
                {{#stats}}
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="stat-item">
                        <i class="fa {{{ icon }}} fa-2x mb-3"></i>
                        <div class="stat-value" data-target="{{{ value }}}">
                            0<span class="stat-suffix">{{{ suffix }}}</span>
                        </div>
                        <div class="stat-label">{{{ label }}}</div>
                    </div>
                </div>
                {{/stats}}
            </div>
        </div>
    </section>
    {{/hasstats}}
    
    {{#hastestimonials}}
    <section class="testimonials-section">
        <div class="container">
            <h2 class="text-center mb-5">{{{ testimonialstitle }}}</h2>
            <div class="row">
                {{#testimonials}}
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="testimonial-card">
                        {{#image}}
                        <img src="{{{ image }}}" alt="{{{ name }}}" class="testimonial-image">
                        {{/image}}
                        <h5 class="testimonial-name">{{{ name }}}</h5>
                        <p class="testimonial-role text-muted">{{{ role }}}</p>
                        <p class="testimonial-quote">{{{ quote }}}</p>
                    </div>
                </div>
                {{/testimonials}}
            </div>
        </div>
    </section>
    {{/hastestimonials}}
    
    <main id="page" class="container-fluid py-4">
        {{{ output.main_content }}}
    </main>
    
    {{> theme_nexus/footer }}

</div>

{{{ output.standard_end_of_body_html }}}

{{#js}}
require(['theme_nexus/counter'], function(Counter) {
    Counter.init();
});
{{/js}}

</body>
</html>
```

---

## 11. Helper Classes

### 11.1 classes/output/themesettings.php

```php
<?php
namespace theme_nexus\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Theme settings helper - prepares data for templates.
 */
class themesettings {
    
    protected $theme;
    
    public function __construct() {
        $this->theme = \theme_config::load('nexus');
    }
    
    public function hero_section(): array {
        $settings = $this->theme->settings;
        
        if (empty($settings->heroenabled)) {
            return ['hashero' => false];
        }
        
        return [
            'hashero' => true,
            'heroheading' => $settings->heroheading ?? '',
            'herosubheading' => format_text($settings->herosubheading ?? '', FORMAT_HTML),
            'heroctabutton' => $settings->heroctabutton ?? '',
            'heroctaurl' => $settings->heroctaurl ?? '',
            'hascta' => !empty($settings->heroctabutton),
        ];
    }
    
    public function slider(): array {
        $settings = $this->theme->settings;
        
        if (empty($settings->sliderenabled)) {
            return ['hasslides' => false];
        }
        
        $slides = [];
        $count = (int)($settings->slidercount ?? 3);
        
        for ($i = 1; $i <= $count; $i++) {
            $imageurl = $this->theme->setting_file_url("slide{$i}image", "slide{$i}image");
            if (!empty($imageurl)) {
                $slides[] = [
                    'isactive' => count($slides) === 0,
                    'image' => $imageurl,
                    'title' => $settings->{"slide{$i}title"} ?? '',
                    'caption' => $settings->{"slide{$i}caption"} ?? '',
                    'buttontext' => $settings->{"slide{$i}buttontext"} ?? '',
                    'buttonurl' => $settings->{"slide{$i}buttonurl"} ?? '',
                    'hasbutton' => !empty($settings->{"slide{$i}buttontext"}),
                ];
            }
        }
        
        return [
            'hasslides' => !empty($slides),
            'slides' => $slides,
            'sliderinterval' => (int)($settings->sliderinterval ?? 5000),
        ];
    }
    
    public function marketing_blocks(): array {
        $settings = $this->theme->settings;
        
        if (empty($settings->marketingenabled)) {
            return ['hasmarketing' => false];
        }
        
        $blocks = [];
        $count = (int)($settings->marketingcount ?? 4);
        
        for ($i = 1; $i <= $count; $i++) {
            $title = $settings->{"marketing{$i}title"} ?? '';
            if (!empty($title)) {
                $blocks[] = [
                    'icon' => $settings->{"marketing{$i}icon"} ?? 'fa-star',
                    'title' => $title,
                    'content' => format_text($settings->{"marketing{$i}content"} ?? '', FORMAT_HTML),
                    'buttontext' => $settings->{"marketing{$i}buttontext"} ?? '',
                    'buttonurl' => $settings->{"marketing{$i}buttonurl"} ?? '',
                    'hasbutton' => !empty($settings->{"marketing{$i}buttontext"}),
                ];
            }
        }
        
        return [
            'hasmarketing' => !empty($blocks),
            'marketingtitle' => $settings->marketingtitle ?? '',
            'marketingblocks' => $blocks,
        ];
    }
    
    public function statistics(): array {
        $settings = $this->theme->settings;
        
        if (empty($settings->statsenabled)) {
            return ['hasstats' => false];
        }
        
        $stats = [];
        for ($i = 1; $i <= 4; $i++) {
            $value = $settings->{"stat{$i}value"} ?? '';
            if (!empty($value)) {
                $stats[] = [
                    'value' => $value,
                    'suffix' => $settings->{"stat{$i}suffix"} ?? '',
                    'label' => $settings->{"stat{$i}label"} ?? '',
                    'icon' => $settings->{"stat{$i}icon"} ?? 'fa-star',
                ];
            }
        }
        
        return [
            'hasstats' => !empty($stats),
            'statstitle' => $settings->statstitle ?? '',
            'stats' => $stats,
        ];
    }
    
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
                $testimonials[] = [
                    'image' => $this->theme->setting_file_url("testimonial{$i}image", "testimonial{$i}image"),
                    'name' => $name,
                    'role' => $settings->{"testimonial{$i}role"} ?? '',
                    'quote' => format_text($settings->{"testimonial{$i}quote"} ?? '', FORMAT_HTML),
                ];
            }
        }
        
        return [
            'hastestimonials' => !empty($testimonials),
            'testimonialstitle' => $settings->testimonialstitle ?? '',
            'testimonials' => $testimonials,
        ];
    }
    
    public function footer(): array {
        $settings = $this->theme->settings;
        $copyright = str_replace('{year}', date('Y'), 
            $settings->copyrighttext ?? '© {year} All Rights Reserved.');
        
        return [
            'footercontent' => format_text($settings->footercontent ?? '', FORMAT_HTML),
            'copyrighttext' => $copyright,
            'facebookurl' => $settings->facebookurl ?? '',
            'twitterurl' => $settings->twitterurl ?? '',
            'instagramurl' => $settings->instagramurl ?? '',
            'linkedinurl' => $settings->linkedinurl ?? '',
            'youtubeurl' => $settings->youtubeurl ?? '',
            'hassocialmedia' => !empty($settings->facebookurl) || !empty($settings->twitterurl) ||
                !empty($settings->instagramurl) || !empty($settings->linkedinurl) || 
                !empty($settings->youtubeurl),
        ];
    }
}
```

---

## 12. Custom Renderer

### 12.1 classes/output/core_renderer.php

```php
<?php
namespace theme_nexus\output;

defined('MOODLE_INTERNAL') || die;

use theme_boost\output\core_renderer as boost_core_renderer;

/**
 * Custom renderer - overrides core output methods.
 */
class core_renderer extends boost_core_renderer {
    
    /**
     * Return favicon URL from theme settings or default.
     */
    public function favicon() {
        $favicon = $this->page->theme->setting_file_url('favicon', 'favicon');
        if (!empty($favicon)) {
            return $favicon;
        }
        return parent::favicon();
    }
    
    /**
     * Return compact logo URL.
     */
    public function get_compact_logo_url($maxwidth = null, $maxheight = null) {
        $logo = $this->page->theme->setting_file_url('logocompact', 'logocompact');
        if (!empty($logo)) {
            return $logo;
        }
        return parent::get_compact_logo_url($maxwidth, $maxheight);
    }
    
    /**
     * Return full logo URL.
     */
    public function get_logo_url($maxwidth = null, $maxheight = null) {
        $logo = $this->page->theme->setting_file_url('logo', 'logo');
        if (!empty($logo)) {
            return $logo;
        }
        return parent::get_logo_url($maxwidth, $maxheight);
    }
}
```

---

## 13. JavaScript AMD Modules

### 13.1 amd/src/counter.js

```javascript
/**
 * Statistics counter animation module.
 *
 * @module     theme_nexus/counter
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    'use strict';
    
    return {
        /**
         * Initialize counter animations.
         */
        init: function() {
            let animated = false;
            
            const animateCounters = () => {
                const counters = document.querySelectorAll('.stat-value[data-target]');
                
                counters.forEach(counter => {
                    const target = parseInt(counter.dataset.target, 10);
                    if (isNaN(target)) return;
                    
                    const duration = 2000; // 2 seconds
                    const start = performance.now();
                    
                    const updateCounter = (currentTime) => {
                        const elapsed = currentTime - start;
                        const progress = Math.min(elapsed / duration, 1);
                        
                        // Ease-out cubic
                        const easeOut = 1 - Math.pow(1 - progress, 3);
                        const current = Math.floor(target * easeOut);
                        
                        // Get suffix from existing content
                        const suffix = counter.querySelector('.stat-suffix');
                        const suffixText = suffix ? suffix.textContent : '';
                        
                        counter.textContent = current.toLocaleString();
                        if (suffixText) {
                            const suffixEl = document.createElement('span');
                            suffixEl.className = 'stat-suffix';
                            suffixEl.textContent = suffixText;
                            counter.appendChild(suffixEl);
                        }
                        
                        if (progress < 1) {
                            requestAnimationFrame(updateCounter);
                        }
                    };
                    
                    requestAnimationFrame(updateCounter);
                });
            };
            
            // Intersection Observer for scroll-triggered animation
            const checkVisibility = () => {
                const section = document.querySelector('.statistics-section');
                if (!section || animated) return;
                
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting && !animated) {
                            animated = true;
                            animateCounters();
                            observer.disconnect();
                        }
                    });
                }, { threshold: 0.3 });
                
                observer.observe(section);
            };
            
            // Initialize when DOM ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', checkVisibility);
            } else {
                checkVisibility();
            }
        }
    };
});
```

---

## 14. Language Strings

### 14.1 lang/en/theme_nexus.php

```php
<?php
defined('MOODLE_INTERNAL') || die();

// Plugin metadata
$string['pluginname'] = 'Nexus';
$string['configtitle'] = 'Nexus Settings';
$string['choosereadme'] = 'Nexus is a modern, highly customizable theme for Moodle 5.0+.';

// General settings
$string['generalsettings'] = 'General';
$string['logo'] = 'Logo';
$string['logo_desc'] = 'Upload your site logo (recommended: SVG or PNG with transparent background).';
$string['logocompact'] = 'Compact logo';
$string['logocompact_desc'] = 'A smaller version of your logo for the navbar.';
$string['favicon'] = 'Favicon';
$string['favicon_desc'] = 'Upload a favicon (ICO or PNG format).';
$string['showsitename'] = 'Show site name';
$string['showsitename_desc'] = 'Display the site name next to the logo.';

// Color settings
$string['colorsettings'] = 'Colors';
$string['brandcolor'] = 'Brand color';
$string['brandcolor_desc'] = 'Primary brand color used throughout the theme.';
$string['secondarycolor'] = 'Secondary color';
$string['secondarycolor_desc'] = 'Secondary accent color.';
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

// Activity icon colors
$string['activityiconcolors'] = 'Activity icon colors';
$string['activityiconcolors_desc'] = 'Customize colors for different activity categories.';
$string['activityiconcoloradmin'] = 'Administration';
$string['activityiconcolorassessment'] = 'Assessment';
$string['activityiconcolorcollaboration'] = 'Collaboration';
$string['activityiconcolorcommunication'] = 'Communication';
$string['activityiconcolorcontent'] = 'Content';
$string['activityiconcolorinteractive'] = 'Interactive content';

// Typography settings
$string['typographysettings'] = 'Typography';
$string['fontbody'] = 'Body font';
$string['fontbody_desc'] = 'Font family for body text.';
$string['fontheadings'] = 'Headings font';
$string['fontheadings_desc'] = 'Font family for headings.';
$string['fontsize'] = 'Base font size';
$string['fontsize_desc'] = 'Base font size for the theme.';
$string['font_inherit'] = 'System default';
$string['fontsize_default'] = 'Default';
$string['fontsize_small'] = 'Small (14px)';
$string['fontsize_medium'] = 'Medium (15px)';
$string['fontsize_large'] = 'Large (16px)';

// Layout settings
$string['layoutsettings'] = 'Layout';
$string['borderradius'] = 'Border radius';
$string['borderradius_desc'] = 'Corner roundness for elements.';
$string['borderradius_default'] = 'Default';
$string['borderradius_none'] = 'None (square)';
$string['borderradius_small'] = 'Small';
$string['borderradius_medium'] = 'Medium';
$string['borderradius_large'] = 'Large';
$string['borderradius_xlarge'] = 'Extra large';
$string['cardborderradius'] = 'Card border radius';
$string['cardborderradius_desc'] = 'Corner roundness for cards.';
$string['navbarstyle'] = 'Navbar style';
$string['navbarstyle_desc'] = 'Choose the navbar appearance.';
$string['navbarstyle_light'] = 'Light';
$string['navbarstyle_dark'] = 'Dark';
$string['navbarstyle_primary'] = 'Brand color';
$string['navbarbg'] = 'Navbar background';
$string['navbarbg_desc'] = 'Custom navbar background color.';
$string['drawerwidth'] = 'Drawer width';
$string['drawerwidth_desc'] = 'Width of the side drawer navigation.';
$string['drawerwidth_default'] = 'Default (285px)';

// Hero settings
$string['herosettings'] = 'Hero Section';
$string['heroenabled'] = 'Enable hero section';
$string['heroenabled_desc'] = 'Show the hero section on the frontpage.';
$string['herobackgroundimage'] = 'Hero background image';
$string['herobackgroundimage_desc'] = 'Background image for the hero section.';
$string['heroheading'] = 'Hero heading';
$string['heroheading_desc'] = 'Main heading text in the hero section.';
$string['herosubheading'] = 'Hero subheading';
$string['herosubheading_desc'] = 'Supporting text below the heading.';
$string['heroctabutton'] = 'CTA button text';
$string['heroctabutton_desc'] = 'Text for the call-to-action button.';
$string['heroctaurl'] = 'CTA button URL';
$string['heroctaurl_desc'] = 'Link for the call-to-action button.';
$string['herostyle'] = 'Hero style';
$string['herostyle_desc'] = 'Layout style for the hero section.';
$string['herostyle_full'] = 'Full width';
$string['herostyle_split'] = 'Split (text/image)';
$string['herostyle_centered'] = 'Centered';
$string['herooverlay'] = 'Hero overlay color';
$string['herooverlay_desc'] = 'Overlay color for readability (use rgba format).';

// Slider settings
$string['slidersettings'] = 'Slider';
$string['sliderenabled'] = 'Enable slider';
$string['sliderenabled_desc'] = 'Show a carousel slider on the frontpage.';
$string['slidercount'] = 'Number of slides';
$string['slidercount_desc'] = 'How many slides to show.';
$string['sliderinterval'] = 'Slide interval';
$string['sliderinterval_desc'] = 'Time between slide transitions.';
$string['seconds'] = 'seconds';
$string['slide'] = 'Slide';
$string['slideimage'] = 'Slide image';
$string['slideimage_desc'] = 'Image for this slide.';
$string['slidetitle'] = 'Slide title';
$string['slidecaption'] = 'Slide caption';
$string['slidebuttontext'] = 'Button text';
$string['slidebuttonurl'] = 'Button URL';

// Marketing settings
$string['marketingsettings'] = 'Marketing Blocks';
$string['marketingenabled'] = 'Enable marketing blocks';
$string['marketingenabled_desc'] = 'Show marketing/feature blocks on the frontpage.';
$string['marketingcount'] = 'Number of blocks';
$string['marketingcount_desc'] = 'How many marketing blocks to display.';
$string['marketingtitle'] = 'Section title';
$string['marketingtitle_desc'] = 'Heading for the marketing section.';
$string['marketingblock'] = 'Marketing block';
$string['marketingicon'] = 'Icon';
$string['marketingicon_desc'] = 'FontAwesome icon class (e.g., fa-star).';
$string['marketingblocktitle'] = 'Title';
$string['marketingcontent'] = 'Content';
$string['marketingbuttontext'] = 'Button text';
$string['marketingbuttonurl'] = 'Button URL';

// Statistics settings
$string['statisticssettings'] = 'Statistics';
$string['statsenabled'] = 'Enable statistics';
$string['statsenabled_desc'] = 'Show statistics counters on the frontpage.';
$string['statstitle'] = 'Section title';
$string['statstitle_desc'] = 'Heading for the statistics section.';
$string['statssubtitle'] = 'Section subtitle';
$string['statistic'] = 'Statistic';
$string['statvalue'] = 'Value';
$string['statsuffix'] = 'Suffix';
$string['statlabel'] = 'Label';
$string['staticon'] = 'Icon';

// Testimonials settings
$string['testimonialsettings'] = 'Testimonials';
$string['testimonialsenabled'] = 'Enable testimonials';
$string['testimonialsenabled_desc'] = 'Show testimonials on the frontpage.';
$string['testimonialstitle'] = 'Section title';
$string['testimonialcount'] = 'Number of testimonials';
$string['testimonial'] = 'Testimonial';
$string['testimonialimage'] = 'Photo';
$string['testimonialname'] = 'Name';
$string['testimonialrole'] = 'Role/Title';
$string['testimonialquote'] = 'Quote';

// Footer settings
$string['footersettings'] = 'Footer';
$string['footerbgcolor'] = 'Footer background';
$string['footerbgcolor_desc'] = 'Background color for the footer.';
$string['footertextcolor'] = 'Footer text color';
$string['footercontent'] = 'Footer content';
$string['footercontent_desc'] = 'HTML content for the footer.';
$string['copyrighttext'] = 'Copyright text';
$string['copyrighttext_desc'] = 'Copyright notice. Use {year} for current year.';
$string['facebookurl'] = 'Facebook URL';
$string['twitterurl'] = 'Twitter/X URL';
$string['instagramurl'] = 'Instagram URL';
$string['linkedinurl'] = 'LinkedIn URL';
$string['youtubeurl'] = 'YouTube URL';

// Login settings
$string['loginsettings'] = 'Login Page';
$string['loginbackgroundimage'] = 'Login background';
$string['loginbackgroundimage_desc'] = 'Background image for the login page.';
$string['loginboxposition'] = 'Login box position';
$string['loginboxposition_desc'] = 'Position of the login form.';
$string['loginboxposition_center'] = 'Center';
$string['loginboxposition_left'] = 'Left';
$string['loginboxposition_right'] = 'Right';
$string['logintext'] = 'Custom login text';
$string['logintext_desc'] = 'Welcome message on the login page.';
$string['welcome'] = 'Welcome';
$string['loginwelcome_desc'] = 'Sign in to access your learning dashboard.';

// Advanced settings
$string['advancedsettings'] = 'Advanced';
$string['rawscsspre'] = 'Raw initial SCSS';
$string['rawscsspre_desc'] = 'SCSS code added before Bootstrap (use for variables).';
$string['rawscss'] = 'Raw SCSS';
$string['rawscss_desc'] = 'SCSS code added at the end (highest cascade priority).';
$string['customheadhtml'] = 'Custom head HTML';
$string['customheadhtml_desc'] = 'HTML added to the <head> section.';
$string['customfooterhtml'] = 'Custom footer HTML';
$string['customfooterhtml_desc'] = 'HTML added before closing </body>.';

// Footer partials
$string['quicklinks'] = 'Quick Links';
$string['support'] = 'Support';
$string['connect'] = 'Connect With Us';
$string['faq'] = 'FAQ';
$string['helpdesk'] = 'Help Desk';
$string['privacy'] = 'Privacy Policy';
$string['terms'] = 'Terms of Use';
$string['poweredbymoodle'] = 'Powered by Moodle';
```

---

## 15. Performance Optimization

### 15.1 SCSS Compilation

```bash
# Use native SASSC for faster compilation
# Add to config.php:
$CFG->pathtosassc = '/usr/bin/sassc';
```

### 15.2 Best Practices

1. **Avoid `@extend`** in SCSS (generates excessive CSS)
2. **Use Bootstrap utility classes** instead of custom CSS
3. **Disable Theme Designer Mode** in production
4. **Use `set_updatedcallback('theme_reset_all_caches')`** for all visual settings
5. **Lazy load images** with `loading="lazy"`
6. **Pre-optimize images** before upload (WebP recommended)
7. **Minimize JavaScript modules** - combine where possible

### 15.3 Caching Strategy

```php
// lib.php - Always call this for settings affecting CSS
$setting->set_updatedcallback('theme_reset_all_caches');

// CLI command for deployment
php admin/cli/purge_caches.php --theme
```

---

## 16. Testing Strategy

### 16.1 PHPUnit Test

```php
<?php
// tests/theme_nexus_test.php
namespace theme_nexus;

class theme_nexus_test extends \advanced_testcase {
    
    public function setUp(): void {
        $this->resetAfterTest(true);
    }
    
    public function test_theme_config_exists() {
        global $CFG;
        $this->assertFileExists("{$CFG->dirroot}/theme/nexus/config.php");
    }
    
    public function test_scss_callbacks_return_strings() {
        $theme = \theme_config::load('nexus');
        $prescss = theme_nexus_get_pre_scss($theme);
        $this->assertIsString($prescss);
    }
    
    public function test_preset_file_exists() {
        global $CFG;
        $this->assertFileExists("{$CFG->dirroot}/theme/nexus/scss/preset/default.scss");
    }
}
```

### 16.2 Behat Test

```gherkin
# tests/behat/theme_settings.feature
@theme @theme_nexus @javascript
Feature: Theme nexus settings
  As an administrator
  I need to configure theme settings
  So that the theme displays correctly

  Scenario: Configure brand color
    Given I log in as "admin"
    And I navigate to "Appearance > Themes > Nexus" in site administration
    When I set the field "Brand color" to "#FF0000"
    And I press "Save changes"
    Then I should see "Changes saved"
```

---

## 17. Accessibility Compliance

### 17.1 WCAG 2.1 AA Requirements

- **Color contrast**: Minimum 4.5:1 for normal text, 3:1 for large text
- **Focus indicators**: Visible focus rings on all interactive elements
- **Keyboard navigation**: All functionality accessible via keyboard
- **ARIA landmarks**: Proper use of `role` and `aria-*` attributes
- **Alt text**: All images must have descriptive alt text
- **Skip links**: Provide "Skip to main content" links

### 17.2 Implementation

```scss
// Focus ring for accessibility
*:focus-visible {
    outline: 2px solid $primary;
    outline-offset: 2px;
}

// Skip link
.sr-only-focusable:focus {
    position: absolute;
    top: 0;
    left: 0;
    z-index: 9999;
    padding: 1rem;
    background: $primary;
    color: #ffffff;
}

// Reduced motion
@media (prefers-reduced-motion: reduce) {
    * {
        animation-duration: 0.01ms !important;
        transition-duration: 0.01ms !important;
    }
}
```

---

## 18. Deployment & Plugin Directory

### 18.1 Pre-submission Checklist

- [ ] All required files present (version.php, config.php, lang file)
- [ ] Frankenstyle naming throughout (`theme_nexus`)
- [ ] GPL v3+ headers on all PHP files
- [ ] PHPDoc on all classes and functions
- [ ] No PHP warnings with developer debugging
- [ ] Tested on MySQL and PostgreSQL
- [ ] Screenshot at 500×400px
- [ ] README.md documentation
- [ ] Public source control URL (GitHub)
- [ ] Bug tracker URL

### 18.2 Repository Naming

```
GitHub: moodle-theme_nexus
```

---

## 19. Bootstrap 5 Migration Reference

### Quick Reference Card

```scss
// Margins/Padding
.ml-3 → .ms-3    // margin-start
.mr-3 → .me-3    // margin-end
.pl-3 → .ps-3    // padding-start
.pr-3 → .pe-3    // padding-end

// Text Alignment
.text-left → .text-start
.text-right → .text-end

// Floats
.float-left → .float-start
.float-right → .float-end

// Badges
.badge-primary → .text-bg-primary

// Screen Reader
.sr-only → .visually-hidden

// Data Attributes
data-toggle → data-bs-toggle
data-target → data-bs-target
data-dismiss → data-bs-dismiss
```

---

## 20. Implementation Checklist

### Phase 1: Scaffolding
- [ ] Create directory structure
- [ ] Create version.php
- [ ] Create config.php
- [ ] Create lib.php with basic callbacks
- [ ] Create lang/en/theme_nexus.php (basic strings)

### Phase 2: Core Functionality
- [ ] Create scss/preset/default.scss
- [ ] Create SCSS partials (_variables, _mixins, etc.)
- [ ] Create settings.php (all 12 tabs)
- [ ] Create layout files
- [ ] Create Mustache templates

### Phase 3: Advanced Features
- [ ] Create themesettings.php helper class
- [ ] Create core_renderer.php
- [ ] Create JavaScript AMD modules
- [ ] Implement frontpage sections

### Phase 4: Testing & Polish
- [ ] Run stylelint on SCSS
- [ ] Run eslint on JavaScript
- [ ] Create PHPUnit tests
- [ ] Create Behat tests
- [ ] Accessibility audit
- [ ] Cross-browser testing
- [ ] Mobile responsiveness testing

### Phase 5: Documentation & Release
- [ ] Complete README.md
- [ ] Add screenshot.png
- [ ] Create LICENSE.txt
- [ ] Submit to Moodle Plugin Directory

---

**Document Version:** 1.0.0  
**Last Updated:** April 2025  
**Compatible With:** Moodle 5.0+, Bootstrap 5.3  
**License:** GPL v3+
