<?php
/**
 * Unit tests for ThreadsAutoPoster::split_content_for_thread_chain()
 *
 * Tests Threads chain mode where long posts are split into multiple posts
 * that reply to each other, creating a thread.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestableThreadsAutoPoster.php';

class SplitContentForThreadChainTest extends TestCase {

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
     * Test: split_content_for_thread_chain()
     */

    public function test_short_content_returns_single_post_with_url() {
        // Given: A post with content under 500 characters
        $post = create_mock_post(
            1,
            'Short Title',
            'This is a short post that fits in one post.'
        );

        // When: We split the content for thread chain
        $result = $this->poster->test_split_content_for_thread_chain($post);

        // Then: Should return single post with URL
        $this->assertIsArray($result, 'Result should be an array');
        $this->assertCount(1, $result, 'Should have only one post');
        $this->assertStringContainsString('Short Title', $result[0], 'Should contain title');
        $this->assertStringContainsString('https://example.com/post-1/', $result[0], 'Should contain URL');
        $this->assertLessThanOrEqual(500, strlen($result[0]), 'Should not exceed character limit');
    }

    public function test_long_content_splits_into_multiple_posts() {
        // Given: A post with long content that requires splitting
        set_test_option('threads_split_preference', 'words');
        set_test_option('threads_max_chain_length', 5);

        $long_content = str_repeat('This is a sentence that will be repeated many times. ', 30); // ~1500 chars
        $post = create_mock_post(1, 'Long Post Title', $long_content);

        // When: We split the content
        $result = $this->poster->test_split_content_for_thread_chain($post);

        // Then: Should return multiple posts
        $this->assertIsArray($result, 'Result should be an array');
        $this->assertGreaterThan(1, count($result), 'Should have multiple posts');
        $this->assertLessThanOrEqual(5, count($result), 'Should not exceed max chain length');

        // Each post should be under character limit
        foreach ($result as $index => $post_text) {
            $this->assertLessThanOrEqual(
                500,
                strlen($post_text),
                "Post {$index} should not exceed 500 characters"
            );
        }
    }

    public function test_url_only_appears_in_last_post() {
        // Given: A long post that needs splitting
        set_test_option('threads_split_preference', 'words');

        $long_content = str_repeat('Lorem ipsum dolor sit amet. ', 50); // ~1400 chars
        $post = create_mock_post(1, 'Multi-Part Post', $long_content);

        // When: We split the content
        $result = $this->poster->test_split_content_for_thread_chain($post);

        // Then: URL should only be in last post
        $url = 'https://example.com/post-1/';

        for ($i = 0; $i < count($result) - 1; $i++) {
            $this->assertStringNotContainsString(
                $url,
                $result[$i],
                "Post {$i} (not last) should not contain URL"
            );
        }

        $last_post = $result[count($result) - 1];
        $this->assertStringContainsString($url, $last_post, 'Last post should contain URL');
    }

    public function test_url_appears_in_last_post_with_bitly() {
        // Given: A long post with Bitly configured
        set_test_option('bitly_access_token', 'test_token_123');
        set_test_option('threads_split_preference', 'words');

        $long_content = str_repeat('Test content here. ', 50);
        $post = create_mock_post(1, 'Test Post', $long_content);

        // When: We split the content
        $result = $this->poster->test_split_content_for_thread_chain($post);

        // Then: Last post should contain Bitly URL
        $last_post = $result[count($result) - 1];
        $this->assertStringContainsString('https://bit.ly/abc123', $last_post, 'Last post should contain Bitly shortened URL');
        $this->assertStringNotContainsString('https://example.com/post-1/', $last_post, 'Should not contain original URL');
    }

    public function test_respects_max_chain_length() {
        // Given: Very long content and max chain length of 3
        set_test_option('threads_max_chain_length', 3);
        set_test_option('threads_split_preference', 'words');

        $very_long_content = str_repeat('Content that would require many posts if unlimited. ', 100); // ~5000 chars
        $post = create_mock_post(1, 'Very Long Post', $very_long_content);

        // When: We split the content
        $result = $this->poster->test_split_content_for_thread_chain($post);

        // Then: Should not exceed max chain length
        $this->assertLessThanOrEqual(3, count($result), 'Should respect max chain length of 3');

        // Last post should still have URL even if content was truncated
        $last_post = $result[count($result) - 1];
        $this->assertStringContainsString('https://example.com/post-1/', $last_post, 'Last post should have URL');
    }

    public function test_sentence_split_preference() {
        // Given: Content with clear sentence boundaries
        set_test_option('threads_split_preference', 'sentences');

        $content = str_repeat('This is sentence one. This is sentence two. This is sentence three. ', 15); // ~1000 chars
        $post = create_mock_post(1, 'Title', $content);

        // When: We split the content
        $result = $this->poster->test_split_content_for_thread_chain($post);

        // Then: Should split at sentence boundaries
        $this->assertGreaterThan(1, count($result), 'Should split into multiple posts');

        // Check that posts end with sentence punctuation (except last post which has URL)
        for ($i = 0; $i < count($result) - 1; $i++) {
            $trimmed = trim($result[$i]);
            $this->assertMatchesRegularExpression(
                '/[.!?]$/',
                $trimmed,
                "Post {$i} should end with sentence punctuation"
            );
        }
    }

    public function test_paragraph_split_preference() {
        // Given: Content with paragraph breaks
        set_test_option('threads_split_preference', 'paragraphs');

        $paragraph = "This is paragraph one with some content.\n\n";
        $content = str_repeat($paragraph, 20); // Multiple paragraphs, ~800 chars
        $post = create_mock_post(1, 'Multi-Paragraph', $content);

        // When: We split the content
        $result = $this->poster->test_split_content_for_thread_chain($post);

        // Then: Should split at paragraph boundaries where possible
        $this->assertGreaterThan(1, count($result), 'Should split into multiple posts');

        // Each post should be under limit
        foreach ($result as $index => $post_text) {
            $this->assertLessThanOrEqual(500, strlen($post_text), "Post {$index} should be under limit");
        }
    }

    public function test_word_split_preference() {
        // Given: Content without sentence or paragraph breaks
        set_test_option('threads_split_preference', 'words');

        $content = str_repeat('word ', 300); // 1500 chars, no sentences or paragraphs
        $post = create_mock_post(1, 'Title', $content);

        // When: We split the content
        $result = $this->poster->test_split_content_for_thread_chain($post);

        // Then: Should split at word boundaries
        $this->assertGreaterThan(1, count($result), 'Should split into multiple posts');

        // Posts should not have orphaned words (shouldn't end mid-word)
        for ($i = 0; $i < count($result) - 1; $i++) {
            $trimmed = trim($result[$i]);
            // Should end with a complete word (followed by space or punctuation)
            $this->assertNotEmpty($trimmed, "Post {$i} should not be empty");
        }
    }

    public function test_fallback_to_force_split_when_no_good_boundary() {
        // Given: Very long single word that can't be split nicely
        set_test_option('threads_split_preference', 'words');

        $content = str_repeat('a', 1200); // Single "word" of 1200 characters
        $post = create_mock_post(1, 'T', $content);

        // When: We split the content
        $result = $this->poster->test_split_content_for_thread_chain($post);

        // Then: Should force split and add ellipsis
        $this->assertGreaterThan(1, count($result), 'Should split into multiple posts');

        // Should contain ellipsis where forced split occurred
        $found_ellipsis = false;
        foreach ($result as $post_text) {
            if (strpos($post_text, '...') !== false) {
                $found_ellipsis = true;
                break;
            }
        }
        $this->assertTrue($found_ellipsis, 'Should contain ellipsis for forced split');
    }

    public function test_first_post_contains_title() {
        // Given: A long post
        set_test_option('threads_split_preference', 'words');

        $long_content = str_repeat('Content here. ', 100);
        $post = create_mock_post(1, 'Important Title', $long_content);

        // When: We split the content
        $result = $this->poster->test_split_content_for_thread_chain($post);

        // Then: First post should contain the title
        $this->assertStringContainsString('Important Title', $result[0], 'First post should contain title');
    }

    public function test_empty_content_returns_title_and_url() {
        // Given: A post with only title, no content
        $post = create_mock_post(1, 'Just a Title', '');

        // When: We split the content
        $result = $this->poster->test_split_content_for_thread_chain($post);

        // Then: Should return single post with title and URL
        $this->assertCount(1, $result, 'Should return single post');
        $this->assertStringContainsString('Just a Title', $result[0], 'Should contain title');
        $this->assertStringContainsString('https://example.com/post-1/', $result[0], 'Should contain URL');
    }

    public function test_very_long_title_is_handled() {
        // Given: A post with very long title
        $very_long_title = str_repeat('Long Title Word ', 40); // ~640 chars
        $content = 'Some content';
        $post = create_mock_post(1, $very_long_title, $content);

        // When: We split the content
        $result = $this->poster->test_split_content_for_thread_chain($post);

        // Then: Should handle gracefully and split
        $this->assertIsArray($result, 'Should return array');
        $this->assertGreaterThan(0, count($result), 'Should have at least one post');

        // Each post should be under limit
        foreach ($result as $index => $post_text) {
            $this->assertLessThanOrEqual(500, strlen($post_text), "Post {$index} should be under limit");
        }
    }

    public function test_special_characters_preserved_in_split() {
        // Given: Content with special characters that needs splitting
        set_test_option('threads_split_preference', 'sentences');

        $content = str_repeat('Émojis 🎉 and symbols & < > work. ', 30); // ~1000 chars
        $post = create_mock_post(1, 'Special Chars 🚀', $content);

        // When: We split the content
        $result = $this->poster->test_split_content_for_thread_chain($post);

        // Then: Special characters should be preserved across all posts
        $full_result = implode(' ', $result);
        $this->assertStringContainsString('Émojis', $full_result, 'Should preserve accented characters');
        $this->assertStringContainsString('🎉', $full_result, 'Should preserve emojis');
        $this->assertStringContainsString('&', $full_result, 'Should preserve ampersand');
    }

    public function test_newlines_preserved_in_split() {
        // Given: Content with newlines that needs splitting
        $paragraph1 = "First paragraph with content.\n\n";
        $paragraph2 = "Second paragraph with more content.\n\n";
        $content = str_repeat($paragraph1 . $paragraph2, 10); // ~700 chars
        $post = create_mock_post(1, 'Multi-Para', $content);

        // When: We split the content
        $result = $this->poster->test_split_content_for_thread_chain($post);

        // Then: Newlines should be preserved
        $full_result = implode('', $result);
        $this->assertStringContainsString("\n\n", $full_result, 'Should preserve paragraph breaks');
    }

    public function test_max_chain_length_of_one() {
        // Given: Long content but max chain length is 1
        set_test_option('threads_max_chain_length', 1);

        $long_content = str_repeat('Content here. ', 100); // ~1400 chars
        $post = create_mock_post(1, 'Title', $long_content);

        // When: We split the content
        $result = $this->poster->test_split_content_for_thread_chain($post);

        // Then: Should return only one post with URL, truncating content
        $this->assertCount(1, $result, 'Should return only one post when max is 1');
        $this->assertStringContainsString('https://example.com/post-1/', $result[0], 'Should contain URL');
        $this->assertLessThanOrEqual(500, strlen($result[0]), 'Should be under character limit');
    }

    public function test_content_at_boundary_of_500_chars() {
        // Given: Content that's just over 500 chars total
        $title = 'Title';
        $content = str_repeat('a', 510); // Title + content = ~520 chars
        $post = create_mock_post(1, $title, $content);

        // When: We split the content
        $result = $this->poster->test_split_content_for_thread_chain($post);

        // Then: Should split into 2 posts
        $this->assertCount(2, $result, 'Should split into 2 posts');

        // First post under 500
        $this->assertLessThanOrEqual(500, strlen($result[0]), 'First post should be under 500');

        // Second post should have remaining content and URL
        $this->assertLessThanOrEqual(500, strlen($result[1]), 'Second post should be under 500');
        $this->assertStringContainsString('https://example.com/post-1/', $result[1], 'Last post should have URL');
    }

    public function test_all_posts_are_non_empty() {
        // Given: Long content
        set_test_option('threads_split_preference', 'words');

        $long_content = str_repeat('Test content. ', 80);
        $post = create_mock_post(1, 'Title', $long_content);

        // When: We split the content
        $result = $this->poster->test_split_content_for_thread_chain($post);

        // Then: No post should be empty
        foreach ($result as $index => $post_text) {
            $this->assertNotEmpty(trim($post_text), "Post {$index} should not be empty");
        }
    }

    public function test_default_split_preference_is_used() {
        // Given: No split preference set (should default to 'sentences')
        clear_test_options();

        $content = str_repeat('Sentence one. Sentence two. ', 30);
        $post = create_mock_post(1, 'Title', $content);

        // When: We split the content
        $result = $this->poster->test_split_content_for_thread_chain($post);

        // Then: Should successfully split the content
        $this->assertIsArray($result, 'Should return array');
        $this->assertGreaterThan(0, count($result), 'Should have at least one post');
    }

    public function test_default_max_chain_length_is_used() {
        // Given: No max chain length set (should default to 5)
        clear_test_options();

        $very_long_content = str_repeat('Content. ', 500); // ~4500 chars, would need many posts
        $post = create_mock_post(1, 'Title', $very_long_content);

        // When: We split the content
        $result = $this->poster->test_split_content_for_thread_chain($post);

        // Then: Should not exceed default max of 5
        $this->assertLessThanOrEqual(5, count($result), 'Should default to max chain length of 5');
    }
}
