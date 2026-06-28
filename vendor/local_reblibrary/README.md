# REB Library - Moodle Plugin

A comprehensive Moodle local plugin for managing the Rwanda Education Board digital library with TypeScript + Preact frontend and full-featured resource management system.

## Overview

**REB Library** (`local_reblibrary`) provides:
- Public library interface with categorized books by education level, sublevel, and class
- Admin dashboard for managing resources, authors, categories, and education structure
- Integration with Rwanda's education system (Levels, Sublevels, Classes, A-Level Sections)
- RESTful web services API for all CRUD operations
- Modern TypeScript + Preact frontend with Tailwind CSS v4
- PDF reader with zoom and navigation controls
- Horizontal scrolling book sections grouped by class

## Features

✅ **Resource Management**
- Add/edit/delete books with cover images, PDFs, ISBNs, and descriptions
- Assign resources to multiple classes and categories
- Author management with biographical information
- Category hierarchy with parent-child relationships

✅ **Education Structure**
- Manage education levels (Pre-primary, Primary, Secondary)
- Define sublevels (Nursery, Lower Primary, Upper Primary, O Level, A Level)
- Create classes with unique codes (N1, P1-P6, S1-S6, etc.)
- Configure A-Level sections (PCM, MEG, HEG, etc.)

✅ **Public Library Interface**
- Browse books organized by education level and class
- Filter by level, sublevel, class, and category
- Search by title or author
- Horizontal scrolling sections for each class
- Built-in PDF reader

✅ **Modern Tech Stack**
- TypeScript for type safety
- Preact for lightweight reactive UI
- Tailwind CSS v4 for styling
- Vite for fast builds and HMR
- Moodle web services for backend API

## Development Setup

### Prerequisites
- Node.js 22.11.0+ (for pnpm and Vite)
- pnpm package manager
- Docker (if using the parent Moodle Docker setup)

### Installation

```bash
# Install dependencies
pnpm install

# Build once
pnpm run build

# Watch for changes (development mode)
pnpm run dev
```

### After Every Build

Always purge Moodle caches after building frontend changes:

```bash
# From project root
docker compose exec php php /var/www/html/moodle_app/admin/cli/purge_caches.php
```

## Project Structure

```
local/reblibrary/
├── amd/
│   ├── src/                          # TypeScript source files
│   │   ├── components/               # Preact components
│   │   │   ├── admin/               # Admin UI components
│   │   │   │   ├── Dashboard.tsx
│   │   │   │   ├── Resources.tsx
│   │   │   │   ├── Categories.tsx
│   │   │   │   └── StatsCard.tsx
│   │   │   ├── education/           # Education structure components
│   │   │   │   ├── EdStructure.tsx
│   │   │   │   ├── LevelTab.tsx
│   │   │   │   ├── SublevelTab.tsx
│   │   │   │   ├── ClassTab.tsx
│   │   │   │   └── SectionTab.tsx
│   │   │   ├── shared/              # Shared components
│   │   │   │   ├── Sidebar.tsx
│   │   │   │   ├── PDFReader.tsx
│   │   │   │   └── Toast.tsx
│   │   │   └── LibraryHome.tsx      # Public library home
│   │   ├── services/                # API service layers
│   │   │   ├── resources.ts
│   │   │   ├── authors.ts
│   │   │   ├── categories.ts
│   │   │   └── edu_structure.ts
│   │   ├── config/                  # Configuration
│   │   │   ├── admin-menu.ts
│   │   │   └── library-menu.ts
│   │   ├── types.ts                 # TypeScript type definitions
│   │   ├── styles.css               # Tailwind CSS imports
│   │   ├── dashboard.ts             # Entry: Admin dashboard
│   │   ├── resources.ts             # Entry: Resource management
│   │   ├── categories.ts            # Entry: Category management
│   │   ├── ed-structure.ts          # Entry: Education structure
│   │   └── library-home.ts          # Entry: Library home page
│   └── build/                        # Compiled AMD modules (generated)
├── admin/                            # Admin pages
│   ├── index.php                    # Dashboard
│   ├── resources.php                # Resource management
│   ├── categories.php               # Category management
│   └── ed_structure.php             # Education structure
├── classes/
│   └── external/                     # Web services API
│       ├── resources.php            # Resource CRUD
│       ├── authors.php              # Author CRUD
│       ├── categories.php           # Category CRUD
│       └── edu_structure.php        # Education structure CRUD
├── db/
│   ├── access.php                   # Capabilities
│   ├── install.xml                  # Database schema
│   ├── services.php                 # Web services registration
│   └── upgrade.php                  # Upgrade routines
├── lang/
│   └── en/
│       └── local_reblibrary.php     # Language strings
├── templates/
│   └── library_home.mustache        # Library home template with skeleton
├── index.php                         # Public library home page
├── version.php                       # Plugin version
├── vite.config.ts                   # Vite build configuration
├── package.json                     # Node dependencies
├── pnpm-lock.yaml                   # Lock file
└── README.md                         # This file
```

## Database Schema

The plugin creates 8 custom tables:

### Education Structure
- `mdl_local_reblibrary_edu_levels` - Main education levels
- `mdl_local_reblibrary_edu_sublevels` - Sublevels within each level
- `mdl_local_reblibrary_classes` - Grade levels/classes
- `mdl_local_reblibrary_sections` - A-Level subject combinations

### Resource Management
- `mdl_local_reblibrary_resources` - Books/resources
- `mdl_local_reblibrary_authors` - Author information
- `mdl_local_reblibrary_categories` - Hierarchical categories

### Junction Tables
- `mdl_local_reblibrary_res_assigns` - Resource-to-class assignments
- `mdl_local_reblibrary_res_categories` - Resource-to-category mappings

## Web Services API

All CRUD operations are exposed via Moodle's external API (AJAX-enabled):

### Resources
- `local_reblibrary_get_all_resources`
- `local_reblibrary_get_resource_by_id`
- `local_reblibrary_create_resource`
- `local_reblibrary_update_resource`
- `local_reblibrary_delete_resource`

### Authors, Categories, and Education Structure
Similar CRUD endpoints for each entity type.

## Frontend Development

### Entry Points

All root-level `.ts` and `.tsx` files in `amd/src/` are automatically discovered as entry points by Vite:

- `dashboard.ts` → Admin dashboard
- `resources.ts` → Resource management
- `categories.ts` → Category management
- `ed-structure.ts` → Education structure management
- `library-home.ts` → Public library home

**Excluded from entries** (imported by other modules):
- `app`, `types`, `store` (configured in `vite.config.ts`)

### Creating a New Page

1. Create entry point in `amd/src/`:

```typescript
// amd/src/mypage.ts
import { h, render } from 'preact';
import MyComponent from './components/MyComponent';

export const init = () => {
  render(<MyComponent />, document.getElementById('app'));
};
```

2. Create component in `amd/src/components/`:

```tsx
// amd/src/components/MyComponent.tsx
import { h } from 'preact';

export default function MyComponent() {
  return (
    <div className="p-4 bg-white rounded-lg shadow-md">
      <h1 className="text-2xl font-bold text-gray-900">My Component</h1>
    </div>
  );
}
```

3. Load in PHP:

```php
$PAGE->requires->js_call_amd('local_reblibrary/mypage', 'init');
```

4. Build and purge caches:

```bash
pnpm run build
docker compose exec php php /var/www/html/moodle_app/admin/cli/purge_caches.php
```

### Using Tailwind CSS

Tailwind CSS v4 is pre-configured with REB brand colors:

```typescript
<div className="bg-reb-blue text-white p-4 rounded-lg">
  REB Blue Background
</div>
```

**Available REB Blue Shades:**
- `bg-reb-blue` (default: #005198)
- `bg-reb-blue-50` through `bg-reb-blue-900`

CSS is automatically injected when modules load - no separate CSS files needed.

### API Service Usage

Example using the resources service:

```typescript
import { getResources, createResource, deleteResource } from './services/resources';

// Fetch all resources
const resources = await getResources();

// Create a new resource
const newResource = await createResource({
  title: 'New Book',
  isbn: '978-...',
  description: 'A great book',
  author_id: 1,
  file_url: 'https://...',
  cover_image_url: 'https://...',
  class_ids: [1, 2, 3],
  category_ids: [1, 2]
});

// Delete a resource
await deleteResource(resourceId);
```

All services handle Moodle's AJAX API communication and error handling.

## URLs

### Public Pages
- **Library Home**: `http://localhost:8080/local/reblibrary/`

### Admin Pages (requires `moodle/site:config`)
- **Dashboard**: `http://localhost:8080/local/reblibrary/admin/`
- **Resources**: `http://localhost:8080/local/reblibrary/admin/resources.php`
- **Categories**: `http://localhost:8080/local/reblibrary/admin/categories.php`
- **Education Structure**: `http://localhost:8080/local/reblibrary/admin/ed_structure.php`

## Capabilities

- `local/reblibrary:view` - View library (all users)
- `local/reblibrary:manageresources` - Manage resources
- `local/reblibrary:manageauthors` - Manage authors
- `local/reblibrary:managecategories` - Manage categories
- `local/reblibrary:manageedulevels` - Manage education levels
- `local/reblibrary:manageedusublevels` - Manage sublevels
- `local/reblibrary:manageclasses` - Manage classes
- `local/reblibrary:managesections` - Manage A-Level sections
- `local/reblibrary:manageassignments` - Assign resources to classes

## Build Configuration

**vite.config.ts** features:
- Auto-discovery of entry points from `amd/src/*.{ts,tsx}`
- AMD format output for RequireJS compatibility
- Dependency bundling (Preact, Tailwind CSS, etc.)
- Source maps for debugging
- Minification via esbuild
- Export preservation (required for Moodle)
- CSS injection (no separate CSS files)

## Troubleshooting

### Changes not showing up

```bash
pnpm run build
docker compose exec php php /var/www/html/moodle_app/admin/cli/purge_caches.php
```

### Database schema changes

1. Edit `db/install.xml`
2. Add upgrade step to `db/upgrade.php`
3. Increment version in `version.php`
4. Run upgrade:

```bash
docker compose exec php php /var/www/html/moodle_app/admin/cli/upgrade.php
```

### TypeScript errors

```bash
pnpm install
pnpm run build
```

### Web service not working

- Check capabilities in `db/services.php`
- Verify user has required capability
- Check browser console for AJAX errors
- Ensure method names follow convention: `{method}_parameters()`, `{method}_returns()`

## Version

**Current Version**: 1.3.1 (2025102403)

**Requirements**:
- Moodle 5.0+
- PHP 8.2+
- Node.js 22.11.0+
- MariaDB 11.4 or PostgreSQL

## License

GNU GPL v3 or later

## Credits

**Copyright**: 2025 Rwanda Education Board
**Maintainer**: REB Development Team
