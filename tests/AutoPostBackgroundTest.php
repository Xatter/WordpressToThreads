<?php
/**
 * Tests for auto_post_to_threads background scheduling
 * Verifies that publishing a post schedules background cron events
 * instead of posting synchronously
 */

use PHPUnit\Framework\TestCase;

class AutoPostBackgroundTest extends TestCase {

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
     * Test that auto_post_to_threads schedules a cron event instead of posting synchronously
     */
    public function test_auto_post_schedules_background_event_for_threads() {
        set_test_option('threads_auto_post_enabled', '1');
        $post = create_mock_post(1, 'Test Post', 'Test content');

        $this->poster->auto_post_to_threads(1, $post);

        $events = get_scheduled_events();
        $threads_events = array_filter($events, function($e) {
            return $e['hook'] === 'threads_scheduled_post_to_threads';
        });
        $this->assertCount(1, $threads_events);
        $event = array_values($threads_events)[0];
        $this->assertEquals(array(1, 'auto'), $event['args']);
    }

    /**
     * Test that auto_post_to_threads schedules a cron event for X
     */
    public function test_auto_post_schedules_background_event_for_x() {
        set_test_option('x_auto_post_enabled', '1');
        $post = create_mock_post(1, 'Test Post', 'Test content');

        $this->poster->auto_post_to_threads(1, $post);

        $events = get_scheduled_events();
        $x_events = array_filter($events, function($e) {
            return $e['hook'] === 'threads_scheduled_post_to_x';
        });
        $this->assertCount(1, $x_events);
    }

    /**
     * Test that auto_post_to_threads does NOT schedule if already posted
     */
    public function test_auto_post_does_not_schedule_if_already_posted() {
        set_test_option('threads_auto_post_enabled', '1');
        set_test_post_meta(1, '_threads_posted', '1');
        $post = create_mock_post(1, 'Test Post', 'Test content');

        $this->poster->auto_post_to_threads(1, $post);

        $events = get_scheduled_events();
        $this->assertCount(0, $events);
    }

    /**
     * Test that non-post types are ignored
     */
    public function test_auto_post_ignores_non_post_types() {
        set_test_option('threads_auto_post_enabled', '1');
        $post = create_mock_post(1, 'Test Page', 'Test content');
        $post->post_type = 'page';

        $this->poster->auto_post_to_threads(1, $post);

        $events = get_scheduled_events();
        $this->assertCount(0, $events);
    }

    /**
     * Test that draft posts are ignored
     */
    public function test_auto_post_ignores_drafts() {
        set_test_option('threads_auto_post_enabled', '1');
        $post = create_mock_post(1, 'Draft Post', 'Test content');
        $post->post_status = 'draft';

        $this->poster->auto_post_to_threads(1, $post);

        $events = get_scheduled_events();
        $this->assertCount(0, $events);
    }

    /**
     * Test that publish_choice 'none' skips scheduling
     */
    public function test_auto_post_skips_when_choice_is_none() {
        set_test_option('threads_auto_post_enabled', '1');
        set_test_post_meta(1, '_threads_publish_choice', 'none');
        $post = create_mock_post(1, 'Test Post', 'Test content');

        $this->poster->auto_post_to_threads(1, $post);

        $events = get_scheduled_events();
        $this->assertCount(0, $events);
    }

    /**
     * Test that publish_choice 'single' is passed through to the scheduled event
     */
    public function test_auto_post_passes_single_choice_to_scheduled_event() {
        set_test_option('threads_auto_post_enabled', '1');
        set_test_post_meta(1, '_threads_publish_choice', 'single');
        $post = create_mock_post(1, 'Test Post', 'Test content');

        $this->poster->auto_post_to_threads(1, $post);

        $events = get_scheduled_events();
        $threads_events = array_filter($events, function($e) {
            return $e['hook'] === 'threads_scheduled_post_to_threads';
        });
        $this->assertCount(1, $threads_events);
        $event = array_values($threads_events)[0];
        $this->assertEquals(array(1, 'single'), $event['args']);
    }

    /**
     * Test that publish_choice 'chain' is passed through to the scheduled event
     */
    public function test_auto_post_passes_chain_choice_to_scheduled_event() {
        set_test_option('threads_auto_post_enabled', '1');
        set_test_post_meta(1, '_threads_publish_choice', 'chain');
        $post = create_mock_post(1, 'Test Post', 'Test content');

        $this->poster->auto_post_to_threads(1, $post);

        $events = get_scheduled_events();
        $threads_events = array_filter($events, function($e) {
            return $e['hook'] === 'threads_scheduled_post_to_threads';
        });
        $this->assertCount(1, $threads_events);
        $event = array_values($threads_events)[0];
        $this->assertEquals(array(1, 'chain'), $event['args']);
    }

    /**
     * Test that both Threads and X are scheduled when both enabled
     */
    public function test_auto_post_schedules_both_when_both_enabled() {
        set_test_option('threads_auto_post_enabled', '1');
        set_test_option('x_auto_post_enabled', '1');
        $post = create_mock_post(1, 'Test Post', 'Test content');

        $this->poster->auto_post_to_threads(1, $post);

        $events = get_scheduled_events();
        $this->assertCount(2, $events);
        $hooks = array_column($events, 'hook');
        $this->assertContains('threads_scheduled_post_to_threads', $hooks);
        $this->assertContains('threads_scheduled_post_to_x', $hooks);
    }
}
