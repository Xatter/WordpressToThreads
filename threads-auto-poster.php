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
        $this->handle_oauth_endpoints();
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
        flush_rewrite_rules();
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
        register_setting('threads_auto_poster_settings', 'threads_access_token');
        register_setting('threads_auto_poster_settings', 'bitly_access_token');
        register_setting('threads_auto_poster_settings', 'threads_auto_post_enabled');
        register_setting('threads_auto_poster_settings', 'threads_include_media');
        register_setting('threads_auto_poster_settings', 'threads_media_priority');
        register_setting('threads_auto_poster_settings', 'threads_token_expires');
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
                        <th scope="row">Bitly Access Token</th>
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
        $user_id = get_option('threads_user_id');
        
        if (empty($user_id)) {
            error_log('Threads Auto Poster: Missing user ID. Please authorize the plugin.');
            return false;
        }
        
        // Ensure we have a valid access token (will refresh if needed)
        $access_token = $this->ensure_valid_token();
        if (empty($access_token)) {
            error_log('Threads Auto Poster: Could not obtain valid access token. Please re-authorize the plugin.');
            return false;
        }
        
        $post_content = $this->prepare_post_content($post);
        
        if (!$post_content) {
            error_log('Threads Auto Poster: Failed to prepare post content');
            return false;
        }
        
        // Check for media in the post (if enabled)
        $media_info = null;
        if (get_option('threads_include_media', '1') === '1') {
            $media_info = $this->extract_post_media($post);
        }
        
        if ($media_info && !empty($media_info['url'])) {
            $threads_post_data = array(
                'media_type' => $media_info['type'],
                'text' => $post_content
            );
            
            if ($media_info['type'] === 'IMAGE') {
                $threads_post_data['image_url'] = $media_info['url'];
            } elseif ($media_info['type'] === 'VIDEO') {
                $threads_post_data['video_url'] = $media_info['url'];
            }
            
            error_log('Threads Auto Poster: Posting with media - Type: ' . $media_info['type'] . ', URL: ' . $media_info['url']);
        } else {
            $threads_post_data = array(
                'media_type' => 'TEXT',
                'text' => $post_content
            );
            
            error_log('Threads Auto Poster: Posting text-only content');
        }
        
        $container_response = $this->create_threads_container($user_id, $threads_post_data, $access_token);
        
        if (!$container_response || !isset($container_response['id'])) {
            error_log('Threads Auto Poster: Failed to create container. Response: ' . print_r($container_response, true));
            return false;
        }
        
        error_log('Threads Auto Poster: Container created successfully. ID: ' . $container_response['id']);
        
        $publish_response = $this->publish_threads_container($user_id, $container_response['id'], $access_token);
        
        error_log('Threads Auto Poster: Publish response: ' . print_r($publish_response, true));
        
        if ($publish_response && isset($publish_response['id'])) {
            error_log('Threads Auto Poster: Post successful, updating meta for post ID: ' . $post->ID);
            update_post_meta($post->ID, '_threads_posted', '1');
            update_post_meta($post->ID, '_threads_post_id', $publish_response['id']);
            error_log('Threads Auto Poster: Meta updated. Posted status: ' . get_post_meta($post->ID, '_threads_posted', true));
            return true;
        }
        
        error_log('Threads Auto Poster: Publish failed or missing ID in response');
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
            'method' => 'POST',
            'timeout' => 30
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
    
    private function extract_post_media($post) {
        $media_priority = get_option('threads_media_priority', 'featured');
        
        // Collect all available media
        $featured_image = null;
        $content_images = array();
        $videos = array();
        
        // Get featured image
        $thumbnail_id = get_post_thumbnail_id($post->ID);
        if ($thumbnail_id) {
            $image_url = wp_get_attachment_image_url($thumbnail_id, 'large');
            if ($image_url && $this->is_valid_image_url($image_url)) {
                $featured_image = array(
                    'type' => 'IMAGE',
                    'url' => $image_url,
                    'id' => $thumbnail_id
                );
            }
        }
        
        // Get images from post content
        preg_match_all('/<img[^>]+src="([^"]+)"[^>]*>/i', $post->post_content, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $img_url) {
                if ($this->is_valid_image_url($img_url)) {
                    $content_images[] = array(
                        'type' => 'IMAGE',
                        'url' => $img_url,
                        'id' => null
                    );
                }
            }
        }
        
        // Get videos from shortcodes
        if (has_shortcode($post->post_content, 'video')) {
            preg_match('/\[video[^\]]*src="([^"]+)"[^\]]*\]/i', $post->post_content, $video_match);
            if (!empty($video_match[1]) && $this->is_valid_video_url($video_match[1])) {
                $videos[] = array(
                    'type' => 'VIDEO',
                    'url' => $video_match[1],
                    'id' => null
                );
            }
        }
        
        // Get videos from HTML tags
        preg_match_all('/<video[^>]+src="([^"]+)"[^>]*>/i', $post->post_content, $video_matches);
        if (!empty($video_matches[1])) {
            foreach ($video_matches[1] as $video_url) {
                if ($this->is_valid_video_url($video_url)) {
                    $videos[] = array(
                        'type' => 'VIDEO',
                        'url' => $video_url,
                        'id' => null
                    );
                }
            }
        }
        
        // Return media based on priority
        switch ($media_priority) {
            case 'video':
                if (!empty($videos)) return $videos[0];
                if ($featured_image) return $featured_image;
                if (!empty($content_images)) return $content_images[0];
                break;
                
            case 'content':
                if (!empty($content_images)) return $content_images[0];
                if ($featured_image) return $featured_image;
                if (!empty($videos)) return $videos[0];
                break;
                
            case 'featured':
            default:
                if ($featured_image) return $featured_image;
                if (!empty($content_images)) return $content_images[0];
                if (!empty($videos)) return $videos[0];
                break;
        }
        
        return null;
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
            error_log('Threads Auto Poster: Failed to create container - authentication failed');
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
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($data),
            'method' => 'POST',
            'timeout' => 30
        );
        
        $response = $this->make_authenticated_request($api_url, $args);
        
        if (!$response) {
            error_log('Threads Auto Poster: Failed to publish container - authentication failed');
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $headers = wp_remote_retrieve_headers($response);
        
        error_log('Threads Auto Poster: Publish HTTP response code: ' . $response_code);
        error_log('Threads Auto Poster: Publish raw response body: ' . $body);
        error_log('Threads Auto Poster: Publish response headers: ' . print_r($headers, true));
        
        $decoded_response = json_decode($body, true);
        error_log('Threads Auto Poster: Publish decoded response: ' . print_r($decoded_response, true));
        
        return $decoded_response;
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
            $display_title = $post->post_title;
            if (empty($display_title)) {
                $content = wp_strip_all_tags($post->post_content);
                if (strlen($content) > 20) {
                    $display_title = substr($content, 0, 20) . '...';
                } else {
                    $display_title = $content;
                }
            }
            echo '<td><a href="' . get_edit_post_link($post->ID) . '">' . esc_html($display_title) . '</a></td>';
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
        error_log('Threads Auto Poster: handle_manual_post called');
        error_log('Threads Auto Poster: POST data: ' . print_r($_POST, true));
        
        if (!isset($_POST['nonce'])) {
            error_log('Threads Auto Poster: No nonce provided');
            wp_send_json_error('No security token provided');
            return;
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'threads_manual_post_nonce')) {
            error_log('Threads Auto Poster: Nonce verification failed');
            wp_send_json_error('Security check failed');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            error_log('Threads Auto Poster: User lacks permissions');
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        if (!isset($_POST['post_id'])) {
            error_log('Threads Auto Poster: No post_id provided');
            wp_send_json_error('No post ID provided');
            return;
        }
        
        $post_id = intval($_POST['post_id']);
        error_log('Threads Auto Poster: Processing post ID: ' . $post_id);
        
        $post = get_post($post_id);
        
        if (!$post || $post->post_type !== 'post') {
            error_log('Threads Auto Poster: Invalid post or post type');
            wp_send_json_error('Invalid post');
            return;
        }
        
        // Check if already posted
        if (get_post_meta($post_id, '_threads_posted', true)) {
            error_log('Threads Auto Poster: Post already shared to Threads');
            wp_send_json_error('This post has already been shared to Threads');
            return;
        }
        
        // Check authentication
        $access_token = get_option('threads_access_token');
        $user_id = get_option('threads_user_id');
        
        if (empty($access_token) || empty($user_id)) {
            error_log('Threads Auto Poster: Missing authentication credentials');
            wp_send_json_error('Plugin not properly authorized. Please re-authorize in settings.');
            return;
        }
        
        error_log('Threads Auto Poster: Attempting to post to Threads');
        $result = $this->post_to_threads($post);
        
        if ($result) {
            error_log('Threads Auto Poster: Successfully posted to Threads');
            wp_send_json_success('Post successfully shared to Threads!');
        } else {
            error_log('Threads Auto Poster: Failed to post to Threads');
            wp_send_json_error('Failed to post to Threads. Check error logs for details.');
        }
    }
    
    public function handle_oauth_endpoints() {
        error_log('Threads OAuth Debug: handle_oauth_endpoints called. GET params: ' . print_r($_GET, true));
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
            }
        } else {
            error_log('Threads OAuth Debug: No threads_oauth_action found in GET params');
        }
    }
    
    public function get_authorize_url() {
        $app_id = get_option('threads_app_id');
        
        if (empty($app_id)) {
            return '#';
        }
        
        $redirect_uri = $this->get_redirect_uri();
        $state = wp_create_nonce('threads_oauth_state');
        
        set_transient('threads_oauth_state', $state, 600); // 10 minutes
        
        $params = array(
            'client_id' => $app_id,
            'redirect_uri' => $redirect_uri,
            'scope' => 'threads_basic,threads_content_publish',
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
        error_log('Threads OAuth Debug: Exchanging code for token');
        $access_token = $this->exchange_code_for_token($code);
        
        if ($access_token) {
            error_log('Threads OAuth Debug: Got access token, updating options');
            update_option('threads_access_token', $access_token);
            
            // Set expiration to 60 days from now (long-lived token)
            $expires_at = time() + (60 * 24 * 60 * 60); // 60 days in seconds
            update_option('threads_token_expires', $expires_at);
            
            $user_data = $this->get_user_data($access_token);
            if ($user_data && isset($user_data['id'])) {
                update_option('threads_user_id', $user_data['id']);
                error_log('Threads OAuth Debug: Updated user ID');
            }
            
            error_log('Threads OAuth Debug: Redirecting to settings with success');
            wp_redirect(admin_url('options-general.php?page=threads-auto-poster&authorized=1'));
        } else {
            error_log('Threads OAuth Debug: Token exchange failed, redirecting with error');
            wp_redirect(admin_url('options-general.php?page=threads-auto-poster&error=1'));
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
        
        wp_redirect(admin_url('options-general.php?page=threads-auto-poster&deauthorized=1'));
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
            return $token_data['access_token'];
        }
        
        error_log('Threads OAuth: Failed to exchange code - ' . $body);
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
    
    private function refresh_access_token() {
        $current_token = get_option('threads_access_token');
        
        if (empty($current_token)) {
            error_log('Threads Auto Poster: No access token to refresh');
            return false;
        }
        
        $api_url = 'https://graph.threads.net/refresh_access_token?' . http_build_query(array(
            'grant_type' => 'th_refresh_token',
            'access_token' => $current_token
        ));
        
        $args = array(
            'timeout' => 30
        );
        
        error_log('Threads Auto Poster: Attempting to refresh access token');
        $response = wp_remote_get($api_url, $args);
        
        if (is_wp_error($response)) {
            error_log('Threads Auto Poster: Token refresh error - ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        error_log('Threads Auto Poster: Token refresh response code: ' . $response_code);
        error_log('Threads Auto Poster: Token refresh response body: ' . $body);
        
        $token_data = json_decode($body, true);
        
        if ($response_code === 200 && isset($token_data['access_token'])) {
            $new_token = $token_data['access_token'];
            $expires_in = isset($token_data['expires_in']) ? $token_data['expires_in'] : (60 * 24 * 60 * 60); // Default 60 days
            
            update_option('threads_access_token', $new_token);
            update_option('threads_token_expires', time() + $expires_in);
            
            error_log('Threads Auto Poster: Token refreshed successfully');
            return $new_token;
        }
        
        // Check if token has expired completely and needs re-authorization
        if (isset($token_data['error']['code']) && $token_data['error']['code'] == 190) {
            error_log('Threads Auto Poster: Access token has expired, clearing credentials to force re-authorization');
            $this->clear_authentication_data();
        }
        
        error_log('Threads Auto Poster: Token refresh failed - ' . $body);
        return false;
    }
    
    private function clear_authentication_data() {
        delete_option('threads_access_token');
        delete_option('threads_token_expires');
        // Keep threads_user_id - it doesn't change between authorizations
        error_log('Threads Auto Poster: Expired token cleared, user ID preserved');
    }
    
    private function is_token_expired() {
        $expires_at = get_option('threads_token_expires');
        
        if (empty($expires_at)) {
            // No expiration data, assume expired for safety
            return true;
        }
        
        // Consider token expired if it expires within next 24 hours
        $buffer_time = 24 * 60 * 60; // 24 hours in seconds
        return (time() + $buffer_time) >= $expires_at;
    }
    
    private function ensure_valid_token() {
        // First check if we have any token at all
        $current_token = get_option('threads_access_token');
        if (empty($current_token)) {
            error_log('Threads Auto Poster: No access token available');
            return false;
        }
        
        if ($this->is_token_expired()) {
            error_log('Threads Auto Poster: Token is expired or expiring soon, attempting refresh');
            $new_token = $this->refresh_access_token();
            
            // If refresh failed and credentials were cleared, return false
            if (!$new_token && empty(get_option('threads_access_token'))) {
                error_log('Threads Auto Poster: Token refresh failed and credentials cleared');
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
            error_log('Threads Auto Poster: No valid access token available');
            return false;
        }
        
        // Update the authorization header with current token
        if (!isset($args['headers'])) {
            $args['headers'] = array();
        }
        $args['headers']['Authorization'] = 'Bearer ' . $access_token;
        
        error_log('Threads Auto Poster: Making authenticated request to ' . $url . ' (attempt ' . ($retry_count + 1) . ')');
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            error_log('Threads Auto Poster: Request error - ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Check for authentication errors
        if ($response_code === 401 || $response_code === 403) {
            error_log('Threads Auto Poster: Authentication error (HTTP ' . $response_code . '), response: ' . $body);
            
            if ($retry_count < $max_retries) {
                error_log('Threads Auto Poster: Attempting token refresh and retry');
                
                // Force token refresh
                $new_token = $this->refresh_access_token();
                if ($new_token) {
                    // Retry with new token
                    return $this->make_authenticated_request($url, $args, $retry_count + 1);
                }
            }
            
            error_log('Threads Auto Poster: Authentication failed, exhausted retries');
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
        
        error_log('Threads Auto Poster: Deleted data for user ' . $user_id);
    }
}

ThreadsAutoPoster::get_instance();