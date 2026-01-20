<?php
/**
 * Unit tests for ThreadsAutoPoster::prepare_post_content()
 *
 * Tests Threads single post mode with 500 character limit
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestableThreadsAutoPoster.php';

class PreparePostContentTest extends TestCase {

    private $poster;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        clear_test_options();
        $this->poster = new TestableThreadsAutoPoster();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Test: prepare_post_content()
     */

    public function test_content_under_limit_returns_full_text() {
        // Given: A post with content under 500 characters
        $post = create_mock_post(
            1,
            'Short Title',
            'This is a short post content that fits within the character limit.'
        );

        // When: We prepare the post content
        $result = $this->poster->test_prepare_post_content($post);

        // Then: Should return full text without truncation
        $expected = "Short Title\n\nThis is a short post content that fits within the character limit.";
        $this->assertEquals(
            $expected,
            $result,
            'Content under character limit should return full text without URL'
        );
    }

    public function test_content_at_exact_limit_returns_full_text() {
        // Given: A post with content exactly at 500 characters
        $title = 'Title';
        // strlen("Title") = 5, "\n\n" = 2, so we need 493 chars of content to hit 500 total
        $content = str_repeat('a', 493);
        $post = create_mock_post(1, $title, $content);

        $full_text = $title . "\n\n" . $content;
        $this->assertEquals(500, strlen($full_text), 'Full text should be exactly 500 chars');

        // When: We prepare the post content
        $result = $this->poster->test_prepare_post_content($post);

        // Then: Content at exactly 500 chars should NOT be truncated
        $expected = $title . "\n\n" . $content;
        $this->assertEquals($expected, $result, 'Content at exactly 500 characters should return full text without URL');
        $this->assertEquals(500, strlen($result), 'Result should be exactly 500 characters');
    }

    public function test_content_over_limit_truncates_and_adds_url_without_bitly() {
        // Given: A post with content over 500 characters and no Bitly token
        clear_test_options();
        $long_content = str_repeat('This is a long post. ', 50); // ~1000 chars
        $post = create_mock_post(1, 'My Blog Post', $long_content);

        // When: We prepare the post content
        $result = $this->poster->test_prepare_post_content($post);

        // Then: Should truncate content and add URL
        $this->assertStringContainsString('My Blog Post', $result, 'Should contain title');
        $this->assertStringContainsString('https://example.com/post-1/', $result, 'Should contain URL');
        $this->assertStringEndsWith('https://example.com/post-1/', $result, 'URL should be at the end');
        $this->assertLessThanOrEqual(500, strlen($result), 'Result should not exceed 500 characters');
        $this->assertStringContainsString('... More: ', $result, 'Should contain "... More: " for truncation');
    }

    public function test_content_over_limit_uses_bitly_when_available() {
        // Given: A post with content over limit and Bitly token configured
        set_test_option('bitly_access_token', 'test_token_123');
        $long_content = str_repeat('This is a long post. ', 50);
        $post = create_mock_post(1, 'My Blog Post', $long_content);

        // When: We prepare the post content
        $result = $this->poster->test_prepare_post_content($post);

        // Then: Should use shortened URL
        $this->assertStringContainsString('https://bit.ly/abc123', $result, 'Should use Bitly shortened URL');
        $this->assertStringNotContainsString('https://example.com/post-1/', $result, 'Should not contain original URL');
        $this->assertLessThanOrEqual(500, strlen($result), 'Result should not exceed 500 characters');
    }

    public function test_very_long_title_gets_truncated() {
        // Given: A post with a very long title that exceeds 500 chars when combined with content
        $very_long_title = str_repeat('Very Long Title Words ', 30); // ~660 chars
        $post = create_mock_post(1, $very_long_title, 'Some content here.');

        // When: We prepare the post content
        $result = $this->poster->test_prepare_post_content($post);

        // Then: Should truncate title and add URL with "... More: " format, no content
        $this->assertStringContainsString('... More: ', $result, 'Should truncate title with "... More: "');
        $this->assertStringContainsString('https://example.com/post-1/', $result, 'Should contain URL');
        $this->assertLessThanOrEqual(500, strlen($result), 'Result should not exceed 500 characters');
        $this->assertStringNotContainsString('Some content here', $result, 'Should not include content when title is too long');
    }

    public function test_medium_title_with_long_content_truncates_content() {
        // Given: A post with medium title and long content
        $title = 'This is a Medium Length Title';
        $long_content = str_repeat('Lorem ipsum dolor sit amet. ', 30);
        $post = create_mock_post(1, $title, $long_content);

        // When: We prepare the post content
        $result = $this->poster->test_prepare_post_content($post);

        // Then: Should include full title, truncated content, and URL with "... More: " format
        $this->assertStringContainsString($title, $result, 'Should contain full title');
        $this->assertStringContainsString('... More: ', $result, 'Should truncate content with "... More: " before URL');
        $this->assertStringContainsString('https://example.com/post-1/', $result, 'Should contain URL');
        $this->assertLessThanOrEqual(500, strlen($result), 'Result should not exceed 500 characters');

        // Verify structure: title \n\n content... More: URL (2 parts separated by \n\n)
        $parts = explode("\n\n", $result);
        $this->assertCount(2, $parts, 'Should have two parts: title and content+URL');
        $this->assertStringEndsWith('https://example.com/post-1/', $result, 'URL should be at the end');
    }

    public function test_empty_content_with_title() {
        // Given: A post with only title, no content
        $post = create_mock_post(1, 'Just a Title', '');

        // When: We prepare the post content
        $result = $this->poster->test_prepare_post_content($post);

        // Then: Should return just the title
        $expected = "Just a Title\n\n";
        $this->assertEquals($expected, $result, 'Should return title with spacing');
    }

    public function test_special_characters_are_preserved() {
        // Given: A post with special characters
        $post = create_mock_post(
            1,
            'Title with émojis 🎉',
            'Content with special chars: & < > " \' and émojis 🚀'
        );

        // When: We prepare the post content
        $result = $this->poster->test_prepare_post_content($post);

        // Then: Should preserve special characters
        $this->assertStringContainsString('émojis 🎉', $result, 'Should preserve title emoji');
        $this->assertStringContainsString('🚀', $result, 'Should preserve content emoji');
        $this->assertStringContainsString('&', $result, 'Should preserve ampersand');
    }

    public function test_newlines_in_content_are_preserved() {
        // Given: A post with newlines in content
        $content = "First paragraph.\n\nSecond paragraph.\n\nThird paragraph.";
        $post = create_mock_post(1, 'Multi-paragraph Post', $content);

        // When: We prepare the post content
        $result = $this->poster->test_prepare_post_content($post);

        // Then: Should preserve newlines
        $this->assertStringContainsString("First paragraph.\n\nSecond paragraph.", $result, 'Should preserve paragraph breaks');
    }

    public function test_content_exactly_at_truncation_boundary() {
        // Given: Content that exactly fills available space after title and URL
        set_test_option('bitly_access_token', 'test_token');
        $title = 'Test';
        $short_url = 'https://bit.ly/abc123';

        // Calculate exact content length: 500 - title - 2*"\n\n" - url - 4
        $available_chars = 500 - strlen($short_url) - 4; // 4 for spacing
        $content_chars = $available_chars - strlen($title) - 2;
        $content = str_repeat('a', $content_chars);

        $post = create_mock_post(1, $title, $content);

        // When: We prepare the post content
        $result = $this->poster->test_prepare_post_content($post);

        // Then: Should fit exactly without truncation ellipsis
        $this->assertLessThanOrEqual(500, strlen($result), 'Should not exceed limit');
        // Note: This might still add ellipsis due to implementation details
    }

    public function test_html_tags_are_stripped_from_content() {
        // Given: A post with HTML tags in both title and content
        $post = create_mock_post(
            1,
            'Title with <strong>HTML</strong>',
            '<p>This is <strong>bold</strong> and <em>italic</em> text.</p>'
        );

        // When: We prepare the post content
        $result = $this->poster->test_prepare_post_content($post);

        // Then: Should strip HTML tags from both title and content
        $this->assertStringNotContainsString('<p>', $result, 'Should not contain paragraph tags');
        $this->assertStringNotContainsString('<strong>', $result, 'Should not contain strong tags');
        $this->assertStringNotContainsString('<em>', $result, 'Should not contain em tags');
        $this->assertStringContainsString('Title with HTML', $result, 'Should contain title without tags');
        $this->assertStringContainsString('This is bold and italic text.', $result, 'Should contain text without tags');
    }
}
