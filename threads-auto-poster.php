<?php
/**
 * Plugin Name: Threads Auto Poster
 * Plugin URI: https://extroverteddeveloper.com
 * Description: Automatically posts blog posts to Threads with character limit handling and URL shortening for long posts.
 * Version: 1.0.0
 * Author: ExtrovertedDeveloper
 * License: GPL v2 or later
 * Text Domain: threads-auto-poster
 */

if (!defined('ABSPATH')) {
    exit;
}

define('THREADS_AUTO_POSTER_VERSION', '1.0.0');
define('THREADS_AUTO_POSTER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('THREADS_AUTO_POSTER_PLUGIN_URL', plugin_dir_url(__FILE__));

class ThreadsAutoPoster {
    
    private static $instance = null;
    private $threads_character_limit = 500;
    
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
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('wp_ajax_threads_manual_post', array($this, 'handle_manual_post'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    public function activate() {
        add_option('threads_app_id', '');
        add_option('threads_app_secret', '');
        add_option('threads_user_id', '');
        add_option('bitly_access_token', '');
        add_option('threads_auto_post_enabled', '1');
    }
    
    public function deactivate() {
        // Clean up if needed
    }
    
    public function add_admin_menu() {
        add_options_page(
            'Threads Auto Poster Settings',
            'Threads Auto Poster',
            'manage_options',
            'threads-auto-poster',
            array($this, 'admin_page')
        );
    }
    
    public function admin_init() {
        register_setting('threads_auto_poster_settings', 'threads_app_id');
        register_setting('threads_auto_poster_settings', 'threads_app_secret');
        register_setting('threads_auto_poster_settings', 'threads_user_id');
        register_setting('threads_auto_poster_settings', 'bitly_access_token');
        register_setting('threads_auto_poster_settings', 'threads_auto_post_enabled');
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Threads Auto Poster Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('threads_auto_poster_settings');
                do_settings_sections('threads_auto_poster_settings');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Auto Posting</th>
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
                            <input type="text" name="threads_user_id" value="<?php echo esc_attr(get_option('threads_user_id')); ?>" class="regular-text" />
                            <p class="description">Your Threads user ID.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Bitly Access Token</th>
                        <td>
                            <input type="text" name="bitly_access_token" value="<?php echo esc_attr(get_option('bitly_access_token')); ?>" class="regular-text" />
                            <p class="description">Your Bitly API access token for URL shortening.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <h2>Manual Post to Threads</h2>
            <p>Select posts to manually post to Threads:</p>
            
            <?php $this->display_manual_post_section(); ?>
        </div>
        <?php
    }
    
    public function auto_post_to_threads($post_id, $post) {
        if (!get_option('threads_auto_post_enabled')) {
            return;
        }
        
        if ($post->post_type !== 'post' || $post->post_status !== 'publish') {
            return;
        }
        
        if (get_post_meta($post_id, '_threads_posted', true)) {
            return;
        }
        
        $this->post_to_threads($post);
    }
    
    public function post_to_threads($post) {
        $app_id = get_option('threads_app_id');
        $app_secret = get_option('threads_app_secret');
        $user_id = get_option('threads_user_id');
        
        if (empty($app_id) || empty($app_secret) || empty($user_id)) {
            error_log('Threads Auto Poster: Missing app credentials or user ID');
            return false;
        }
        
        $access_token = $this->get_access_token($app_id, $app_secret);
        if (!$access_token) {
            error_log('Threads Auto Poster: Failed to get access token');
            return false;
        }
        
        $post_content = $this->prepare_post_content($post);
        
        if (!$post_content) {
            error_log('Threads Auto Poster: Failed to prepare post content');
            return false;
        }
        
        $threads_post_data = array(
            'media_type' => 'TEXT',
            'text' => $post_content
        );
        
        $container_response = $this->create_threads_container($user_id, $threads_post_data, $access_token);
        
        if (!$container_response || !isset($container_response['id'])) {
            error_log('Threads Auto Poster: Failed to create container');
            return false;
        }
        
        $publish_response = $this->publish_threads_container($user_id, $container_response['id'], $access_token);
        
        if ($publish_response && isset($publish_response['id'])) {
            update_post_meta($post->ID, '_threads_posted', '1');
            update_post_meta($post->ID, '_threads_post_id', $publish_response['id']);
            return true;
        }
        
        return false;
    }
    
    private function prepare_post_content($post) {
        $title = $post->post_title;
        $content = wp_strip_all_tags($post->post_content);
        $post_url = get_permalink($post->ID);
        
        $full_text = $title . "\n\n" . $content;
        
        if (strlen($full_text) <= $this->threads_character_limit) {
            return $full_text;
        }
        
        $short_url = $this->shorten_url($post_url);
        if (!$short_url) {
            $short_url = $post_url;
        }
        
        $available_chars = $this->threads_character_limit - strlen($short_url) - 4;
        
        if (strlen($title) + 2 < $available_chars) {
            $remaining_chars = $available_chars - strlen($title) - 2;
            $truncated_content = substr($content, 0, $remaining_chars - 3) . '...';
            return $title . "\n\n" . $truncated_content . "\n\n" . $short_url;
        } else {
            $truncated_title = substr($title, 0, $available_chars - 3) . '...';
            return $truncated_title . "\n\n" . $short_url;
        }
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
            'method' => 'POST'
        );
        
        $response = wp_remote_post($api_url, $args);
        
        if (is_wp_error($response)) {
            error_log('Threads Auto Poster: Bitly API error - ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['link'])) {
            return $data['link'];
        }
        
        return false;
    }
    
    private function create_threads_container($user_id, $post_data, $access_token) {
        $api_url = "https://graph.threads.net/v1.0/{$user_id}/threads";
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($post_data),
            'method' => 'POST'
        );
        
        $response = wp_remote_post($api_url, $args);
        
        if (is_wp_error($response)) {
            error_log('Threads Auto Poster: API error - ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    private function publish_threads_container($user_id, $container_id, $access_token) {
        $api_url = "https://graph.threads.net/v1.0/{$user_id}/threads_publish";
        
        $data = array(
            'creation_id' => $container_id
        );
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($data),
            'method' => 'POST'
        );
        
        $response = wp_remote_post($api_url, $args);
        
        if (is_wp_error($response)) {
            error_log('Threads Auto Poster: Publish API error - ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    private function get_access_token($app_id, $app_secret) {
        $cached_token = get_transient('threads_access_token');
        if ($cached_token) {
            return $cached_token;
        }
        
        $api_url = 'https://graph.threads.net/oauth/access_token';
        
        $data = array(
            'client_id' => $app_id,
            'client_secret' => $app_secret,
            'grant_type' => 'client_credentials'
        );
        
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'body' => http_build_query($data),
            'method' => 'POST'
        );
        
        $response = wp_remote_post($api_url, $args);
        
        if (is_wp_error($response)) {
            error_log('Threads Auto Poster: OAuth error - ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $token_data = json_decode($body, true);
        
        if (isset($token_data['access_token'])) {
            $expires_in = isset($token_data['expires_in']) ? $token_data['expires_in'] : 3600;
            set_transient('threads_access_token', $token_data['access_token'], $expires_in - 300);
            return $token_data['access_token'];
        }
        
        error_log('Threads Auto Poster: Failed to get access token - ' . $body);
        return false;
    }
    
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_threads-auto-poster') {
            return;
        }
        
        wp_enqueue_script('threads-auto-poster-admin', THREADS_AUTO_POSTER_PLUGIN_URL . 'admin.js', array('jquery'), THREADS_AUTO_POSTER_VERSION, true);
        wp_localize_script('threads-auto-poster-admin', 'threads_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('threads_manual_post_nonce')
        ));
    }
    
    public function display_manual_post_section() {
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => 20,
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        if (empty($posts)) {
            echo '<p>No published posts found.</p>';
            return;
        }
        
        echo '<div id="threads-manual-posts">';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Post Title</th><th>Date</th><th>Status</th><th>Action</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($posts as $post) {
            $posted_status = get_post_meta($post->ID, '_threads_posted', true);
            $threads_post_id = get_post_meta($post->ID, '_threads_post_id', true);
            
            echo '<tr>';
            echo '<td><a href="' . get_edit_post_link($post->ID) . '">' . esc_html($post->post_title) . '</a></td>';
            echo '<td>' . get_the_date('Y-m-d H:i', $post) . '</td>';
            
            if ($posted_status) {
                echo '<td><span style="color: green;">✓ Posted</span>';
                if ($threads_post_id) {
                    echo '<br><small>ID: ' . esc_html($threads_post_id) . '</small>';
                }
                echo '</td>';
                echo '<td><button class="button threads-repost-btn" data-post-id="' . $post->ID . '" disabled>Re-post</button></td>';
            } else {
                echo '<td><span style="color: orange;">Not posted</span></td>';
                echo '<td><button class="button button-primary threads-post-btn" data-post-id="' . $post->ID . '">Post to Threads</button></td>';
            }
            
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '<div id="threads-post-results"></div>';
        echo '</div>';
    }
    
    public function handle_manual_post() {
        if (!wp_verify_nonce($_POST['nonce'], 'threads_manual_post_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $post_id = intval($_POST['post_id']);
        $post = get_post($post_id);
        
        if (!$post || $post->post_type !== 'post') {
            wp_send_json_error('Invalid post');
        }
        
        $result = $this->post_to_threads($post);
        
        if ($result) {
            wp_send_json_success('Post successfully shared to Threads!');
        } else {
            wp_send_json_error('Failed to post to Threads. Check error logs.');
        }
    }
}

ThreadsAutoPoster::get_instance();