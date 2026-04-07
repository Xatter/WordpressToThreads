jQuery(document).ready(function($) {
    var currentStep = parseInt(threads_wizard.initial_step) || 1;
    var totalSteps = 6;

    function showStep(step) {
        if (step < 1) step = 1;
        if (step > totalSteps) step = totalSteps;

        $('.wizard-step').removeClass('active');
        $('#wizard-step-' + step).addClass('active');
        updateProgressBar(step);
        currentStep = step;

        // Update URL without reload
        if (window.history.replaceState) {
            var url = new URL(window.location);
            url.searchParams.set('step', step);
            window.history.replaceState({}, '', url);
        }

        // Scroll to top of wizard
        $('html, body').animate({ scrollTop: $('.threads-setup-wizard').offset().top - 40 }, 200);
    }

    function updateProgressBar(step) {
        $('.wizard-progress-step').each(function() {
            var stepNum = parseInt($(this).data('step'));
            $(this).removeClass('active completed');
            if (stepNum === step) {
                $(this).addClass('active');
            } else if (stepNum < step) {
                $(this).addClass('completed');
            }
        });
    }

    // Steps that have credential fields to save before advancing
    var credentialSteps = {
        2: 'threads',
        3: 'x',
        4: 'bitly'
    };

    // Shared save function for credential steps. Returns a jQuery Deferred.
    // onSuccess callback is called after a successful save.
    function saveStepCredentials(stepNum, onSuccess) {
        var stepKey = credentialSteps[stepNum];
        if (!stepKey) {
            // Not a credential step, nothing to save
            if (onSuccess) onSuccess();
            return;
        }

        var $stepEl = $('#wizard-step-' + stepNum);
        var $status = $stepEl.find('.wizard-save-status');
        var $saveBtn = $stepEl.find('.wizard-save-credentials');
        var $spinner = $saveBtn.find('.wizard-spinner');

        // Check if there are any filled-in fields worth saving
        var hasValues = false;
        $stepEl.find('input[data-wizard-field]').each(function() {
            if ($(this).val().trim()) hasValues = true;
        });

        if (!hasValues) {
            // No credentials entered, just proceed
            if (onSuccess) onSuccess();
            return;
        }

        $saveBtn.prop('disabled', true);
        $spinner.addClass('active');
        $status.removeClass('success error').hide();

        var data = {
            action: 'threads_wizard_save',
            nonce: threads_wizard.nonce,
            step: stepKey
        };

        $stepEl.find('input[data-wizard-field]').each(function() {
            data[$(this).data('wizard-field')] = $(this).val();
        });

        $.post(threads_wizard.ajax_url, data, function(response) {
            $saveBtn.prop('disabled', false);
            $spinner.removeClass('active');

            if (response.success) {
                $status.text('Saved!').addClass('success').show();

                // Show OAuth authorize section if URL returned
                if (response.data && response.data.authorize_url) {
                    var $authActions = $stepEl.find('.wizard-auth-actions');
                    $authActions.find('.wizard-oauth-btn').attr('href', response.data.authorize_url);
                    $authActions.addClass('visible');
                }

                if (onSuccess) onSuccess();
            } else {
                var msg = (response.data && typeof response.data === 'string') ? response.data : 'Failed to save. Please try again.';
                $status.text(msg).addClass('error').show();
            }
        }).fail(function() {
            $saveBtn.prop('disabled', false);
            $spinner.removeClass('active');
            $status.text('Connection error. Please try again.').addClass('error').show();
        });
    }

    // Navigation: Next (saves credentials on steps 2-4 before advancing)
    $(document).on('click', '.wizard-next', function(e) {
        e.preventDefault();
        var nextStep = currentStep + 1;

        if (credentialSteps[currentStep]) {
            saveStepCredentials(currentStep, function() {
                showStep(nextStep);
            });
        } else {
            showStep(nextStep);
        }
    });

    // Navigation: Back
    $(document).on('click', '.wizard-back', function(e) {
        e.preventDefault();
        showStep(currentStep - 1);
    });

    // Navigation: Skip
    $(document).on('click', '.wizard-skip', function(e) {
        e.preventDefault();
        showStep(currentStep + 1);
    });

    // Navigation: Go to specific step
    $(document).on('click', '.wizard-goto', function(e) {
        e.preventDefault();
        var step = parseInt($(this).data('step'));
        if (step) showStep(step);
    });

    // Save Credentials button (stays on current step, shows OAuth section)
    $(document).on('click', '.wizard-save-credentials', function(e) {
        e.preventDefault();
        saveStepCredentials(currentStep);
    });

    // Before OAuth redirect, set the return transient
    $(document).on('click', '.wizard-oauth-btn', function(e) {
        e.preventDefault();
        var $link = $(this);
        var platform = $link.data('platform');
        var href = $link.attr('href');

        if (!href || href === '#') {
            return;
        }

        $link.text('Redirecting...').prop('disabled', true);

        $.post(threads_wizard.ajax_url, {
            action: 'threads_wizard_set_oauth_return',
            nonce: threads_wizard.nonce,
            platform: platform,
            return_step: currentStep
        }, function() {
            window.location.href = href;
        }).fail(function() {
            // Still redirect even if transient fails — they'll just land on settings page instead
            window.location.href = href;
        });
    });

    // Save Preferences (Step 5)
    $(document).on('click', '.wizard-save-preferences', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $stepEl = $('#wizard-step-5');
        var $status = $stepEl.find('.wizard-save-status');
        var $spinner = $btn.find('.wizard-spinner');

        $btn.prop('disabled', true);
        $spinner.addClass('active');
        $status.removeClass('success error').hide();

        var data = {
            action: 'threads_wizard_save',
            nonce: threads_wizard.nonce,
            step: 'preferences',
            threads_auto_post_enabled: $stepEl.find('[data-wizard-field="threads_auto_post_enabled"]').is(':checked') ? '1' : '0',
            x_auto_post_enabled: $stepEl.find('[data-wizard-field="x_auto_post_enabled"]').is(':checked') ? '1' : '0',
            threads_include_media: $stepEl.find('[data-wizard-field="threads_include_media"]').is(':checked') ? '1' : '0',
            x_include_media: $stepEl.find('[data-wizard-field="x_include_media"]').is(':checked') ? '1' : '0',
            threads_media_priority: $stepEl.find('[data-wizard-field="threads_media_priority"]').val(),
            threads_enable_thread_chains: $stepEl.find('[data-wizard-field="threads_enable_thread_chains"]').is(':checked') ? '1' : '0',
            threads_max_chain_length: $stepEl.find('[data-wizard-field="threads_max_chain_length"]').val(),
            threads_split_preference: $stepEl.find('[data-wizard-field="threads_split_preference"]').val()
        };

        $.post(threads_wizard.ajax_url, data, function(response) {
            $btn.prop('disabled', false);
            $spinner.removeClass('active');

            if (response.success) {
                showStep(6);
            } else {
                $status.text('Failed to save preferences. Please try again.').addClass('error').show();
            }
        }).fail(function() {
            $btn.prop('disabled', false);
            $spinner.removeClass('active');
            $status.text('Connection error. Please try again.').addClass('error').show();
        });
    });

    // Finish Wizard
    $(document).on('click', '.wizard-finish', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var href = $btn.data('href');

        $.post(threads_wizard.ajax_url, {
            action: 'threads_wizard_save',
            nonce: threads_wizard.nonce,
            step: 'complete'
        }, function() {
            window.location.href = href;
        }).fail(function() {
            window.location.href = href;
        });
    });

    // Check if we returned from OAuth with success
    function checkOAuthReturn() {
        var urlParams = new URLSearchParams(window.location.search);

        if (urlParams.get('authorized') === '1' && threads_wizard.threads_authorized) {
            var $step = $('#wizard-step-2');
            $step.find('.wizard-auth-status')
                .html('<div class="wizard-callout wizard-callout-success"><strong>Connected to Threads!</strong> Your account has been authorized successfully.</div>')
                .addClass('visible');
            $step.find('.wizard-auth-actions').removeClass('visible');
            $step.find('.wizard-save-credentials').hide();
        }

        if (urlParams.get('x_authorized') === '1' && threads_wizard.x_authorized) {
            var $step = $('#wizard-step-3');
            $step.find('.wizard-auth-status')
                .html('<div class="wizard-callout wizard-callout-success"><strong>Connected to X!</strong> Your account has been authorized successfully' + (threads_wizard.x_username ? ' as @' + threads_wizard.x_username : '') + '.</div>')
                .addClass('visible');
            $step.find('.wizard-auth-actions').removeClass('visible');
            $step.find('.wizard-save-credentials').hide();
        }
    }

    // Show pre-filled OAuth state on load (if already authorized)
    function checkExistingAuth() {
        if (threads_wizard.threads_authorized) {
            var $step = $('#wizard-step-2');
            $step.find('.wizard-auth-status')
                .html('<div class="wizard-callout wizard-callout-success"><strong>Already connected to Threads!</strong> Your account is authorized. You can proceed to the next step or re-authorize if needed.</div>')
                .addClass('visible');
        }

        if (threads_wizard.x_authorized) {
            var $step = $('#wizard-step-3');
            $step.find('.wizard-auth-status')
                .html('<div class="wizard-callout wizard-callout-success"><strong>Already connected to X!</strong> Your account is authorized' + (threads_wizard.x_username ? ' as @' + threads_wizard.x_username : '') + '. You can proceed or re-authorize.</div>')
                .addClass('visible');
        }

        // If credentials already exist, show auth actions
        if (threads_wizard.has_threads_credentials) {
            $('#wizard-step-2 .wizard-auth-actions').addClass('visible');
        }
        if (threads_wizard.has_x_credentials) {
            $('#wizard-step-3 .wizard-auth-actions').addClass('visible');
        }
    }

    // Initialize
    checkOAuthReturn();
    checkExistingAuth();
    showStep(currentStep);
});
