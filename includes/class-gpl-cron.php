<?php
/**
 * Cron / Scheduled Tasks Class
 *
 * Handles scheduled tasks for Git Plugin Loader.
 *
 * @package Git_Plugin_Loader
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GPL_Cron class
 */
class GPL_Cron {

    /**
     * Hook name for update checking
     *
     * @var string
     */
    const HOOK_CHECK_UPDATES = 'gpl_check_updates';

    /**
     * Hook name for auto-sync
     *
     * @var string
     */
    const HOOK_AUTO_SYNC = 'gpl_auto_sync';

    /**
     * Hook name for export cleanup
     *
     * @var string
     */
    const HOOK_CLEANUP_EXPORTS = 'gpl_cleanup_exports';

    /**
     * Plugin manager instance
     *
     * @var GPL_Plugin_Manager
     */
    private $plugin_manager;

    /**
     * Constructor
     *
     * @param GPL_Plugin_Manager $plugin_manager Plugin manager instance.
     */
    public function __construct( GPL_Plugin_Manager $plugin_manager ) {
        $this->plugin_manager = $plugin_manager;

        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Register cron actions
        add_action( self::HOOK_CHECK_UPDATES, array( $this, 'check_updates' ) );
        add_action( self::HOOK_AUTO_SYNC, array( $this, 'auto_sync' ) );
        add_action( self::HOOK_CLEANUP_EXPORTS, array( $this, 'cleanup_exports' ) );

        // Handle schedule changes
        add_action( 'update_option_' . GPL_OPTION_SETTINGS, array( $this, 'on_settings_update' ), 10, 2 );
    }

    /**
     * Schedule all cron events
     */
    public static function schedule_events() {
        $settings = Git_Plugin_Loader::get_settings();
        $interval = isset( $settings['auto_sync_interval'] ) ? $settings['auto_sync_interval'] : 'hourly';

        // Schedule update checking
        if ( ! wp_next_scheduled( self::HOOK_CHECK_UPDATES ) ) {
            wp_schedule_event( time(), $interval, self::HOOK_CHECK_UPDATES );
        }

        // Schedule auto-sync
        if ( ! wp_next_scheduled( self::HOOK_AUTO_SYNC ) ) {
            wp_schedule_event( time(), $interval, self::HOOK_AUTO_SYNC );
        }

        // Schedule export cleanup (daily)
        if ( ! wp_next_scheduled( self::HOOK_CLEANUP_EXPORTS ) ) {
            wp_schedule_event( time(), 'daily', self::HOOK_CLEANUP_EXPORTS );
        }
    }

    /**
     * Unschedule all cron events
     */
    public static function unschedule_events() {
        $hooks = array(
            self::HOOK_CHECK_UPDATES,
            self::HOOK_AUTO_SYNC,
            self::HOOK_CLEANUP_EXPORTS,
        );

        foreach ( $hooks as $hook ) {
            $timestamp = wp_next_scheduled( $hook );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, $hook );
            }
        }
    }

    /**
     * Handle settings update
     *
     * @param mixed $old_value Old settings value.
     * @param mixed $new_value New settings value.
     */
    public function on_settings_update( $old_value, $new_value ) {
        $old_interval = isset( $old_value['auto_sync_interval'] ) ? $old_value['auto_sync_interval'] : 'hourly';
        $new_interval = isset( $new_value['auto_sync_interval'] ) ? $new_value['auto_sync_interval'] : 'hourly';

        // Reschedule if interval changed
        if ( $old_interval !== $new_interval ) {
            $this->reschedule_events( $new_interval );
        }
    }

    /**
     * Reschedule events with new interval
     *
     * @param string $interval New interval.
     */
    private function reschedule_events( $interval ) {
        // Unschedule existing events
        $check_timestamp = wp_next_scheduled( self::HOOK_CHECK_UPDATES );
        if ( $check_timestamp ) {
            wp_unschedule_event( $check_timestamp, self::HOOK_CHECK_UPDATES );
        }

        $sync_timestamp = wp_next_scheduled( self::HOOK_AUTO_SYNC );
        if ( $sync_timestamp ) {
            wp_unschedule_event( $sync_timestamp, self::HOOK_AUTO_SYNC );
        }

        // Reschedule with new interval
        wp_schedule_event( time(), $interval, self::HOOK_CHECK_UPDATES );
        wp_schedule_event( time(), $interval, self::HOOK_AUTO_SYNC );
    }

    /**
     * Check for updates on all managed plugins
     */
    public function check_updates() {
        // Verify system requirements
        if ( ! Git_Plugin_Loader::is_git_installed() || ! Git_Plugin_Loader::is_exec_available() ) {
            return;
        }

        $this->plugin_manager->check_all_updates();
    }

    /**
     * Auto-sync enabled plugins
     */
    public function auto_sync() {
        // Verify system requirements
        if ( ! Git_Plugin_Loader::is_git_installed() || ! Git_Plugin_Loader::is_exec_available() ) {
            return;
        }

        $this->plugin_manager->sync_auto_enabled();
    }

    /**
     * Cleanup old export files
     */
    public function cleanup_exports() {
        $settings    = Git_Plugin_Loader::get_settings();
        $max_age     = isset( $settings['cleanup_exports_after'] ) ? (int) $settings['cleanup_exports_after'] : 24;
        $export_dir  = GPL_Export::get_export_dir();

        if ( ! is_dir( $export_dir ) ) {
            return;
        }

        $cutoff_time = time() - ( $max_age * HOUR_IN_SECONDS );
        $files       = glob( $export_dir . '/*.zip' );

        if ( ! $files ) {
            return;
        }

        foreach ( $files as $file ) {
            if ( filemtime( $file ) < $cutoff_time ) {
                @unlink( $file );
            }
        }
    }

    /**
     * Get next scheduled time for a hook
     *
     * @param string $hook Hook name.
     * @return int|false Timestamp or false if not scheduled.
     */
    public static function get_next_scheduled( $hook ) {
        return wp_next_scheduled( $hook );
    }

    /**
     * Get cron schedule info
     *
     * @return array
     */
    public static function get_schedule_info() {
        $settings = Git_Plugin_Loader::get_settings();
        $interval = isset( $settings['auto_sync_interval'] ) ? $settings['auto_sync_interval'] : 'hourly';

        $schedules = wp_get_schedules();
        $interval_display = isset( $schedules[ $interval ] ) ? $schedules[ $interval ]['display'] : $interval;

        return array(
            'check_updates' => array(
                'next_run' => self::get_next_scheduled( self::HOOK_CHECK_UPDATES ),
                'interval' => $interval_display,
            ),
            'auto_sync' => array(
                'next_run' => self::get_next_scheduled( self::HOOK_AUTO_SYNC ),
                'interval' => $interval_display,
            ),
            'cleanup_exports' => array(
                'next_run' => self::get_next_scheduled( self::HOOK_CLEANUP_EXPORTS ),
                'interval' => __( 'Daily', 'git-plugin-loader' ),
            ),
        );
    }
}
