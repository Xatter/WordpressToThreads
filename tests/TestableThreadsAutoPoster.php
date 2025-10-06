<?php
/**
 * Testable wrapper for ThreadsAutoPoster class
 * Exposes private methods for unit testing
 */

require_once dirname(__DIR__) . '/threads-auto-poster.php';

class TestableThreadsAutoPoster extends ThreadsAutoPoster {

    /**
     * Expose private method for testing using Reflection
     */
    public function test_prepare_post_content($post) {
        $reflection = new ReflectionClass(get_parent_class($this));
        $method = $reflection->getMethod('prepare_post_content');
        $method->setAccessible(true);
        return $method->invokeArgs($this, [$post]);
    }

    /**
     * Expose private method for testing using Reflection
     */
    public function test_split_content_for_thread_chain($post) {
        $reflection = new ReflectionClass(get_parent_class($this));
        $method = $reflection->getMethod('split_content_for_thread_chain');
        $method->setAccessible(true);
        return $method->invokeArgs($this, [$post]);
    }

    /**
     * Expose private method for testing using Reflection
     */
    public function test_find_split_point($text, $max_length, $split_preference) {
        $reflection = new ReflectionClass(get_parent_class($this));
        $method = $reflection->getMethod('find_split_point');
        $method->setAccessible(true);
        return $method->invokeArgs($this, [$text, $max_length, $split_preference]);
    }

    /**
     * Expose private method for testing using Reflection
     */
    public function test_prepare_post_content_for_x($post) {
        $reflection = new ReflectionClass(get_parent_class($this));
        $method = $reflection->getMethod('prepare_post_content_for_x');
        $method->setAccessible(true);
        return $method->invokeArgs($this, [$post]);
    }

    /**
     * Mock the shorten_url method to avoid actual API calls
     */
    public function shorten_url($url) {
        // Return a mock shortened URL for testing
        return 'https://bit.ly/abc123';
    }

    /**
     * Override constructor to prevent WordPress hooks registration during testing
     */
    public function __construct() {
        // Don't call parent constructor to avoid WordPress hooks
        $this->threads_character_limit = 500;
        $this->x_character_limit = 280;
        $this->x_url_length = 23;
    }
}
