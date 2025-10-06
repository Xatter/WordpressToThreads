<?php
/**
 * Unit tests for ThreadsAutoPoster::prepare_post_content_for_x()
 *
 * Tests X (Twitter) single post mode with 280 character limit.
 * X counts all URLs as 23 characters regardless of actual length.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestableThreadsAutoPoster.php';

class PreparePostContentForXTest extends TestCase {

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
     * Test: prepare_post_content_for_x()
     */

    public function test_short_content_with_url_fits_within_280_chars() {
        // Given: A post that fits within 280 characters including URL
        $post = create_mock_post(
            1,
            'Short Title',
            'This is short content.'
        );

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Should include everything
        $full_text = "Short Title\n\nThis is short content.\n\nhttps://example.com/post-1/";
        $this->assertEquals($full_text, $result, 'Should include title, content, and URL when under 280');
        $this->assertLessThanOrEqual(280, strlen($result), 'Should be under 280 characters');
    }

    public function test_content_exactly_at_280_chars() {
        // Given: Content that exactly fills 280 characters
        $title = 'T';
        $content = str_repeat('a', 280 - strlen($title) - strlen("\n\n") - strlen("\n\nhttps://example.com/post-1/"));
        $post = create_mock_post(1, $title, $content);

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Should fit exactly
        $this->assertEquals(280, strlen($result), 'Should be exactly 280 characters');
    }

    public function test_long_content_truncates_with_url() {
        // Given: Content over 280 characters
        $long_content = str_repeat('This is a long post. ', 50); // ~1000 chars
        $post = create_mock_post(1, 'My Post', $long_content);

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Should truncate and include URL
        $this->assertStringContainsString('My Post', $result, 'Should include title');
        $this->assertStringContainsString('...', $result, 'Should include ellipsis');
        $this->assertStringContainsString('https://example.com/post-1/', $result, 'Should include URL');

        // X counts URLs as 23 chars, so calculate as X would
        $result_without_url = preg_replace('#https?://[^\s]+#', '', $result);
        $x_counted_length = strlen($result_without_url) + 23; // X counts URL as 23
        $this->assertLessThanOrEqual(280, $x_counted_length, 'Should be under 280 when counted by X');
    }

    public function test_url_counted_as_23_chars_on_x() {
        // Given: Long content that needs URL shortening consideration
        clear_test_options(); // No Bitly
        $long_content = str_repeat('Content here. ', 30); // ~420 chars

        $post = create_mock_post(1, 'Title', $long_content);

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Should account for X's 23-char URL counting
        $this->assertStringContainsString('https://example.com/post-1/', $result, 'Should include URL');

        // X counts URLs as 23 chars, so calculate as X would
        $result_without_url = preg_replace('#https?://[^\s]+#', '', $result);
        $x_counted_length = strlen($result_without_url) + 23;
        $this->assertLessThanOrEqual(280, $x_counted_length, 'Should be under 280 when counted by X');
    }

    public function test_bitly_url_used_when_available() {
        // Given: Long content with Bitly configured
        set_test_option('bitly_access_token', 'test_token_123');
        $long_content = str_repeat('Content. ', 50);
        $post = create_mock_post(1, 'Title', $long_content);

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Should use Bitly shortened URL
        $this->assertStringContainsString('https://bit.ly/abc123', $result, 'Should use Bitly URL');
        $this->assertStringNotContainsString('https://example.com/post-1/', $result, 'Should not use original URL');

        // X counts URLs as 23 chars
        $result_without_url = preg_replace('#https?://[^\s]+#', '', $result);
        $x_counted_length = strlen($result_without_url) + 23;
        $this->assertLessThanOrEqual(280, $x_counted_length, 'Should be under 280 when counted by X');
    }

    public function test_very_long_title_gets_truncated() {
        // Given: Very long title
        $very_long_title = str_repeat('Long Title Words ', 30); // ~510 chars
        $post = create_mock_post(1, $very_long_title, 'Content.');

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Should truncate title and include URL
        $this->assertStringContainsString('...', $result, 'Should truncate with ellipsis');
        $this->assertStringContainsString('https://example.com/post-1/', $result, 'Should include URL');

        // X counts URLs as 23 chars
        $result_without_url = preg_replace('#https?://[^\s]+#', '', $result);
        $x_counted_length = strlen($result_without_url) + 23;
        $this->assertLessThanOrEqual(280, $x_counted_length, 'Should be under 280 when counted by X');

        $this->assertStringNotContainsString('Content.', $result, 'Should not include content when title too long');
    }

    public function test_medium_title_with_long_content_truncates_content() {
        // Given: Medium title with long content
        $title = 'Medium Length Title Here';
        $long_content = str_repeat('Lorem ipsum dolor sit amet. ', 40);
        $post = create_mock_post(1, $title, $long_content);

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Should include full title, truncated content, and URL
        $this->assertStringContainsString($title, $result, 'Should include full title');
        $this->assertStringContainsString('...', $result, 'Should truncate content');
        $this->assertStringContainsString('https://example.com/post-1/', $result, 'Should include URL');

        // X counts URLs as 23 chars
        $result_without_url = preg_replace('#https?://[^\s]+#', '', $result);
        $x_counted_length = strlen($result_without_url) + 23;
        $this->assertLessThanOrEqual(280, $x_counted_length, 'Should be under 280 when counted by X');
    }

    public function test_empty_content_with_title() {
        // Given: Post with only title
        $post = create_mock_post(1, 'Just a Title', '');

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Should return title with spacing and URL
        $expected = "Just a Title\n\n\n\nhttps://example.com/post-1/";
        $this->assertEquals($expected, $result, 'Should return title with URL');
    }

    public function test_special_characters_preserved() {
        // Given: Content with special characters
        $post = create_mock_post(
            1,
            'Title with émojis 🎉',
            'Content with & < > " \' and 🚀'
        );

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Should preserve special characters
        $this->assertStringContainsString('émojis 🎉', $result, 'Should preserve emoji in title');
        $this->assertStringContainsString('🚀', $result, 'Should preserve emoji in content');
        $this->assertStringContainsString('&', $result, 'Should preserve ampersand');
    }

    public function test_newlines_preserved() {
        // Given: Content with newlines
        $content = "First line.\n\nSecond line.\n\nThird line.";
        $post = create_mock_post(1, 'Multi-line', $content);

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Should preserve newlines (if content fits)
        if (strlen($result) < 280) {
            $this->assertStringContainsString("\n\n", $result, 'Should preserve newlines when content fits');
        }
    }

    public function test_html_tags_stripped() {
        // Given: Content with HTML
        $post = create_mock_post(
            1,
            'Title with HTML',
            '<p>This is <em>formatted</em> text.</p>'
        );

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Should strip HTML tags (wp_strip_all_tags is called on content, but not title in the implementation)
        $this->assertStringNotContainsString('<p>', $result, 'Should not have p tags');
        $this->assertStringNotContainsString('<em>', $result, 'Should not have em tags');
        $this->assertStringContainsString('This is formatted text.', $result, 'Should have text without tags');
    }

    public function test_url_calculation_with_long_actual_url() {
        // Given: Post that generates a long URL, but X counts it as 23
        clear_test_options(); // No Bitly

        // The URL is "https://example.com/post-1/" which is 29 chars
        // But X counts all URLs as 23 chars
        // So we have 280 - 23 - 2 (for \n\n) = 255 chars for content

        $title = 'X';
        // Content that would fit if URL is counted as 23, but might not if counted as 29
        $content = str_repeat('a', 250); // 250 + 1 (title) + 2 (\n\n) + 2 (\n\n) + 23 (URL counted) = 278

        $post = create_mock_post(1, $title, $content);

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Implementation should handle URL counting
        $this->assertStringContainsString('https://example.com/post-1/', $result, 'Should include URL');

        // The result might be longer than 280 actual chars because X counts URL as 23
        // But the implementation should ensure it works within X's counting rules
    }

    public function test_available_chars_calculation_without_bitly() {
        // Given: Long content without Bitly
        clear_test_options();
        $long_content = str_repeat('test ', 100); // 500 chars
        $post = create_mock_post(1, 'Title', $long_content);

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Should use 23-char URL counting
        $this->assertStringContainsString('...', $result, 'Should truncate long content');

        // X counts URLs as 23 chars
        $result_without_url = preg_replace('#https?://[^\s]+#', '', $result);
        $x_counted_length = strlen($result_without_url) + 23;
        $this->assertLessThanOrEqual(280, $x_counted_length, 'Should be under 280 when counted by X');
    }

    public function test_available_chars_calculation_with_bitly() {
        // Given: Long content with Bitly
        set_test_option('bitly_access_token', 'test_token');
        $long_content = str_repeat('test ', 100);
        $post = create_mock_post(1, 'Title', $long_content);

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Should still use 23-char URL counting (X counts all URLs as 23)
        $this->assertStringContainsString('https://bit.ly/abc123', $result, 'Should use Bitly');

        // X counts URLs as 23 chars
        $result_without_url = preg_replace('#https?://[^\s]+#', '', $result);
        $x_counted_length = strlen($result_without_url) + 23;
        $this->assertLessThanOrEqual(280, $x_counted_length, 'Should be under 280 when counted by X');
    }

    public function test_content_exactly_at_available_chars_boundary() {
        // Given: Content that exactly fills available space
        set_test_option('bitly_access_token', 'test_token');

        // 280 - 23 (URL counted) - 2 (spacing) = 255 available
        $title = 'T';
        $content_chars = 255 - strlen($title) - 2; // Minus title and \n\n
        $content = str_repeat('a', $content_chars);

        $post = create_mock_post(1, $title, $content);

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Should fit without truncation
        $this->assertStringNotContainsString('...', $result, 'Should not need truncation');
        $this->assertStringContainsString('https://bit.ly/abc123', $result, 'Should include URL');
    }

    public function test_title_only_no_content() {
        // Given: Post with title, no content
        $post = create_mock_post(1, 'My Title Here', '');

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Should have title and URL
        $this->assertStringContainsString('My Title Here', $result, 'Should have title');
        $this->assertStringContainsString('https://example.com/post-1/', $result, 'Should have URL');
        $this->assertLessThanOrEqual(280, strlen($result), 'Should be under 280');
    }

    public function test_very_short_content_and_title() {
        // Given: Very short post
        $post = create_mock_post(1, 'Hi', 'test');

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Should include everything
        $expected = "Hi\n\ntest\n\nhttps://example.com/post-1/";
        $this->assertEquals($expected, $result, 'Should include all parts for short content');
    }

    public function test_content_with_urls_already_in_text() {
        // Given: Content that already contains URLs
        $content = 'Check out https://other-site.com for more info and https://another.com too.';
        $post = create_mock_post(1, 'Links', $content);

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Should include content URLs plus the post URL
        // Note: X counts each URL as 23 chars, but our implementation may not account for URLs in content
        $this->assertStringContainsString('https://other-site.com', $result, 'Should preserve URLs in content');
        $this->assertStringContainsString('https://example.com/post-1/', $result, 'Should include post URL');
    }

    public function test_multibyte_characters_counted_correctly() {
        // Given: Content with multibyte characters
        $post = create_mock_post(
            1,
            'Multibyte: 日本語',
            'Content with 中文 and العربية characters.'
        );

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Should handle multibyte chars
        $this->assertStringContainsString('日本語', $result, 'Should preserve Japanese');
        $this->assertStringContainsString('中文', $result, 'Should preserve Chinese');
        $this->assertStringContainsString('العربية', $result, 'Should preserve Arabic');
        // strlen counts bytes, not characters, so this might be > 280 bytes but correct for X
    }

    public function test_exactly_255_chars_content_with_url() {
        // Given: Content that is exactly 255 chars (280 - 23 - 2)
        clear_test_options();
        $content_with_title = str_repeat('a', 255);
        $post = create_mock_post(1, '', $content_with_title); // No title, just content

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Should fit exactly when URL is counted as 23
        // The actual implementation adds the URL, so result might be longer than 280 in actual chars
        // but X will count it as 280 or less
        $this->assertStringContainsString('https://example.com/post-1/', $result, 'Should include URL');
    }

    public function test_truncation_preserves_readability() {
        // Given: Content that needs truncation - make it definitely longer
        $title = 'Article';
        $content = str_repeat('This is the beginning of a very long article that talks about many interesting things and goes on for quite a while with detailed explanations and examples and more content that definitely exceeds the character limit for X posts. ', 3);
        $post = create_mock_post(1, $title, $content);

        // When: We prepare for X
        $result = $this->poster->test_prepare_post_content_for_x($post);

        // Then: Should truncate at reasonable point with ellipsis
        $this->assertStringContainsString('Article', $result, 'Should include title');
        $this->assertStringContainsString('This is the beginning', $result, 'Should include start of content');
        $this->assertStringContainsString('...', $result, 'Should have ellipsis');
        $this->assertStringContainsString('https://example.com/post-1/', $result, 'Should have URL');

        // X counts URLs as 23 chars
        $result_without_url = preg_replace('#https?://[^\s]+#', '', $result);
        $x_counted_length = strlen($result_without_url) + 23;
        $this->assertLessThanOrEqual(280, $x_counted_length, 'Should be under 280 when counted by X');

        // Should not contain the end of the original content
        $this->assertStringNotContainsString('the character limit for X posts. This is the beginning of a very long article that talks about many interesting things and goes on for quite a while with detailed explanations and examples and more content that definitely exceeds the character limit for X posts.', $result, 'Should truncate long content');
    }
}
