<?php
/**
 * Plugin Name: WordPress to Threads & X
 * Plugin URI: https://extroverteddeveloper.com
 * Description: Automatically posts WordPress blog posts to Meta's Threads platform and X (Twitter) with intelligent character limit handling and URL shortening.
 * Version: 2.0.0
 * Author: ExtrovertedDeveloper
 * License: MIT
 * Text Domain: threads-auto-poster
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WORDPRESS_TO_THREADS_VERSION', '2.0.0');
define('WORDPRESS_TO_THREADS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WORDPRESS_TO_THREADS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load the autoshare-for-twitter library if available
if (file_exists(WP_PLUGIN_DIR . '/autoshare-for-twitter/vendor/autoload.php')) {
    require_once WP_PLUGIN_DIR . '/autoshare-for-twitter/vendor/autoload.php';
}

class ThreadsAutoPoster {

    private static $instance = null;
    private $threads_character_limit = 500;
    private $x_character_limit = 280;
    private $x_url_length = 23; // X counts all URLs as 23 characters
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        add_action('publish_post', array($this, 'auto_post_to_threads'), 10, 2);
        $this->handle_oauth_endpoints();
        $this->handle_x_oauth_endpoints();
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('wp_ajax_threads_store_publish_choice', array($this, 'handle_store_publish_choice'));
        add_action('wp_ajax_threads_retry_all_pending', array($this, 'handle_retry_all_pending'));
        add_action('wp_ajax_threads_retry_single_pending', array($this, 'handle_retry_single_pending'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('threads_refresh_token', array($this, 'refresh_access_token'));
        add_action('admin_notices', array($this, 'show_authorization_notices'));

        // X OAuth callback handlers using admin-post.php
        add_action('admin_post_x_oauth_callback', array($this, 'handle_x_oauth_callback'));
        add_action('admin_post_nopriv_x_oauth_callback', array($this, 'handle_x_oauth_callback'));

        // Scheduled posting cron hooks
        add_action('threads_scheduled_post_to_threads', array($this, 'scheduled_post_to_threads'), 10, 1);
        add_action('threads_scheduled_post_to_x', array($this, 'scheduled_post_to_x'), 10, 1);

        // Add Threads status column to posts list
        add_filter('manage_post_posts_columns', array($this, 'add_threads_status_column'));
        add_action('manage_post_posts_custom_column', array($this, 'display_threads_status_column'), 10, 2);

        // Add bulk actions for posting to Threads
        add_filter('bulk_actions-edit-post', array($this, 'add_bulk_actions'));
        add_filter('handle_bulk_actions-edit-post', array($this, 'handle_bulk_actions'), 10, 3);
        add_action('admin_notices', array($this, 'bulk_action_admin_notice'));

        // Ensure cron job is scheduled if we have tokens
        $this->ensure_token_refresh_scheduled();
    }
    
    public function activate() {
        add_option('threads_app_id', '');
        add_option('threads_app_secret', '');
        add_option('threads_user_id', '');
        add_option('threads_access_token', '');
        add_option('bitly_access_token', '');
        add_option('threads_auto_post_enabled', '1');
        add_option('threads_include_media', '1');
        add_option('threads_media_priority', 'featured');
        add_option('threads_token_expires', '');
        add_option('threads_enable_thread_chains', '1');
        add_option('threads_max_chain_length', '5');
        add_option('threads_split_preference', 'sentences');

        // X (Twitter) options
        add_option('x_auto_post_enabled', '0');
        add_option('x_api_key', '');
        add_option('x_api_secret', '');
        add_option('x_access_token', '');
        add_option('x_access_token_secret', '');
        add_option('x_include_media', '1');

        if (!wp_next_scheduled('threads_refresh_token')) {
            // Schedule every 12 hours (43200 seconds) starting now
            wp_schedule_event(time(), 'twicedaily', 'threads_refresh_token');
        }

        // Add custom 12-hour interval if it doesn't exist
        add_filter('cron_schedules', array($this, 'add_custom_cron_intervals'));
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        wp_clear_scheduled_hook('threads_refresh_token');
    }
    
    public function add_custom_cron_intervals($schedules) {
        $schedules['twelve_hours'] = array(
            'interval' => 30 * MINUTE_IN_SECONDS, // 30 minutes in seconds
            'display' => __('Every 30 Minutes')
        );
        return $schedules;
    }
    
    public function add_admin_menu() {
        add_options_page(
            'WordPress to Threads & X Settings',
            'Threads & X',
            'manage_options',
            'wordpress-to-threads',
            array($this, 'admin_page')
        );
    }
    
    public function admin_init() {
        register_setting('wordpress_to_threads_settings', 'threads_app_id');
        register_setting('wordpress_to_threads_settings', 'threads_app_secret');
        register_setting('wordpress_to_threads_settings', 'bitly_access_token');
        register_setting('wordpress_to_threads_settings', 'threads_auto_post_enabled');
        register_setting('wordpress_to_threads_settings', 'threads_include_media');
        register_setting('wordpress_to_threads_settings', 'threads_media_priority');
        register_setting('wordpress_to_threads_settings', 'threads_enable_thread_chains');
        register_setting('wordpress_to_threads_settings', 'threads_max_chain_length');
        register_setting('wordpress_to_threads_settings', 'threads_split_preference');

        // X (Twitter) settings
        register_setting('wordpress_to_threads_settings', 'x_auto_post_enabled');
        register_setting('wordpress_to_threads_settings', 'x_api_key');
        register_setting('wordpress_to_threads_settings', 'x_api_secret');
        register_setting('wordpress_to_threads_settings', 'x_include_media');

        // Note: x_access_token, x_access_token_secret, x_username, x_user_id
        // are NOT registered here because they're set via OAuth flow, not form submission

        // Bulk posting settings
        register_setting('wordpress_to_threads_settings', 'bulk_post_stagger_interval');
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>WordPress to Threads & X Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wordpress_to_threads_settings');
                do_settings_sections('wordpress_to_threads_settings');
                ?>

                <h2>Threads Settings</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Auto Posting to Threads</th>
                        <td>
                            <input type="checkbox" name="threads_auto_post_enabled" value="1" <?php checked(get_option('threads_auto_post_enabled'), '1'); ?> />
                            <p class="description">Enable automatic posting to Threads when a blog post is published.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Threads App ID</th>
                        <td>
                            <input type="text" name="threads_app_id" value="<?php echo esc_attr(get_option('threads_app_id')); ?>" class="regular-text" />
                            <p class="description">Your Threads App ID from Meta for Developers.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Threads App Secret</th>
                        <td>
                            <input type="password" name="threads_app_secret" value="<?php echo esc_attr(get_option('threads_app_secret')); ?>" class="regular-text" />
                            <p class="description">Your Threads App Secret from Meta for Developers.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Threads User ID</th>
                        <td>
                            <div style="padding: 6px 8px; background-color: #f1f1f1; border: 1px solid #ddd; border-radius: 3px; display: inline-block; min-width: 200px;"><?php echo esc_html(get_option('threads_user_id') ?: 'Not set - authorize to retrieve'); ?></div>
                            <p class="description">Your Threads user ID (retrieved automatically from OAuth).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">OAuth Authorization</th>
                        <td>
                            <?php
                            $access_token = get_option('threads_access_token');
                            $token_expires = get_option('threads_token_expires');
                            $app_id = get_option('threads_app_id');
                            $app_secret = get_option('threads_app_secret');
                            
                            if ($access_token) {
                                if (!empty($token_expires) && $this->is_token_expired()) {
                                    echo '<span style="color: orange;">⚠ Token expires soon - will auto-refresh</span><br>';
                                } else {
                                    echo '<span style="color: green;">✓ Authorized</span><br>';
                                }
                                echo '<a href="' . $this->get_deauthorize_url() . '" class="button">Deauthorize</a>';
                            } elseif (empty($app_id) || empty($app_secret)) {
                                echo '<span style="color: red;">⚠ Please save your App ID and App Secret first</span>';
                            } else {
                                // Check if we recently cleared expired credentials
                                if (!empty($token_expires) && time() > $token_expires) {
                                    echo '<span style="color: red;">⚠ Your token has expired. Please re-authorize.</span><br>';
                                }
                                echo '<a href="' . $this->get_authorize_url() . '" class="button button-primary">Authorize with Threads</a>';
                            }
                            ?>
                            <p class="description">Authorize this plugin to post to your Threads account.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Bitly Access Token (optional, but recommended)</th>
                        <td>
                            <input type="text" name="bitly_access_token" value="<?php echo esc_attr(get_option('bitly_access_token')); ?>" class="regular-text" />
                            <p class="description">Your Bitly API access token for URL shortening.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Include Media</th>
                        <td>
                            <input type="checkbox" name="threads_include_media" value="1" <?php checked(get_option('threads_include_media', '1'), '1'); ?> />
                            <p class="description">Include images and videos from posts when posting to Threads.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Media Priority</th>
                        <td>
                            <select name="threads_media_priority">
                                <option value="featured" <?php selected(get_option('threads_media_priority', 'featured'), 'featured'); ?>>Featured Image First</option>
                                <option value="content" <?php selected(get_option('threads_media_priority', 'featured'), 'content'); ?>>Content Images First</option>
                                <option value="video" <?php selected(get_option('threads_media_priority', 'featured'), 'video'); ?>>Videos First</option>
                            </select>
                            <p class="description">Choose which media to prioritize when multiple options are available.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Enable Thread Chains</th>
                        <td>
                            <input type="checkbox" name="threads_enable_thread_chains" value="1" <?php checked(get_option('threads_enable_thread_chains', '1'), '1'); ?> />
                            <p class="description">For posts longer than 500 characters, create a series of connected thread posts instead of truncating.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Maximum Thread Chain Length</th>
                        <td>
                            <select name="threads_max_chain_length">
                                <?php
                                $max_length = get_option('threads_max_chain_length', '5');
                                for ($i = 2; $i <= 10; $i++) {
                                    echo '<option value="' . $i . '"' . selected($max_length, $i, false) . '>' . $i . ' posts</option>';
                                }
                                ?>
                            </select>
                            <p class="description">Maximum number of posts in a thread chain (including the main post).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Content Splitting Method</th>
                        <td>
                            <select name="threads_split_preference">
                                <option value="sentences" <?php selected(get_option('threads_split_preference', 'sentences'), 'sentences'); ?>>Split at sentence boundaries</option>
                                <option value="paragraphs" <?php selected(get_option('threads_split_preference', 'sentences'), 'paragraphs'); ?>>Split at paragraph boundaries</option>
                                <option value="words" <?php selected(get_option('threads_split_preference', 'sentences'), 'words'); ?>>Split at word boundaries</option>
                            </select>
                            <p class="description">How to intelligently split long content into thread posts.</p>
                        </td>
                    </tr>
                </table>

                <h2>X (Twitter) Settings</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Auto Posting to X</th>
                        <td>
                            <input type="checkbox" name="x_auto_post_enabled" value="1" <?php checked(get_option('x_auto_post_enabled'), '1'); ?> />
                            <p class="description">Enable automatic posting to X (Twitter) when a blog post is published.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">X API Key (Consumer Key)</th>
                        <td>
                            <input type="text" name="x_api_key" value="<?php echo esc_attr(get_option('x_api_key')); ?>" class="regular-text" />
                            <p class="description">Your X API Key from <a href="https://developer.twitter.com/en/portal/projects-and-apps" target="_blank">X Developer Portal</a>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">X API Secret (Consumer Secret)</th>
                        <td>
                            <input type="password" name="x_api_secret" value="<?php echo esc_attr(get_option('x_api_secret')); ?>" class="regular-text" />
                            <p class="description">Your X API Secret from X Developer Portal.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">OAuth Authorization</th>
                        <td>
                            <?php
                            $x_access_token = get_option('x_access_token');
                            $x_api_key = get_option('x_api_key');
                            $x_api_secret = get_option('x_api_secret');

                            if ($x_access_token) {
                                $x_username = get_option('x_username');
                                echo '<span style="color: green;">✓ Authorized' . ($x_username ? ' as @' . esc_html($x_username) : '') . '</span><br>';
                                echo '<a href="' . $this->get_x_deauthorize_url() . '" class="button">Deauthorize</a>';
                            } elseif (empty($x_api_key) || empty($x_api_secret)) {
                                echo '<span style="color: red;">⚠ Please save your API Key and API Secret first</span>';
                            } else {
                                echo '<a href="' . $this->get_x_authorize_url() . '" class="button button-primary">Authorize with X</a>';
                            }
                            ?>
                            <p class="description">Authorize this plugin to post to your X account. You must save your API credentials first.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Include Media on X</th>
                        <td>
                            <input type="checkbox" name="x_include_media" value="1" <?php checked(get_option('x_include_media', '1'), '1'); ?> />
                            <p class="description">Include images from posts when posting to X (Twitter).</p>
                        </td>
                    </tr>
                </table>

                <h2>Bulk Posting Settings</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Stagger Bulk Posts</th>
                        <td>
                            <select name="bulk_post_stagger_interval">
                                <option value="0" <?php selected(get_option('bulk_post_stagger_interval', '0'), '0'); ?>>No delay (post immediately)</option>
                                <option value="1800" <?php selected(get_option('bulk_post_stagger_interval', '0'), '1800'); ?>>30 minutes apart</option>
                                <option value="3600" <?php selected(get_option('bulk_post_stagger_interval', '0'), '3600'); ?>>1 hour apart</option>
                                <option value="7200" <?php selected(get_option('bulk_post_stagger_interval', '0'), '7200'); ?>>2 hours apart</option>
                                <option value="14400" <?php selected(get_option('bulk_post_stagger_interval', '0'), '14400'); ?>>4 hours apart</option>
                                <option value="21600" <?php selected(get_option('bulk_post_stagger_interval', '0'), '21600'); ?>>6 hours apart</option>
                                <option value="43200" <?php selected(get_option('bulk_post_stagger_interval', '0'), '43200'); ?>>12 hours apart</option>
                                <option value="86400" <?php selected(get_option('bulk_post_stagger_interval', '0'), '86400'); ?>>24 hours apart</option>
                            </select>
                            <p class="description">When using bulk actions to post multiple posts, schedule them with this time interval between each post. Helps avoid rate limits and makes posting look more natural.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <?php $this->display_pending_posts_section(); ?>

            <h2>Manage Posts</h2>
            <p>To post or re-post content to Threads and/or X, go to the <a href="<?php echo admin_url('edit.php'); ?>">All Posts</a> page. You can use the bulk actions dropdown to post multiple posts at once to either or both platforms.</p>
        </div>
        <?php
    }
    
    public function auto_post_to_threads($post_id, $post) {
        if ($post->post_type !== 'post' || $post->post_status !== 'publish') {
            return;
        }

        $threads_enabled = get_option('threads_auto_post_enabled');
        $x_enabled = get_option('x_auto_post_enabled');

        // Check if user made a specific choice during publishing
        $publish_choice = get_post_meta($post_id, '_threads_publish_choice', true);

        if ($publish_choice === 'none') {
            // User chose not to post to social media
            return;
        }

        // Post to Threads if enabled and not already posted
        if ($threads_enabled && !get_post_meta($post_id, '_threads_posted', true)) {
            $result = false;
            if ($publish_choice === 'single') {
                // Force single post mode
                $result = $this->post_to_threads($post, 'single');
            } elseif ($publish_choice === 'chain') {
                // Force chain mode
                $result = $this->post_to_threads($post, 'chain');
            } else {
                // No choice stored, use default behavior
                $result = $this->post_to_threads($post);
            }

            // Handle posting failures
            if (!$result) {
                // Check if it's an authorization issue
                $access_token = get_option('threads_access_token');
                $user_id = get_option('threads_user_id');

                if (empty($access_token) || empty($user_id)) {
                    error_log('WordPress to Threads: Auto-posting failed due to missing authorization for post ID: ' . $post_id);
                    // Store this post as needing authorization
                    $this->store_pending_post($post_id, $publish_choice ?: 'auto');
                    // Set a transient to show admin notice
                    set_transient('threads_auth_needed_notice', true, DAY_IN_SECONDS);
                } else {
                    error_log('WordPress to Threads: Auto-posting failed for post ID: ' . $post_id . ' - not an authorization issue');
                }
            } else {
                // Clean up the choice after successful posting
                delete_post_meta($post_id, '_threads_publish_choice');
                // Remove from pending posts if it was there
                $this->remove_pending_post($post_id);
            }
        }

        // Post to X if enabled and not already posted
        if ($x_enabled && !get_post_meta($post_id, '_x_posted', true)) {
            $this->post_to_x($post);
        }
    }

    /**
     * X (Twitter) OAuth Flow Methods
     */
    public function handle_x_oauth_endpoints() {
        if (isset($_GET['x_oauth_action'])) {
            switch ($_GET['x_oauth_action']) {
                case 'authorize':
                    $this->initiate_x_oauth();
                    break;
                case 'deauthorize':
                    $this->handle_x_deauthorize();
                    break;
            }
        }
    }

    public function get_x_authorize_url() {
        return add_query_arg('x_oauth_action', 'authorize', admin_url('options-general.php?page=wordpress-to-threads'));
    }

    public function get_x_deauthorize_url() {
        return add_query_arg('x_oauth_action', 'deauthorize', admin_url('options-general.php?page=wordpress-to-threads'));
    }

    public function get_x_oauth_callback_url() {
        return admin_url('admin-post.php?action=x_oauth_callback');
    }

    private function initiate_x_oauth() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $api_key = get_option('x_api_key');
        $api_secret = get_option('x_api_secret');

        if (empty($api_key) || empty($api_secret)) {
            wp_die('Please configure your X API credentials first.');
        }

        if (!class_exists('Abraham\TwitterOAuth\TwitterOAuth')) {
            wp_die('TwitterOAuth library not found');
        }

        try {
            $connection = new \Abraham\TwitterOAuth\TwitterOAuth($api_key, $api_secret);
            $callback_url = $this->get_x_oauth_callback_url();

            // Request OAuth token
            $request_token = $connection->oauth('oauth/request_token', array('oauth_callback' => $callback_url));

            if (!isset($request_token['oauth_token']) || !isset($request_token['oauth_token_secret'])) {
                error_log('X OAuth Error: Failed to get request token - ' . print_r($request_token, true));
                wp_die('Failed to initiate X authorization. Please check your API credentials.');
            }

            // Store the request token temporarily
            set_transient('x_oauth_token', $request_token['oauth_token'], 10 * MINUTE_IN_SECONDS);
            set_transient('x_oauth_token_secret', $request_token['oauth_token_secret'], 10 * MINUTE_IN_SECONDS);

            // Redirect to X authorization page
            $authorize_url = $connection->url('oauth/authorize', array('oauth_token' => $request_token['oauth_token']));
            wp_redirect($authorize_url);
            exit;
        } catch (\Exception $e) {
            error_log('X OAuth Error: ' . $e->getMessage());
            wp_die('Failed to initiate X authorization: ' . $e->getMessage());
        }
    }

    public function handle_x_oauth_callback() {
        if (!isset($_GET['oauth_token']) || !isset($_GET['oauth_verifier'])) {
            wp_die('Invalid OAuth callback - missing parameters');
        }

        $oauth_token = sanitize_text_field($_GET['oauth_token']);
        $oauth_verifier = sanitize_text_field($_GET['oauth_verifier']);

        // Retrieve stored request token
        $stored_oauth_token = get_transient('x_oauth_token');
        $stored_oauth_token_secret = get_transient('x_oauth_token_secret');

        if ($oauth_token !== $stored_oauth_token || empty($stored_oauth_token_secret)) {
            error_log('X OAuth Error: Token mismatch. Received: ' . $oauth_token . ', Stored: ' . $stored_oauth_token);
            wp_die('OAuth token mismatch');
        }

        $api_key = get_option('x_api_key');
        $api_secret = get_option('x_api_secret');

        if (!class_exists('Abraham\TwitterOAuth\TwitterOAuth')) {
            wp_die('TwitterOAuth library not found');
        }

        try {
            $connection = new \Abraham\TwitterOAuth\TwitterOAuth(
                $api_key,
                $api_secret,
                $stored_oauth_token,
                $stored_oauth_token_secret
            );

            // Exchange for access token
            $access_token = $connection->oauth('oauth/access_token', array('oauth_verifier' => $oauth_verifier));

            if (!isset($access_token['oauth_token']) || !isset($access_token['oauth_token_secret'])) {
                error_log('X OAuth Error: Failed to get access token - ' . print_r($access_token, true));
                wp_die('Failed to complete X authorization');
            }

            // Save access token and secret
            update_option('x_access_token', $access_token['oauth_token']);
            update_option('x_access_token_secret', $access_token['oauth_token_secret']);

            // Save user info if available
            if (isset($access_token['screen_name'])) {
                update_option('x_username', $access_token['screen_name']);
            }
            if (isset($access_token['user_id'])) {
                update_option('x_user_id', $access_token['user_id']);
            }

            // Clean up transients
            delete_transient('x_oauth_token');
            delete_transient('x_oauth_token_secret');

            error_log('X OAuth: Successfully authorized as @' . $access_token['screen_name']);

            wp_redirect(admin_url('options-general.php?page=wordpress-to-threads&x_authorized=1'));
            exit;
        } catch (\Exception $e) {
            error_log('X OAuth Error: ' . $e->getMessage());
            wp_die('Failed to complete X authorization: ' . $e->getMessage());
        }
    }

    private function handle_x_deauthorize() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        delete_option('x_access_token');
        delete_option('x_access_token_secret');
        delete_option('x_username');
        delete_option('x_user_id');

        wp_redirect(admin_url('options-general.php?page=wordpress-to-threads&x_deauthorized=1'));
        exit;
    }

    public function post_to_threads($post, $force_mode = null) {
        $user_id = get_option('threads_user_id');
        
        if (empty($user_id)) {
            error_log('WordPress to Threads: Missing user ID. Please authorize the plugin.');
            return false;
        }
        
        // Ensure we have a valid access token (will refresh if needed)
        $access_token = $this->ensure_valid_token();
        if (empty($access_token)) {
            error_log('WordPress to Threads: Could not obtain valid access token. Please re-authorize the plugin.');
            return false;
        }
        
        // Check if user forced a specific posting mode
        if ($force_mode === 'chain') {
            error_log('WordPress to Threads: User forced thread chain mode');
            return $this->create_thread_chain($post);
        } elseif ($force_mode === 'single') {
            error_log('WordPress to Threads: User forced single post mode');
            // Skip the length check and go straight to single post
        } else {
            // Check if we should use thread chains for long content (default behavior)
            $enable_thread_chains = get_option('threads_enable_thread_chains', '1') === '1';
            $title = $post->post_title;
            $content = wp_strip_all_tags($post->post_content);
            $full_text = $title . "\n\n" . $content;
            
            if ($enable_thread_chains && strlen($full_text) > $this->threads_character_limit) {
                error_log('WordPress to Threads: Content exceeds character limit, using thread chain');
                return $this->create_thread_chain($post);
            }
        }
        
        // Use original single-post method for shorter content
        $post_content = $this->prepare_post_content($post);
        
        if (!$post_content) {
            error_log('WordPress to Threads: Failed to prepare post content');
            return false;
        }
        
        // Check for media in the post (if enabled)
        $media_items = array();
        if (get_option('threads_include_media', '1') === '1') {
            $media_items = $this->extract_post_images_and_videos($post);
        }

        $threads_post_data = array(
            'media_type' => 'TEXT',
            'text' => $post_content
        );

        $media_container_ids = array();
        $final_container_id = null; // This will hold the ID of the final container to publish (either text-only, single media, or carousel album)

        // If there are media items
        if (!empty($media_items)) {
            // Check if we have multiple media items (need carousel) or single media item
            if (count($media_items) > 1) {
                // Multiple media items: create individual containers first, then carousel
                foreach ($media_items as $media_item) {
                    $media_type = $media_item['type'];
                    $media_url = $media_item['url'];

                    $media_specific_data = array(
                        'media_type' => $media_type
                    );
                    if ($media_type === 'IMAGE') {
                        $media_specific_data['image_url'] = $media_url;
                    } elseif ($media_type === 'VIDEO') {
                        $media_specific_data['video_url'] = $media_url;
                    }

                    $container_response = $this->create_threads_container($user_id, $media_specific_data, $access_token);

                    if ($container_response && isset($container_response['id'])) {
                        $media_container_ids[] = $container_response['id'];
                        error_log('WordPress to Threads: Media container created. ID: ' . $container_response['id']);
                    } else {
                        error_log('WordPress to Threads: Failed to create media container. Response: ' . print_r($container_response, true));
                        // If any media container fails, clear all and fall back to text-only
                        $media_container_ids = array();
                        break;
                    }
                }

                // Create carousel album container with all media containers
                if (!empty($media_container_ids)) {
                    error_log('WordPress to Threads: Creating carousel album container with IDs: ' . implode(', ', $media_container_ids));
                    $carousel_container_data = array(
                        'media_type' => 'CAROUSEL',
                        'children' => $media_container_ids,
                        'text' => $post_content // Text for the entire carousel post
                    );
                    $container_response = $this->create_threads_container($user_id, $carousel_container_data, $access_token);
                    if ($container_response && isset($container_response['id'])) {
                        $final_container_id = $container_response['id'];
                    } else {
                        error_log('WordPress to Threads: Failed to create carousel album container. Response: ' . print_r($container_response, true));
                        // Fallback to text-only if carousel creation fails
                        $final_container_id = null;
                    }
                }
            } elseif (count($media_items) === 1) {
                // Single media item: create container with media and text directly
                $media_item = $media_items[0]; // Get the first (and only) media item
                $media_type = $media_item['type'];
                $media_url = $media_item['url'];

                $single_media_post_data = array(
                    'media_type' => $media_type,
                    'text' => $post_content
                );
                if ($media_type === 'IMAGE') {
                    $single_media_post_data['image_url'] = $media_url;
                } elseif ($media_type === 'VIDEO') {
                    $single_media_post_data['video_url'] = $media_url;
                }
                $container_response = $this->create_threads_container($user_id, $single_media_post_data, $access_token);
                if ($container_response && isset($container_response['id'])) {
                    $final_container_id = $container_response['id'];
                    error_log('WordPress to Threads: Single media container with text created. ID: ' . $final_container_id);
                } else {
                    error_log('WordPress to Threads: Failed to create single media container with text. Response: ' . print_r($container_response, true));
                    $final_container_id = null;
                }
            }
        }

        // If no media or media container creation failed, proceed with text-only post
        if (empty($final_container_id)) {
            error_log('WordPress to Threads: No valid media container, posting text-only content');
            $text_only_data = array(
                'media_type' => 'TEXT',
                'text' => $post_content
            );
            $container_response = $this->create_threads_container($user_id, $text_only_data, $access_token);
            if (!$container_response || !isset($container_response['id'])) {
                error_log('WordPress to Threads: Failed to create text-only container. Response: ' . print_r($container_response, true));
                return false;
            }
            $final_container_id = $container_response['id'];
        }

        if (!$final_container_id) {
            error_log('WordPress to Threads: No container ID available for publishing.');
            return false;
        }

        error_log('WordPress to Threads: Final container ID for publishing: ' . $final_container_id);

        // Check if this is a video post that might need processing time
        $has_video = false;
        if (!empty($media_items)) {
            foreach ($media_items as $media_item) {
                if ($media_item['type'] === 'VIDEO') {
                    $has_video = true;
                    break;
                }
            }
        }

        $publish_response = $this->publish_threads_container($user_id, $final_container_id, $access_token, $has_video);
        
        if (!$publish_response || !isset($publish_response['id'])) {
            error_log('WordPress to Threads: Failed to publish container. Response: ' . print_r($publish_response, true));
            return false;
        }
        
        error_log('WordPress to Threads: Post published successfully. ID: ' . $publish_response['id']);
        
        error_log('WordPress to Threads: Publish response: ' . print_r($publish_response, true));
        
        if ($publish_response && isset($publish_response['id'])) {
            error_log('WordPress to Threads: Post successful, updating meta for post ID: ' . $post->ID);
            update_post_meta($post->ID, '_threads_posted', '1');
            update_post_meta($post->ID, '_threads_post_id', $publish_response['id']);
            error_log('WordPress to Threads: Meta updated. Posted status: ' . get_post_meta($post->ID, '_threads_posted', true));
            return true;
        }
        
        error_log('WordPress to Threads: Publish failed or missing ID in response');
        return false;
    }
    
    private function prepare_post_content($post) {
        $title = $post->post_title;
        $content = wp_strip_all_tags($post->post_content);
        $post_url = get_permalink($post->ID);
        
        $full_text = $title . "\n\n" . $content;

        if (strlen($full_text) < $this->threads_character_limit) {
            return $full_text;
        }
        
        $bitly_token = get_option('bitly_access_token');
        $url_to_use = $post_url;
        
        if (!empty($bitly_token)) {
            $short_url = $this->shorten_url($post_url);
            if ($short_url) {
                $url_to_use = $short_url;
            }
        }
        
        $available_chars = $this->threads_character_limit - strlen($url_to_use) - 4;
        
        if (strlen($title) + 2 < $available_chars) {
            $remaining_chars = $available_chars - strlen($title) - 2;
            $truncated_content = substr($content, 0, $remaining_chars - 3) . '...';
            return $title . "\n\n" . $truncated_content . "\n\n" . $url_to_use;
        } else {
            $truncated_title = substr($title, 0, $available_chars - 3) . '...';
            return $truncated_title . "\n\n" . $url_to_use;
        }
    }
    
    private function split_content_for_thread_chain($post) {
        $title = $post->post_title;
        $content = wp_strip_all_tags($post->post_content);
        $post_url = get_permalink($post->ID);
        $split_preference = get_option('threads_split_preference', 'sentences');
        $max_chain_length = (int) get_option('threads_max_chain_length', 5);
        
        // Shorten URL if Bitly is available
        $bitly_token = get_option('bitly_access_token');
        $url_to_use = $post_url;
        if (!empty($bitly_token)) {
            $short_url = $this->shorten_url($post_url);
            if ($short_url) {
                $url_to_use = $short_url;
            }
        }
        
        // Full text with title and content
        $full_text = $title . "\n\n" . $content;
        
        // Calculate available characters for content (reserve space for URL at the end and thread indicators)
        $url_space = strlen("\n\n" . $url_to_use);
        $available_chars_last_post = $this->threads_character_limit - $url_space;
        $available_chars_regular = $this->threads_character_limit;
        
        $thread_parts = array();
        $remaining_text = $full_text;
        $part_count = 0;
        
        while (!empty($remaining_text) && $part_count < $max_chain_length) {
            $part_count++;
            $is_last_part = ($part_count == $max_chain_length);
            $char_limit = $is_last_part ? $available_chars_last_post : $available_chars_regular;
            
            if (strlen($remaining_text) <= $char_limit) {
                // Remaining text fits in this part
                $part_text = $remaining_text;
                if ($is_last_part || $part_count == 1) {
                    $part_text .= "\n\n" . $url_to_use;
                }
                $thread_parts[] = $part_text;
                break;
            }
            
            // Need to split the content
            $split_point = $this->find_split_point($remaining_text, $char_limit, $split_preference);
            
            if ($split_point === false) {
                // No good split point found, force split at character limit
                $split_point = $char_limit - 3; // Leave room for "..."
                $part_text = substr($remaining_text, 0, $split_point) . '...';
            } else {
                $part_text = substr($remaining_text, 0, $split_point);
            }
            
            // Add URL to the last part only
            if ($is_last_part) {
                $part_text .= "\n\n" . $url_to_use;
            }
            
            $thread_parts[] = trim($part_text);
            $remaining_text = trim(substr($remaining_text, $split_point));
        }
        
        // If we still have remaining text and hit the max chain length, append URL to last part
        if (!empty($remaining_text) && !strpos(end($thread_parts), $url_to_use)) {
            $last_part = array_pop($thread_parts);
            $last_part .= "\n\n" . $url_to_use;
            $thread_parts[] = $last_part;
        }
        
        return $thread_parts;
    }
    
    private function find_split_point($text, $max_length, $split_preference) {
        if (strlen($text) <= $max_length) {
            return strlen($text);
        }
        
        // Get the portion we're considering for splitting
        $search_text = substr($text, 0, $max_length);
        
        switch ($split_preference) {
            case 'sentences':
                // Look for sentence endings (. ! ?) followed by space or end of string
                if (preg_match('/.*[.!?](?=\s|$)/s', $search_text, $matches)) {
                    return strlen($matches[0]);
                }
                // Fall through to paragraph splitting if no sentence boundary found
                
            case 'paragraphs':
                // Look for double newlines (paragraph breaks)
                $last_paragraph = strrpos($search_text, "\n\n");
                if ($last_paragraph !== false && $last_paragraph > $max_length * 0.5) {
                    return $last_paragraph + 2; // Include the paragraph break
                }
                // Fall through to word splitting if no paragraph boundary found
                
            case 'words':
            default:
                // Look for last complete word
                $last_space = strrpos($search_text, ' ');
                if ($last_space !== false && $last_space > $max_length * 0.7) {
                    return $last_space + 1; // Include the space
                }
                break;
        }
        
        return false; // No good split point found
    }
    
    private function create_thread_chain($post) {
        $user_id = get_option('threads_user_id');
        
        if (empty($user_id)) {
            error_log('WordPress to Threads: Missing user ID for thread chain. Please authorize the plugin.');
            return false;
        }
        
        // Ensure we have a valid access token
        $access_token = $this->ensure_valid_token();
        if (empty($access_token)) {
            error_log('WordPress to Threads: Could not obtain valid access token for thread chain. Please re-authorize the plugin.');
            return false;
        }
        
        // Split content into thread parts
        $thread_parts = $this->split_content_for_thread_chain($post);
        
        if (empty($thread_parts)) {
            error_log('WordPress to Threads: No thread parts generated');
            return false;
        }
        
        error_log('WordPress to Threads: Creating thread chain with ' . count($thread_parts) . ' parts');
        
        
        $thread_post_ids = array();
        $main_post_id = null;
        $previous_post_id = null;
        
        // Process each part of the thread
        foreach ($thread_parts as $index => $part_content) {
            $is_first_post = ($index === 0);
            
            // Check for media in the first post only (if enabled)
            $media_items = array();
            if ($is_first_post && get_option('threads_include_media', '1') === '1') {
                $media_items = $this->extract_post_images_and_videos($post);
            }
            
            // Prepare the thread post data - add reply_to_id for non-first posts
            $thread_post_data = array(
                'media_type' => 'TEXT',
                'text' => $part_content
            );
            
            // For reply posts, add reply_to_id parameter (reply to previous post in chain)
            if (!$is_first_post && $previous_post_id) {
                $thread_post_data['reply_to_id'] = $previous_post_id;
            }
            
            $media_container_ids = array();
            $final_container_id = null;
            
            // Handle media for the first post only
            if ($is_first_post && !empty($media_items)) {
                // Check if we have multiple media items (need carousel) or single media item
                if (count($media_items) > 1) {
                    // Multiple media items: create individual containers first, then carousel
                    foreach ($media_items as $media_item) {
                        $media_type = $media_item['type'];
                        $media_url = $media_item['url'];
                        
                        $media_specific_data = array(
                            'media_type' => $media_type
                        );
                        if ($media_type === 'IMAGE') {
                            $media_specific_data['image_url'] = $media_url;
                        } elseif ($media_type === 'VIDEO') {
                            $media_specific_data['video_url'] = $media_url;
                        }
                        
                        $container_response = $this->create_threads_container($user_id, $media_specific_data, $access_token);
                        
                        if ($container_response && isset($container_response['id'])) {
                            $media_container_ids[] = $container_response['id'];
                            error_log('WordPress to Threads: Media container created for thread part ' . ($index + 1) . '. ID: ' . $container_response['id']);
                        } else {
                            error_log('WordPress to Threads: Failed to create media container for thread part ' . ($index + 1) . '. Response: ' . print_r($container_response, true));
                            $media_container_ids = array();
                            break;
                        }
                    }
                    
                    // Create carousel album container with all media containers
                    if (!empty($media_container_ids)) {
                        $carousel_container_data = array(
                            'media_type' => 'CAROUSEL',
                            'children' => $media_container_ids,
                            'text' => $part_content
                        );
                        $container_response = $this->create_threads_container($user_id, $carousel_container_data, $access_token);
                        if ($container_response && isset($container_response['id'])) {
                            $final_container_id = $container_response['id'];
                        }
                    }
                } elseif (count($media_items) === 1) {
                    // Single media item: create container with media and text directly
                    $media_item = $media_items[0];
                    $media_type = $media_item['type'];
                    $media_url = $media_item['url'];
                    
                    $single_media_post_data = array(
                        'media_type' => $media_type,
                        'text' => $part_content
                    );
                    if ($media_type === 'IMAGE') {
                        $single_media_post_data['image_url'] = $media_url;
                    } elseif ($media_type === 'VIDEO') {
                        $single_media_post_data['video_url'] = $media_url;
                    }
                    $container_response = $this->create_threads_container($user_id, $single_media_post_data, $access_token);
                    if ($container_response && isset($container_response['id'])) {
                        $final_container_id = $container_response['id'];
                    }
                }
            }
            
            // If no media or media failed, create text-only container
            if (empty($final_container_id)) {
                $container_response = $this->create_threads_container($user_id, $thread_post_data, $access_token);
                if (!$container_response || !isset($container_response['id'])) {
                    error_log('WordPress to Threads: Failed to create thread part ' . ($index + 1) . ' container. Response: ' . print_r($container_response, true));
                    return false;
                }
                $final_container_id = $container_response['id'];
            }
            
            error_log('WordPress to Threads: Thread part ' . ($index + 1) . ' container created. ID: ' . $final_container_id);
            
            // Publish the container (check for video in first post only)
            $has_video = ($is_first_post && !empty($media_items)) ? in_array('VIDEO', array_column($media_items, 'type')) : false;
            $publish_response = $this->publish_threads_container($user_id, $final_container_id, $access_token, $has_video);
            
            if (!$publish_response || !isset($publish_response['id'])) {
                error_log('WordPress to Threads: Failed to publish thread part ' . ($index + 1) . '. Response: ' . print_r($publish_response, true));
                return false;
            }
            
            $published_post_id = $publish_response['id'];
            $thread_post_ids[] = $published_post_id;
            
            // Store the main post ID and update previous post ID for sequential replies
            if ($is_first_post) {
                $main_post_id = $published_post_id;
            }
            $previous_post_id = $published_post_id;
            
            error_log('WordPress to Threads: Thread part ' . ($index + 1) . ' published successfully. ID: ' . $published_post_id . ($is_first_post ? ' (main post)' : ' (reply to previous post)'));
            
            // Add a small delay between posts to avoid rate limiting
            if ($index < count($thread_parts) - 1) {
                sleep(3); // Slightly longer delay to ensure main post is fully processed before creating replies
            }
        }
        
        // Store thread chain information in post meta
        update_post_meta($post->ID, '_threads_posted', '1');
        update_post_meta($post->ID, '_threads_post_id', $thread_post_ids[0]); // Main post ID
        update_post_meta($post->ID, '_threads_chain_ids', $thread_post_ids); // All post IDs
        update_post_meta($post->ID, '_threads_chain_count', count($thread_post_ids));
        
        error_log('WordPress to Threads: Sequential thread chain created successfully with ' . count($thread_post_ids) . ' posts');
        return true;
    }
    
    private function shorten_url($url) {
        $bitly_token = get_option('bitly_access_token');
        
        if (empty($bitly_token)) {
            return false;
        }
        
        $api_url = 'https://api-ssl.bitly.com/v4/shorten';
        
        $data = array(
            'long_url' => $url
        );
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $bitly_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($data),
            'method' => 'POST',
            'timeout' => 30
        );
        
        $response = wp_remote_post($api_url, $args);
        
        if (is_wp_error($response)) {
            error_log('WordPress to Threads: Bitly API error - ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['link'])) {
            return $data['link'];
        }
        
        return false;
    }
    
    private function extract_post_images_and_videos($post) {
        $media_priority = get_option('threads_media_priority', 'featured');
        
        $all_media = array();
        
        // Get featured image
        $thumbnail_id = get_post_thumbnail_id($post->ID);
        if ($thumbnail_id) {
            $image_url = wp_get_attachment_image_url($thumbnail_id, 'large');
            if ($image_url && $this->is_valid_image_url($image_url)) {
                $all_media['featured'] = array(
                    'type' => 'IMAGE',
                    'url' => $image_url,
                    'id' => $thumbnail_id,
                    'order' => -1 // Featured image has highest priority
                );
            }
        }
        
        // Get images from post content
        preg_match_all('/<img[^>]+src="([^"]+)"[^>]*>/i', $post->post_content, $matches, PREG_OFFSET_CAPTURE);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $index => $match) {
                $img_url = $match[0];
                $offset = $match[1];
                if ($this->is_valid_image_url($img_url)) {
                    $all_media['content_image_' . $index] = array(
                        'type' => 'IMAGE',
                        'url' => $img_url,
                        'id' => null,
                        'order' => $offset
                    );
                }
            }
        }
        
        // Get videos from shortcodes and HTML tags
        // For simplicity, we'll just get the first valid video for now, as Threads API only supports one video per post (or carousel item)
        $video_found = false;
        
        // From shortcodes
        if (has_shortcode($post->post_content, 'video')) {
            preg_match('/\[video[^\]]*src="([^"]+)"[^\]]*\]/i', $post->post_content, $video_match, PREG_OFFSET_CAPTURE);
            if (!empty($video_match[1]) && $this->is_valid_video_url($video_match[1][0])) {
                $all_media['video_shortcode'] = array(
                    'type' => 'VIDEO',
                    'url' => $video_match[1][0],
                    'id' => null,
                    'order' => $video_match[1][1]
                );
                $video_found = true;
            }
        }
        
        // From HTML tags (only if no video found from shortcode or if shortcode video is invalid)
        if (!$video_found) {
            preg_match('/<video[^>]+src="([^"]+)"[^>]*>/i', $post->post_content, $video_match, PREG_OFFSET_CAPTURE);
            if (!empty($video_match[1]) && $this->is_valid_video_url($video_match[1][0])) {
                $all_media['video_html'] = array(
                    'type' => 'VIDEO',
                    'url' => $video_match[1][0],
                    'id' => null,
                    'order' => $video_match[1][1]
                );
                $video_found = true;
            }
        }
        
        // Sort all media by their appearance order in the content, with featured image first
        uasort($all_media, function($a, $b) {
            if ($a['order'] == $b['order']) {
                return 0;
            }
            return ($a['order'] < $b['order']) ? -1 : 1;
        });
        
        $final_media_list = array();
        
        // Add featured image if it exists and priority is 'featured'
        if ($media_priority === 'featured' && isset($all_media['featured'])) {
            $final_media_list[] = $all_media['featured'];
            unset($all_media['featured']); // Remove to avoid duplication
        }
        
        // Add videos based on priority
        if ($media_priority === 'video') {
            foreach ($all_media as $key => $media_item) {
                if ($media_item['type'] === 'VIDEO') {
                    $final_media_list[] = $media_item;
                    unset($all_media[$key]);
                    break; // Only one video is supported per post/carousel item
                }
            }
        }
        
        // Add remaining images and videos in their sorted order
        foreach ($all_media as $media_item) {
            $final_media_list[] = $media_item;
        }
        
        // Ensure only one video is included in the final list, as Threads API only supports one video per post (or carousel item)
        $video_count = 0;
        $filtered_media_list = array();
        foreach ($final_media_list as $item) {
            if ($item['type'] === 'VIDEO') {
                if ($video_count === 0) {
                    $filtered_media_list[] = $item;
                    $video_count++;
                }
            } else {
                $filtered_media_list[] = $item;
            }
        }
        
        return $filtered_media_list;
    }
    
    private function is_valid_image_url($url) {
        $valid_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp');
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        
        // Check file extension
        if (!in_array($extension, $valid_extensions)) {
            return false;
        }
        
        // Ensure it's a publicly accessible URL
        if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
            return false;
        }
        
        return true;
    }
    
    private function is_valid_video_url($url) {
        $valid_extensions = array('mp4', 'mov', 'avi', 'webm');
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        
        // Check file extension
        if (!in_array($extension, $valid_extensions)) {
            return false;
        }
        
        // Ensure it's a publicly accessible URL
        if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
            return false;
        }
        
        return true;
    }
    
    private function create_threads_container($user_id, $post_data, $access_token) {
        $api_url = "https://graph.threads.net/v1.0/{$user_id}/threads";
        
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($post_data),
            'method' => 'POST',
            'timeout' => 30
        );
        
        $response = $this->make_authenticated_request($api_url, $args);
        
        if (!$response) {
            error_log('WordPress to Threads: Failed to create container - authentication failed');
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    private function publish_threads_container($user_id, $container_id, $access_token, $has_video = false) {
        $api_url = "https://graph.threads.net/v1.0/{$user_id}/threads_publish";
        
        $data = array(
            'creation_id' => $container_id
        );
        
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($data),
            'method' => 'POST',
            'timeout' => 30
        );
        
        // For video posts, add delay and retry logic
        $max_attempts = $has_video ? 3 : 1;
        $delay_seconds = $has_video ? 5 : 0;
        
        if ($has_video && $delay_seconds > 0) {
            error_log('WordPress to Threads: Video detected, waiting ' . $delay_seconds . ' seconds for processing...');
            sleep($delay_seconds);
        }
        
        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            error_log('WordPress to Threads: Publishing attempt ' . $attempt . ' of ' . $max_attempts);
            
            $response = $this->make_authenticated_request($api_url, $args);
            
            if (!$response) {
                error_log('WordPress to Threads: Failed to publish container - authentication failed on attempt ' . $attempt);
                if ($attempt === $max_attempts) {
                    return false;
                }
                continue;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $headers = wp_remote_retrieve_headers($response);
            
            error_log('WordPress to Threads: Publish HTTP response code: ' . $response_code . ' (attempt ' . $attempt . ')');
            error_log('WordPress to Threads: Publish raw response body: ' . $body);
            if ($attempt === 1) {
                error_log('WordPress to Threads: Publish response headers: ' . print_r($headers, true));
            }
            
            $decoded_response = json_decode($body, true);
            error_log('WordPress to Threads: Publish decoded response: ' . print_r($decoded_response, true));
            
            // Check if it's a media not found error (code 24, subcode 4279009)
            if ($response_code === 400 && 
                isset($decoded_response['error']['code']) && 
                $decoded_response['error']['code'] === 24 && 
                isset($decoded_response['error']['error_subcode']) && 
                $decoded_response['error']['error_subcode'] === 4279009) {
                
                if ($attempt < $max_attempts) {
                    $retry_delay = $attempt * 5; // Increasing delay: 5s, 10s
                    error_log('WordPress to Threads: Media not found error, retrying in ' . $retry_delay . ' seconds...');
                    sleep($retry_delay);
                    continue;
                } else {
                    error_log('WordPress to Threads: Media not found error persisted after all retry attempts');
                }
            }
            
            // For successful responses or non-retryable errors, return immediately
            return $decoded_response;
        }
        
        // This should never be reached, but just in case
        return false;
    }
    
    
    public function enqueue_admin_scripts($hook) {
        // Load CSS for posts list columns
        if ($hook === 'edit.php') {
            wp_enqueue_style('threads-x-admin-css', WORDPRESS_TO_THREADS_PLUGIN_URL . 'assets/css/admin.css', array(), WORDPRESS_TO_THREADS_VERSION);
        }

        // Load publish confirmation script on post edit pages
        if ($hook === 'post.php' || $hook === 'post-new.php') {
            wp_enqueue_script('wordpress-to-threads-publish', WORDPRESS_TO_THREADS_PLUGIN_URL . 'publish-confirm.js', array('jquery'), WORDPRESS_TO_THREADS_VERSION, true);
            wp_localize_script('wordpress-to-threads-publish', 'threads_publish_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('threads_publish_confirm_nonce'),
                'auto_post_enabled' => get_option('threads_auto_post_enabled', false)
            ));
        }
    }

    public function handle_store_publish_choice() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'threads_publish_confirm_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        if (!isset($_POST['post_id']) || !isset($_POST['choice'])) {
            wp_send_json_error('Missing required parameters');
            return;
        }
        
        $post_id = intval($_POST['post_id']);
        $choice = sanitize_text_field($_POST['choice']);
        
        if (!in_array($choice, ['single', 'chain', 'none'])) {
            wp_send_json_error('Invalid choice');
            return;
        }
        
        // Store the user's choice temporarily
        update_post_meta($post_id, '_threads_publish_choice', $choice);
        
        wp_send_json_success('Choice stored');
    }
    
    public function handle_oauth_endpoints() {
        if (isset($_GET['threads_oauth_action'])) {
            error_log('Threads OAuth Debug: Found threads_oauth_action = ' . $_GET['threads_oauth_action']);
            switch ($_GET['threads_oauth_action']) {
                case 'redirect':
                    error_log('Threads OAuth Debug: Calling handle_oauth_redirect');
                    $this->handle_oauth_redirect();
                    break;
                case 'deauthorize':
                    $this->handle_deauthorize();
                    break;
                case 'data_deletion':
                    $this->handle_data_deletion();
                    break;
                case 'debug':
                    $this->handle_debug_info();
                    break;
            }
        }
    }
    
    public function get_authorize_url() {
        $app_id = get_option('threads_app_id');
        
        if (empty($app_id)) {
            return '#';
        }
        
        $redirect_uri = $this->get_redirect_uri();
        $state = wp_create_nonce('threads_oauth_state');
        
        set_transient('threads_oauth_state', $state, 10 * MINUTE_IN_SECONDS); // 10 minutes
        
        $params = array(
            'client_id' => $app_id,
            'redirect_uri' => $redirect_uri,
            'scope' => 'threads_basic,threads_content_publish,threads_manage_replies',
            'response_type' => 'code',
            'state' => $state
        );
        
        return 'https://threads.net/oauth/authorize?' . http_build_query($params);
    }
    
    public function get_deauthorize_url() {
        return add_query_arg('threads_oauth_action', 'deauthorize', home_url());
    }
    
    public function get_redirect_uri() {
        return add_query_arg('threads_oauth_action', 'redirect', home_url());
    }
    
    public function handle_oauth_redirect() {
        error_log('Threads OAuth Debug: handle_oauth_redirect called');
        if (!isset($_GET['code']) || !isset($_GET['state'])) {
            error_log('Threads OAuth Debug: Missing code or state parameters');
            wp_die('Invalid OAuth response');
        }
        
        error_log('Threads OAuth Debug: Code and state parameters found');
        $state = sanitize_text_field($_GET['state']);
        $stored_state = get_transient('threads_oauth_state');
        
        error_log('Threads OAuth Debug: Received state = ' . $state);
        error_log('Threads OAuth Debug: Stored state = ' . ($stored_state ?: 'EMPTY'));
        
        if (!$stored_state || $state !== $stored_state) {
            error_log('Threads OAuth Debug: State validation failed');
            wp_die('Invalid OAuth state');
        }
        
        error_log('Threads OAuth Debug: State validation passed');
        delete_transient('threads_oauth_state');
        
        $code = sanitize_text_field($_GET['code']);
        error_log('Threads OAuth Debug: Exchanging code for short-term token');
        $short_term_token_data = $this->exchange_code_for_token($code);
        
        if ($short_term_token_data && isset($short_term_token_data['access_token'])) {
            error_log('Threads OAuth Debug: Got short-term token, exchanging for long-term token');
            $short_term_token = $short_term_token_data['access_token'];
            
            $long_term_token_data = $this->exchange_short_term_for_long_term_token($short_term_token);
            
            if ($long_term_token_data && isset($long_term_token_data['access_token'])) {
                error_log('Threads OAuth Debug: Got long-term token, updating options');
                $long_term_token = $long_term_token_data['access_token'];
                update_option('threads_access_token', $long_term_token);
                
                // Long-term tokens are valid for 60 days
                $expires_in = isset($long_term_token_data['expires_in']) ? $long_term_token_data['expires_in'] : 60 * DAY_IN_SECONDS; // Default 60 days
                $expires_at = time() + $expires_in;
                update_option('threads_token_expires', $expires_at);
                
                $user_data = $this->get_user_data($long_term_token);
                if ($user_data && isset($user_data['id'])) {
                    update_option('threads_user_id', $user_data['id']);
                    error_log('Threads OAuth Debug: Updated user ID');
                }
                
                // Retry any pending posts after successful authorization
                $this->retry_pending_posts();
                
                error_log('Threads OAuth Debug: Redirecting to settings with success');
                wp_redirect(admin_url('admin.php?page=wordpress-to-threads&authorized=1'));
            } else {
                error_log('Threads OAuth Debug: Long-term token exchange failed, redirecting with error');
                wp_redirect(admin_url('admin.php?page=wordpress-to-threads&error=1'));
            }
        } else {
            error_log('Threads OAuth Debug: Short-term token exchange failed, redirecting with error');
            wp_redirect(admin_url('admin.php?page=wordpress-to-threads&error=1'));
        }
        exit;
    }
    
    public function handle_deauthorize() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        delete_option('threads_access_token');
        delete_option('threads_user_id');
        delete_option('threads_token_expires');
        delete_transient('threads_access_token');
        
        wp_redirect(admin_url('options-general.php?page=wordpress-to-threads&deauthorized=1'));
        exit;
    }
    
    public function handle_data_deletion() {
        $signed_request = isset($_POST['signed_request']) ? $_POST['signed_request'] : '';
        
        if (empty($signed_request)) {
            http_response_code(400);
            echo json_encode(array('error' => 'Missing signed_request'));
            exit;
        }
        
        $data = $this->parse_signed_request($signed_request);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(array('error' => 'Invalid signed_request'));
            exit;
        }
        
        $user_id = isset($data['user_id']) ? $data['user_id'] : '';
        
        if ($user_id) {
            $this->delete_user_data($user_id);
        }
        
        $confirmation_code = 'threads_deletion_' . time() . '_' . wp_generate_password(8, false);
        $status_url = home_url('?threads_deletion_status=' . $confirmation_code);
        
        echo json_encode(array(
            'url' => $status_url,
            'confirmation_code' => $confirmation_code
        ));
        exit;
    }
    
    private function exchange_code_for_token($code) {
        $app_id = get_option('threads_app_id');
        $app_secret = get_option('threads_app_secret');
        $redirect_uri = $this->get_redirect_uri();
        
        // Debug logging
        error_log('Threads OAuth Debug: App ID = ' . ($app_id ? 'SET' : 'EMPTY'));
        error_log('Threads OAuth Debug: App Secret = ' . ($app_secret ? 'SET' : 'EMPTY'));
        error_log('Threads OAuth Debug: Redirect URI = ' . $redirect_uri);
        error_log('Threads OAuth Debug: Code = ' . ($code ? 'SET' : 'EMPTY'));
        
        if (empty($app_id) || empty($app_secret)) {
            error_log('Threads OAuth: Missing App ID or App Secret');
            return false;
        }
        
        $api_url = 'https://graph.threads.net/oauth/access_token';
        
        $data = array(
            'client_id' => $app_id,
            'client_secret' => $app_secret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirect_uri,
            'code' => $code
        );
        
        // Debug the request data
        error_log('Threads OAuth Debug: Request data = ' . print_r($data, true));
        
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'body' => http_build_query($data),
            'method' => 'POST',
            'timeout' => 30
        );
        
        // Debug the request body
        error_log('Threads OAuth Debug: Request body = ' . $args['body']);
        
        $response = wp_remote_post($api_url, $args);
        
        if (is_wp_error($response)) {
            error_log('Threads OAuth: Token exchange error - ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        error_log('Threads OAuth Debug: Response code = ' . $response_code);
        error_log('Threads OAuth Debug: Response body = ' . $body);
        
        $token_data = json_decode($body, true);
        
        if (isset($token_data['access_token'])) {
            return $token_data;
        }
        
        error_log('Threads OAuth: Failed to exchange code - ' . $body);
        return false;
    }
    
    private function exchange_short_term_for_long_term_token($short_term_token) {
        $app_secret = get_option('threads_app_secret');
        
        if (empty($app_secret)) {
            error_log('Threads OAuth: Missing App Secret for long-term token exchange');
            return false;
        }
        
        $api_url = 'https://graph.threads.net/access_token';
        
        $data = array(
            'grant_type' => 'th_exchange_token',
            'client_secret' => $app_secret,
            'access_token' => $short_term_token
        );
        
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'body' => http_build_query($data),
            'method' => 'GET',
            'timeout' => 30
        );
        
        error_log('Threads OAuth Debug: Exchanging short-term token for long-term token');
        $response = wp_remote_get($api_url . '?' . http_build_query($data), array('timeout' => 30));
        
        if (is_wp_error($response)) {
            error_log('Threads OAuth: Long-term token exchange error - ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        error_log('Threads OAuth Debug: Long-term token response code = ' . $response_code);
        error_log('Threads OAuth Debug: Long-term token response body = ' . $body);
        
        $token_data = json_decode($body, true);
        
        if ($response_code === 200 && isset($token_data['access_token'])) {
            return $token_data;
        }
        
        error_log('Threads OAuth: Failed to exchange short-term token for long-term token - ' . $body);
        return false;
    }
    
    private function get_user_data($access_token) {
        $api_url = 'https://graph.threads.net/v1.0/me?fields=id,username&access_token=' . $access_token;
        
        $response = wp_remote_get($api_url);
        
        if (is_wp_error($response)) {
            error_log('Threads OAuth: User data error - ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    public function refresh_access_token() {
        $current_token = get_option('threads_access_token');
        
        if (empty($current_token)) {
            error_log('WordPress to Threads: No access token to refresh via cron');
            return false;
        }
        
        $api_url = 'https://graph.threads.net/refresh_access_token?' . http_build_query(array(
            'grant_type' => 'th_refresh_token',
            'access_token' => $current_token
        ));
        
        $args = array(
            'timeout' => 30
        );
        
        error_log('WordPress to Threads: Long-term token refresh triggered');
        $response = wp_remote_get($api_url, $args);
        
        if (is_wp_error($response)) {
            error_log('WordPress to Threads: Long-term token refresh error - ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        error_log('WordPress to Threads: Long-term token refresh response code: ' . $response_code);
        error_log('WordPress to Threads: Long-term token refresh response body: ' . $body);
        
        $token_data = json_decode($body, true);
        
        if ($response_code === 200 && isset($token_data['access_token'])) {
            $new_token = $token_data['access_token'];
            // Long-term tokens are valid for 60 days when refreshed
            $expires_in = isset($token_data['expires_in']) ? $token_data['expires_in'] : 60 * DAY_IN_SECONDS; // Default 60 days
            
            update_option('threads_access_token', $new_token);
            update_option('threads_token_expires', time() + $expires_in);
            
            error_log('WordPress to Threads: Long-term token refresh successful, new token expires in ' . $expires_in . ' seconds');
            return $new_token;
        }
        
        // Check if token has expired completely and needs re-authorization
        if (isset($token_data['error'])) {
            $error = $token_data['error'];
            if (isset($error['type']) && $error['type'] === 'OAuthException' && 
                isset($error['code']) && $error['code'] == 190) {
                error_log('WordPress to Threads: Long-term access token has expired via cron, clearing credentials to force re-authorization');
                $this->clear_authentication_data();
            }
        }
        
        error_log('WordPress to Threads: Long-term token refresh failed - ' . $body);
        return false;
    }
    
    private function clear_authentication_data() {
        delete_option('threads_access_token');
        delete_option('threads_token_expires');
        // Keep threads_user_id - it doesn't change between authorizations
        error_log('WordPress to Threads: Expired token cleared, user ID preserved');
    }
    
    private function is_token_expired() {
        $expires_at = get_option('threads_token_expires');
        
        if (empty($expires_at)) {
            // No expiration data, assume expired for safety
            return true;
        }
        
        // Consider token expired if it expires within next 24 hours (since long-term tokens need >24h to refresh)
        $buffer_time = 24 * HOUR_IN_SECONDS; // 24 hours buffer
        return (time() + $buffer_time) >= $expires_at;
    }
    
    private function ensure_token_refresh_scheduled() {
        // Only schedule cron if we have valid tokens
        $access_token = get_option('threads_access_token');
        if (empty($access_token)) {
            return;
        }
        
        // Check if cron is already scheduled
        if (!wp_next_scheduled('threads_refresh_token')) {
            // Schedule every 12 hours (43200 seconds) starting now
            wp_schedule_event(time(), 'twicedaily', 'threads_refresh_token');
            error_log('WordPress to Threads: Token refresh cron job scheduled');
        }
    }
    
    private function ensure_valid_token() {
        // First check if we have any token at all
        $current_token = get_option('threads_access_token');
        if (empty($current_token)) {
            error_log('WordPress to Threads: No access token available');
            return false;
        }
        
        if ($this->is_token_expired()) {
            error_log('WordPress to Threads: Token is expired or expiring soon, attempting refresh');
            $new_token = $this->refresh_access_token();
            
            // If refresh failed and credentials were cleared, return false
            if (!$new_token && empty(get_option('threads_access_token'))) {
                error_log('WordPress to Threads: Token refresh failed and credentials cleared');
                return false;
            }
            
            return $new_token;
        }
        
        return $current_token;
    }
    
    private function make_authenticated_request($url, $args, $retry_count = 0) {
        $max_retries = 1;
        
        // Ensure we have a valid token before making the request
        $access_token = $this->ensure_valid_token();
        if (!$access_token) {
            error_log('WordPress to Threads: No valid access token available');
            return false;
        }
        
        // Update the authorization header with current token
        if (!isset($args['headers'])) {
            $args['headers'] = array();
        }
        $args['headers']['Authorization'] = 'Bearer ' . $access_token;
        
        error_log('WordPress to Threads: Making authenticated request to ' . $url . ' (attempt ' . ($retry_count + 1) . ')');
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            error_log('WordPress to Threads: Request error - ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Check for authentication errors
        if ($response_code === 401) {
            error_log('WordPress to Threads: Authentication error (HTTP ' . $response_code . '), response: ' . $body);
            
            // Parse response to check for specific OAuth error
            $error_data = json_decode($body, true);
            $is_oauth_190 = false;
            
            if (isset($error_data['error'])) {
                $error = $error_data['error'];
                if (isset($error['type']) && $error['type'] === 'OAuthException' && 
                    isset($error['code']) && $error['code'] == 190) {
                    $is_oauth_190 = true;
                    error_log('WordPress to Threads: Detected OAuthException with code 190 - token expired');
                }
            }
            
            if ($is_oauth_190 && $retry_count < $max_retries) {
                error_log('WordPress to Threads: Attempting token refresh for expired token');
                
                // Attempt token refresh with current (expired) token
                $new_token = $this->refresh_access_token();
                if ($new_token) {
                    error_log('WordPress to Threads: Token refresh successful, retrying request');
                    return $this->make_authenticated_request($url, $args, $retry_count + 1);
                } else {
                    error_log('WordPress to Threads: Token refresh failed, clearing authentication data');
                    $this->clear_authentication_data();
                }
            }
            
            error_log('WordPress to Threads: Authentication failed, exhausted retries');
            return false;
        }
        
        return $response;
    }
    
    private function parse_signed_request($signed_request) {
        $app_secret = get_option('threads_app_secret');
        
        list($encoded_signature, $payload) = explode('.', $signed_request, 2);
        
        $signature = base64_decode(strtr($encoded_signature, '-_', '+/'));
        $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        
        $expected_signature = hash_hmac('sha256', $payload, $app_secret, true);
        
        if (hash_equals($signature, $expected_signature)) {
            return $data;
        }
        
        return false;
    }
    
    private function delete_user_data($user_id) {
        global $wpdb;
        
        $wpdb->delete(
            $wpdb->options,
            array('option_name' => 'threads_user_id', 'option_value' => $user_id)
        );
        
        $wpdb->delete(
            $wpdb->postmeta,
            array('meta_key' => '_threads_posted', 'meta_value' => '1')
        );
        
        $wpdb->delete(
            $wpdb->postmeta,
            array('meta_key' => '_threads_post_id')
        );
        
        error_log('WordPress to Threads: Deleted data for user ' . $user_id);
    }
    
    private function store_pending_post($post_id, $mode) {
        $pending_posts = get_option('threads_pending_posts', array());
        $pending_posts[$post_id] = array(
            'mode' => $mode,
            'timestamp' => time(),
            'title' => get_the_title($post_id)
        );
        update_option('threads_pending_posts', $pending_posts);
        error_log('WordPress to Threads: Stored pending post ID: ' . $post_id . ' with mode: ' . $mode);
    }
    
    private function remove_pending_post($post_id) {
        $pending_posts = get_option('threads_pending_posts', array());
        if (isset($pending_posts[$post_id])) {
            unset($pending_posts[$post_id]);
            update_option('threads_pending_posts', $pending_posts);
            error_log('WordPress to Threads: Removed pending post ID: ' . $post_id);
        }
    }
    
    public function show_authorization_notices() {
        // Show notice if authorization is needed
        if (get_transient('threads_auth_needed_notice')) {
            $pending_posts = get_option('threads_pending_posts', array());
            $count = count($pending_posts);
            
            if ($count > 0) {
                echo '<div class="notice notice-warning is-dismissible">';
                echo '<p><strong>Threads Auto Poster:</strong> Failed to post ' . $count . ' blog post(s) to Threads due to missing authorization. ';
                echo '<a href="' . admin_url('admin.php?page=wordpress-to-threads') . '">Please re-authorize the plugin</a> to resume posting.</p>';
                echo '</div>';
            }
            
            delete_transient('threads_auth_needed_notice');
        }
    }
    
    public function retry_pending_posts() {
        $pending_posts = get_option('threads_pending_posts', array());
        
        if (empty($pending_posts)) {
            return;
        }
        
        error_log('WordPress to Threads: Retrying ' . count($pending_posts) . ' pending posts');
        
        foreach ($pending_posts as $post_id => $data) {
            $post = get_post($post_id);
            
            if (!$post || $post->post_status !== 'publish') {
                // Post no longer exists or isn't published, remove from pending
                $this->remove_pending_post($post_id);
                continue;
            }
            
            // Check if already posted (might have been posted manually)
            if (get_post_meta($post_id, '_threads_posted', true)) {
                $this->remove_pending_post($post_id);
                continue;
            }
            
            // Attempt to post
            $mode = $data['mode'];
            $result = false;
            
            if ($mode === 'single') {
                $result = $this->post_to_threads($post, 'single');
            } elseif ($mode === 'chain') {
                $result = $this->post_to_threads($post, 'chain');
            } else {
                $result = $this->post_to_threads($post);
            }
            
            if ($result) {
                $this->remove_pending_post($post_id);
                error_log('WordPress to Threads: Successfully posted pending post ID: ' . $post_id);
            } else {
                error_log('WordPress to Threads: Failed to post pending post ID: ' . $post_id);
            }
        }
    }
    
    public function display_pending_posts_section() {
        $pending_posts = get_option('threads_pending_posts', array());
        
        if (empty($pending_posts)) {
            return; // Don't show section if no pending posts
        }
        
        ?>
        <h2>Pending Posts</h2>
        <p>The following posts failed to post to Threads due to authorization issues:</p>
        
        <div id="pending-posts-results"></div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col">Post Title</th>
                    <th scope="col">Failed Date</th>
                    <th scope="col">Mode</th>
                    <th scope="col">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending_posts as $post_id => $data): ?>
                    <?php $post = get_post($post_id); ?>
                    <?php if ($post): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($post->post_title); ?></strong><br>
                            <small><a href="<?php echo get_edit_post_link($post_id); ?>">Edit</a> | 
                            <a href="<?php echo get_permalink($post_id); ?>" target="_blank">View</a></small>
                        </td>
                        <td><?php echo date('Y-m-d H:i:s', $data['timestamp']); ?></td>
                        <td>
                            <?php 
                            switch($data['mode']) {
                                case 'single': echo 'Single Post'; break;
                                case 'chain': echo 'Thread Chain'; break;
                                default: echo 'Auto'; break;
                            }
                            ?>
                        </td>
                        <td>
                            <button type="button" class="button threads-retry-btn" data-post-id="<?php echo $post_id; ?>">
                                Retry Now
                            </button>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <p>
            <button type="button" class="button button-primary" onclick="retryAllPendingPosts()">
                Retry All Pending Posts
            </button>
        </p>
        
        <script>
        function retryAllPendingPosts() {
            jQuery.ajax({
                url: threads_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'threads_retry_all_pending',
                    nonce: threads_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                },
                error: function() {
                    alert('Network error occurred');
                }
            });
        }
        
        jQuery(document).ready(function($) {
            $('.threads-retry-btn').on('click', function() {
                var button = $(this);
                var postId = button.data('post-id');
                
                button.prop('disabled', true).text('Retrying...');
                
                $.ajax({
                    url: threads_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'threads_retry_single_pending',
                        post_id: postId,
                        nonce: threads_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            button.prop('disabled', false).text('Retry Now');
                            alert('Error: ' + response.data);
                        }
                    },
                    error: function() {
                        button.prop('disabled', false).text('Retry Now');
                        alert('Network error occurred');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function handle_retry_all_pending() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'threads_manual_post_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $this->retry_pending_posts();
        wp_send_json_success('Pending posts retry attempted');
    }
    
    public function handle_retry_single_pending() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'threads_manual_post_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        if (!isset($_POST['post_id'])) {
            wp_send_json_error('No post ID provided');
            return;
        }
        
        $post_id = intval($_POST['post_id']);
        $pending_posts = get_option('threads_pending_posts', array());
        
        if (!isset($pending_posts[$post_id])) {
            wp_send_json_error('Post not found in pending list');
            return;
        }
        
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            $this->remove_pending_post($post_id);
            wp_send_json_error('Post no longer exists or is not published');
            return;
        }
        
        // Check if already posted
        if (get_post_meta($post_id, '_threads_posted', true)) {
            $this->remove_pending_post($post_id);
            wp_send_json_success('Post was already posted to Threads');
            return;
        }
        
        // Attempt to post
        $data = $pending_posts[$post_id];
        $mode = $data['mode'];
        $result = false;
        
        if ($mode === 'single') {
            $result = $this->post_to_threads($post, 'single');
        } elseif ($mode === 'chain') {
            $result = $this->post_to_threads($post, 'chain');
        } else {
            $result = $this->post_to_threads($post);
        }
        
        if ($result) {
            $this->remove_pending_post($post_id);
            wp_send_json_success('Post successfully shared to Threads');
        } else {
            wp_send_json_error('Failed to post to Threads. Check error logs for details.');
        }
    }

    public function handle_debug_info() {
        header('Content-Type: text/plain');
        echo "=== Threads OAuth Debug Info ===\n\n";
        
        echo "Site URL: " . home_url() . "\n";
        echo "Redirect URI: " . $this->get_redirect_uri() . "\n\n";
        
        echo "Configuration:\n";
        echo "- App ID: " . (get_option('threads_app_id') ? 'SET (length: ' . strlen(get_option('threads_app_id')) . ')' : 'NOT SET') . "\n";
        echo "- App Secret: " . (get_option('threads_app_secret') ? 'SET (length: ' . strlen(get_option('threads_app_secret')) . ')' : 'NOT SET') . "\n";
        echo "- Access Token: " . (get_option('threads_access_token') ? 'SET (length: ' . strlen(get_option('threads_access_token')) . ')' : 'NOT SET') . "\n";
        echo "- User ID: " . (get_option('threads_user_id') ? get_option('threads_user_id') : 'NOT SET') . "\n";
        
        $expires = get_option('threads_token_expires');
        if ($expires) {
            echo "- Token Expires: " . date('Y-m-d H:i:s', $expires) . " (" . ($expires > time() ? 'Valid' : 'EXPIRED') . ")\n";
        } else {
            echo "- Token Expires: NOT SET\n";
        }
        
        echo "\nOAuth State:\n";
        $oauth_state = get_transient('threads_oauth_state');
        echo "- Current State: " . ($oauth_state ? $oauth_state : 'NONE') . "\n";
        
        echo "\nTesting Callback URL:\n";
        $callback_url = $this->get_redirect_uri() . '&code=test&state=test';
        echo "- Callback URL: " . $callback_url . "\n";
        echo "- Test this URL to see if it's accessible\n";
        
        echo "\n=== End Debug Info ===\n";
        exit;
    }

    /**
     * Add Threads status column to posts list
     */
    public function add_threads_status_column($columns) {
        // Remove date column temporarily
        unset($columns['date']);

        // Add Threads status column
        $columns['is_threads_posted'] = sprintf(
            '<span class="threads-status-logo" title="%s"><span class="screen-reader-text">%s</span></span>',
            esc_attr__('Threads status', 'threads-auto-poster'),
            esc_html__('Posted to Threads status', 'threads-auto-poster')
        );

        // Add X status column
        $columns['is_x_posted'] = sprintf(
            '<span class="x-status-logo" title="%s"><span class="screen-reader-text">%s</span></span>',
            esc_attr__('X status', 'threads-auto-poster'),
            esc_html__('Posted to X status', 'threads-auto-poster')
        );

        // Add date column back
        $columns['date'] = esc_html__('Date', 'threads-auto-poster');

        return $columns;
    }

    /**
     * Display Threads and X status in posts list column
     */
    public function display_threads_status_column($column_name, $post_id) {
        if ('is_threads_posted' === $column_name) {
            $post_status = get_post_status($post_id);
            $threads_posted = get_post_meta($post_id, '_threads_posted', true);
            $threads_error = get_post_meta($post_id, '_threads_error', true);

            if ('publish' === $post_status && $threads_posted) {
                // Post has been successfully posted to Threads
                $threads_post_id = get_post_meta($post_id, '_threads_post_id', true);
                $title = __('Posted to Threads', 'threads-auto-poster');

                printf(
                    '<span class="threads-status-logo threads-status-logo--posted" title="%s"></span>',
                    esc_attr($title)
                );
            } elseif ('publish' === $post_status && $threads_error) {
                // Post failed to post to Threads
                printf(
                    '<span class="threads-status-logo threads-status-logo--error" title="%s"></span>',
                    esc_attr__('Failed to post to Threads', 'threads-auto-poster')
                );
            } else {
                // Post has not been posted to Threads
                printf(
                    '<span class="threads-status-logo threads-status-logo--disabled" title="%s"></span>',
                    esc_attr__('Has not been posted to Threads', 'threads-auto-poster')
                );
            }
        }

        if ('is_x_posted' === $column_name) {
            $post_status = get_post_status($post_id);
            $x_posted = get_post_meta($post_id, '_x_posted', true);

            if ('publish' === $post_status && $x_posted) {
                // Post has been successfully posted to X
                $x_post_id = get_post_meta($post_id, '_x_post_id', true);
                $title = __('Posted to X', 'threads-auto-poster');

                printf(
                    '<span class="x-status-logo x-status-logo--posted" title="%s"></span>',
                    esc_attr($title)
                );
            } else {
                // Post has not been posted to X
                printf(
                    '<span class="x-status-logo x-status-logo--disabled" title="%s"></span>',
                    esc_attr__('Has not been posted to X', 'threads-auto-poster')
                );
            }
        }
    }

    /**
     * Add custom bulk actions to posts list
     */
    public function add_bulk_actions($bulk_actions) {
        $bulk_actions['threads_post_to_threads'] = __('Post to Threads', 'threads-auto-poster');
        $bulk_actions['threads_post_to_x'] = __('Post to X', 'threads-auto-poster');
        $bulk_actions['threads_post_to_both'] = __('Post to Threads & X', 'threads-auto-poster');
        return $bulk_actions;
    }

    /**
     * Handle bulk action for posting to Threads and/or X
     */
    public function handle_bulk_actions($redirect_to, $action, $post_ids) {
        if (!in_array($action, array('threads_post_to_threads', 'threads_post_to_x', 'threads_post_to_both'))) {
            return $redirect_to;
        }

        $stagger_interval = (int) get_option('bulk_post_stagger_interval', 0);

        $threads_scheduled = 0;
        $threads_already_posted = 0;
        $x_scheduled = 0;
        $x_already_posted = 0;

        $schedule_time = time();
        $post_index = 0;

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);

            if (!$post || $post->post_status !== 'publish') {
                continue;
            }

            // Calculate scheduled time for this post (stagger each one)
            $scheduled_for = $schedule_time + ($post_index * $stagger_interval);

            // Handle Threads posting
            if ($action === 'threads_post_to_threads' || $action === 'threads_post_to_both') {
                if (get_post_meta($post_id, '_threads_posted', true)) {
                    $threads_already_posted++;
                } else {
                    // Schedule the post
                    wp_schedule_single_event($scheduled_for, 'threads_scheduled_post_to_threads', array($post_id));
                    $threads_scheduled++;
                    error_log('Scheduled Threads post for post ID ' . $post_id . ' at ' . date('Y-m-d H:i:s', $scheduled_for));
                }
            }

            // Handle X posting
            if ($action === 'threads_post_to_x' || $action === 'threads_post_to_both') {
                if (get_post_meta($post_id, '_x_posted', true)) {
                    $x_already_posted++;
                } else {
                    // Schedule the post
                    wp_schedule_single_event($scheduled_for, 'threads_scheduled_post_to_x', array($post_id));
                    $x_scheduled++;
                    error_log('Scheduled X post for post ID ' . $post_id . ' at ' . date('Y-m-d H:i:s', $scheduled_for));
                }
            }

            $post_index++;
        }

        // Add query args to redirect URL for notice
        $redirect_to = add_query_arg(array(
            'threads_bulk_scheduled' => $threads_scheduled,
            'threads_bulk_already_posted' => $threads_already_posted,
            'x_bulk_scheduled' => $x_scheduled,
            'x_bulk_already_posted' => $x_already_posted,
            'bulk_stagger_interval' => $stagger_interval
        ), $redirect_to);

        return $redirect_to;
    }

    /**
     * Display admin notice after bulk action
     */
    public function bulk_action_admin_notice() {
        if (!isset($_REQUEST['threads_bulk_scheduled']) && !isset($_REQUEST['x_bulk_scheduled'])) {
            return;
        }

        $messages = array();
        $stagger_interval = isset($_REQUEST['bulk_stagger_interval']) ? intval($_REQUEST['bulk_stagger_interval']) : 0;

        // Threads results
        if (isset($_REQUEST['threads_bulk_scheduled'])) {
            $threads_scheduled = intval($_REQUEST['threads_bulk_scheduled']);
            $threads_already_posted = intval($_REQUEST['threads_bulk_already_posted']);

            if ($threads_scheduled > 0) {
                if ($stagger_interval > 0) {
                    $interval_text = $this->format_time_interval($stagger_interval);
                    $messages[] = sprintf(
                        _n('%d post scheduled for Threads (staggered %s apart).', '%d posts scheduled for Threads (staggered %s apart).', $threads_scheduled, 'threads-auto-poster'),
                        $threads_scheduled,
                        $interval_text
                    );
                } else {
                    $messages[] = sprintf(
                        _n('%d post scheduled for Threads.', '%d posts scheduled for Threads.', $threads_scheduled, 'threads-auto-poster'),
                        $threads_scheduled
                    );
                }
            }

            if ($threads_already_posted > 0) {
                $messages[] = sprintf(
                    _n('%d post was already posted to Threads.', '%d posts were already posted to Threads.', $threads_already_posted, 'threads-auto-poster'),
                    $threads_already_posted
                );
            }
        }

        // X results
        if (isset($_REQUEST['x_bulk_scheduled'])) {
            $x_scheduled = intval($_REQUEST['x_bulk_scheduled']);
            $x_already_posted = intval($_REQUEST['x_bulk_already_posted']);

            if ($x_scheduled > 0) {
                if ($stagger_interval > 0) {
                    $interval_text = $this->format_time_interval($stagger_interval);
                    $messages[] = sprintf(
                        _n('%d post scheduled for X (staggered %s apart).', '%d posts scheduled for X (staggered %s apart).', $x_scheduled, 'threads-auto-poster'),
                        $x_scheduled,
                        $interval_text
                    );
                } else {
                    $messages[] = sprintf(
                        _n('%d post scheduled for X.', '%d posts scheduled for X.', $x_scheduled, 'threads-auto-poster'),
                        $x_scheduled
                    );
                }
            }

            if ($x_already_posted > 0) {
                $messages[] = sprintf(
                    _n('%d post was already posted to X.', '%d posts were already posted to X.', $x_already_posted, 'threads-auto-poster'),
                    $x_already_posted
                );
            }
        }

        if (!empty($messages)) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                implode(' ', array_map('esc_html', $messages))
            );
        }
    }

    /**
     * Format time interval in human-readable format
     */
    private function format_time_interval($seconds) {
        if ($seconds >= 86400) {
            $hours = $seconds / 3600;
            return sprintf(_n('%d hour', '%d hours', $hours, 'threads-auto-poster'), $hours);
        } elseif ($seconds >= 3600) {
            $hours = $seconds / 3600;
            return sprintf(_n('%d hour', '%d hours', $hours, 'threads-auto-poster'), $hours);
        } else {
            $minutes = $seconds / 60;
            return sprintf(_n('%d minute', '%d minutes', $minutes, 'threads-auto-poster'), $minutes);
        }
    }

    /**
     * Scheduled post to Threads (cron callback)
     */
    public function scheduled_post_to_threads($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            error_log('Scheduled Threads post failed: Post ' . $post_id . ' not found');
            return;
        }

        error_log('Executing scheduled Threads post for post ID ' . $post_id);
        $result = $this->post_to_threads($post);

        if ($result) {
            error_log('Scheduled Threads post successful for post ID ' . $post_id);
        } else {
            error_log('Scheduled Threads post failed for post ID ' . $post_id);
        }
    }

    /**
     * Scheduled post to X (cron callback)
     */
    public function scheduled_post_to_x($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            error_log('Scheduled X post failed: Post ' . $post_id . ' not found');
            return;
        }

        error_log('Executing scheduled X post for post ID ' . $post_id);
        $result = $this->post_to_x($post);

        if ($result) {
            error_log('Scheduled X post successful for post ID ' . $post_id);
        } else {
            error_log('Scheduled X post failed for post ID ' . $post_id);
        }
    }

    /**
     * Post to X (Twitter)
     */
    private function post_to_x($post) {
        $api_key = get_option('x_api_key');
        $api_secret = get_option('x_api_secret');
        $access_token = get_option('x_access_token');
        $access_token_secret = get_option('x_access_token_secret');

        if (empty($api_key) || empty($api_secret) || empty($access_token) || empty($access_token_secret)) {
            error_log('WordPress to X: Missing API credentials');
            return false;
        }

        if (!class_exists('Abraham\TwitterOAuth\TwitterOAuth')) {
            error_log('WordPress to X: TwitterOAuth library not found');
            return false;
        }

        try {
            $connection = new \Abraham\TwitterOAuth\TwitterOAuth(
                $api_key,
                $api_secret,
                $access_token,
                $access_token_secret
            );

            $connection->setTimeouts(10, 30);
            $connection->setApiVersion('2');

            // Prepare content for X using the same intelligent logic
            $post_content = $this->prepare_post_content_for_x($post);

            if (!$post_content) {
                error_log('WordPress to X: Failed to prepare post content');
                return false;
            }

            $update_data = array(
                'text' => $post_content
            );

            // Handle media if enabled
            $include_media = get_option('x_include_media', '1') === '1';
            if ($include_media && has_post_thumbnail($post->ID)) {
                $media_id = $this->upload_media_to_x($post, $connection);
                if ($media_id) {
                    $update_data['media'] = array(
                        'media_ids' => array((string) $media_id)
                    );
                }
            }

            // Send tweet
            $response = $connection->post('tweets', $update_data, true);

            // Twitter API V2 wraps response in data
            if (isset($response->data)) {
                $response = $response->data;
            }

            if (isset($response->id)) {
                error_log('WordPress to X: Post successful. Tweet ID: ' . $response->id);
                update_post_meta($post->ID, '_x_posted', '1');
                update_post_meta($post->ID, '_x_post_id', $response->id);
                return true;
            } else {
                error_log('WordPress to X: Failed to post. Response: ' . print_r($response, true));
                return false;
            }
        } catch (\Exception $e) {
            error_log('WordPress to X: Exception occurred: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Prepare post content for X (280 character limit)
     * Reuses the intelligent content management logic from Threads
     */
    private function prepare_post_content_for_x($post) {
        $title = $post->post_title;
        $content = wp_strip_all_tags($post->post_content);
        $post_url = get_permalink($post->ID);

        $full_text = $title . "\n\n" . $content;
        $full_post = $full_text . "\n\n" . $post_url;

        // First check: does the entire post fit within 280 characters as-is?
        if (strlen($full_post) <= $this->x_character_limit) {
            return $full_post;
        }

        // Post is too long, need to account for URL shortening and truncation
        $bitly_token = get_option('bitly_access_token');
        $url_to_use = $post_url;

        if (!empty($bitly_token)) {
            $short_url = $this->shorten_url($post_url);
            if ($short_url) {
                $url_to_use = $short_url;
            }
        }

        // X counts all URLs as 23 characters regardless of actual length
        $url_counted_length = $this->x_url_length;

        // Calculate available characters (280 - URL - spacing)
        $available_chars = $this->x_character_limit - $url_counted_length - 2; // 2 for "\n\n" spacing

        if (strlen($full_text) <= $available_chars) {
            return $full_text . "\n\n" . $url_to_use;
        }

        // Content is too long, need to truncate intelligently
        if (strlen($title) + 2 < $available_chars) {
            $remaining_chars = $available_chars - strlen($title) - 2;
            $truncated_content = substr($content, 0, $remaining_chars - 3) . '...';
            return $title . "\n\n" . $truncated_content . "\n\n" . $url_to_use;
        } else {
            $truncated_title = substr($title, 0, $available_chars - 3) . '...';
            return $truncated_title . "\n\n" . $url_to_use;
        }
    }

    /**
     * Upload media to X
     */
    private function upload_media_to_x($post, $connection) {
        if (!has_post_thumbnail($post->ID)) {
            return null;
        }

        $attachment_id = get_post_thumbnail_id($post->ID);
        $file_path = get_attached_file($attachment_id);

        if (!$file_path || !file_exists($file_path)) {
            return null;
        }

        // Check file size (max 5MB for images on X)
        $file_size = filesize($file_path);
        if ($file_size > 5000000) {
            error_log('WordPress to X: Image too large (' . $file_size . ' bytes)');
            return null;
        }

        try {
            $connection->setApiVersion('1.1');
            $response = $connection->upload('media/upload', array('media' => $file_path));

            if (isset($response->media_id)) {
                error_log('WordPress to X: Media uploaded. ID: ' . $response->media_id);
                return $response->media_id;
            } else {
                error_log('WordPress to X: Failed to upload media. Response: ' . print_r($response, true));
                return null;
            }
        } catch (\Exception $e) {
            error_log('WordPress to X: Media upload exception: ' . $e->getMessage());
            return null;
        }
    }
}

ThreadsAutoPoster::get_instance();