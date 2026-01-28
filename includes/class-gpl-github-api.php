<?php
/**
 * GitHub API Class
 *
 * Handles all GitHub API interactions.
 *
 * @package Git_Plugin_Loader
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GPL_GitHub_API class for GitHub API operations
 */
class GPL_GitHub_API {

    /**
     * API base URL
     *
     * @var string
     */
    private $api_url = 'https://api.github.com';

    /**
     * Last error message
     *
     * @var string
     */
    private $last_error = '';

    /**
     * Last response headers
     *
     * @var array
     */
    private $last_headers = array();

    /**
     * Cache duration in seconds (15 minutes)
     *
     * @var int
     */
    private $cache_duration = 900;

    /**
     * Constructor
     */
    public function __construct() {
        // Nothing to initialize
    }

    /**
     * Make an API request
     *
     * @param string $endpoint API endpoint.
     * @param array  $args     Request arguments.
     * @param bool   $use_cache Whether to use cached response.
     * @return array|false
     */
    private function request( $endpoint, $args = array(), $use_cache = true ) {
        $url = $this->api_url . $endpoint;

        // Generate cache key
        $cache_key = 'gpl_api_' . md5( $url . wp_json_encode( $args ) );

        // Check cache first
        if ( $use_cache ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        // Set default headers
        $default_args = array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'Git-Plugin-Loader/' . GPL_VERSION,
            ),
            'timeout' => 30,
        );

        // Add authentication if token is available
        $token = $this->get_token();
        if ( $token ) {
            $default_args['headers']['Authorization'] = 'token ' . $token;
        }

        $args = wp_parse_args( $args, $default_args );

        // Make the request
        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->last_error = $response->get_error_message();
            return false;
        }

        $this->last_headers = wp_remote_retrieve_headers( $response );
        $body               = wp_remote_retrieve_body( $response );
        $status_code        = wp_remote_retrieve_response_code( $response );

        // Handle rate limiting
        if ( 403 === $status_code || 429 === $status_code ) {
            $remaining = isset( $this->last_headers['x-ratelimit-remaining'] ) ? (int) $this->last_headers['x-ratelimit-remaining'] : 0;
            if ( 0 === $remaining ) {
                $reset_time         = isset( $this->last_headers['x-ratelimit-reset'] ) ? (int) $this->last_headers['x-ratelimit-reset'] : 0;
                $this->last_error   = sprintf(
                    /* translators: %s: time when rate limit resets */
                    __( 'GitHub API rate limit exceeded. Resets at %s.', 'git-plugin-loader' ),
                    wp_date( 'H:i:s', $reset_time )
                );
                return false;
            }
        }

        // Handle errors
        if ( $status_code >= 400 ) {
            $decoded = json_decode( $body, true );
            if ( isset( $decoded['message'] ) ) {
                $this->last_error = $decoded['message'];
            } else {
                $this->last_error = sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'GitHub API error: HTTP %d', 'git-plugin-loader' ),
                    $status_code
                );
            }
            return false;
        }

        $data = json_decode( $body, true );

        if ( null === $data && ! empty( $body ) ) {
            $this->last_error = __( 'Failed to parse GitHub API response.', 'git-plugin-loader' );
            return false;
        }

        // Cache the response
        if ( $use_cache ) {
            set_transient( $cache_key, $data, $this->cache_duration );
        }

        return $data;
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
     * Get rate limit information
     *
     * @return array
     */
    public function get_rate_limit_info() {
        return array(
            'limit'     => isset( $this->last_headers['x-ratelimit-limit'] ) ? (int) $this->last_headers['x-ratelimit-limit'] : 0,
            'remaining' => isset( $this->last_headers['x-ratelimit-remaining'] ) ? (int) $this->last_headers['x-ratelimit-remaining'] : 0,
            'reset'     => isset( $this->last_headers['x-ratelimit-reset'] ) ? (int) $this->last_headers['x-ratelimit-reset'] : 0,
        );
    }

    /**
     * Get GitHub token
     *
     * @return string|false
     */
    private function get_token() {
        $settings = Git_Plugin_Loader::get_settings();
        $token    = isset( $settings['github_token'] ) ? $settings['github_token'] : '';

        if ( empty( $token ) ) {
            return false;
        }

        // Decrypt token if encrypted
        return $this->decrypt_token( $token );
    }

    /**
     * Encrypt a token for storage
     *
     * @param string $token Plain text token.
     * @return string Encrypted token.
     */
    public function encrypt_token( $token ) {
        if ( empty( $token ) ) {
            return '';
        }

        $key = $this->get_encryption_key();

        if ( function_exists( 'openssl_encrypt' ) ) {
            $iv        = openssl_random_pseudo_bytes( 16 );
            $encrypted = openssl_encrypt( $token, 'AES-256-CBC', $key, 0, $iv );
            return base64_encode( $iv . $encrypted );
        }

        // Fallback: simple obfuscation (not secure, but better than plain text)
        return base64_encode( $token );
    }

    /**
     * Decrypt a stored token
     *
     * @param string $encrypted_token Encrypted token.
     * @return string Plain text token.
     */
    private function decrypt_token( $encrypted_token ) {
        if ( empty( $encrypted_token ) ) {
            return '';
        }

        $key = $this->get_encryption_key();

        if ( function_exists( 'openssl_decrypt' ) ) {
            $decoded = base64_decode( $encrypted_token );
            if ( strlen( $decoded ) < 16 ) {
                // Likely not encrypted with openssl
                return base64_decode( $encrypted_token );
            }
            $iv        = substr( $decoded, 0, 16 );
            $encrypted = substr( $decoded, 16 );
            $decrypted = openssl_decrypt( $encrypted, 'AES-256-CBC', $key, 0, $iv );
            if ( false !== $decrypted ) {
                return $decrypted;
            }
        }

        // Fallback: simple decode
        return base64_decode( $encrypted_token );
    }

    /**
     * Get encryption key
     *
     * @return string
     */
    private function get_encryption_key() {
        if ( defined( 'AUTH_KEY' ) && ! empty( AUTH_KEY ) ) {
            return hash( 'sha256', AUTH_KEY );
        }
        return hash( 'sha256', 'git-plugin-loader-default-key' );
    }

    /**
     * Verify a repository exists and is accessible
     *
     * @param string $owner Repository owner.
     * @param string $repo  Repository name.
     * @return bool
     */
    public function verify_repo( $owner, $repo ) {
        $endpoint = "/repos/{$owner}/{$repo}";
        $result   = $this->request( $endpoint );

        return false !== $result;
    }

    /**
     * Get repository information
     *
     * @param string $owner Repository owner.
     * @param string $repo  Repository name.
     * @return array|false
     */
    public function get_repo( $owner, $repo ) {
        $endpoint = "/repos/{$owner}/{$repo}";
        return $this->request( $endpoint );
    }

    /**
     * Get repository branches
     *
     * @param string $owner    Repository owner.
     * @param string $repo     Repository name.
     * @param int    $per_page Results per page (max 100).
     * @return array|false
     */
    public function get_branches( $owner, $repo, $per_page = 100 ) {
        $endpoint = "/repos/{$owner}/{$repo}/branches?per_page={$per_page}";
        $result   = $this->request( $endpoint );

        if ( false === $result ) {
            return false;
        }

        // Extract branch names
        $branches = array();
        foreach ( $result as $branch ) {
            $branches[] = array(
                'name'   => $branch['name'],
                'commit' => $branch['commit']['sha'],
            );
        }

        return $branches;
    }

    /**
     * Get repository tags
     *
     * @param string $owner    Repository owner.
     * @param string $repo     Repository name.
     * @param int    $per_page Results per page (max 100).
     * @return array|false
     */
    public function get_tags( $owner, $repo, $per_page = 100 ) {
        $endpoint = "/repos/{$owner}/{$repo}/tags?per_page={$per_page}";
        $result   = $this->request( $endpoint );

        if ( false === $result ) {
            return false;
        }

        // Extract tag info
        $tags = array();
        foreach ( $result as $tag ) {
            $tags[] = array(
                'name'   => $tag['name'],
                'commit' => $tag['commit']['sha'],
            );
        }

        return $tags;
    }

    /**
     * Get commit information
     *
     * @param string $owner  Repository owner.
     * @param string $repo   Repository name.
     * @param string $commit Commit SHA.
     * @return array|false
     */
    public function get_commit( $owner, $repo, $commit ) {
        $endpoint = "/repos/{$owner}/{$repo}/commits/{$commit}";
        $result   = $this->request( $endpoint );

        if ( false === $result ) {
            return false;
        }

        return array(
            'sha'       => $result['sha'],
            'message'   => $result['commit']['message'],
            'author'    => $result['commit']['author']['name'],
            'email'     => $result['commit']['author']['email'],
            'date'      => $result['commit']['author']['date'],
            'timestamp' => strtotime( $result['commit']['author']['date'] ),
        );
    }

    /**
     * Get latest commit for a branch
     *
     * @param string $owner  Repository owner.
     * @param string $repo   Repository name.
     * @param string $branch Branch name.
     * @return array|false
     */
    public function get_latest_commit( $owner, $repo, $branch = 'main' ) {
        $endpoint = "/repos/{$owner}/{$repo}/commits/{$branch}";
        $result   = $this->request( $endpoint, array(), false ); // Don't cache

        if ( false === $result ) {
            return false;
        }

        return array(
            'sha'       => $result['sha'],
            'message'   => $result['commit']['message'],
            'author'    => $result['commit']['author']['name'],
            'email'     => $result['commit']['author']['email'],
            'date'      => $result['commit']['author']['date'],
            'timestamp' => strtotime( $result['commit']['author']['date'] ),
        );
    }

    /**
     * Get commits for a repository
     *
     * @param string $owner    Repository owner.
     * @param string $repo     Repository name.
     * @param string $branch   Branch name.
     * @param int    $per_page Results per page.
     * @return array|false
     */
    public function get_commits( $owner, $repo, $branch = 'main', $per_page = 10 ) {
        $endpoint = "/repos/{$owner}/{$repo}/commits?sha={$branch}&per_page={$per_page}";
        $result   = $this->request( $endpoint );

        if ( false === $result ) {
            return false;
        }

        $commits = array();
        foreach ( $result as $commit ) {
            $commits[] = array(
                'sha'       => $commit['sha'],
                'message'   => $commit['commit']['message'],
                'author'    => $commit['commit']['author']['name'],
                'date'      => $commit['commit']['author']['date'],
                'timestamp' => strtotime( $commit['commit']['author']['date'] ),
            );
        }

        return $commits;
    }

    /**
     * Compare two commits
     *
     * @param string $owner Repository owner.
     * @param string $repo  Repository name.
     * @param string $base  Base commit/branch.
     * @param string $head  Head commit/branch.
     * @return array|false
     */
    public function compare_commits( $owner, $repo, $base, $head ) {
        $endpoint = "/repos/{$owner}/{$repo}/compare/{$base}...{$head}";
        $result   = $this->request( $endpoint, array(), false ); // Don't cache

        if ( false === $result ) {
            return false;
        }

        return array(
            'status'        => $result['status'],
            'ahead_by'      => $result['ahead_by'],
            'behind_by'     => $result['behind_by'],
            'total_commits' => $result['total_commits'],
        );
    }

    /**
     * Get repository contents
     *
     * @param string $owner Repository owner.
     * @param string $repo  Repository name.
     * @param string $path  Path within repository.
     * @param string $ref   Branch/tag/commit reference.
     * @return array|false
     */
    public function get_contents( $owner, $repo, $path = '', $ref = null ) {
        $endpoint = "/repos/{$owner}/{$repo}/contents/{$path}";
        if ( $ref ) {
            $endpoint .= "?ref={$ref}";
        }

        return $this->request( $endpoint );
    }

    /**
     * Check if repository requires authentication
     *
     * @param string $owner Repository owner.
     * @param string $repo  Repository name.
     * @return bool
     */
    public function is_private_repo( $owner, $repo ) {
        $repo_data = $this->get_repo( $owner, $repo );

        if ( false === $repo_data ) {
            return true; // Assume private if we can't access it
        }

        return ! empty( $repo_data['private'] );
    }

    /**
     * Verify token is valid
     *
     * @param string $token Token to verify.
     * @return bool
     */
    public function verify_token( $token ) {
        $args = array(
            'headers' => array(
                'Accept'        => 'application/vnd.github.v3+json',
                'User-Agent'    => 'Git-Plugin-Loader/' . GPL_VERSION,
                'Authorization' => 'token ' . $token,
            ),
            'timeout' => 15,
        );

        $response = wp_remote_get( $this->api_url . '/user', $args );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        return 200 === $status_code;
    }

    /**
     * Clear API cache
     *
     * @param string $owner Optional owner to clear cache for.
     * @param string $repo  Optional repo to clear cache for.
     * @return void
     */
    public function clear_cache( $owner = null, $repo = null ) {
        global $wpdb;

        if ( $owner && $repo ) {
            // Clear specific repo cache
            $pattern = '%gpl_api_' . md5( $this->api_url . '/repos/' . $owner . '/' . $repo ) . '%';
        } else {
            // Clear all API cache
            $pattern = '%gpl_api_%';
        }

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . $pattern
            )
        );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_timeout_' . $pattern
            )
        );
    }
}
