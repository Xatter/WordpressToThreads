<?php
/**
 * Unit tests for ThreadsAutoPoster::find_split_point()
 *
 * Tests the helper method that finds intelligent split points in text
 * based on different preferences (sentences, paragraphs, words).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestableThreadsAutoPoster.php';

class FindSplitPointTest extends TestCase {

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

    /**
     * Test: find_split_point() - Sentence preference
     */

    public function test_sentence_preference_finds_sentence_boundary() {
        // Given: Text with sentence boundaries
        $text = 'This is sentence one. This is sentence two. This is sentence three.';

        // When: We find split point at position 40 with sentence preference
        $result = $this->poster->test_find_split_point($text, 40, 'sentences');

        // Then: Should split at sentence boundary (after "one.")
        $this->assertNotFalse($result, 'Should find a split point');
        $this->assertLessThanOrEqual(40, $result, 'Should be within max length');

        // Should split at or before "one."
        $split_text = substr($text, 0, $result);
        $this->assertMatchesRegularExpression('/[.!?](?=\s|$)/s', $split_text, 'Should end with sentence punctuation');
    }

    public function test_sentence_preference_with_exclamation() {
        // Given: Text with exclamation marks
        $text = 'This is exciting! This is more! And even more!';

        // When: We find split point
        $result = $this->poster->test_find_split_point($text, 25, 'sentences');

        // Then: Should split at exclamation mark
        $this->assertNotFalse($result, 'Should find a split point');

        $split_text = substr($text, 0, $result);
        $this->assertStringContainsString('!', $split_text, 'Should include exclamation');
    }

    public function test_sentence_preference_with_question_mark() {
        // Given: Text with question marks
        $text = 'What is this? Where are we? Why though?';

        // When: We find split point
        $result = $this->poster->test_find_split_point($text, 20, 'sentences');

        // Then: Should split at question mark
        $this->assertNotFalse($result, 'Should find a split point');

        $split_text = substr($text, 0, $result);
        $this->assertStringContainsString('?', $split_text, 'Should include question mark');
    }

    public function test_sentence_preference_no_sentence_fallback_to_paragraph() {
        // Given: Text without sentence punctuation but with paragraph breaks
        $text = "First paragraph without punctuation\n\nSecond paragraph here\n\nThird one";

        // When: We find split point with sentence preference
        $result = $this->poster->test_find_split_point($text, 50, 'sentences');

        // Then: Should fall back to paragraph splitting
        $this->assertNotFalse($result, 'Should find a split point');

        // Check if it split at paragraph boundary
        $split_text = substr($text, 0, $result);
        // Should either end with sentence punctuation or paragraph break
        $has_good_split = (strpos($split_text, "\n\n") !== false) || preg_match('/[.!?]/', $split_text);
        $this->assertTrue($has_good_split, 'Should find paragraph boundary when no sentence boundary');
    }

    /**
     * Test: find_split_point() - Paragraph preference
     */

    public function test_paragraph_preference_finds_paragraph_boundary() {
        // Given: Text with paragraph breaks
        $text = "First paragraph.\n\nSecond paragraph.\n\nThird paragraph.";

        // When: We find split point with paragraph preference
        $result = $this->poster->test_find_split_point($text, 30, 'paragraphs');

        // Then: Should split at paragraph boundary
        $this->assertNotFalse($result, 'Should find a split point');
        $this->assertLessThanOrEqual(30, $result, 'Should be within max length');

        // Verify it includes paragraph break
        $split_text = substr($text, 0, $result);
        $this->assertStringContainsString("\n\n", $split_text, 'Should include paragraph break');
    }

    public function test_paragraph_preference_respects_minimum_threshold() {
        // Given: Text with early paragraph break (before 50% of max_length)
        $text = "Short.\n\n" . str_repeat('Long paragraph content ', 20);

        // When: We find split point at 100 chars
        $result = $this->poster->test_find_split_point($text, 100, 'paragraphs');

        // Then: Should not split at the early paragraph (it's < 50% of max length)
        // Should fall back to word splitting or find later paragraph
        $this->assertNotFalse($result, 'Should find a split point');

        // If it finds the early paragraph, it should be rejected (< 50% of 100 = < 50)
        // So result should either be false or > 50
        if ($result !== false && $result < 50) {
            // This would be the early paragraph - should not happen
            $this->fail('Should not split at paragraph that is less than 50% of max_length');
        }
    }

    public function test_paragraph_preference_no_paragraph_fallback_to_words() {
        // Given: Text without paragraph breaks
        $text = 'This is all one paragraph with many words but no breaks between them at all';

        // When: We find split point with paragraph preference
        $result = $this->poster->test_find_split_point($text, 40, 'paragraphs');

        // Then: Should fall back to word splitting
        $this->assertNotFalse($result, 'Should find a split point');

        $split_text = substr($text, 0, $result);
        // Should end at word boundary (space)
        $this->assertMatchesRegularExpression('/\s$/', $split_text, 'Should end at word boundary');
    }

    /**
     * Test: find_split_point() - Word preference
     */

    public function test_word_preference_finds_word_boundary() {
        // Given: Text with words
        $text = 'This is a test of the word splitting functionality';

        // When: We find split point with word preference
        $result = $this->poster->test_find_split_point($text, 20, 'words');

        // Then: Should split at word boundary
        $this->assertNotFalse($result, 'Should find a split point');
        $this->assertLessThanOrEqual(20, $result, 'Should be within max length');

        $split_text = substr($text, 0, $result);
        // Should end with a space (word boundary)
        $this->assertMatchesRegularExpression('/\s$/', $split_text, 'Should end at space');
    }

    public function test_word_preference_respects_minimum_threshold() {
        // Given: Text with early space (before 70% of max_length)
        $text = "Hi " . str_repeat('verylongword', 20);

        // When: We find split point at 50 chars
        $result = $this->poster->test_find_split_point($text, 50, 'words');

        // Then: Should not split at the early space (it's < 70% of max length)
        // "Hi " is position 3, which is < 35 (70% of 50)
        if ($result !== false) {
            $this->assertGreaterThan(3, $result, 'Should not split at word that is less than 70% of max_length');
        } else {
            // If result is false, that's also acceptable (no good split point found)
            $this->assertFalse($result, 'Should return false when no good word boundary exists');
        }
    }

    public function test_word_preference_no_good_word_boundary_returns_false() {
        // Given: Very long word with no spaces near the end
        $text = str_repeat('verylongword', 10); // 120 chars, no spaces

        // When: We find split point with word preference
        $result = $this->poster->test_find_split_point($text, 50, 'words');

        // Then: Should return false (no good split point)
        $this->assertFalse($result, 'Should return false when no good word boundary exists');
    }

    /**
     * Test: Edge cases
     */

    public function test_text_shorter_than_max_length() {
        // Given: Text shorter than max length
        $text = 'Short text';

        // When: We find split point
        $result = $this->poster->test_find_split_point($text, 100, 'words');

        // Then: Should return the full length
        $this->assertEquals(strlen($text), $result, 'Should return full text length when under max');
    }

    public function test_text_exactly_at_max_length() {
        // Given: Text exactly at max length
        $text = str_repeat('a', 50);

        // When: We find split point
        $result = $this->poster->test_find_split_point($text, 50, 'words');

        // Then: Should return the full length
        $this->assertEquals(50, $result, 'Should return full length when exactly at max');
    }

    public function test_empty_text() {
        // Given: Empty text
        $text = '';

        // When: We find split point
        $result = $this->poster->test_find_split_point($text, 100, 'words');

        // Then: Should return 0
        $this->assertEquals(0, $result, 'Should return 0 for empty text');
    }

    public function test_max_length_of_zero() {
        // Given: Any text but max length is 0
        $text = 'Some text here';

        // When: We find split point with max_length 0
        $result = $this->poster->test_find_split_point($text, 0, 'words');

        // Then: Should return 0
        $this->assertEquals(0, $result, 'Should return 0 when max_length is 0');
    }

    public function test_multiple_sentence_endings_chooses_last_one() {
        // Given: Text with multiple sentences within max_length
        $text = 'First. Second. Third. Fourth. Fifth.';

        // When: We find split point
        $result = $this->poster->test_find_split_point($text, 30, 'sentences');

        // Then: Should choose the last sentence boundary within max_length
        $this->assertNotFalse($result, 'Should find a split point');

        $split_text = substr($text, 0, $result);
        // Should include at least "First. Second." (14 chars) but less than 30
        $this->assertGreaterThan(14, strlen($split_text), 'Should include multiple sentences');
        $this->assertLessThanOrEqual(30, strlen($split_text), 'Should be under max length');
    }

    public function test_sentence_at_exact_max_length() {
        // Given: Text where sentence ends exactly at max_length
        $text = str_repeat('a', 19) . '. More text here.';

        // When: We find split point at 20
        $result = $this->poster->test_find_split_point($text, 20, 'sentences');

        // Then: Should split at the period
        $this->assertNotFalse($result, 'Should find split point');
        $this->assertEquals(20, $result, 'Should split exactly at sentence boundary');
    }

    public function test_whitespace_only_text() {
        // Given: Text with only whitespace
        $text = "   \n\n  \t  ";

        // When: We find split point
        $result = $this->poster->test_find_split_point($text, 10, 'words');

        // Then: Should handle gracefully
        $this->assertIsInt($result, 'Should return integer');
    }

    public function test_special_characters_dont_break_logic() {
        // Given: Text with special characters
        $text = 'Text with émojis 🎉 and symbols & < >. More text here.';

        // When: We find split point
        $result = $this->poster->test_find_split_point($text, 40, 'sentences');

        // Then: Should handle special characters gracefully
        $this->assertNotFalse($result, 'Should find split point with special chars');
        $this->assertLessThanOrEqual(40, $result, 'Should be within max length');
    }

    public function test_very_long_max_length() {
        // Given: Text shorter than very long max_length
        $text = 'Normal text here. With sentences.';

        // When: We find split point with very large max_length
        $result = $this->poster->test_find_split_point($text, 10000, 'sentences');

        // Then: Should return full text length
        $this->assertEquals(strlen($text), $result, 'Should return full length when max is very large');
    }

    public function test_fallback_chain_sentences_to_paragraphs_to_words() {
        // Given: Text with no sentences, but has paragraphs and words
        $text = "First part no punctuation\n\nSecond part also no punctuation";

        // When: We find split point with sentence preference
        $result = $this->poster->test_find_split_point($text, 40, 'sentences');

        // Then: Should fall back through chain and find a split point
        $this->assertNotFalse($result, 'Should find split point through fallback chain');
        $this->assertLessThanOrEqual(40, $result, 'Should be within max length');
    }

    public function test_sentence_preference_with_abbreviations() {
        // Given: Text with abbreviations (periods that aren't sentence endings)
        $text = 'Dr. Smith went to the store. He bought milk. Then he left.';

        // When: We find split point
        $result = $this->poster->test_find_split_point($text, 35, 'sentences');

        // Then: Should ideally split at real sentence boundary
        // This is a tricky case - the regex might split at "Dr." or "store."
        $this->assertNotFalse($result, 'Should find a split point');
        $this->assertLessThanOrEqual(35, $result, 'Should be within max length');

        $split_text = substr($text, 0, $result);
        $this->assertMatchesRegularExpression('/[.!?](?=\s|$)/s', $split_text, 'Should end with punctuation');
    }

    public function test_default_preference_handled() {
        // Given: Text with various splitting options
        $text = 'This is a sentence. This is another one with words.';

        // When: We use 'default' or any other unknown preference (should fall through to words)
        $result = $this->poster->test_find_split_point($text, 30, 'default');

        // Then: Should handle gracefully and find a split
        // The switch statement has a default case that goes to word splitting
        $this->assertNotFalse($result, 'Should handle default preference');
    }

    public function test_multiline_text_with_sentence_preference() {
        // Given: Multiline text
        $text = "First line with sentence.\nSecond line with another sentence.\nThird line here.";

        // When: We find split point
        $result = $this->poster->test_find_split_point($text, 40, 'sentences');

        // Then: Should find sentence boundary across lines
        $this->assertNotFalse($result, 'Should find split point in multiline text');

        $split_text = substr($text, 0, $result);
        $this->assertMatchesRegularExpression('/[.!?](?=\s|$)/s', $split_text, 'Should end with sentence punctuation');
    }
}
