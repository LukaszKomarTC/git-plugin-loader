<?php
/**
 * Plugin Name: Git Plugin Loader
 * Plugin URI: https://github.com/LukaszKomarTC/git-plugin-loader
 * Description: Load WordPress plugins directly from GitHub repositories with automatic syncing and version tracking.
 * Version: 1.0.0
 * Author: LukaszKomarTC
 * Author URI: https://github.com/LukaszKomarTC
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: git-plugin-loader
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'GPL_VERSION', '1.0.0' );
define( 'GPL_PLUGIN_FILE', __FILE__ );
define( 'GPL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GPL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GPL_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'GPL_INCLUDES_DIR', GPL_PLUGIN_DIR . 'includes/' );
define( 'GPL_ASSETS_URL', GPL_PLUGIN_URL . 'assets/' );

// Database option keys
define( 'GPL_OPTION_PLUGINS', 'gpl_managed_plugins' );
define( 'GPL_OPTION_SETTINGS', 'gpl_settings' );

// GitHub API constants
define( 'GPL_GITHUB_API_URL', 'https://api.github.com' );
define( 'GPL_GITHUB_RAW_URL', 'https://raw.githubusercontent.com' );

// Include required files
require_once GPL_INCLUDES_DIR . 'class-gpl-git.php';
require_once GPL_INCLUDES_DIR . 'class-gpl-github-api.php';
require_once GPL_INCLUDES_DIR . 'class-gpl-plugin-manager.php';
require_once GPL_INCLUDES_DIR . 'class-gpl-admin.php';
require_once GPL_INCLUDES_DIR . 'class-gpl-cron.php';
require_once GPL_INCLUDES_DIR . 'class-gpl-export.php';
require_once GPL_INCLUDES_DIR . 'class-gpl-ajax.php';

/**
 * Main plugin class
 */
class Git_Plugin_Loader {

    /**
     * Single instance of the class
     *
     * @var Git_Plugin_Loader
     */
    private static $instance = null;

    /**
     * Git operations handler
     *
     * @var GPL_Git
     */
    public $git;

    /**
     * GitHub API handler
     *
     * @var GPL_GitHub_API
     */
    public $github_api;

    /**
     * Plugin manager
     *
     * @var GPL_Plugin_Manager
     */
    public $plugin_manager;

    /**
     * Admin handler
     *
     * @var GPL_Admin
     */
    public $admin;

    /**
     * Cron handler
     *
     * @var GPL_Cron
     */
    public $cron;

    /**
     * Export handler
     *
     * @var GPL_Export
     */
    public $export;

    /**
     * AJAX handler
     *
     * @var GPL_Ajax
     */
    public $ajax;

    /**
     * Get single instance of the class
     *
     * @return Git_Plugin_Loader
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
        $this->init_classes();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        register_activation_hook( GPL_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( GPL_PLUGIN_FILE, array( $this, 'deactivate' ) );

        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
    }

    /**
     * Initialize plugin classes
     */
    private function init_classes() {
        $this->git            = new GPL_Git();
        $this->github_api     = new GPL_GitHub_API();
        $this->plugin_manager = new GPL_Plugin_Manager( $this->git, $this->github_api );
        $this->admin          = new GPL_Admin( $this->plugin_manager );
        $this->cron           = new GPL_Cron( $this->plugin_manager );
        $this->export         = new GPL_Export();
        $this->ajax           = new GPL_Ajax( $this->plugin_manager, $this->github_api, $this->export );
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Initialize default options if they don't exist
        if ( false === get_option( GPL_OPTION_PLUGINS ) ) {
            add_option( GPL_OPTION_PLUGINS, array() );
        }

        if ( false === get_option( GPL_OPTION_SETTINGS ) ) {
            $default_settings = array(
                'github_token'       => '',
                'auto_sync_interval' => 'hourly',
                'export_exclusions'  => array(
                    '.git',
                    '.gitignore',
                    '.gitattributes',
                    'node_modules',
                    'tests',
                    'test',
                    '.github',
                    '*.md',
                    'README.md',
                    'CHANGELOG.md',
                    'phpunit.xml',
                    'phpunit.xml.dist',
                    '.travis.yml',
                    '.editorconfig',
                    'composer.json',
                    'composer.lock',
                    'package.json',
                    'package-lock.json',
                    'Gruntfile.js',
                    'Gulpfile.js',
                    'webpack.config.js',
                ),
                'cleanup_exports_after' => 24, // hours
            );
            add_option( GPL_OPTION_SETTINGS, $default_settings );
        }

        // Schedule cron events
        GPL_Cron::schedule_events();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Unschedule cron events
        GPL_Cron::unschedule_events();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'git-plugin-loader',
            false,
            dirname( GPL_PLUGIN_BASENAME ) . '/languages/'
        );
    }

    /**
     * Get plugin settings
     *
     * @param string $key Optional specific setting key.
     * @return mixed
     */
    public static function get_settings( $key = null ) {
        $settings = get_option( GPL_OPTION_SETTINGS, array() );

        if ( null !== $key ) {
            return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
        }

        return $settings;
    }

    /**
     * Update plugin settings
     *
     * @param array $new_settings Settings to update.
     * @return bool
     */
    public static function update_settings( $new_settings ) {
        $current_settings = self::get_settings();
        $updated_settings = array_merge( $current_settings, $new_settings );
        return update_option( GPL_OPTION_SETTINGS, $updated_settings );
    }

    /**
     * Get managed plugins
     *
     * @return array
     */
    public static function get_managed_plugins() {
        return get_option( GPL_OPTION_PLUGINS, array() );
    }

    /**
     * Update managed plugins
     *
     * @param array $plugins Plugins data.
     * @return bool
     */
    public static function update_managed_plugins( $plugins ) {
        return update_option( GPL_OPTION_PLUGINS, $plugins );
    }

    /**
     * Check if exec() function is available
     *
     * @return bool
     */
    public static function is_exec_available() {
        if ( ! function_exists( 'exec' ) ) {
            return false;
        }

        $disabled_functions = ini_get( 'disable_functions' );
        if ( ! empty( $disabled_functions ) ) {
            $disabled_array = array_map( 'trim', explode( ',', $disabled_functions ) );
            if ( in_array( 'exec', $disabled_array, true ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if Git is installed
     *
     * @return bool
     */
    public static function is_git_installed() {
        if ( ! self::is_exec_available() ) {
            return false;
        }

        exec( 'git --version 2>&1', $output, $return_var );
        return 0 === $return_var;
    }
}

/**
 * Returns the main instance of Git_Plugin_Loader
 *
 * @return Git_Plugin_Loader
 */
function GPL() {
    return Git_Plugin_Loader::get_instance();
}

// Initialize the plugin
GPL();
