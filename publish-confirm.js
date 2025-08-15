jQuery(document).ready(function($) {
    console.log('Threads publish confirmation script loaded');
    
    // Only proceed if auto-posting is enabled
    if (!threads_publish_ajax.auto_post_enabled) {
        return;
    }
    
    var originalSubmit = null;
    var publishChoice = null;
    
    // Override the publish button behavior
    function interceptPublish() {
        var publishButton = $('#publish, #save-post');
        
        if (publishButton.length === 0) {
            return;
        }
        
        // Store the original click handler
        if (!originalSubmit) {
            originalSubmit = publishButton.attr('onclick');
            publishButton.removeAttr('onclick');
            
            // Remove existing event handlers
            publishButton.off('click');
        }
        
        publishButton.on('click.threads', function(e) {
            var postStatus = $('#post_status').val() || $('#original_post_status').val();
            var currentStatus = $('#original_post_status').val();
            
            // Only show dialog when publishing (not for drafts, updates, etc.)
            if (postStatus !== 'publish' || currentStatus === 'publish') {
                return true; // Allow normal publishing
            }
            
            e.preventDefault();
            e.stopPropagation();
            
            showPublishDialog(this);
            
            return false;
        });
    }
    
    function showPublishDialog(publishButton) {
        var dialog = $(`
            <div id="threads-publish-dialog" style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 999999;
                display: flex;
                align-items: center;
                justify-content: center;
            ">
                <div style="
                    background: white;
                    padding: 30px;
                    border-radius: 8px;
                    max-width: 500px;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                ">
                    <h3 style="margin-top: 0; color: #333;">Post to Threads?</h3>
                    <p style="margin-bottom: 25px; color: #666;">How would you like to share this post on Threads?</p>
                    
                    <div style="margin-bottom: 25px;">
                        <label style="display: block; margin-bottom: 15px; cursor: pointer;">
                            <input type="radio" name="threads_publish_type" value="single" style="margin-right: 10px;" checked>
                            <strong>Single post with link</strong><br>
                            <small style="color: #666; margin-left: 25px;">Share as a single post with title and link to full article</small>
                        </label>
                        
                        <label style="display: block; margin-bottom: 15px; cursor: pointer;">
                            <input type="radio" name="threads_publish_type" value="chain" style="margin-right: 10px;">
                            <strong>Thread chain</strong><br>
                            <small style="color: #666; margin-left: 25px;">Break the full content into multiple connected posts</small>
                        </label>
                        
                        <label style="display: block; cursor: pointer;">
                            <input type="radio" name="threads_publish_type" value="none" style="margin-right: 10px;">
                            <strong>Don't post to Threads</strong><br>
                            <small style="color: #666; margin-left: 25px;">Just publish the WordPress post</small>
                        </label>
                    </div>
                    
                    <div style="text-align: right;">
                        <button id="threads-dialog-cancel" style="
                            margin-right: 10px;
                            padding: 8px 16px;
                            background: #f1f1f1;
                            border: 1px solid #ccc;
                            border-radius: 4px;
                            cursor: pointer;
                        ">Cancel</button>
                        
                        <button id="threads-dialog-confirm" style="
                            padding: 8px 16px;
                            background: #0073aa;
                            color: white;
                            border: none;
                            border-radius: 4px;
                            cursor: pointer;
                        ">Publish Post</button>
                    </div>
                </div>
            </div>
        `);
        
        $('body').append(dialog);
        
        // Handle dialog buttons
        $('#threads-dialog-cancel').on('click', function() {
            dialog.remove();
        });
        
        $('#threads-dialog-confirm').on('click', function() {
            publishChoice = $('input[name="threads_publish_type"]:checked').val();
            dialog.remove();
            
            // Store the choice and proceed with publish
            storePublishChoice(publishChoice);
            proceedWithPublish(publishButton);
        });
        
        // Close on overlay click
        dialog.on('click', function(e) {
            if (e.target === this) {
                dialog.remove();
            }
        });
    }
    
    function storePublishChoice(choice) {
        // Store the choice in a hidden field or session storage
        var postId = $('#post_ID').val();
        if (postId && choice !== 'none') {
            $.ajax({
                url: threads_publish_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'threads_store_publish_choice',
                    post_id: postId,
                    choice: choice,
                    nonce: threads_publish_ajax.nonce
                },
                async: false // Wait for this to complete
            });
        }
    }
    
    function proceedWithPublish(publishButton) {
        // Remove our event handler temporarily
        $(publishButton).off('click.threads');
        
        // Restore original onclick if it existed
        if (originalSubmit) {
            $(publishButton).attr('onclick', originalSubmit);
        }
        
        // Trigger the original publish action
        $(publishButton).trigger('click');
        
        // Re-attach our handler after a brief delay
        setTimeout(function() {
            interceptPublish();
        }, 1000);
    }
    
    // Initialize when the page loads
    setTimeout(interceptPublish, 500);
    
    // Re-initialize if the page content changes (for block editor)
    if (window.wp && window.wp.data) {
        var unsubscribe = window.wp.data.subscribe(function() {
            setTimeout(interceptPublish, 100);
        });
    }
});