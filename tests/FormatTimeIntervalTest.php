<?php
/**
 * Unit tests for ThreadsAutoPoster::format_time_interval()
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestableThreadsAutoPoster.php';

class FormatTimeIntervalTest extends TestCase {

    private $poster;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        $this->poster = new TestableThreadsAutoPoster();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_60_seconds_returns_minute() {
        $result = $this->poster->test_format_time_interval(60);
        $this->assertEquals('1 minute', $result);
    }

    public function test_1800_seconds_returns_30_minutes() {
        $result = $this->poster->test_format_time_interval(1800);
        $this->assertEquals('30 minutes', $result);
    }

    public function test_3600_seconds_returns_1_hour() {
        $result = $this->poster->test_format_time_interval(3600);
        $this->assertEquals('1 hour', $result);
    }

    public function test_7200_seconds_returns_2_hours() {
        $result = $this->poster->test_format_time_interval(7200);
        $this->assertEquals('2 hours', $result);
    }

    public function test_86400_seconds_returns_24_hours() {
        $result = $this->poster->test_format_time_interval(86400);
        $this->assertEquals('24 hours', $result);
    }
}
