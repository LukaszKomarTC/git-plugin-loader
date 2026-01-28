<?php
/**
 * Plugin Manager Class
 *
 * Handles management of Git-loaded plugins.
 *
 * @package Git_Plugin_Loader
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GPL_Plugin_Manager class
 */
class GPL_Plugin_Manager {

    /**
     * Git operations handler
     *
     * @var GPL_Git
     */
    private $git;

    /**
     * GitHub API handler
     *
     * @var GPL_GitHub_API
     */
    private $github_api;

    /**
     * Constructor
     *
     * @param GPL_Git        $git        Git operations handler.
     * @param GPL_GitHub_API $github_api GitHub API handler.
     */
    public function __construct( GPL_Git $git, GPL_GitHub_API $github_api ) {
        $this->git        = $git;
        $this->github_api = $github_api;
    }

    /**
     * Add a new plugin from GitHub
     *
     * @param string $url    GitHub repository URL.
     * @param string $branch Branch or tag to checkout.
     * @param string $slug   Optional plugin slug.
     * @return array|WP_Error
     */
    public function add_plugin( $url, $branch = 'main', $slug = null ) {
        // Parse repository URL
        $repo_info = $this->git->parse_repo_url( $url );
        if ( ! $repo_info ) {
            return new WP_Error( 'invalid_url', __( 'Invalid GitHub repository URL.', 'git-plugin-loader' ) );
        }

        // Verify repository exists
        if ( ! $this->github_api->verify_repo( $repo_info['owner'], $repo_info['repo'] ) ) {
            $error = $this->github_api->get_last_error();
            if ( empty( $error ) ) {
                $error = __( 'Repository not found or not accessible.', 'git-plugin-loader' );
            }
            return new WP_Error( 'repo_not_found', $error );
        }

        // Determine plugin slug
        if ( ! $slug ) {
            $slug = sanitize_file_name( $repo_info['repo'] );
        }

        // Check if plugin already exists
        $plugins     = Git_Plugin_Loader::get_managed_plugins();
        $plugin_path = WP_PLUGIN_DIR . '/' . $slug;

        if ( isset( $plugins[ $slug ] ) ) {
            return new WP_Error( 'plugin_exists', __( 'This plugin is already managed by Git Plugin Loader.', 'git-plugin-loader' ) );
        }

        if ( file_exists( $plugin_path ) ) {
            return new WP_Error( 'directory_exists', __( 'A plugin with this slug already exists.', 'git-plugin-loader' ) );
        }

        // Get repository info for authentication check
        $repo_data  = $this->github_api->get_repo( $repo_info['owner'], $repo_info['repo'] );
        $is_private = $repo_data && ! empty( $repo_data['private'] );

        // Check if token is required for private repos
        if ( $is_private ) {
            $settings = Git_Plugin_Loader::get_settings();
            if ( empty( $settings['github_token'] ) ) {
                return new WP_Error( 'token_required', __( 'A GitHub token is required to clone private repositories.', 'git-plugin-loader' ) );
            }
        }

        // Prepare clone URL (add token for authentication)
        $clone_url = $this->git->sanitize_repo_url( $url );
        $settings  = Git_Plugin_Loader::get_settings();

        // Add token to URL if available (required for private repos, helpful for rate limits on public)
        if ( ! empty( $settings['github_token'] ) ) {
            // Token is stored encrypted, we need to use it directly in URL
            // The GitHub API class handles decryption internally, but for git clone we need the raw token
            $token     = $this->decrypt_token_for_clone( $settings['github_token'] );
            $clone_url = preg_replace( '/^https:\/\//', 'https://' . $token . '@', $clone_url );
        } elseif ( $is_private ) {
            return new WP_Error( 'token_required', __( 'A GitHub token is required to clone private repositories. Please add your token in Settings.', 'git-plugin-loader' ) );
        }

        // Clone the repository
        if ( ! $this->git->clone_repo( $clone_url, $slug, $branch ) ) {
            $error = $this->git->get_last_error();
            // Provide more helpful error messages
            if ( strpos( $error, 'Authentication failed' ) !== false || strpos( $error, 'could not read Username' ) !== false ) {
                if ( $is_private ) {
                    $error = __( 'Authentication failed. Please check your GitHub token in Settings.', 'git-plugin-loader' );
                } else {
                    $error = __( 'Repository not accessible. If this is a private repository, please add your GitHub token in Settings.', 'git-plugin-loader' );
                }
            }
            return new WP_Error( 'clone_failed', $error );
        }

        // Get current commit info
        $commit = $this->git->get_current_commit( $plugin_path );
        $commit_info = $this->git->get_commit_info( $plugin_path );

        // Get WordPress plugin info
        $wp_plugin_info = $this->get_wordpress_plugin_info( $plugin_path );

        // Store plugin data
        $plugin_data = array(
            'slug'              => $slug,
            'repo_url'          => $url,
            'owner'             => $repo_info['owner'],
            'repo'              => $repo_info['repo'],
            'branch'            => $branch,
            'local_commit'      => $commit,
            'remote_commit'     => $commit,
            'last_sync'         => time(),
            'auto_sync'         => false,
            'is_private'        => $is_private,
            'wp_plugin_name'    => $wp_plugin_info['name'],
            'wp_plugin_version' => $wp_plugin_info['version'],
            'wp_plugin_file'    => $wp_plugin_info['file'],
            'status'            => 'up_to_date',
        );

        $plugins[ $slug ] = $plugin_data;
        Git_Plugin_Loader::update_managed_plugins( $plugins );

        return $plugin_data;
    }

    /**
     * Remove a managed plugin
     *
     * @param string $slug         Plugin slug.
     * @param bool   $delete_files Whether to delete plugin files.
     * @return bool|WP_Error
     */
    public function remove_plugin( $slug, $delete_files = false ) {
        $plugins = Git_Plugin_Loader::get_managed_plugins();

        if ( ! isset( $plugins[ $slug ] ) ) {
            return new WP_Error( 'not_found', __( 'Plugin not found.', 'git-plugin-loader' ) );
        }

        // Deactivate the plugin first if active
        $plugin_file = $plugins[ $slug ]['wp_plugin_file'];
        if ( $plugin_file && is_plugin_active( $plugin_file ) ) {
            deactivate_plugins( $plugin_file );
        }

        // Delete files if requested
        if ( $delete_files ) {
            $plugin_path = WP_PLUGIN_DIR . '/' . $slug;
            if ( file_exists( $plugin_path ) ) {
                $this->delete_directory( $plugin_path );
            }
        }

        // Remove from managed plugins
        unset( $plugins[ $slug ] );
        Git_Plugin_Loader::update_managed_plugins( $plugins );

        return true;
    }

    /**
     * Sync a plugin with remote repository
     *
     * @param string $slug Plugin slug.
     * @return array|WP_Error
     */
    public function sync_plugin( $slug ) {
        $plugins = Git_Plugin_Loader::get_managed_plugins();

        if ( ! isset( $plugins[ $slug ] ) ) {
            return new WP_Error( 'not_found', __( 'Plugin not found.', 'git-plugin-loader' ) );
        }

        $plugin_data = $plugins[ $slug ];
        $plugin_path = WP_PLUGIN_DIR . '/' . $slug;

        if ( ! file_exists( $plugin_path ) ) {
            return new WP_Error( 'directory_not_found', __( 'Plugin directory not found.', 'git-plugin-loader' ) );
        }

        // Update status to syncing
        $plugins[ $slug ]['status'] = 'syncing';
        Git_Plugin_Loader::update_managed_plugins( $plugins );

        // Fetch latest changes
        if ( ! $this->git->fetch( $plugin_path, false, true ) ) {
            $plugins[ $slug ]['status'] = 'error';
            Git_Plugin_Loader::update_managed_plugins( $plugins );
            return new WP_Error( 'fetch_failed', $this->git->get_last_error() );
        }

        // Checkout the correct branch/tag
        if ( ! $this->git->checkout( $plugin_path, $plugin_data['branch'] ) ) {
            // May already be on the correct branch
        }

        // Reset any local changes and pull
        $this->git->reset( $plugin_path, 'HEAD', true );

        if ( ! $this->git->pull( $plugin_path ) ) {
            $plugins[ $slug ]['status'] = 'error';
            Git_Plugin_Loader::update_managed_plugins( $plugins );
            return new WP_Error( 'pull_failed', $this->git->get_last_error() );
        }

        // Get updated commit info
        $commit      = $this->git->get_current_commit( $plugin_path );
        $commit_info = $this->git->get_commit_info( $plugin_path );

        // Get updated WordPress plugin info
        $wp_plugin_info = $this->get_wordpress_plugin_info( $plugin_path );

        // Update plugin data
        $plugins[ $slug ]['local_commit']      = $commit;
        $plugins[ $slug ]['remote_commit']     = $commit;
        $plugins[ $slug ]['last_sync']         = time();
        $plugins[ $slug ]['status']            = 'up_to_date';
        $plugins[ $slug ]['wp_plugin_name']    = $wp_plugin_info['name'];
        $plugins[ $slug ]['wp_plugin_version'] = $wp_plugin_info['version'];
        $plugins[ $slug ]['wp_plugin_file']    = $wp_plugin_info['file'];

        Git_Plugin_Loader::update_managed_plugins( $plugins );

        return $plugins[ $slug ];
    }

    /**
     * Check for updates for a plugin
     *
     * @param string $slug Plugin slug.
     * @return array|WP_Error
     */
    public function check_updates( $slug ) {
        $plugins = Git_Plugin_Loader::get_managed_plugins();

        if ( ! isset( $plugins[ $slug ] ) ) {
            return new WP_Error( 'not_found', __( 'Plugin not found.', 'git-plugin-loader' ) );
        }

        $plugin_data = $plugins[ $slug ];
        $plugin_path = WP_PLUGIN_DIR . '/' . $slug;

        // Get local commit
        $local_commit = $this->git->get_current_commit( $plugin_path );

        // Get remote commit
        $remote_commit = $this->github_api->get_latest_commit(
            $plugin_data['owner'],
            $plugin_data['repo'],
            $plugin_data['branch']
        );

        if ( ! $remote_commit ) {
            return new WP_Error( 'api_error', $this->github_api->get_last_error() );
        }

        $has_update = $local_commit !== $remote_commit['sha'];

        // Update stored data
        $plugins[ $slug ]['local_commit']  = $local_commit;
        $plugins[ $slug ]['remote_commit'] = $remote_commit['sha'];
        $plugins[ $slug ]['status']        = $has_update ? 'update_available' : 'up_to_date';

        Git_Plugin_Loader::update_managed_plugins( $plugins );

        return array(
            'has_update'    => $has_update,
            'local_commit'  => $local_commit,
            'remote_commit' => $remote_commit['sha'],
            'commit_info'   => $remote_commit,
            'status'        => $plugins[ $slug ]['status'],
        );
    }

    /**
     * Check updates for all managed plugins
     *
     * @return array
     */
    public function check_all_updates() {
        $plugins = Git_Plugin_Loader::get_managed_plugins();
        $results = array();

        foreach ( $plugins as $slug => $plugin_data ) {
            $results[ $slug ] = $this->check_updates( $slug );
        }

        return $results;
    }

    /**
     * Sync all plugins with auto-sync enabled
     *
     * @return array
     */
    public function sync_auto_enabled() {
        $plugins = Git_Plugin_Loader::get_managed_plugins();
        $results = array();

        foreach ( $plugins as $slug => $plugin_data ) {
            if ( ! empty( $plugin_data['auto_sync'] ) ) {
                $results[ $slug ] = $this->sync_plugin( $slug );
            }
        }

        return $results;
    }

    /**
     * Toggle auto-sync for a plugin
     *
     * @param string $slug    Plugin slug.
     * @param bool   $enabled Whether to enable auto-sync.
     * @return bool|WP_Error
     */
    public function toggle_auto_sync( $slug, $enabled ) {
        $plugins = Git_Plugin_Loader::get_managed_plugins();

        if ( ! isset( $plugins[ $slug ] ) ) {
            return new WP_Error( 'not_found', __( 'Plugin not found.', 'git-plugin-loader' ) );
        }

        $plugins[ $slug ]['auto_sync'] = (bool) $enabled;
        Git_Plugin_Loader::update_managed_plugins( $plugins );

        return true;
    }

    /**
     * Change branch or tag for a plugin
     *
     * @param string $slug   Plugin slug.
     * @param string $branch New branch or tag.
     * @return array|WP_Error
     */
    public function change_branch( $slug, $branch ) {
        $plugins = Git_Plugin_Loader::get_managed_plugins();

        if ( ! isset( $plugins[ $slug ] ) ) {
            return new WP_Error( 'not_found', __( 'Plugin not found.', 'git-plugin-loader' ) );
        }

        $plugin_path = WP_PLUGIN_DIR . '/' . $slug;

        // Fetch all refs
        if ( ! $this->git->fetch( $plugin_path, true, true ) ) {
            return new WP_Error( 'fetch_failed', $this->git->get_last_error() );
        }

        // Checkout the new branch/tag
        if ( ! $this->git->checkout( $plugin_path, $branch ) ) {
            return new WP_Error( 'checkout_failed', $this->git->get_last_error() );
        }

        // Update stored data
        $plugins[ $slug ]['branch'] = $branch;
        Git_Plugin_Loader::update_managed_plugins( $plugins );

        // Sync to get latest commit info
        return $this->sync_plugin( $slug );
    }

    /**
     * Get branches and tags for a plugin
     *
     * @param string $slug Plugin slug.
     * @return array|WP_Error
     */
    public function get_refs( $slug ) {
        $plugins = Git_Plugin_Loader::get_managed_plugins();

        if ( ! isset( $plugins[ $slug ] ) ) {
            return new WP_Error( 'not_found', __( 'Plugin not found.', 'git-plugin-loader' ) );
        }

        $plugin_data = $plugins[ $slug ];

        // Get branches from GitHub API
        $branches = $this->github_api->get_branches(
            $plugin_data['owner'],
            $plugin_data['repo']
        );

        // Get tags from GitHub API
        $tags = $this->github_api->get_tags(
            $plugin_data['owner'],
            $plugin_data['repo']
        );

        return array(
            'branches'       => $branches ? $branches : array(),
            'tags'           => $tags ? $tags : array(),
            'current_branch' => $plugin_data['branch'],
        );
    }

    /**
     * Get plugin details
     *
     * @param string $slug Plugin slug.
     * @return array|WP_Error
     */
    public function get_plugin( $slug ) {
        $plugins = Git_Plugin_Loader::get_managed_plugins();

        if ( ! isset( $plugins[ $slug ] ) ) {
            return new WP_Error( 'not_found', __( 'Plugin not found.', 'git-plugin-loader' ) );
        }

        $plugin_data = $plugins[ $slug ];
        $plugin_path = WP_PLUGIN_DIR . '/' . $slug;

        // Get current commit info
        $commit_info = $this->git->get_commit_info( $plugin_path );

        // Get status
        $status = $this->git->get_status( $plugin_path );

        return array_merge( $plugin_data, array(
            'commit_info'  => $commit_info,
            'has_changes'  => $status ? ! $status['clean'] : false,
            'is_active'    => $plugin_data['wp_plugin_file'] ? is_plugin_active( $plugin_data['wp_plugin_file'] ) : false,
        ) );
    }

    /**
     * Get all managed plugins with details
     *
     * @return array
     */
    public function get_all_plugins() {
        $plugins = Git_Plugin_Loader::get_managed_plugins();
        $result  = array();

        foreach ( $plugins as $slug => $plugin_data ) {
            $plugin_path = WP_PLUGIN_DIR . '/' . $slug;

            // Check if directory exists
            if ( ! file_exists( $plugin_path ) ) {
                $plugin_data['status'] = 'error';
                $plugin_data['error']  = __( 'Plugin directory not found.', 'git-plugin-loader' );
            }

            $plugin_data['is_active'] = $plugin_data['wp_plugin_file'] ? is_plugin_active( $plugin_data['wp_plugin_file'] ) : false;

            $result[ $slug ] = $plugin_data;
        }

        return $result;
    }

    /**
     * Get WordPress plugin information from a directory
     *
     * @param string $plugin_path Plugin directory path.
     * @return array
     */
    private function get_wordpress_plugin_info( $plugin_path ) {
        $info = array(
            'name'    => '',
            'version' => '',
            'file'    => '',
        );

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $slug = basename( $plugin_path );

        // Get all plugins and find matching one
        $all_plugins = get_plugins();

        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            if ( strpos( $plugin_file, $slug . '/' ) === 0 ) {
                $info['name']    = $plugin_data['Name'];
                $info['version'] = $plugin_data['Version'];
                $info['file']    = $plugin_file;
                break;
            }
        }

        return $info;
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

        // Security check: ensure we're within plugins directory
        $plugins_dir = realpath( WP_PLUGIN_DIR );
        $dir         = realpath( $dir );

        if ( ! $dir || strpos( $dir, $plugins_dir ) !== 0 ) {
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
     * Decrypt token for use in git clone URL
     *
     * @param string $encrypted_token Encrypted token from settings.
     * @return string Decrypted token.
     */
    private function decrypt_token_for_clone( $encrypted_token ) {
        if ( empty( $encrypted_token ) ) {
            return '';
        }

        $key = $this->get_encryption_key();

        if ( function_exists( 'openssl_decrypt' ) ) {
            $decoded = base64_decode( $encrypted_token );
            if ( strlen( $decoded ) >= 16 ) {
                $iv        = substr( $decoded, 0, 16 );
                $encrypted = substr( $decoded, 16 );
                $decrypted = openssl_decrypt( $encrypted, 'AES-256-CBC', $key, 0, $iv );
                if ( false !== $decrypted ) {
                    return $decrypted;
                }
            }
        }

        // Fallback: try simple base64 decode
        $decoded = base64_decode( $encrypted_token );
        if ( $decoded !== false ) {
            return $decoded;
        }

        return $encrypted_token;
    }

    /**
     * Get encryption key for token decryption
     *
     * @return string
     */
    private function get_encryption_key() {
        if ( defined( 'AUTH_KEY' ) && ! empty( AUTH_KEY ) ) {
            return hash( 'sha256', AUTH_KEY );
        }
        return hash( 'sha256', 'git-plugin-loader-default-key' );
    }
}
