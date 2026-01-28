<?php
/**
 * Export Functionality Class
 *
 * Handles exporting plugins as clean ZIP files.
 *
 * @package Git_Plugin_Loader
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GPL_Export class
 */
class GPL_Export {

    /**
     * Export directory name
     *
     * @var string
     */
    const EXPORT_DIR_NAME = 'gpl-exports';

    /**
     * Constructor
     */
    public function __construct() {
        // Nothing to initialize
    }

    /**
     * Get export directory path
     *
     * @return string
     */
    public static function get_export_dir() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/' . self::EXPORT_DIR_NAME;
    }

    /**
     * Get export directory URL
     *
     * @return string
     */
    public static function get_export_url() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['baseurl'] . '/' . self::EXPORT_DIR_NAME;
    }

    /**
     * Ensure export directory exists
     *
     * @return bool
     */
    private function ensure_export_dir() {
        $export_dir = self::get_export_dir();

        if ( ! file_exists( $export_dir ) ) {
            if ( ! wp_mkdir_p( $export_dir ) ) {
                return false;
            }

            // Add index.php to prevent directory listing
            file_put_contents( $export_dir . '/index.php', '<?php // Silence is golden.' );

            // Add .htaccess for extra security
            file_put_contents( $export_dir . '/.htaccess', 'Options -Indexes' );
        }

        return is_writable( $export_dir );
    }

    /**
     * Export a plugin as ZIP
     *
     * @param string $slug Plugin slug.
     * @return array|WP_Error Array with 'file' and 'url' keys, or WP_Error on failure.
     */
    public function export_plugin( $slug ) {
        $plugins = Git_Plugin_Loader::get_managed_plugins();

        if ( ! isset( $plugins[ $slug ] ) ) {
            return new WP_Error( 'not_found', __( 'Plugin not found.', 'git-plugin-loader' ) );
        }

        $plugin_path = WP_PLUGIN_DIR . '/' . $slug;

        if ( ! file_exists( $plugin_path ) ) {
            return new WP_Error( 'directory_not_found', __( 'Plugin directory not found.', 'git-plugin-loader' ) );
        }

        // Ensure export directory exists
        if ( ! $this->ensure_export_dir() ) {
            return new WP_Error( 'export_dir_error', __( 'Could not create export directory.', 'git-plugin-loader' ) );
        }

        // Check if ZipArchive is available
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'zip_unavailable', __( 'ZipArchive class is not available.', 'git-plugin-loader' ) );
        }

        // Generate filename with timestamp
        $plugin_data = $plugins[ $slug ];
        $version     = $plugin_data['wp_plugin_version'] ? $plugin_data['wp_plugin_version'] : substr( $plugin_data['local_commit'], 0, 7 );
        $filename    = sprintf( '%s-%s-%s.zip', $slug, $version, date( 'Ymd-His' ) );
        $export_path = self::get_export_dir() . '/' . $filename;

        // Create temp directory for clean copy
        $temp_dir = self::get_export_dir() . '/temp-' . uniqid();
        if ( ! wp_mkdir_p( $temp_dir . '/' . $slug ) ) {
            return new WP_Error( 'temp_dir_error', __( 'Could not create temporary directory.', 'git-plugin-loader' ) );
        }

        // Get exclusion patterns
        $settings   = Git_Plugin_Loader::get_settings();
        $exclusions = isset( $settings['export_exclusions'] ) ? $settings['export_exclusions'] : array();

        // Copy files to temp directory, excluding specified files
        $copy_result = $this->copy_directory( $plugin_path, $temp_dir . '/' . $slug, $exclusions );
        if ( ! $copy_result ) {
            $this->delete_directory( $temp_dir );
            return new WP_Error( 'copy_failed', __( 'Failed to copy plugin files.', 'git-plugin-loader' ) );
        }

        // Create ZIP archive
        $zip = new ZipArchive();
        if ( $zip->open( $export_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            $this->delete_directory( $temp_dir );
            return new WP_Error( 'zip_create_failed', __( 'Failed to create ZIP archive.', 'git-plugin-loader' ) );
        }

        // Add files to ZIP
        $this->add_directory_to_zip( $zip, $temp_dir, '' );
        $zip->close();

        // Clean up temp directory
        $this->delete_directory( $temp_dir );

        // Verify ZIP was created
        if ( ! file_exists( $export_path ) ) {
            return new WP_Error( 'zip_not_created', __( 'ZIP file was not created.', 'git-plugin-loader' ) );
        }

        return array(
            'file'     => $export_path,
            'filename' => $filename,
            'url'      => self::get_export_url() . '/' . $filename,
            'size'     => filesize( $export_path ),
        );
    }

    /**
     * Copy a directory, excluding specified patterns
     *
     * @param string $source      Source directory.
     * @param string $destination Destination directory.
     * @param array  $exclusions  Patterns to exclude.
     * @return bool
     */
    private function copy_directory( $source, $destination, $exclusions = array() ) {
        if ( ! is_dir( $source ) ) {
            return false;
        }

        if ( ! is_dir( $destination ) ) {
            if ( ! wp_mkdir_p( $destination ) ) {
                return false;
            }
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $source, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            $relative_path = str_replace( $source . '/', '', $item->getPathname() );

            // Check if file/directory should be excluded
            if ( $this->should_exclude( $relative_path, $exclusions ) ) {
                continue;
            }

            $dest_path = $destination . '/' . $relative_path;

            if ( $item->isDir() ) {
                if ( ! is_dir( $dest_path ) ) {
                    wp_mkdir_p( $dest_path );
                }
            } else {
                $dest_dir = dirname( $dest_path );
                if ( ! is_dir( $dest_dir ) ) {
                    wp_mkdir_p( $dest_dir );
                }
                copy( $item->getPathname(), $dest_path );
            }
        }

        return true;
    }

    /**
     * Check if a path should be excluded
     *
     * @param string $path       Path to check.
     * @param array  $exclusions Exclusion patterns.
     * @return bool
     */
    private function should_exclude( $path, $exclusions ) {
        // Default exclusions always applied
        $default_exclusions = array( '.git' );
        $exclusions         = array_merge( $default_exclusions, $exclusions );

        foreach ( $exclusions as $pattern ) {
            $pattern = trim( $pattern );
            if ( empty( $pattern ) ) {
                continue;
            }

            // Check for exact match
            if ( $path === $pattern || basename( $path ) === $pattern ) {
                return true;
            }

            // Check if path starts with pattern (directory match)
            if ( strpos( $path, $pattern . '/' ) === 0 || strpos( $path, $pattern ) === 0 ) {
                return true;
            }

            // Check for wildcard patterns
            if ( strpos( $pattern, '*' ) !== false ) {
                $regex = '/^' . str_replace(
                    array( '\*\*', '\*' ),
                    array( '.*', '[^\/]*' ),
                    preg_quote( $pattern, '/' )
                ) . '$/';

                if ( preg_match( $regex, $path ) || preg_match( $regex, basename( $path ) ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Add a directory to a ZIP archive
     *
     * @param ZipArchive $zip     ZIP archive instance.
     * @param string     $dir     Directory to add.
     * @param string     $prefix  Prefix for files in archive.
     */
    private function add_directory_to_zip( ZipArchive $zip, $dir, $prefix = '' ) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            $relative_path = str_replace( $dir . '/', '', $item->getPathname() );
            $archive_path  = $prefix ? $prefix . '/' . $relative_path : $relative_path;

            if ( $item->isDir() ) {
                $zip->addEmptyDir( $archive_path );
            } else {
                $zip->addFile( $item->getPathname(), $archive_path );
            }
        }
    }

    /**
     * Delete a directory recursively
     *
     * @param string $dir Directory path.
     * @return bool
     */
    private function delete_directory( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return false;
        }

        // Security check: ensure we're within uploads directory
        $upload_dir = wp_upload_dir();
        $base_dir   = realpath( $upload_dir['basedir'] );
        $dir        = realpath( $dir );

        if ( ! $dir || strpos( $dir, $base_dir ) !== 0 ) {
            return false;
        }

        $files = array_diff( scandir( $dir ), array( '.', '..' ) );

        foreach ( $files as $file ) {
            $path = $dir . '/' . $file;
            if ( is_dir( $path ) ) {
                $this->delete_directory( $path );
            } else {
                unlink( $path );
            }
        }

        return rmdir( $dir );
    }

    /**
     * Get list of exported files
     *
     * @return array
     */
    public function get_exports() {
        $export_dir = self::get_export_dir();
        $exports    = array();

        if ( ! is_dir( $export_dir ) ) {
            return $exports;
        }

        $files = glob( $export_dir . '/*.zip' );

        if ( ! $files ) {
            return $exports;
        }

        foreach ( $files as $file ) {
            $exports[] = array(
                'filename' => basename( $file ),
                'path'     => $file,
                'url'      => self::get_export_url() . '/' . basename( $file ),
                'size'     => filesize( $file ),
                'created'  => filemtime( $file ),
            );
        }

        // Sort by creation time (newest first)
        usort( $exports, function( $a, $b ) {
            return $b['created'] - $a['created'];
        } );

        return $exports;
    }

    /**
     * Delete an export file
     *
     * @param string $filename Export filename.
     * @return bool
     */
    public function delete_export( $filename ) {
        $export_dir = self::get_export_dir();
        $file_path  = $export_dir . '/' . basename( $filename );

        // Security check
        $real_path = realpath( $file_path );
        $real_dir  = realpath( $export_dir );

        if ( ! $real_path || strpos( $real_path, $real_dir ) !== 0 ) {
            return false;
        }

        if ( file_exists( $real_path ) && pathinfo( $real_path, PATHINFO_EXTENSION ) === 'zip' ) {
            return unlink( $real_path );
        }

        return false;
    }
}
