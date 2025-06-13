jQuery(document).ready(function($) {
    $('.threads-post-btn').on('click', function() {
        var button = $(this);
        var postId = button.data('post-id');
        var row = button.closest('tr');
        
        button.prop('disabled', true).text('Posting...');
        
        $.ajax({
            url: threads_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'threads_manual_post',
                post_id: postId,
                nonce: threads_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    row.find('td:nth-child(3)').html('<span style="color: green;">✓ Posted</span>');
                    button.removeClass('button-primary').prop('disabled', true).text('Posted');
                    
                    $('#threads-post-results').prepend(
                        '<div class="notice notice-success is-dismissible"><p>' + 
                        response.data + '</p></div>'
                    );
                } else {
                    button.prop('disabled', false).text('Post to Threads');
                    
                    $('#threads-post-results').prepend(
                        '<div class="notice notice-error is-dismissible"><p>Error: ' + 
                        response.data + '</p></div>'
                    );
                }
            },
            error: function() {
                button.prop('disabled', false).text('Post to Threads');
                
                $('#threads-post-results').prepend(
                    '<div class="notice notice-error is-dismissible"><p>Network error occurred. Please try again.</p></div>'
                );
            }
        });
    });
    
    $(document).on('click', '.notice-dismiss', function() {
        $(this).closest('.notice').fadeOut();
    });
});