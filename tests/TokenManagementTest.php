<?php
/**
 * Unit tests for token expiration checking
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestableThreadsAutoPoster.php';

class TokenManagementTest extends TestCase {

    private $poster;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        clear_test_options();
        $this->poster = new TestableThreadsAutoPoster();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        clear_test_options();
        parent::tearDown();
    }

    public function test_no_expiration_stored_returns_expired() {
        // No threads_token_expires set
        $this->assertTrue($this->poster->test_is_token_expired());
    }

    public function test_token_far_in_future_returns_not_expired() {
        // Token expires 30 days from now
        set_test_option('threads_token_expires', time() + 30 * DAY_IN_SECONDS);
        $this->assertFalse($this->poster->test_is_token_expired());
    }

    public function test_token_expiring_within_24_hours_returns_expired() {
        // Token expires in 12 hours (within 24h buffer)
        set_test_option('threads_token_expires', time() + 12 * HOUR_IN_SECONDS);
        $this->assertTrue($this->poster->test_is_token_expired());
    }

    public function test_token_already_expired_returns_expired() {
        // Token expired yesterday
        set_test_option('threads_token_expires', time() - DAY_IN_SECONDS);
        $this->assertTrue($this->poster->test_is_token_expired());
    }

    public function test_token_expiring_exactly_at_24_hours_returns_expired() {
        // Token expires in exactly 24 hours — the buffer check uses >=
        set_test_option('threads_token_expires', time() + 24 * HOUR_IN_SECONDS);
        $this->assertTrue($this->poster->test_is_token_expired());
    }

    public function test_token_expiring_just_over_24_hours_returns_not_expired() {
        // Token expires in 24h + 1 second
        set_test_option('threads_token_expires', time() + 24 * HOUR_IN_SECONDS + 1);
        $this->assertFalse($this->poster->test_is_token_expired());
    }
}
