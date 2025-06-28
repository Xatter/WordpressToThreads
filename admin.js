jQuery(document).ready(function($) {
    console.log('Wordpress to Threads admin script loaded');
    console.log('threads_ajax object:', threads_ajax);
    
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
    
    $(document).on('click', '.notice-dismiss', function() {
        $(this).closest('.notice').fadeOut();
    });
});