import { defineConfig } from 'vite';
import { resolve } from 'path';
import { readdirSync } from 'fs';

// Automatically find all TypeScript files in amd/src/ (root level only)
// Exclude files that are only imported by other modules (not entry points)
// Note: Subdirectories (components/, services/) are automatically excluded
const srcDir = resolve(__dirname, 'amd/src');
const excludeFromEntries = ['app', 'types', 'store']; // Only imported by other modules, not AMD entries

const entryPoints = readdirSync(srcDir)
  .filter(file => file.endsWith('.ts') || file.endsWith('.tsx'))
  .filter(file => !excludeFromEntries.includes(file.replace(/\.(ts|tsx)$/, '')))
  .reduce((entries, file) => {
    const name = file.replace(/\.(ts|tsx)$/, '');
    entries[name] = resolve(srcDir, file);
    return entries;
  }, {} as Record<string, string>);

export default defineConfig({
  build: {
    // Output directory for compiled files
    outDir: 'amd/build',

    // Don't empty the output directory (in case other files exist)
    emptyOutDir: false,

    // Rollup-specific options
    rollupOptions: {
      // Multiple entry points
      input: entryPoints,

      // Mark Moodle core modules as external (loaded by RequireJS)
      external: ['core/ajax'],

      output: {
        // AMD-specific output options
        format: 'amd',
        // Output each entry as a separate file. Moodle's dev AMD loader
        // (core_requirejs::find_one_amd_module) resolves ONLY <module>.min.js,
        // so emit .min.js or the module 404s under cachejs=false/jsrev=-1.
        entryFileNames: '[name].min.js',
        // CSS output without hash
        assetFileNames: '[name][extname]',
        // Use simple variable names for cleaner output
        compact: false,
        // Preserve module structure
        preserveModules: false,
        // IMPORTANT: Preserve exports for Moodle AMD modules
        exports: 'named',
        // AMD module ID for RequireJS/Moodle
        amd: {
          // Don't define module IDs - let Moodle handle this
          define: 'define',
        },
        // Disable automatic chunking - bundle dependencies into each entry
        manualChunks: undefined,
      },

      // CRITICAL: Preserve all exports from entry points even if unused
      preserveEntrySignatures: 'exports-only',
    },

    // Generate sourcemaps for debugging
    sourcemap: true,

    // Minify for production (set to false for easier debugging)
    minify: 'esbuild',
  },

  // Resolve configuration for TypeScript and npm packages
  resolve: {
    extensions: ['.ts', '.tsx', '.js', '.jsx'],
  },
});
