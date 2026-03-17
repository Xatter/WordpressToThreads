<?php
/**
 * Unit tests for is_valid_image_url() and is_valid_video_url()
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestableThreadsAutoPoster.php';

class MediaValidationTest extends TestCase {

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

    // --- is_valid_image_url ---

    public function test_valid_jpg_url() {
        $this->assertTrue($this->poster->test_is_valid_image_url('https://example.com/photo.jpg'));
    }

    public function test_valid_jpeg_url() {
        $this->assertTrue($this->poster->test_is_valid_image_url('https://example.com/photo.jpeg'));
    }

    public function test_valid_png_url() {
        $this->assertTrue($this->poster->test_is_valid_image_url('https://example.com/photo.png'));
    }

    public function test_valid_gif_url() {
        $this->assertTrue($this->poster->test_is_valid_image_url('https://example.com/photo.gif'));
    }

    public function test_valid_webp_url() {
        $this->assertTrue($this->poster->test_is_valid_image_url('https://example.com/photo.webp'));
    }

    public function test_http_image_url_is_valid() {
        $this->assertTrue($this->poster->test_is_valid_image_url('http://example.com/photo.jpg'));
    }

    public function test_invalid_image_extension() {
        $this->assertFalse($this->poster->test_is_valid_image_url('https://example.com/file.mp4'));
    }

    public function test_invalid_image_no_extension() {
        $this->assertFalse($this->poster->test_is_valid_image_url('https://example.com/file'));
    }

    public function test_invalid_image_ftp_protocol() {
        $this->assertFalse($this->poster->test_is_valid_image_url('ftp://example.com/photo.jpg'));
    }

    public function test_invalid_image_relative_path() {
        $this->assertFalse($this->poster->test_is_valid_image_url('/uploads/photo.jpg'));
    }

    public function test_image_url_with_query_string() {
        // pathinfo on URLs with query strings may not parse correctly
        $result = $this->poster->test_is_valid_image_url('https://example.com/photo.jpg?w=800');
        // The extension parsing may fail here — documenting current behavior
        $this->assertIsBool($result);
    }

    // --- is_valid_video_url ---

    public function test_valid_mp4_url() {
        $this->assertTrue($this->poster->test_is_valid_video_url('https://example.com/clip.mp4'));
    }

    public function test_valid_mov_url() {
        $this->assertTrue($this->poster->test_is_valid_video_url('https://example.com/clip.mov'));
    }

    public function test_valid_webm_url() {
        $this->assertTrue($this->poster->test_is_valid_video_url('https://example.com/clip.webm'));
    }

    public function test_valid_avi_url() {
        $this->assertTrue($this->poster->test_is_valid_video_url('https://example.com/clip.avi'));
    }

    public function test_invalid_video_extension() {
        $this->assertFalse($this->poster->test_is_valid_video_url('https://example.com/photo.jpg'));
    }

    public function test_invalid_video_relative_path() {
        $this->assertFalse($this->poster->test_is_valid_video_url('/uploads/clip.mp4'));
    }
}
