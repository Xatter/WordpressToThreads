jQuery(document).ready(function($) {
    console.log('WordPress to Threads admin script loaded');
    console.log('threads_ajax object:', threads_ajax);
    
    // Handle thread chain options visibility
    function toggleThreadChainOptions() {
        var isEnabled = $('input[name="threads_enable_thread_chains"]').is(':checked');
        var maxChainRow = $('select[name="threads_max_chain_length"]').closest('tr');
        var splitMethodRow = $('select[name="threads_split_preference"]').closest('tr');
        
        if (isEnabled) {
            maxChainRow.show();
            splitMethodRow.show();
        } else {
            maxChainRow.hide();
            splitMethodRow.hide();
        }
    }
    
    // Initialize visibility on page load
    toggleThreadChainOptions();
    
    // Toggle visibility when checkbox changes
    $('input[name="threads_enable_thread_chains"]').on('change', function() {
        toggleThreadChainOptions();
    });
    
    $('.threads-post-btn').on('click', function(e) {
        e.preventDefault();
        console.log('Post button clicked');
        
        var button = $(this);
        var postId = button.data('post-id');
        var row = button.closest('tr');
        
        console.log('Post ID:', postId);
        console.log('Button element:', button);
        
        if (!postId) {
            alert('Error: No post ID found');
            return;
        }
        
        if (typeof threads_ajax === 'undefined') {
            alert('Error: AJAX configuration not loaded');
            return;
        }
        
        button.prop('disabled', true).text('Posting...');
        
        var ajaxData = {
            action: 'threads_manual_post',
            post_id: postId,
            nonce: threads_ajax.nonce
        };
        
        console.log('AJAX data:', ajaxData);
        console.log('AJAX URL:', threads_ajax.ajax_url);
        
        $.ajax({
            url: threads_ajax.ajax_url,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                console.log('AJAX Success Response:', response);
                
                if (response.success) {
                    // Reload the page to show updated status
                    location.reload();
                } else {
                    button.prop('disabled', false).text('Post to Threads');
                    
                    $('#threads-post-results').prepend(
                        '<div class="notice notice-error is-dismissible"><p>Error: ' + 
                        response.data + '</p></div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                console.log('AJAX Error:', {xhr: xhr, status: status, error: error});
                console.log('Response Text:', xhr.responseText);
                
                button.prop('disabled', false).text('Post to Threads');
                
                $('#threads-post-results').prepend(
                    '<div class="notice notice-error is-dismissible"><p>Network error: ' + error + ' (Status: ' + status + ')</p></div>'
                );
            }
        });
    });
    
    // Re-post button handler
    $('.threads-repost-btn').on('click', function(e) {
        e.preventDefault();
        console.log('Re-post button clicked');
        
        var button = $(this);
        var postId = button.data('post-id');
        var row = button.closest('tr');
        
        console.log('Re-post Post ID:', postId);
        
        if (!postId) {
            alert('Error: No post ID found');
            return;
        }
        
        if (typeof threads_ajax === 'undefined') {
            alert('Error: AJAX configuration not loaded');
            return;
        }
        
        // Confirm re-posting
        if (!confirm('Are you sure you want to re-post this to Threads? This will create a new post even if it was already shared.')) {
            return;
        }
        
        button.prop('disabled', true).text('Re-posting...');
        
        var ajaxData = {
            action: 'threads_repost',
            post_id: postId,
            nonce: threads_ajax.nonce
        };
        
        console.log('Re-post AJAX data:', ajaxData);
        
        $.ajax({
            url: threads_ajax.ajax_url,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                console.log('Re-post AJAX Success Response:', response);
                
                if (response.success) {
                    // Reload the page to show updated status
                    location.reload();
                } else {
                    button.prop('disabled', false).text('Re-post');
                    
                    $('#threads-post-results').prepend(
                        '<div class="notice notice-error is-dismissible"><p>Error: ' + 
                        response.data + '</p></div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                console.log('Re-post AJAX Error:', {xhr: xhr, status: status, error: error});
                
                button.prop('disabled', false).text('Re-post');
                
                $('#threads-post-results').prepend(
                    '<div class="notice notice-error is-dismissible"><p>Network error: ' + error + ' (Status: ' + status + ')</p></div>'
                );
            }
        });
    });
    
    $(document).on('click', '.notice-dismiss', function() {
        $(this).closest('.notice').fadeOut();
    });
});