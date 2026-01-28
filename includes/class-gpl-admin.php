<?php
/**
 * Admin Interface Class
 *
 * Handles the WordPress admin interface for Git Plugin Loader.
 *
 * @package Git_Plugin_Loader
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GPL_Admin class
 */
class GPL_Admin {

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

        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_notices', array( $this, 'display_notices' ) );
    }

    /**
     * Register admin menu
     */
    public function register_menu() {
        add_menu_page(
            __( 'Git Plugins', 'git-plugin-loader' ),
            __( 'Git Plugins', 'git-plugin-loader' ),
            'manage_options',
            'git-plugin-loader',
            array( $this, 'render_main_page' ),
            'dashicons-github',
            65
        );

        add_submenu_page(
            'git-plugin-loader',
            __( 'Manage Plugins', 'git-plugin-loader' ),
            __( 'Manage Plugins', 'git-plugin-loader' ),
            'manage_options',
            'git-plugin-loader',
            array( $this, 'render_main_page' )
        );

        add_submenu_page(
            'git-plugin-loader',
            __( 'Settings', 'git-plugin-loader' ),
            __( 'Settings', 'git-plugin-loader' ),
            'manage_options',
            'git-plugin-loader-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_scripts( $hook ) {
        if ( strpos( $hook, 'git-plugin-loader' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'gpl-admin-css',
            GPL_ASSETS_URL . 'css/admin.css',
            array(),
            GPL_VERSION
        );

        wp_enqueue_script(
            'gpl-admin-js',
            GPL_ASSETS_URL . 'js/admin.js',
            array( 'jquery' ),
            GPL_VERSION,
            true
        );

        wp_localize_script( 'gpl-admin-js', 'gplAdmin', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'gpl_ajax_nonce' ),
            'pluginUrl' => GPL_PLUGIN_URL,
            'strings'   => array(
                'confirmDelete'     => __( 'Are you sure you want to remove this plugin from Git Plugin Loader?', 'git-plugin-loader' ),
                'confirmDeleteFiles' => __( 'Do you also want to delete the plugin files?', 'git-plugin-loader' ),
                'syncing'           => __( 'Syncing...', 'git-plugin-loader' ),
                'checking'          => __( 'Checking...', 'git-plugin-loader' ),
                'exporting'         => __( 'Exporting...', 'git-plugin-loader' ),
                'success'           => __( 'Success!', 'git-plugin-loader' ),
                'error'             => __( 'Error', 'git-plugin-loader' ),
                'validating'        => __( 'Validating repository...', 'git-plugin-loader' ),
                'cloning'           => __( 'Cloning repository...', 'git-plugin-loader' ),
                'noPlugins'         => __( 'No plugins are being managed yet.', 'git-plugin-loader' ),
            ),
        ) );
    }

    /**
     * Display admin notices
     */
    public function display_notices() {
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'git-plugin-loader' ) === false ) {
            return;
        }

        // Check for Git availability
        if ( ! Git_Plugin_Loader::is_git_installed() ) {
            $this->render_notice(
                __( 'Git is not installed or not accessible on this server. Git Plugin Loader requires Git to function.', 'git-plugin-loader' ),
                'error'
            );
        }

        // Check for exec() availability
        if ( ! Git_Plugin_Loader::is_exec_available() ) {
            $this->render_notice(
                __( 'The PHP exec() function is not available. Git Plugin Loader requires exec() to run Git commands.', 'git-plugin-loader' ),
                'error'
            );
        }
    }

    /**
     * Render an admin notice
     *
     * @param string $message Notice message.
     * @param string $type    Notice type (error, warning, success, info).
     */
    private function render_notice( $message, $type = 'info' ) {
        printf(
            '<div class="notice notice-%s"><p>%s</p></div>',
            esc_attr( $type ),
            esc_html( $message )
        );
    }

    /**
     * Render the main plugins page
     */
    public function render_main_page() {
        $plugins     = $this->plugin_manager->get_all_plugins();
        $system_ok   = Git_Plugin_Loader::is_git_installed() && Git_Plugin_Loader::is_exec_available();
        ?>
        <div class="wrap gpl-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Git Plugin Loader', 'git-plugin-loader' ); ?></h1>

            <?php if ( $system_ok ) : ?>
                <a href="#" class="page-title-action gpl-add-new-btn">
                    <?php esc_html_e( 'Add New', 'git-plugin-loader' ); ?>
                </a>
            <?php endif; ?>

            <hr class="wp-header-end">

            <?php if ( ! $system_ok ) : ?>
                <div class="gpl-system-error">
                    <p><?php esc_html_e( 'Git Plugin Loader cannot function because of missing system requirements. Please see the notices above.', 'git-plugin-loader' ); ?></p>
                </div>
            <?php else : ?>
                <!-- Add New Plugin Form -->
                <div class="gpl-add-form" style="display: none;">
                    <div class="gpl-card">
                        <h2><?php esc_html_e( 'Add Plugin from GitHub', 'git-plugin-loader' ); ?></h2>
                        <form id="gpl-add-plugin-form">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="gpl-repo-url"><?php esc_html_e( 'GitHub Repository URL', 'git-plugin-loader' ); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" id="gpl-repo-url" name="repo_url" class="regular-text" placeholder="https://github.com/owner/repo" required>
                                        <p class="description"><?php esc_html_e( 'Enter the full GitHub repository URL.', 'git-plugin-loader' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="gpl-branch"><?php esc_html_e( 'Branch / Tag', 'git-plugin-loader' ); ?></label>
                                    </th>
                                    <td>
                                        <select id="gpl-branch" name="branch" class="regular-text">
                                            <option value="main">main</option>
                                        </select>
                                        <button type="button" id="gpl-load-refs" class="button" disabled><?php esc_html_e( 'Load Branches/Tags', 'git-plugin-loader' ); ?></button>
                                        <p class="description"><?php esc_html_e( 'Select a branch or tag to checkout.', 'git-plugin-loader' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="gpl-slug"><?php esc_html_e( 'Plugin Slug', 'git-plugin-loader' ); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" id="gpl-slug" name="slug" class="regular-text" placeholder="<?php esc_attr_e( 'auto-generated from repo name', 'git-plugin-loader' ); ?>">
                                        <p class="description"><?php esc_html_e( 'Optional. The plugin directory name.', 'git-plugin-loader' ); ?></p>
                                    </td>
                                </tr>
                            </table>
                            <p class="submit">
                                <button type="submit" class="button button-primary"><?php esc_html_e( 'Add Plugin', 'git-plugin-loader' ); ?></button>
                                <button type="button" class="button gpl-cancel-add"><?php esc_html_e( 'Cancel', 'git-plugin-loader' ); ?></button>
                                <span class="spinner"></span>
                                <span class="gpl-status-message"></span>
                            </p>
                        </form>
                    </div>
                </div>

                <!-- Plugins List -->
                <div class="gpl-plugins-list">
                    <?php if ( empty( $plugins ) ) : ?>
                        <div class="gpl-no-plugins">
                            <p><?php esc_html_e( 'No plugins are being managed yet. Click "Add New" to add a plugin from GitHub.', 'git-plugin-loader' ); ?></p>
                        </div>
                    <?php else : ?>
                        <table class="wp-list-table widefat fixed striped gpl-plugins-table">
                            <thead>
                                <tr>
                                    <th class="column-name"><?php esc_html_e( 'Plugin', 'git-plugin-loader' ); ?></th>
                                    <th class="column-repo"><?php esc_html_e( 'Repository', 'git-plugin-loader' ); ?></th>
                                    <th class="column-branch"><?php esc_html_e( 'Branch/Tag', 'git-plugin-loader' ); ?></th>
                                    <th class="column-status"><?php esc_html_e( 'Status', 'git-plugin-loader' ); ?></th>
                                    <th class="column-last-sync"><?php esc_html_e( 'Last Sync', 'git-plugin-loader' ); ?></th>
                                    <th class="column-actions"><?php esc_html_e( 'Actions', 'git-plugin-loader' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $plugins as $slug => $plugin ) : ?>
                                    <tr data-slug="<?php echo esc_attr( $slug ); ?>">
                                        <td class="column-name">
                                            <strong><?php echo esc_html( $plugin['wp_plugin_name'] ? $plugin['wp_plugin_name'] : $slug ); ?></strong>
                                            <?php if ( $plugin['is_active'] ) : ?>
                                                <span class="gpl-badge gpl-badge-active"><?php esc_html_e( 'Active', 'git-plugin-loader' ); ?></span>
                                            <?php endif; ?>
                                            <?php if ( $plugin['is_private'] ) : ?>
                                                <span class="gpl-badge gpl-badge-private"><?php esc_html_e( 'Private', 'git-plugin-loader' ); ?></span>
                                            <?php endif; ?>
                                            <?php if ( ! empty( $plugin['auto_sync'] ) ) : ?>
                                                <span class="gpl-badge gpl-badge-autosync"><?php esc_html_e( 'Auto-sync', 'git-plugin-loader' ); ?></span>
                                            <?php endif; ?>
                                            <br>
                                            <small class="gpl-commit-info">
                                                <?php
                                                printf(
                                                    /* translators: %s: commit hash */
                                                    esc_html__( 'Commit: %s', 'git-plugin-loader' ),
                                                    '<code>' . esc_html( substr( $plugin['local_commit'], 0, 7 ) ) . '</code>'
                                                );
                                                ?>
                                            </small>
                                        </td>
                                        <td class="column-repo">
                                            <a href="<?php echo esc_url( $plugin['repo_url'] ); ?>" target="_blank" rel="noopener">
                                                <?php echo esc_html( $plugin['owner'] . '/' . $plugin['repo'] ); ?>
                                            </a>
                                        </td>
                                        <td class="column-branch">
                                            <select class="gpl-branch-select" data-slug="<?php echo esc_attr( $slug ); ?>">
                                                <option value="<?php echo esc_attr( $plugin['branch'] ); ?>" selected>
                                                    <?php echo esc_html( $plugin['branch'] ); ?>
                                                </option>
                                            </select>
                                            <button type="button" class="button-link gpl-load-branches" data-slug="<?php echo esc_attr( $slug ); ?>"><?php esc_html_e( 'Change', 'git-plugin-loader' ); ?></button>
                                        </td>
                                        <td class="column-status">
                                            <?php echo $this->render_status_badge( $plugin['status'] ); ?>
                                        </td>
                                        <td class="column-last-sync">
                                            <?php
                                            if ( $plugin['last_sync'] ) {
                                                echo esc_html( human_time_diff( $plugin['last_sync'], time() ) . ' ' . __( 'ago', 'git-plugin-loader' ) );
                                            } else {
                                                esc_html_e( 'Never', 'git-plugin-loader' );
                                            }
                                            ?>
                                        </td>
                                        <td class="column-actions">
                                            <div class="gpl-actions">
                                                <button type="button" class="button gpl-sync-btn" data-slug="<?php echo esc_attr( $slug ); ?>">
                                                    <?php esc_html_e( 'Sync', 'git-plugin-loader' ); ?>
                                                </button>
                                                <button type="button" class="button gpl-check-btn" data-slug="<?php echo esc_attr( $slug ); ?>">
                                                    <?php esc_html_e( 'Check', 'git-plugin-loader' ); ?>
                                                </button>
                                                <button type="button" class="button gpl-export-btn" data-slug="<?php echo esc_attr( $slug ); ?>">
                                                    <?php esc_html_e( 'Export', 'git-plugin-loader' ); ?>
                                                </button>
                                                <div class="gpl-more-actions">
                                                    <button type="button" class="button gpl-more-btn">
                                                        <span class="dashicons dashicons-ellipsis"></span>
                                                    </button>
                                                    <div class="gpl-dropdown">
                                                        <label class="gpl-dropdown-item">
                                                            <input type="checkbox" class="gpl-autosync-toggle" data-slug="<?php echo esc_attr( $slug ); ?>" <?php checked( ! empty( $plugin['auto_sync'] ) ); ?>>
                                                            <?php esc_html_e( 'Auto-sync', 'git-plugin-loader' ); ?>
                                                        </label>
                                                        <a href="#" class="gpl-dropdown-item gpl-remove-btn" data-slug="<?php echo esc_attr( $slug ); ?>">
                                                            <?php esc_html_e( 'Remove', 'git-plugin-loader' ); ?>
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                            <span class="spinner"></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the settings page
     */
    public function render_settings_page() {
        $settings = Git_Plugin_Loader::get_settings();
        ?>
        <div class="wrap gpl-wrap">
            <h1><?php esc_html_e( 'Git Plugin Loader Settings', 'git-plugin-loader' ); ?></h1>

            <form id="gpl-settings-form" method="post">
                <?php wp_nonce_field( 'gpl_save_settings', 'gpl_settings_nonce' ); ?>

                <div class="gpl-card">
                    <h2><?php esc_html_e( 'GitHub Authentication', 'git-plugin-loader' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="gpl-github-token"><?php esc_html_e( 'Personal Access Token', 'git-plugin-loader' ); ?></label>
                            </th>
                            <td>
                                <input type="password" id="gpl-github-token" name="github_token" class="regular-text" value="<?php echo esc_attr( $settings['github_token'] ? '********' : '' ); ?>">
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %s: link to GitHub token settings */
                                        esc_html__( 'Required for private repositories. %s', 'git-plugin-loader' ),
                                        '<a href="https://github.com/settings/tokens" target="_blank" rel="noopener">' . esc_html__( 'Create a token', 'git-plugin-loader' ) . '</a>'
                                    );
                                    ?>
                                </p>
                                <?php if ( $settings['github_token'] ) : ?>
                                    <p>
                                        <label>
                                            <input type="checkbox" name="clear_token" value="1">
                                            <?php esc_html_e( 'Clear existing token', 'git-plugin-loader' ); ?>
                                        </label>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="gpl-card">
                    <h2><?php esc_html_e( 'Auto-Sync Settings', 'git-plugin-loader' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="gpl-sync-interval"><?php esc_html_e( 'Check Interval', 'git-plugin-loader' ); ?></label>
                            </th>
                            <td>
                                <select id="gpl-sync-interval" name="auto_sync_interval">
                                    <option value="hourly" <?php selected( $settings['auto_sync_interval'], 'hourly' ); ?>>
                                        <?php esc_html_e( 'Hourly', 'git-plugin-loader' ); ?>
                                    </option>
                                    <option value="twicedaily" <?php selected( $settings['auto_sync_interval'], 'twicedaily' ); ?>>
                                        <?php esc_html_e( 'Twice Daily', 'git-plugin-loader' ); ?>
                                    </option>
                                    <option value="daily" <?php selected( $settings['auto_sync_interval'], 'daily' ); ?>>
                                        <?php esc_html_e( 'Daily', 'git-plugin-loader' ); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="gpl-card">
                    <h2><?php esc_html_e( 'Export Settings', 'git-plugin-loader' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="gpl-export-exclusions"><?php esc_html_e( 'Exclude from Exports', 'git-plugin-loader' ); ?></label>
                            </th>
                            <td>
                                <textarea id="gpl-export-exclusions" name="export_exclusions" rows="10" class="large-text code"><?php echo esc_textarea( implode( "\n", $settings['export_exclusions'] ) ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Files and directories to exclude from exports (one per line). Supports wildcards.', 'git-plugin-loader' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="gpl-cleanup-hours"><?php esc_html_e( 'Cleanup Export Files After', 'git-plugin-loader' ); ?></label>
                            </th>
                            <td>
                                <input type="number" id="gpl-cleanup-hours" name="cleanup_exports_after" value="<?php echo esc_attr( $settings['cleanup_exports_after'] ); ?>" min="1" max="168" class="small-text">
                                <?php esc_html_e( 'hours', 'git-plugin-loader' ); ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="gpl-card">
                    <h2><?php esc_html_e( 'System Status', 'git-plugin-loader' ); ?></h2>
                    <table class="form-table gpl-system-status">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Git Installation', 'git-plugin-loader' ); ?></th>
                            <td>
                                <?php if ( Git_Plugin_Loader::is_git_installed() ) : ?>
                                    <span class="gpl-status-ok"><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Installed', 'git-plugin-loader' ); ?></span>
                                <?php else : ?>
                                    <span class="gpl-status-error"><span class="dashicons dashicons-no"></span> <?php esc_html_e( 'Not Found', 'git-plugin-loader' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'PHP exec() Function', 'git-plugin-loader' ); ?></th>
                            <td>
                                <?php if ( Git_Plugin_Loader::is_exec_available() ) : ?>
                                    <span class="gpl-status-ok"><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Available', 'git-plugin-loader' ); ?></span>
                                <?php else : ?>
                                    <span class="gpl-status-error"><span class="dashicons dashicons-no"></span> <?php esc_html_e( 'Disabled', 'git-plugin-loader' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'GitHub Token', 'git-plugin-loader' ); ?></th>
                            <td>
                                <?php if ( ! empty( $settings['github_token'] ) ) : ?>
                                    <span class="gpl-status-ok"><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Configured', 'git-plugin-loader' ); ?></span>
                                <?php else : ?>
                                    <span class="gpl-status-warning"><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Not Set (required for private repos)', 'git-plugin-loader' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Plugins Directory', 'git-plugin-loader' ); ?></th>
                            <td>
                                <?php if ( is_writable( WP_PLUGIN_DIR ) ) : ?>
                                    <span class="gpl-status-ok"><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Writable', 'git-plugin-loader' ); ?></span>
                                <?php else : ?>
                                    <span class="gpl-status-error"><span class="dashicons dashicons-no"></span> <?php esc_html_e( 'Not Writable', 'git-plugin-loader' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'git-plugin-loader' ); ?></button>
                    <span class="spinner"></span>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Render a status badge
     *
     * @param string $status Status key.
     * @return string HTML for status badge.
     */
    private function render_status_badge( $status ) {
        $statuses = array(
            'up_to_date'       => array(
                'label' => __( 'Up to date', 'git-plugin-loader' ),
                'class' => 'gpl-status-ok',
            ),
            'update_available' => array(
                'label' => __( 'Update available', 'git-plugin-loader' ),
                'class' => 'gpl-status-update',
            ),
            'syncing'          => array(
                'label' => __( 'Syncing...', 'git-plugin-loader' ),
                'class' => 'gpl-status-syncing',
            ),
            'error'            => array(
                'label' => __( 'Error', 'git-plugin-loader' ),
                'class' => 'gpl-status-error',
            ),
        );

        $status_info = isset( $statuses[ $status ] ) ? $statuses[ $status ] : $statuses['error'];

        return sprintf(
            '<span class="gpl-status-badge %s">%s</span>',
            esc_attr( $status_info['class'] ),
            esc_html( $status_info['label'] )
        );
    }
}
