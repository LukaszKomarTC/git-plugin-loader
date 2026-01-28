<?php
/**
 * AJAX Handler Class
 *
 * Handles all AJAX requests for Git Plugin Loader.
 *
 * @package Git_Plugin_Loader
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GPL_Ajax class
 */
class GPL_Ajax {

    /**
     * Plugin manager instance
     *
     * @var GPL_Plugin_Manager
     */
    private $plugin_manager;

    /**
     * GitHub API instance
     *
     * @var GPL_GitHub_API
     */
    private $github_api;

    /**
     * Export handler instance
     *
     * @var GPL_Export
     */
    private $export;

    /**
     * Constructor
     *
     * @param GPL_Plugin_Manager $plugin_manager Plugin manager instance.
     * @param GPL_GitHub_API     $github_api     GitHub API instance.
     * @param GPL_Export         $export         Export handler instance.
     */
    public function __construct( GPL_Plugin_Manager $plugin_manager, GPL_GitHub_API $github_api, GPL_Export $export ) {
        $this->plugin_manager = $plugin_manager;
        $this->github_api     = $github_api;
        $this->export         = $export;

        $this->register_ajax_handlers();
    }

    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers() {
        $actions = array(
            'gpl_validate_repo',
            'gpl_add_plugin',
            'gpl_sync_plugin',
            'gpl_check_updates',
            'gpl_remove_plugin',
            'gpl_export_plugin',
            'gpl_save_settings',
            'gpl_toggle_autosync',
            'gpl_get_refs',
            'gpl_change_branch',
        );

        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, array( $this, str_replace( 'gpl_', 'ajax_', $action ) ) );
        }
    }

    /**
     * Verify AJAX request
     *
     * @return bool
     */
    private function verify_request() {
        // Check nonce
        if ( ! check_ajax_referer( 'gpl_ajax_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'git-plugin-loader' ) ) );
            return false;
        }

        // Check capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'git-plugin-loader' ) ) );
            return false;
        }

        return true;
    }

    /**
     * AJAX: Validate repository
     */
    public function ajax_validate_repo() {
        if ( ! $this->verify_request() ) {
            return;
        }

        $url = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';

        if ( empty( $url ) ) {
            wp_send_json_error( array( 'message' => __( 'Repository URL is required.', 'git-plugin-loader' ) ) );
            return;
        }

        // Parse repository URL
        $git = new GPL_Git();
        $repo_info = $git->parse_repo_url( $url );

        if ( ! $repo_info ) {
            wp_send_json_error( array( 'message' => __( 'Invalid GitHub repository URL.', 'git-plugin-loader' ) ) );
            return;
        }

        // Verify repository exists
        if ( ! $this->github_api->verify_repo( $repo_info['owner'], $repo_info['repo'] ) ) {
            $error = $this->github_api->get_last_error();
            if ( empty( $error ) ) {
                $error = __( 'Repository not found or not accessible.', 'git-plugin-loader' );
            }
            wp_send_json_error( array( 'message' => $error ) );
            return;
        }

        // Get repository info
        $repo_data = $this->github_api->get_repo( $repo_info['owner'], $repo_info['repo'] );

        wp_send_json_success( array(
            'owner'      => $repo_info['owner'],
            'repo'       => $repo_info['repo'],
            'is_private' => ! empty( $repo_data['private'] ),
            'default_branch' => isset( $repo_data['default_branch'] ) ? $repo_data['default_branch'] : 'main',
        ) );
    }

    /**
     * AJAX: Add plugin
     */
    public function ajax_add_plugin() {
        if ( ! $this->verify_request() ) {
            return;
        }

        $url    = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
        $branch = isset( $_POST['branch'] ) ? sanitize_text_field( wp_unslash( $_POST['branch'] ) ) : 'main';
        $slug   = isset( $_POST['slug'] ) ? sanitize_file_name( wp_unslash( $_POST['slug'] ) ) : null;

        if ( empty( $url ) ) {
            wp_send_json_error( array( 'message' => __( 'Repository URL is required.', 'git-plugin-loader' ) ) );
            return;
        }

        $result = $this->plugin_manager->add_plugin( $url, $branch, $slug );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            return;
        }

        wp_send_json_success( array(
            'message' => __( 'Plugin added successfully.', 'git-plugin-loader' ),
            'plugin'  => $result,
        ) );
    }

    /**
     * AJAX: Sync plugin
     */
    public function ajax_sync_plugin() {
        if ( ! $this->verify_request() ) {
            return;
        }

        $slug = isset( $_POST['slug'] ) ? sanitize_file_name( wp_unslash( $_POST['slug'] ) ) : '';

        if ( empty( $slug ) ) {
            wp_send_json_error( array( 'message' => __( 'Plugin slug is required.', 'git-plugin-loader' ) ) );
            return;
        }

        $result = $this->plugin_manager->sync_plugin( $slug );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            return;
        }

        wp_send_json_success( array(
            'message' => __( 'Plugin synced successfully.', 'git-plugin-loader' ),
            'plugin'  => $result,
        ) );
    }

    /**
     * AJAX: Check for updates
     */
    public function ajax_check_updates() {
        if ( ! $this->verify_request() ) {
            return;
        }

        $slug = isset( $_POST['slug'] ) ? sanitize_file_name( wp_unslash( $_POST['slug'] ) ) : '';

        if ( empty( $slug ) ) {
            wp_send_json_error( array( 'message' => __( 'Plugin slug is required.', 'git-plugin-loader' ) ) );
            return;
        }

        $result = $this->plugin_manager->check_updates( $slug );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            return;
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Remove plugin
     */
    public function ajax_remove_plugin() {
        if ( ! $this->verify_request() ) {
            return;
        }

        $slug         = isset( $_POST['slug'] ) ? sanitize_file_name( wp_unslash( $_POST['slug'] ) ) : '';
        $delete_files = isset( $_POST['delete_files'] ) && $_POST['delete_files'] === 'true';

        if ( empty( $slug ) ) {
            wp_send_json_error( array( 'message' => __( 'Plugin slug is required.', 'git-plugin-loader' ) ) );
            return;
        }

        $result = $this->plugin_manager->remove_plugin( $slug, $delete_files );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            return;
        }

        wp_send_json_success( array(
            'message' => __( 'Plugin removed successfully.', 'git-plugin-loader' ),
        ) );
    }

    /**
     * AJAX: Export plugin
     */
    public function ajax_export_plugin() {
        if ( ! $this->verify_request() ) {
            return;
        }

        $slug = isset( $_POST['slug'] ) ? sanitize_file_name( wp_unslash( $_POST['slug'] ) ) : '';

        if ( empty( $slug ) ) {
            wp_send_json_error( array( 'message' => __( 'Plugin slug is required.', 'git-plugin-loader' ) ) );
            return;
        }

        $result = $this->export->export_plugin( $slug );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            return;
        }

        wp_send_json_success( array(
            'message'  => __( 'Plugin exported successfully.', 'git-plugin-loader' ),
            'filename' => $result['filename'],
            'url'      => $result['url'],
            'size'     => size_format( $result['size'] ),
        ) );
    }

    /**
     * AJAX: Save settings
     */
    public function ajax_save_settings() {
        if ( ! $this->verify_request() ) {
            return;
        }

        $settings = Git_Plugin_Loader::get_settings();

        // GitHub token
        if ( isset( $_POST['clear_token'] ) && $_POST['clear_token'] === '1' ) {
            $settings['github_token'] = '';
        } elseif ( isset( $_POST['github_token'] ) && ! empty( $_POST['github_token'] ) && $_POST['github_token'] !== '********' ) {
            $token = sanitize_text_field( wp_unslash( $_POST['github_token'] ) );
            // Verify token is valid
            if ( ! $this->github_api->verify_token( $token ) ) {
                wp_send_json_error( array( 'message' => __( 'Invalid GitHub token.', 'git-plugin-loader' ) ) );
                return;
            }
            $settings['github_token'] = $this->github_api->encrypt_token( $token );
        }

        // Auto-sync interval
        if ( isset( $_POST['auto_sync_interval'] ) ) {
            $interval = sanitize_text_field( wp_unslash( $_POST['auto_sync_interval'] ) );
            if ( in_array( $interval, array( 'hourly', 'twicedaily', 'daily' ), true ) ) {
                $settings['auto_sync_interval'] = $interval;
            }
        }

        // Export exclusions
        if ( isset( $_POST['export_exclusions'] ) ) {
            $exclusions = sanitize_textarea_field( wp_unslash( $_POST['export_exclusions'] ) );
            $settings['export_exclusions'] = array_filter( array_map( 'trim', explode( "\n", $exclusions ) ) );
        }

        // Cleanup hours
        if ( isset( $_POST['cleanup_exports_after'] ) ) {
            $hours = (int) $_POST['cleanup_exports_after'];
            if ( $hours >= 1 && $hours <= 168 ) {
                $settings['cleanup_exports_after'] = $hours;
            }
        }

        Git_Plugin_Loader::update_settings( $settings );

        wp_send_json_success( array(
            'message' => __( 'Settings saved successfully.', 'git-plugin-loader' ),
        ) );
    }

    /**
     * AJAX: Toggle auto-sync
     */
    public function ajax_toggle_autosync() {
        if ( ! $this->verify_request() ) {
            return;
        }

        $slug    = isset( $_POST['slug'] ) ? sanitize_file_name( wp_unslash( $_POST['slug'] ) ) : '';
        $enabled = isset( $_POST['enabled'] ) && $_POST['enabled'] === 'true';

        if ( empty( $slug ) ) {
            wp_send_json_error( array( 'message' => __( 'Plugin slug is required.', 'git-plugin-loader' ) ) );
            return;
        }

        $result = $this->plugin_manager->toggle_auto_sync( $slug, $enabled );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            return;
        }

        wp_send_json_success( array(
            'message' => $enabled
                ? __( 'Auto-sync enabled.', 'git-plugin-loader' )
                : __( 'Auto-sync disabled.', 'git-plugin-loader' ),
            'enabled' => $enabled,
        ) );
    }

    /**
     * AJAX: Get branches and tags
     */
    public function ajax_get_refs() {
        if ( ! $this->verify_request() ) {
            return;
        }

        // Can be slug (for managed plugin) or URL (for new plugin)
        $slug = isset( $_POST['slug'] ) ? sanitize_file_name( wp_unslash( $_POST['slug'] ) ) : '';
        $url  = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';

        if ( ! empty( $slug ) ) {
            // Get refs for managed plugin
            $result = $this->plugin_manager->get_refs( $slug );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
                return;
            }

            wp_send_json_success( $result );
        } elseif ( ! empty( $url ) ) {
            // Get refs for URL (new plugin)
            $git       = new GPL_Git();
            $repo_info = $git->parse_repo_url( $url );

            if ( ! $repo_info ) {
                wp_send_json_error( array( 'message' => __( 'Invalid GitHub repository URL.', 'git-plugin-loader' ) ) );
                return;
            }

            $branches = $this->github_api->get_branches( $repo_info['owner'], $repo_info['repo'] );
            $tags     = $this->github_api->get_tags( $repo_info['owner'], $repo_info['repo'] );

            if ( false === $branches ) {
                wp_send_json_error( array( 'message' => $this->github_api->get_last_error() ) );
                return;
            }

            wp_send_json_success( array(
                'branches' => $branches ? $branches : array(),
                'tags'     => $tags ? $tags : array(),
            ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Plugin slug or URL is required.', 'git-plugin-loader' ) ) );
        }
    }

    /**
     * AJAX: Change branch
     */
    public function ajax_change_branch() {
        if ( ! $this->verify_request() ) {
            return;
        }

        $slug   = isset( $_POST['slug'] ) ? sanitize_file_name( wp_unslash( $_POST['slug'] ) ) : '';
        $branch = isset( $_POST['branch'] ) ? sanitize_text_field( wp_unslash( $_POST['branch'] ) ) : '';

        if ( empty( $slug ) ) {
            wp_send_json_error( array( 'message' => __( 'Plugin slug is required.', 'git-plugin-loader' ) ) );
            return;
        }

        if ( empty( $branch ) ) {
            wp_send_json_error( array( 'message' => __( 'Branch is required.', 'git-plugin-loader' ) ) );
            return;
        }

        $result = $this->plugin_manager->change_branch( $slug, $branch );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            return;
        }

        wp_send_json_success( array(
            'message' => __( 'Branch changed successfully.', 'git-plugin-loader' ),
            'plugin'  => $result,
        ) );
    }
}
