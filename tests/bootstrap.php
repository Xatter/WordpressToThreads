<?php
/**
 * PHPUnit bootstrap file for WordPress plugin testing
 */

// Define WordPress constants if not already defined
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', dirname(dirname(__FILE__)));
}

// Require Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Start Brain\Monkey for WordPress function mocking
\Brain\Monkey\setUp();

/**
 * Mock WordPress functions used in the plugin
 */

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string, $remove_breaks = false) {
        if ($remove_breaks) {
            $string = preg_replace('/[\r\n\t ]+/', ' ', $string);
        }
        return strip_tags($string);
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink($post_id) {
        return "https://example.com/post-{$post_id}/";
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $_test_options;
        if (!isset($_test_options)) {
            $_test_options = [];
        }
        return $_test_options[$option] ?? $default;
    }
}

if (!function_exists('error_log')) {
    function error_log($message) {
        // Suppress error logs during testing
        return true;
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) {
        return dirname($file) . '/';
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) {
        return 'https://example.com/wp-content/plugins/' . basename(dirname($file)) . '/';
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $callback) {
        // Mock function for testing
        return true;
    }
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $callback) {
        // Mock function for testing
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        // Mock function for testing
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        // Mock function for testing
        return true;
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $meta_key, $meta_value) {
        // Mock function for testing
        return true;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) {
        // Mock function for testing
        return $single ? '' : array();
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = array()) {
        // Mock Bitly API response for testing
        if (strpos($url, 'api-ssl.bitly.com') !== false) {
            return array(
                'body' => json_encode(array('link' => 'https://bit.ly/abc123'))
            );
        }
        return new WP_Error('http_request_failed', 'Mock error');
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        if (is_array($response) && isset($response['body'])) {
            return $response['body'];
        }
        return '';
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return ($thing instanceof WP_Error);
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $errors = array();
        public function __construct($code = '', $message = '') {
            if (!empty($code)) {
                $this->errors[$code] = array($message);
            }
        }
    }
}

// Helper function to set WordPress options in tests
function set_test_option($option, $value) {
    global $_test_options;
    if (!isset($_test_options)) {
        $_test_options = [];
    }
    $_test_options[$option] = $value;
}

// Helper function to clear test options
function clear_test_options() {
    global $_test_options;
    $_test_options = [];
}

// Helper function to create mock post objects
function create_mock_post($id, $title, $content) {
    $post = new stdClass();
    $post->ID = $id;
    $post->post_title = $title;
    $post->post_content = $content;
    $post->post_type = 'post';
    $post->post_status = 'publish';
    return $post;
}
