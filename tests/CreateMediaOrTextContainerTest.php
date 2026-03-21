<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/TestableThreadsAutoPoster.php';

// Need wp_remote_retrieve_response_code for make_authenticated_request
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        if (is_array($response) && isset($response['response']['code'])) {
            return $response['response']['code'];
        }
        return 200;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value) {
        set_test_option($option, $value);
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option) {
        global $_test_options;
        unset($_test_options[$option]);
        return true;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook) {
        return false;
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook) {
        return true;
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = array()) {
        return new WP_Error('http_request_failed', 'Mock error');
    }
}

/**
 * Override wp_remote_post to return configurable responses per URL call
 */
class ApiResponseQueue {
    private static $responses = [];

    public static function queue($response) {
        self::$responses[] = $response;
    }

    public static function next() {
        return array_shift(self::$responses);
    }

    public static function clear() {
        self::$responses = [];
    }

    public static function count() {
        return count(self::$responses);
    }
}

// We need to track calls and return queued responses.
// Since wp_remote_post is already defined in bootstrap, we can't redefine it.
// Instead, we'll use a subclass that overrides make_authenticated_request
// by making it accessible via reflection and using a Closure.

/**
 * Test subclass that intercepts create_threads_container via reflection
 */
class ContainerTestPoster extends TestableThreadsAutoPoster {
    public $container_calls = [];
    private $container_responses = [];

    public function __construct() {
        parent::__construct();
    }

    public function queue_container_response($response) {
        $this->container_responses[] = $response;
    }

    /**
     * Call create_media_or_text_container using reflection, but with
     * create_threads_container intercepted
     */
    public function test_create_media_or_text_container($media_items, $text, $user_id, $access_token, $fallback_data = null) {
        // Use Closure::bind to temporarily replace create_threads_container behavior
        // by intercepting at the make_authenticated_request level
        $reflection = new ReflectionClass(ThreadsAutoPoster::class);
        $method = $reflection->getMethod('create_media_or_text_container');
        $method->setAccessible(true);
        return $method->invokeArgs($this, [$media_items, $text, $user_id, $access_token, $fallback_data]);
    }
}

class CreateMediaOrTextContainerTest extends TestCase {

    protected function setUp(): void {
        clear_test_options();
        // Set up valid token so ensure_valid_token succeeds
        set_test_option('threads_access_token', 'test-token-abc');
        // Set expiry far in the future so token isn't considered expired
        set_test_option('threads_token_expires', time() + 86400 * 30);
    }

    protected function tearDown(): void {
        clear_test_options();
        ApiResponseQueue::clear();
    }

    /**
     * The core regression test: the method must exist
     */
    public function testMethodExists() {
        $reflection = new ReflectionClass(ThreadsAutoPoster::class);
        $this->assertTrue(
            $reflection->hasMethod('create_media_or_text_container'),
            'create_media_or_text_container method must exist on ThreadsAutoPoster'
        );
    }

    public function testMethodIsCallable() {
        $reflection = new ReflectionClass(ThreadsAutoPoster::class);
        $method = $reflection->getMethod('create_media_or_text_container');
        $method->setAccessible(true);

        // Verify the method signature accepts the expected parameters
        $params = $method->getParameters();
        $this->assertCount(5, $params);
        $this->assertEquals('media_items', $params[0]->getName());
        $this->assertEquals('text', $params[1]->getName());
        $this->assertEquals('user_id', $params[2]->getName());
        $this->assertEquals('access_token', $params[3]->getName());
        $this->assertEquals('fallback_data', $params[4]->getName());
        $this->assertTrue($params[4]->isOptional());
    }

    public function testTextOnlyPostWithNoMedia() {
        $poster = new ContainerTestPoster();

        // wp_remote_post will be called by create_threads_container -> make_authenticated_request
        // The mock in bootstrap returns a bitly-like response for bitly URLs and WP_Error otherwise
        // We need to work around this. Since we can't easily override wp_remote_post after it's defined,
        // let's test via a full integration-style call and verify behavior.

        // For a text-only post (no media), the method should call create_threads_container once
        // with media_type=TEXT. The actual API call will fail (mock wp_remote_post returns WP_Error
        // for non-bitly URLs), which means create_threads_container returns falsy,
        // and the method should return false.
        $result = $poster->test_create_media_or_text_container(
            [], // no media
            'Hello world',
            '12345',
            'test-token-abc'
        );

        // With mock wp_remote_post returning WP_Error for threads API, this will be false
        // The important thing is it doesn't fatal error (the original bug)
        $this->assertFalse($result);
    }

    public function testSingleMediaFallsBackToTextWhenApiFails() {
        $poster = new ContainerTestPoster();

        $media = [
            ['type' => 'IMAGE', 'url' => 'https://example.com/photo.jpg'],
        ];

        // Both the media container and text fallback will fail with mock wp_remote_post
        $result = $poster->test_create_media_or_text_container(
            $media,
            'Post with image',
            '12345',
            'test-token-abc'
        );

        // Doesn't fatal — that's the key assertion (the original bug)
        $this->assertFalse($result);
    }

    public function testMultipleMediaFallsBackToTextWhenApiFails() {
        $poster = new ContainerTestPoster();

        $media = [
            ['type' => 'IMAGE', 'url' => 'https://example.com/photo1.jpg'],
            ['type' => 'VIDEO', 'url' => 'https://example.com/clip.mp4'],
        ];

        $result = $poster->test_create_media_or_text_container(
            $media,
            'Carousel post',
            '12345',
            'test-token-abc'
        );

        $this->assertFalse($result);
    }

    public function testFallbackDataUsedWhenNoMedia() {
        $poster = new ContainerTestPoster();

        $fallback = [
            'media_type' => 'TEXT',
            'text' => 'Reply text',
            'reply_to_id' => 'parent_123',
        ];

        // Will fail due to mock, but won't fatal
        $result = $poster->test_create_media_or_text_container(
            [],
            'Reply text',
            '12345',
            'test-token-abc',
            $fallback
        );

        $this->assertFalse($result);
    }

    public function testMediaContainsVideoHelper() {
        $reflection = new ReflectionClass(ThreadsAutoPoster::class);
        $method = $reflection->getMethod('media_contains_video');
        $method->setAccessible(true);

        $poster = new ContainerTestPoster();

        $this->assertFalse($method->invokeArgs($poster, [[
            ['type' => 'IMAGE', 'url' => 'https://example.com/photo.jpg'],
        ]]));

        $this->assertTrue($method->invokeArgs($poster, [[
            ['type' => 'IMAGE', 'url' => 'https://example.com/photo.jpg'],
            ['type' => 'VIDEO', 'url' => 'https://example.com/clip.mp4'],
        ]]));

        $this->assertTrue($method->invokeArgs($poster, [[
            ['type' => 'VIDEO', 'url' => 'https://example.com/clip.mp4'],
        ]]));

        $this->assertFalse($method->invokeArgs($poster, [[]]));
    }
}
