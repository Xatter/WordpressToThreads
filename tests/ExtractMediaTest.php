<?php
/**
 * Unit tests for ThreadsAutoPoster::extract_post_images_and_videos()
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestableThreadsAutoPoster.php';

class ExtractMediaTest extends TestCase {

    private $poster;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        clear_test_options();
        clear_test_media();
        $this->poster = new TestableThreadsAutoPoster();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        clear_test_options();
        clear_test_media();
        parent::tearDown();
    }

    public function test_post_with_no_media_returns_empty() {
        $post = create_mock_post(1, 'Title', 'Just text content');
        $result = $this->poster->test_extract_post_images_and_videos($post);
        $this->assertEmpty($result);
    }

    public function test_post_with_featured_image() {
        set_test_thumbnail(1, 100);
        set_test_attachment_url(100, 'https://example.com/featured.jpg');
        set_test_option('threads_media_priority', 'featured');

        $post = create_mock_post(1, 'Title', 'Text');
        $result = $this->poster->test_extract_post_images_and_videos($post);

        $this->assertCount(1, $result);
        $this->assertEquals('IMAGE', $result[0]['type']);
        $this->assertEquals('https://example.com/featured.jpg', $result[0]['url']);
    }

    public function test_post_with_inline_image() {
        $content = '<p>Text here</p><img src="https://example.com/inline.png" />';
        $post = create_mock_post(1, 'Title', $content);
        $result = $this->poster->test_extract_post_images_and_videos($post);

        $this->assertCount(1, $result);
        $this->assertEquals('IMAGE', $result[0]['type']);
        $this->assertEquals('https://example.com/inline.png', $result[0]['url']);
    }

    public function test_post_with_multiple_inline_images() {
        $content = '<img src="https://example.com/a.jpg" /><img src="https://example.com/b.png" />';
        $post = create_mock_post(1, 'Title', $content);
        $result = $this->poster->test_extract_post_images_and_videos($post);

        $this->assertCount(2, $result);
    }

    public function test_post_with_video_html_tag() {
        $content = '<video src="https://example.com/clip.mp4"></video>';
        $post = create_mock_post(1, 'Title', $content);
        $result = $this->poster->test_extract_post_images_and_videos($post);

        $this->assertCount(1, $result);
        $this->assertEquals('VIDEO', $result[0]['type']);
    }

    public function test_post_with_video_shortcode() {
        $content = 'Some text [video src="https://example.com/clip.mp4"] more text';
        $post = create_mock_post(1, 'Title', $content);
        $result = $this->poster->test_extract_post_images_and_videos($post);

        $this->assertCount(1, $result);
        $this->assertEquals('VIDEO', $result[0]['type']);
        $this->assertEquals('https://example.com/clip.mp4', $result[0]['url']);
    }

    public function test_only_one_video_included() {
        $content = '<video src="https://example.com/a.mp4"></video><video src="https://example.com/b.mp4"></video>';
        $post = create_mock_post(1, 'Title', $content);
        $result = $this->poster->test_extract_post_images_and_videos($post);

        $video_count = 0;
        foreach ($result as $item) {
            if ($item['type'] === 'VIDEO') {
                $video_count++;
            }
        }
        $this->assertEquals(1, $video_count, 'Only one video should be included');
    }

    public function test_featured_image_priority() {
        set_test_thumbnail(1, 100);
        set_test_attachment_url(100, 'https://example.com/featured.jpg');
        set_test_option('threads_media_priority', 'featured');

        $content = '<img src="https://example.com/inline.png" />';
        $post = create_mock_post(1, 'Title', $content);
        $result = $this->poster->test_extract_post_images_and_videos($post);

        $this->assertCount(2, $result);
        // Featured image should be first
        $this->assertEquals('https://example.com/featured.jpg', $result[0]['url']);
    }

    public function test_video_priority() {
        set_test_thumbnail(1, 100);
        set_test_attachment_url(100, 'https://example.com/featured.jpg');
        set_test_option('threads_media_priority', 'video');

        $content = '<img src="https://example.com/inline.png" /><video src="https://example.com/clip.mp4"></video>';
        $post = create_mock_post(1, 'Title', $content);
        $result = $this->poster->test_extract_post_images_and_videos($post);

        // Video should be first when video priority is set
        $this->assertEquals('VIDEO', $result[0]['type']);
    }

    public function test_invalid_image_extension_excluded() {
        $content = '<img src="https://example.com/file.bmp" />';
        $post = create_mock_post(1, 'Title', $content);
        $result = $this->poster->test_extract_post_images_and_videos($post);

        $this->assertEmpty($result);
    }

    public function test_featured_image_with_invalid_url_excluded() {
        set_test_thumbnail(1, 100);
        set_test_attachment_url(100, '/relative/path.jpg');
        set_test_option('threads_media_priority', 'featured');

        $post = create_mock_post(1, 'Title', 'Text');
        $result = $this->poster->test_extract_post_images_and_videos($post);

        $this->assertEmpty($result);
    }
}
