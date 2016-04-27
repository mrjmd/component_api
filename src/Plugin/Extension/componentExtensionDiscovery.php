<?php

/**
 * @file
 * Contains \Drupal\pdb\Plugin\Extension\PdbExtensionDiscovery.
 */

namespace Drupal\component_api\Plugin\Extension;

use Drupal\Core\Extension\ExtensionDiscovery;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\Discovery\RecursiveExtensionFilterIterator;

/**
 * Discovers available extensions in the filesystem.
 *
 * To also discover test modules, add
 * @code
 * $settings['extension_discovery_scan_tests'] = TRUE;
 * @encode
 * to your settings.php.
 *
 */
class componentExtensionDiscovery extends ExtensionDiscovery {

    /**
     * Origin directory weight: Core.
     */
    const PDB_SITES_ALL = 0;

    /**
     * Discovers available extensions of a given type.
     *
     * Finds all extensions (modules, themes, etc) that exist on the site. It
     * searches in several locations. For instance, to discover all available
     * modules:
     * @code
     * $listing = new ExtensionDiscovery(\Drupal::root());
     * $modules = $listing->scan('module');
     * @endcode
     *
     * The following directories will be searched (in the order stated):
     * - the core directory; i.e., /core
     * - the installation profile directory; e.g., /core/profiles/standard
     * - the legacy site-wide directory; i.e., /sites/all
     * - the site-wide directory; i.e., /
     * - the site-specific directory; e.g., /sites/example.com
     *
     * To also find test modules, add
     * @code
     * $settings['extension_discovery_scan_tests'] = TRUE;
     * @encode
     * to your settings.php.
     *
     * The information is returned in an associative array, keyed by the extension
     * name (without .info.yml extension). Extensions found later in the search
     * will take precedence over extensions found earlier - unless they are not
     * compatible with the current version of Drupal core.
     *
     * @param string $type
     *   The extension type to search for. One of 'profile', 'module', 'theme', or
     *   'theme_engine'.
     * @param bool $include_tests
     *   (optional) Whether to explicitly include or exclude test extensions. By
     *   default, test extensions are only discovered when in a test environment.
     *
     * @return \Drupal\Core\Extension\Extension[]
     *   An associative array of Extension objects, keyed by extension name.
     */
    public function scan($type, $include_tests = NULL) {

        // Search the legacy sites/all directory.
        $searchdirs[static::PDB_SITES_ALL] = 'modules/pdb';

        $files = array();
        foreach ($searchdirs as $dir) {
            // Discover all extensions in the directory, unless we did already.
            if (!isset(static::$files[$dir][$include_tests])) {
                static::$files[$dir][$include_tests] = $this->scanDirectory($dir, $include_tests);
            }
            // Only return extensions of the requested type.
            if (isset(static::$files[$dir][$include_tests][$type])) {
                $files += static::$files[$dir][$include_tests][$type];
            }
        }

        // Sort the discovered extensions by their originating directories.
        $origin_weights = array_flip($searchdirs);
        $files = $this->sort($files, $origin_weights);

        // Process and return the list of extensions keyed by extension name.
        return $this->process($files);
    }

    /**
     * Recursively scans a base directory for the requested extension type.
     *
     * @param string $dir
     *   A relative base directory path to scan, without trailing slash.
     * @param bool $include_tests
     *   Whether to include test extensions. If FALSE, all 'tests' directories are
     *   excluded in the search.
     *
     * @return array
     *   An associative array whose keys are extension type names and whose values
     *   are associative arrays of \Drupal\Core\Extension\Extension objects, keyed
     *   by absolute path name.
     *
     * @see \Drupal\Core\Extension\Discovery\RecursiveExtensionFilterIterator
     */
    protected function scanDirectory($dir, $include_tests) {
        $files = array();

        // In order to scan top-level directories, absolute directory paths have to
        // be used (which also improves performance, since any configured PHP
        // include_paths will not be consulted). Retain the relative originating
        // directory being scanned, so relative paths can be reconstructed below
        // (all paths are expected to be relative to $this->root).
        $dir_prefix = ($dir == '' ? '' : "$dir/");
        $absolute_dir = ($dir == '' ? $this->root : $this->root . "/$dir");

        if (!is_dir($absolute_dir)) {
            return $files;
        }
        // Use Unix paths regardless of platform, skip dot directories, follow
        // symlinks (to allow extensions to be linked from elsewhere), and return
        // the RecursiveDirectoryIterator instance to have access to getSubPath(),
        // since SplFileInfo does not support relative paths.
        $flags = \FilesystemIterator::UNIX_PATHS;
        $flags |= \FilesystemIterator::SKIP_DOTS;
        $flags |= \FilesystemIterator::FOLLOW_SYMLINKS;
        $flags |= \FilesystemIterator::CURRENT_AS_SELF;
        $directory_iterator = new \RecursiveDirectoryIterator($absolute_dir, $flags);

        // Filter the recursive scan to discover extensions only.
        // Important: Without a RecursiveFilterIterator, RecursiveDirectoryIterator
        // would recurse into the entire filesystem directory tree without any kind
        // of limitations.
        $filter = new RecursiveExtensionFilterIterator($directory_iterator);
        $filter->acceptTests($include_tests);

        // The actual recursive filesystem scan is only invoked by instantiating the
        // RecursiveIteratorIterator.
        $iterator = new \RecursiveIteratorIterator($filter,
            \RecursiveIteratorIterator::LEAVES_ONLY,
            // Suppress filesystem errors in case a directory cannot be accessed.
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ($iterator as $key => $fileinfo) {
            $name = $fileinfo->getBasename('.info.yml');

            if ($this->fileCache && $cached_extension = $this->fileCache->get($fileinfo->getPathName())) {
                $files[$cached_extension->getType()][$key] = $cached_extension;
                continue;
            }

            // Determine extension type from info file.
            $type = FALSE;
            $file = $fileinfo->openFile('r');
            while (!$type && !$file->eof()) {
                preg_match('@^type:\s*(\'|")?(\w+)\1?\s*$@', $file->fgets(), $matches);
                if (isset($matches[2])) {
                    $type = $matches[2];
                }
            }
            if (empty($type)) {
                continue;
            }
            $name = $fileinfo->getBasename('.info.yml');
            $pathname = $dir_prefix . $fileinfo->getSubPathname();

            $filename = $name . '.' . $type;

            if (!file_exists(dirname($pathname) . '/' . $filename)) {
                $filename = NULL;
            }

            $extension = new Extension($this->root, $type, $pathname, $filename);

            // Track the originating directory for sorting purposes.
            $extension->subpath = $fileinfo->getSubPath();
            $extension->origin = $dir;

            $files[$type][$key] = $extension;

            if ($this->fileCache) {
                $this->fileCache->set($fileinfo->getPathName(), $extension);
            }
        }
        return $files;
    }
}
