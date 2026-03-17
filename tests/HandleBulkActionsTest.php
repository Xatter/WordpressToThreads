<?php
/**
 * Tests for handle_bulk_actions method
 * Verifies that bulk actions always schedule posts regardless of prior posting status
 */

use PHPUnit\Framework\TestCase;

class HandleBulkActionsTest extends TestCase {

    private $poster;

    protected function setUp(): void {
        parent::setUp();
        clear_test_options();
        clear_test_post_meta();
        clear_scheduled_events();
        clear_mock_posts();
        $this->poster = new TestableThreadsAutoPoster();
    }

    protected function tearDown(): void {
        parent::tearDown();
        clear_test_options();
        clear_test_post_meta();
        clear_scheduled_events();
        clear_mock_posts();
    }

    /**
     * Test that bulk action schedules posts that have NOT been posted before
     */
    public function test_bulk_action_schedules_unposted_posts() {
        $post = create_mock_post(1, 'Test Post', 'Test content');
        set_mock_post(1, $post);
        set_test_option('bulk_post_stagger_interval', '0');

        $redirect = $this->poster->handle_bulk_actions('http://example.com', 'threads_post_to_threads', array(1));

        $this->assertStringContainsString('threads_bulk_scheduled=1', $redirect);

        $events = get_scheduled_events();
        $this->assertCount(1, $events);
        $this->assertEquals('threads_scheduled_post_to_threads', $events[0]['hook']);
        $this->assertEquals(array(1), $events[0]['args']);
    }

    /**
     * Test that bulk action STILL schedules posts that have already been posted
     * This is the key behavior change: bulk actions should always post
     */
    public function test_bulk_action_schedules_already_posted_posts() {
        $post = create_mock_post(1, 'Test Post', 'Test content');
        set_mock_post(1, $post);
        set_test_post_meta(1, '_threads_posted', '1');
        set_test_option('bulk_post_stagger_interval', '0');

        $redirect = $this->poster->handle_bulk_actions('http://example.com', 'threads_post_to_threads', array(1));

        $this->assertStringContainsString('threads_bulk_scheduled=1', $redirect);
        $this->assertStringNotContainsString('threads_bulk_already_posted', $redirect);

        $events = get_scheduled_events();
        $this->assertCount(1, $events);
    }

    /**
     * Test bulk action for X also schedules already-posted posts
     */
    public function test_bulk_action_schedules_already_posted_x_posts() {
        $post = create_mock_post(1, 'Test Post', 'Test content');
        set_mock_post(1, $post);
        set_test_post_meta(1, '_x_posted', '1');
        set_test_option('bulk_post_stagger_interval', '0');

        $redirect = $this->poster->handle_bulk_actions('http://example.com', 'threads_post_to_x', array(1));

        $this->assertStringContainsString('x_bulk_scheduled=1', $redirect);
        $this->assertStringNotContainsString('x_bulk_already_posted', $redirect);
    }

    /**
     * Test bulk action for 'both' schedules even if already posted to both
     */
    public function test_bulk_action_both_schedules_already_posted() {
        $post = create_mock_post(1, 'Test Post', 'Test content');
        set_mock_post(1, $post);
        set_test_post_meta(1, '_threads_posted', '1');
        set_test_post_meta(1, '_x_posted', '1');
        set_test_option('bulk_post_stagger_interval', '0');

        $redirect = $this->poster->handle_bulk_actions('http://example.com', 'threads_post_to_both', array(1));

        $this->assertStringContainsString('threads_bulk_scheduled=1', $redirect);
        $this->assertStringContainsString('x_bulk_scheduled=1', $redirect);
    }

    /**
     * Test that stagger interval still works with the change
     */
    public function test_bulk_action_stagger_works_with_multiple_posts() {
        $post1 = create_mock_post(1, 'Post 1', 'Content 1');
        $post2 = create_mock_post(2, 'Post 2', 'Content 2');
        set_mock_post(1, $post1);
        set_mock_post(2, $post2);
        set_test_post_meta(1, '_threads_posted', '1'); // already posted
        set_test_option('bulk_post_stagger_interval', '3600');

        $redirect = $this->poster->handle_bulk_actions('http://example.com', 'threads_post_to_threads', array(1, 2));

        $this->assertStringContainsString('threads_bulk_scheduled=2', $redirect);

        $events = get_scheduled_events();
        $this->assertCount(2, $events);
        // Second event should be staggered
        $this->assertGreaterThan($events[0]['time'], $events[1]['time']);
    }

    /**
     * Test non-published posts are still skipped
     */
    public function test_bulk_action_skips_non_published_posts() {
        $post = create_mock_post(1, 'Draft Post', 'Content');
        $post->post_status = 'draft';
        set_mock_post(1, $post);

        $redirect = $this->poster->handle_bulk_actions('http://example.com', 'threads_post_to_threads', array(1));

        $this->assertStringContainsString('threads_bulk_scheduled=0', $redirect);
        $events = get_scheduled_events();
        $this->assertCount(0, $events);
    }
}
