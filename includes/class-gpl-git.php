<?php
/**
 * Git Operations Class
 *
 * Handles all Git command execution via PHP's exec() function.
 *
 * @package Git_Plugin_Loader
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GPL_Git class for Git operations
 */
class GPL_Git {

    /**
     * Last error message
     *
     * @var string
     */
    private $last_error = '';

    /**
     * Last command output
     *
     * @var array
     */
    private $last_output = array();

    /**
     * Constructor
     */
    public function __construct() {
        // Nothing to initialize
    }

    /**
     * Execute a Git command
     *
     * @param string $command Git command to execute (without 'git' prefix).
     * @param string $path    Working directory path.
     * @return array|false Output array on success, false on failure.
     */
    private function execute( $command, $path = null ) {
        if ( ! Git_Plugin_Loader::is_exec_available() ) {
            $this->last_error = __( 'The exec() function is not available on this server.', 'git-plugin-loader' );
            return false;
        }

        if ( ! Git_Plugin_Loader::is_git_installed() ) {
            $this->last_error = __( 'Git is not installed on this server.', 'git-plugin-loader' );
            return false;
        }

        // Build the command with config options to prevent interactive prompts
        // GIT_TERMINAL_PROMPT=0 prevents credential prompts (inline var assignment works in sh/bash)
        // -c credential.helper= disables any configured credential helper
        // -c core.askPass= disables askpass programs
        $git_config = '-c credential.helper= -c core.askPass=';
        $command    = 'GIT_TERMINAL_PROMPT=0 git ' . $git_config . ' ' . $command;

        // Change to the specified directory if provided
        if ( $path ) {
            $path = realpath( $path );
            if ( ! $path || ! is_dir( $path ) ) {
                $this->last_error = __( 'Invalid directory path.', 'git-plugin-loader' );
                return false;
            }
            $command = 'cd ' . escapeshellarg( $path ) . ' && ' . $command;
        }

        // Execute the command
        $output     = array();
        $return_var = 0;
        exec( $command . ' 2>&1', $output, $return_var );

        $this->last_output = $output;

        if ( 0 !== $return_var ) {
            $this->last_error = implode( "\n", $output );
            return false;
        }

        return $output;
    }

    /**
     * Get the last error message
     *
     * @return string
     */
    public function get_last_error() {
        return $this->last_error;
    }

    /**
     * Get the last command output
     *
     * @return array
     */
    public function get_last_output() {
        return $this->last_output;
    }

    /**
     * Clone a repository
     *
     * @param string $url         Repository URL.
     * @param string $destination Destination directory.
     * @param string $branch      Optional branch to clone.
     * @return bool
     */
    public function clone_repo( $url, $destination, $branch = null ) {
        $url = $this->sanitize_repo_url( $url );
        if ( ! $url ) {
            $this->last_error = __( 'Invalid repository URL.', 'git-plugin-loader' );
            return false;
        }

        // Validate destination path is within plugins directory
        $plugins_dir = WP_PLUGIN_DIR;
        $destination = trailingslashit( $plugins_dir ) . basename( $destination );

        if ( file_exists( $destination ) ) {
            $this->last_error = __( 'Destination directory already exists.', 'git-plugin-loader' );
            return false;
        }

        $command = 'clone';

        if ( $branch ) {
            $command .= ' -b ' . escapeshellarg( $branch );
        }

        $command .= ' ' . escapeshellarg( $url ) . ' ' . escapeshellarg( $destination );

        $result = $this->execute( $command );

        return false !== $result;
    }

    /**
     * Pull latest changes
     *
     * @param string $path Repository path.
     * @return bool
     */
    public function pull( $path ) {
        if ( ! $this->validate_path( $path ) ) {
            return false;
        }

        $result = $this->execute( 'pull', $path );
        return false !== $result;
    }

    /**
     * Fetch from remote
     *
     * @param string $path   Repository path.
     * @param bool   $all    Fetch all remotes.
     * @param bool   $tags   Fetch tags.
     * @return bool
     */
    public function fetch( $path, $all = false, $tags = false ) {
        if ( ! $this->validate_path( $path ) ) {
            return false;
        }

        $command = 'fetch';

        if ( $all ) {
            $command .= ' --all';
        }

        if ( $tags ) {
            $command .= ' --tags';
        }

        $result = $this->execute( $command, $path );
        return false !== $result;
    }

    /**
     * Get current branch name
     *
     * @param string $path Repository path.
     * @return string|false
     */
    public function get_current_branch( $path ) {
        if ( ! $this->validate_path( $path ) ) {
            return false;
        }

        $result = $this->execute( 'rev-parse --abbrev-ref HEAD', $path );

        if ( false === $result || empty( $result ) ) {
            return false;
        }

        return trim( $result[0] );
    }

    /**
     * Get list of branches
     *
     * @param string $path   Repository path.
     * @param bool   $remote Include remote branches.
     * @return array|false
     */
    public function get_branches( $path, $remote = false ) {
        if ( ! $this->validate_path( $path ) ) {
            return false;
        }

        $command = 'branch';

        if ( $remote ) {
            $command .= ' -r';
        }

        $result = $this->execute( $command, $path );

        if ( false === $result ) {
            return false;
        }

        $branches = array();
        foreach ( $result as $branch ) {
            $branch = trim( $branch, " \t\n\r\0\x0B*" );
            if ( ! empty( $branch ) && strpos( $branch, 'HEAD' ) === false ) {
                $branches[] = $branch;
            }
        }

        return $branches;
    }

    /**
     * Get list of tags
     *
     * @param string $path Repository path.
     * @return array|false
     */
    public function get_tags( $path ) {
        if ( ! $this->validate_path( $path ) ) {
            return false;
        }

        $result = $this->execute( 'tag -l', $path );

        if ( false === $result ) {
            return false;
        }

        return array_filter( array_map( 'trim', $result ) );
    }

    /**
     * Get current commit hash
     *
     * @param string $path  Repository path.
     * @param bool   $short Get short hash.
     * @return string|false
     */
    public function get_current_commit( $path, $short = false ) {
        if ( ! $this->validate_path( $path ) ) {
            return false;
        }

        $command = 'rev-parse';
        if ( $short ) {
            $command .= ' --short';
        }
        $command .= ' HEAD';

        $result = $this->execute( $command, $path );

        if ( false === $result || empty( $result ) ) {
            return false;
        }

        return trim( $result[0] );
    }

    /**
     * Get commit info
     *
     * @param string $path   Repository path.
     * @param string $commit Commit hash.
     * @return array|false
     */
    public function get_commit_info( $path, $commit = 'HEAD' ) {
        if ( ! $this->validate_path( $path ) ) {
            return false;
        }

        $format = '%H%n%h%n%an%n%ae%n%at%n%s';
        $result = $this->execute( 'log -1 --format="' . $format . '" ' . escapeshellarg( $commit ), $path );

        if ( false === $result || count( $result ) < 6 ) {
            return false;
        }

        return array(
            'hash'       => $result[0],
            'short_hash' => $result[1],
            'author'     => $result[2],
            'email'      => $result[3],
            'timestamp'  => (int) $result[4],
            'message'    => $result[5],
        );
    }

    /**
     * Checkout a branch or tag
     *
     * @param string $path Repository path.
     * @param string $ref  Branch or tag name.
     * @return bool
     */
    public function checkout( $path, $ref ) {
        if ( ! $this->validate_path( $path ) ) {
            return false;
        }

        $result = $this->execute( 'checkout ' . escapeshellarg( $ref ), $path );
        return false !== $result;
    }

    /**
     * Get repository status
     *
     * @param string $path Repository path.
     * @return array|false
     */
    public function get_status( $path ) {
        if ( ! $this->validate_path( $path ) ) {
            return false;
        }

        $result = $this->execute( 'status --porcelain', $path );

        if ( false === $result ) {
            return false;
        }

        return array(
            'clean'   => empty( $result ),
            'changes' => $result,
        );
    }

    /**
     * Get remote URL
     *
     * @param string $path   Repository path.
     * @param string $remote Remote name.
     * @return string|false
     */
    public function get_remote_url( $path, $remote = 'origin' ) {
        if ( ! $this->validate_path( $path ) ) {
            return false;
        }

        $result = $this->execute( 'remote get-url ' . escapeshellarg( $remote ), $path );

        if ( false === $result || empty( $result ) ) {
            return false;
        }

        return trim( $result[0] );
    }

    /**
     * Get commits behind/ahead of remote
     *
     * @param string $path   Repository path.
     * @param string $branch Branch name.
     * @return array|false
     */
    public function get_remote_diff( $path, $branch = null ) {
        if ( ! $this->validate_path( $path ) ) {
            return false;
        }

        // Fetch first to get latest remote info
        $this->fetch( $path );

        if ( ! $branch ) {
            $branch = $this->get_current_branch( $path );
        }

        if ( ! $branch ) {
            return false;
        }

        $result = $this->execute( 'rev-list --left-right --count origin/' . escapeshellarg( $branch ) . '...' . escapeshellarg( $branch ), $path );

        if ( false === $result || empty( $result ) ) {
            return array(
                'ahead'  => 0,
                'behind' => 0,
            );
        }

        $counts = preg_split( '/\s+/', trim( $result[0] ) );

        return array(
            'behind' => isset( $counts[0] ) ? (int) $counts[0] : 0,
            'ahead'  => isset( $counts[1] ) ? (int) $counts[1] : 0,
        );
    }

    /**
     * Get remote HEAD commit
     *
     * @param string $path   Repository path.
     * @param string $branch Branch name.
     * @return string|false
     */
    public function get_remote_commit( $path, $branch = null ) {
        if ( ! $this->validate_path( $path ) ) {
            return false;
        }

        // Fetch first
        $this->fetch( $path );

        if ( ! $branch ) {
            $branch = $this->get_current_branch( $path );
        }

        if ( ! $branch ) {
            return false;
        }

        $result = $this->execute( 'rev-parse origin/' . escapeshellarg( $branch ), $path );

        if ( false === $result || empty( $result ) ) {
            return false;
        }

        return trim( $result[0] );
    }

    /**
     * Reset to a specific commit
     *
     * @param string $path   Repository path.
     * @param string $commit Commit hash or ref.
     * @param bool   $hard   Hard reset.
     * @return bool
     */
    public function reset( $path, $commit = 'HEAD', $hard = false ) {
        if ( ! $this->validate_path( $path ) ) {
            return false;
        }

        $command = 'reset';
        if ( $hard ) {
            $command .= ' --hard';
        }
        $command .= ' ' . escapeshellarg( $commit );

        $result = $this->execute( $command, $path );
        return false !== $result;
    }

    /**
     * Check if directory is a Git repository
     *
     * @param string $path Directory path.
     * @return bool
     */
    public function is_repo( $path ) {
        if ( ! is_dir( $path ) ) {
            return false;
        }

        $result = $this->execute( 'rev-parse --is-inside-work-tree', $path );

        if ( false === $result || empty( $result ) ) {
            return false;
        }

        return 'true' === trim( $result[0] );
    }

    /**
     * Validate path is within plugins directory
     *
     * @param string $path Path to validate.
     * @return bool
     */
    private function validate_path( $path ) {
        $plugins_dir = realpath( WP_PLUGIN_DIR );
        $path        = realpath( $path );

        if ( ! $path || ! $plugins_dir ) {
            $this->last_error = __( 'Invalid path.', 'git-plugin-loader' );
            return false;
        }

        // Ensure path is within plugins directory
        if ( strpos( $path, $plugins_dir ) !== 0 ) {
            $this->last_error = __( 'Path is outside the plugins directory.', 'git-plugin-loader' );
            return false;
        }

        if ( ! is_dir( $path ) ) {
            $this->last_error = __( 'Directory does not exist.', 'git-plugin-loader' );
            return false;
        }

        return true;
    }

    /**
     * Sanitize and validate repository URL
     *
     * @param string $url Repository URL.
     * @return string|false Sanitized URL or false if invalid.
     */
    public function sanitize_repo_url( $url ) {
        $url = trim( $url );

        // Convert SSH format to HTTPS
        if ( preg_match( '/^git@github\.com:(.+)\.git$/i', $url, $matches ) ) {
            $url = 'https://github.com/' . $matches[1] . '.git';
        } elseif ( preg_match( '/^git@github\.com:(.+)$/i', $url, $matches ) ) {
            $url = 'https://github.com/' . $matches[1] . '.git';
        }

        // Add .git suffix if missing
        if ( preg_match( '/^https?:\/\/github\.com\/[^\/]+\/[^\/]+$/i', $url ) ) {
            $url .= '.git';
        }

        // Validate GitHub URL format
        if ( ! preg_match( '/^https?:\/\/github\.com\/[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+(\.git)?$/i', $url ) ) {
            return false;
        }

        return esc_url_raw( $url );
    }

    /**
     * Extract owner and repo from GitHub URL
     *
     * @param string $url Repository URL.
     * @return array|false Array with 'owner' and 'repo' keys, or false if invalid.
     */
    public function parse_repo_url( $url ) {
        $url = $this->sanitize_repo_url( $url );

        if ( ! $url ) {
            return false;
        }

        // Remove .git suffix if present
        $url = preg_replace( '/\.git$/', '', $url );

        // Extract owner and repo
        if ( preg_match( '/github\.com\/([^\/]+)\/([^\/]+)$/i', $url, $matches ) ) {
            return array(
                'owner' => $matches[1],
                'repo'  => $matches[2],
            );
        }

        return false;
    }
}
