<?php
/**
 * Plugin Name: WordPress to Threads
 * Plugin URI: https://extroverteddeveloper.com
 * Description: Automatically posts WordPress blog posts to Meta's Threads platform with character limit handling and URL shortening.
 * Version: 1.0.0
 * Author: ExtrovertedDeveloper
 * License: MIT
 * Text Domain: threads-auto-poster
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WORDPRESS_TO_THREADS_VERSION', '1.0.0');
define('WORDPRESS_TO_THREADS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WORDPRESS_TO_THREADS_PLUGIN_URL', plugin_dir_url(__FILE__));

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
        $this->handle_oauth_endpoints();
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('wp_ajax_threads_manual_post', array($this, 'handle_manual_post'));
        add_action('wp_ajax_threads_repost', array($this, 'handle_repost'));
        add_action('wp_ajax_threads_store_publish_choice', array($this, 'handle_store_publish_choice'));
        add_action('wp_ajax_threads_retry_all_pending', array($this, 'handle_retry_all_pending'));
        add_action('wp_ajax_threads_retry_single_pending', array($this, 'handle_retry_single_pending'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('threads_refresh_token', array($this, 'refresh_access_token'));
        add_action('admin_notices', array($this, 'show_authorization_notices'));
        
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
        add_menu_page(
            'WordPress to Threads Settings',
            'Threads',
            'manage_options',
            'wordpress-to-threads',
            array($this, 'admin_page'),
            'dashicons-share',
            30
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
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>WordPress to Threads Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wordpress_to_threads_settings');
                do_settings_sections('wordpress_to_threads_settings');
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
                <?php submit_button(); ?>
            </form>
            
            <?php $this->display_pending_posts_section(); ?>
            
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
        
        // Check if user made a specific choice during publishing
        $publish_choice = get_post_meta($post_id, '_threads_publish_choice', true);
        
        if ($publish_choice === 'none') {
            // User chose not to post to Threads
            return;
        }
        
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
        
        if (strlen($full_text) <= $this->threads_character_limit) {
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
        // Load admin script on settings page
        if ($hook === 'toplevel_page_wordpress-to-threads') {
            wp_enqueue_script('wordpress-to-threads-admin', WORDPRESS_TO_THREADS_PLUGIN_URL . 'admin.js', array('jquery'), WORDPRESS_TO_THREADS_VERSION, true);
            wp_localize_script('wordpress-to-threads-admin', 'threads_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('threads_manual_post_nonce')
            ));
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
    
    public function display_manual_post_section() {
        $current_page = isset($_GET['manual_posts_page']) ? max(1, intval($_GET['manual_posts_page'])) : 1;
        $posts_per_page = 10;
        $offset = ($current_page - 1) * $posts_per_page;
        
        $total_posts = wp_count_posts('post')->publish;
        
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => $posts_per_page,
            'offset' => $offset,
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
            $chain_ids = get_post_meta($post->ID, '_threads_chain_ids', true);
            $chain_count = get_post_meta($post->ID, '_threads_chain_count', true);
            
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
                if ($chain_count && $chain_count > 1) {
                    echo '<br><small>Thread chain (' . esc_html($chain_count) . ' posts)</small>';
                    echo '<br><small>Main ID: ' . esc_html($threads_post_id) . '</small>';
                } elseif ($threads_post_id) {
                    echo '<br><small>ID: ' . esc_html($threads_post_id) . '</small>';
                }
                echo '</td>';
                echo '<td><button class="button threads-repost-btn" data-post-id="' . $post->ID . '">Re-post</button></td>';
            } else {
                echo '<td><span style="color: orange;">Not posted</span></td>';
                echo '<td><button class="button button-primary threads-post-btn" data-post-id="' . $post->ID . '">Post to Threads</button></td>';
            }
            
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        
        $total_pages = ceil($total_posts / $posts_per_page);
        
        if ($total_pages > 1) {
            echo '<div class="threads-pagination" style="margin-top: 20px; text-align: center;">';
            
            $pagination_args = array(
                'page' => 'wordpress-to-threads'
            );
            
            for ($i = 1; $i <= $total_pages; $i++) {
                $pagination_args['manual_posts_page'] = $i;
                $url = admin_url('options-general.php?' . http_build_query($pagination_args));
                
                if ($i == $current_page) {
                    echo '<span class="button button-primary" style="margin: 0 2px;">' . $i . '</span>';
                } else {
                    echo '<a href="' . esc_url($url) . '" class="button" style="margin: 0 2px;">' . $i . '</a>';
                }
            }
            
            echo '</div>';
            echo '<p style="text-align: center; margin-top: 10px;">Page ' . $current_page . ' of ' . $total_pages . ' (' . $total_posts . ' total posts)</p>';
        }
        
        echo '<div id="threads-post-results"></div>';
        echo '</div>';
    }
    
    public function handle_manual_post() {
        error_log('WordPress to Threads: handle_manual_post called');
        error_log('WordPress to Threads: POST data: ' . print_r($_POST, true));
        
        if (!isset($_POST['nonce'])) {
            error_log('WordPress to Threads: No nonce provided');
            wp_send_json_error('No security token provided');
            return;
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'threads_manual_post_nonce')) {
            error_log('WordPress to Threads: Nonce verification failed');
            wp_send_json_error('Security check failed');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            error_log('WordPress to Threads: User lacks permissions');
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        if (!isset($_POST['post_id'])) {
            error_log('WordPress to Threads: No post_id provided');
            wp_send_json_error('No post ID provided');
            return;
        }
        
        $post_id = intval($_POST['post_id']);
        error_log('WordPress to Threads: Processing post ID: ' . $post_id);
        
        $post = get_post($post_id);
        
        if (!$post || $post->post_type !== 'post') {
            error_log('WordPress to Threads: Invalid post or post type');
            wp_send_json_error('Invalid post');
            return;
        }
        
        // Check if already posted
        if (get_post_meta($post_id, '_threads_posted', true)) {
            error_log('WordPress to Threads: Post already shared to Threads');
            wp_send_json_error('This post has already been shared to Threads');
            return;
        }
        
        // Check authentication
        $access_token = get_option('threads_access_token');
        $user_id = get_option('threads_user_id');
        
        if (empty($access_token) || empty($user_id)) {
            error_log('WordPress to Threads: Missing authentication credentials');
            wp_send_json_error('Plugin not properly authorized. Please re-authorize in settings.');
            return;
        }
        
        error_log('WordPress to Threads: Attempting to post to Threads');
        $result = $this->post_to_threads($post);
        
        if ($result) {
            error_log('WordPress to Threads: Successfully posted to Threads');
            wp_send_json_success('Post successfully shared to Threads!');
        } else {
            error_log('WordPress to Threads: Failed to post to Threads');
            wp_send_json_error('Failed to post to Threads. Check error logs for details.');
        }
    }
    
    public function handle_repost() {
        error_log('WordPress to Threads: handle_repost called');
        error_log('WordPress to Threads: POST data: ' . print_r($_POST, true));
        
        if (!isset($_POST['nonce'])) {
            error_log('WordPress to Threads: No nonce provided');
            wp_send_json_error('No security token provided');
            return;
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'threads_manual_post_nonce')) {
            error_log('WordPress to Threads: Nonce verification failed');
            wp_send_json_error('Security check failed');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            error_log('WordPress to Threads: User lacks permissions');
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        if (!isset($_POST['post_id'])) {
            error_log('WordPress to Threads: No post_id provided');
            wp_send_json_error('No post ID provided');
            return;
        }
        
        $post_id = intval($_POST['post_id']);
        error_log('WordPress to Threads: Processing re-post for post ID: ' . $post_id);
        
        $post = get_post($post_id);
        
        if (!$post || $post->post_type !== 'post') {
            error_log('WordPress to Threads: Invalid post or post type');
            wp_send_json_error('Invalid post');
            return;
        }
        
        // Check authentication
        $access_token = get_option('threads_access_token');
        $user_id = get_option('threads_user_id');
        
        if (empty($access_token) || empty($user_id)) {
            error_log('WordPress to Threads: Missing authentication credentials');
            wp_send_json_error('Plugin not properly authorized. Please re-authorize in settings.');
            return;
        }
        
        // Clear the existing post metadata to allow re-posting
        delete_post_meta($post_id, '_threads_posted');
        delete_post_meta($post_id, '_threads_post_id');
        delete_post_meta($post_id, '_threads_chain_ids');
        delete_post_meta($post_id, '_threads_chain_count');
        
        error_log('WordPress to Threads: Attempting to re-post to Threads');
        $result = $this->post_to_threads($post);
        
        if ($result) {
            error_log('WordPress to Threads: Successfully re-posted to Threads');
            wp_send_json_success('Post successfully re-shared to Threads!');
        } else {
            error_log('WordPress to Threads: Failed to re-post to Threads');
            wp_send_json_error('Failed to re-post to Threads. Check error logs for details.');
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
}

ThreadsAutoPoster::get_instance();