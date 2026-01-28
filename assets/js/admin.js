/**
 * Git Plugin Loader Admin JavaScript
 *
 * @package Git_Plugin_Loader
 */

(function($) {
    'use strict';

    var GPL = {
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Add new plugin button
            $(document).on('click', '.gpl-add-new-btn', this.showAddForm);
            $(document).on('click', '.gpl-cancel-add', this.hideAddForm);

            // Repository URL validation
            $(document).on('blur', '#gpl-repo-url', this.onRepoUrlBlur);
            $(document).on('click', '#gpl-load-refs', this.loadRefs);

            // Add plugin form submit
            $(document).on('submit', '#gpl-add-plugin-form', this.addPlugin);

            // Plugin actions
            $(document).on('click', '.gpl-sync-btn', this.syncPlugin);
            $(document).on('click', '.gpl-check-btn', this.checkUpdates);
            $(document).on('click', '.gpl-export-btn', this.exportPlugin);
            $(document).on('click', '.gpl-remove-btn', this.removePlugin);

            // Auto-sync toggle
            $(document).on('change', '.gpl-autosync-toggle', this.toggleAutosync);

            // Branch change
            $(document).on('click', '.gpl-load-branches', this.loadBranches);
            $(document).on('change', '.gpl-branch-select', this.changeBranch);

            // More actions dropdown
            $(document).on('click', '.gpl-more-btn', this.toggleDropdown);
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.gpl-more-actions').length) {
                    $('.gpl-dropdown').removeClass('active');
                }
            });

            // Settings form
            $(document).on('submit', '#gpl-settings-form', this.saveSettings);
        },

        /**
         * Show add plugin form
         */
        showAddForm: function(e) {
            e.preventDefault();
            $('.gpl-add-form').slideDown();
            $('#gpl-repo-url').focus();
        },

        /**
         * Hide add plugin form
         */
        hideAddForm: function(e) {
            e.preventDefault();
            $('.gpl-add-form').slideUp();
            $('#gpl-add-plugin-form')[0].reset();
            GPL.resetBranchSelect();
        },

        /**
         * Handle repository URL blur
         */
        onRepoUrlBlur: function() {
            var url = $(this).val().trim();
            if (url) {
                GPL.validateRepo(url);
            }
        },

        /**
         * Validate repository URL
         */
        validateRepo: function(url) {
            var $status = $('.gpl-status-message');
            var $loadBtn = $('#gpl-load-refs');

            $status.text(gplAdmin.strings.validating).removeClass('error success');
            $loadBtn.prop('disabled', true);

            $.ajax({
                url: gplAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'gpl_validate_repo',
                    nonce: gplAdmin.nonce,
                    url: url
                },
                success: function(response) {
                    if (response.success) {
                        $status.text('').removeClass('error');
                        $loadBtn.prop('disabled', false);

                        // Auto-fill slug if empty
                        if (!$('#gpl-slug').val()) {
                            $('#gpl-slug').attr('placeholder', response.data.repo);
                        }
                    } else {
                        $status.text(response.data.message).addClass('error');
                    }
                },
                error: function() {
                    $status.text(gplAdmin.strings.error).addClass('error');
                }
            });
        },

        /**
         * Load branches and tags
         */
        loadRefs: function(e) {
            e.preventDefault();

            var url = $('#gpl-repo-url').val().trim();
            if (!url) return;

            var $btn = $(this);
            var $select = $('#gpl-branch');

            $btn.prop('disabled', true).text(gplAdmin.strings.checking);

            $.ajax({
                url: gplAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'gpl_get_refs',
                    nonce: gplAdmin.nonce,
                    url: url
                },
                success: function(response) {
                    if (response.success) {
                        GPL.populateBranchSelect($select, response.data);
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function() {
                    alert(gplAdmin.strings.error);
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Load Branches/Tags');
                }
            });
        },

        /**
         * Populate branch select dropdown
         */
        populateBranchSelect: function($select, data) {
            $select.empty();

            // Add branches
            if (data.branches && data.branches.length) {
                var $branchGroup = $('<optgroup label="Branches">');
                $.each(data.branches, function(i, branch) {
                    $branchGroup.append(
                        $('<option>').val(branch.name).text(branch.name)
                    );
                });
                $select.append($branchGroup);
            }

            // Add tags
            if (data.tags && data.tags.length) {
                var $tagGroup = $('<optgroup label="Tags">');
                $.each(data.tags, function(i, tag) {
                    $tagGroup.append(
                        $('<option>').val(tag.name).text(tag.name)
                    );
                });
                $select.append($tagGroup);
            }

            // Select current branch if specified
            if (data.current_branch) {
                $select.val(data.current_branch);
            }
        },

        /**
         * Reset branch select to default
         */
        resetBranchSelect: function() {
            $('#gpl-branch').html('<option value="main">main</option>');
        },

        /**
         * Add plugin
         */
        addPlugin: function(e) {
            e.preventDefault();

            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            var $spinner = $form.find('.spinner');
            var $status = $form.find('.gpl-status-message');

            var url = $('#gpl-repo-url').val().trim();
            var branch = $('#gpl-branch').val();
            var slug = $('#gpl-slug').val().trim();

            if (!url) {
                alert('Please enter a repository URL.');
                return;
            }

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $status.text(gplAdmin.strings.cloning).removeClass('error success');

            $.ajax({
                url: gplAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'gpl_add_plugin',
                    nonce: gplAdmin.nonce,
                    url: url,
                    branch: branch,
                    slug: slug
                },
                success: function(response) {
                    if (response.success) {
                        $status.text(response.data.message).addClass('success');
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        $status.text(response.data.message).addClass('error');
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    $status.text(gplAdmin.strings.error).addClass('error');
                    $btn.prop('disabled', false);
                },
                complete: function() {
                    $spinner.removeClass('is-active');
                }
            });
        },

        /**
         * Sync plugin
         */
        syncPlugin: function(e) {
            e.preventDefault();

            var $btn = $(this);
            var $row = $btn.closest('tr');
            var slug = $btn.data('slug');
            var $spinner = $row.find('.spinner');
            var $status = $row.find('.gpl-status-badge');

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $status.text(gplAdmin.strings.syncing).removeClass('gpl-status-ok gpl-status-update gpl-status-error').addClass('gpl-status-syncing');

            $.ajax({
                url: gplAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'gpl_sync_plugin',
                    nonce: gplAdmin.nonce,
                    slug: slug
                },
                success: function(response) {
                    if (response.success) {
                        GPL.updatePluginRow($row, response.data.plugin);
                    } else {
                        alert(response.data.message);
                        $status.text('Error').removeClass('gpl-status-syncing').addClass('gpl-status-error');
                    }
                },
                error: function() {
                    alert(gplAdmin.strings.error);
                    $status.text('Error').removeClass('gpl-status-syncing').addClass('gpl-status-error');
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        },

        /**
         * Check for updates
         */
        checkUpdates: function(e) {
            e.preventDefault();

            var $btn = $(this);
            var $row = $btn.closest('tr');
            var slug = $btn.data('slug');
            var $spinner = $row.find('.spinner');
            var $status = $row.find('.gpl-status-badge');

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');

            $.ajax({
                url: gplAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'gpl_check_updates',
                    nonce: gplAdmin.nonce,
                    slug: slug
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.has_update) {
                            $status.text('Update available')
                                .removeClass('gpl-status-ok gpl-status-syncing gpl-status-error')
                                .addClass('gpl-status-update');
                        } else {
                            $status.text('Up to date')
                                .removeClass('gpl-status-update gpl-status-syncing gpl-status-error')
                                .addClass('gpl-status-ok');
                        }
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function() {
                    alert(gplAdmin.strings.error);
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        },

        /**
         * Export plugin
         */
        exportPlugin: function(e) {
            e.preventDefault();

            var $btn = $(this);
            var $row = $btn.closest('tr');
            var slug = $btn.data('slug');
            var $spinner = $row.find('.spinner');

            $btn.prop('disabled', true).text(gplAdmin.strings.exporting);
            $spinner.addClass('is-active');

            $.ajax({
                url: gplAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'gpl_export_plugin',
                    nonce: gplAdmin.nonce,
                    slug: slug
                },
                success: function(response) {
                    if (response.success) {
                        // Trigger download
                        window.location.href = response.data.url;
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function() {
                    alert(gplAdmin.strings.error);
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Export');
                    $spinner.removeClass('is-active');
                }
            });
        },

        /**
         * Remove plugin
         */
        removePlugin: function(e) {
            e.preventDefault();

            if (!confirm(gplAdmin.strings.confirmDelete)) {
                return;
            }

            var deleteFiles = confirm(gplAdmin.strings.confirmDeleteFiles);

            var $btn = $(this);
            var $row = $btn.closest('tr');
            var slug = $btn.data('slug');

            $.ajax({
                url: gplAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'gpl_remove_plugin',
                    nonce: gplAdmin.nonce,
                    slug: slug,
                    delete_files: deleteFiles ? 'true' : 'false'
                },
                success: function(response) {
                    if (response.success) {
                        $row.fadeOut(function() {
                            $(this).remove();
                            if ($('.gpl-plugins-table tbody tr').length === 0) {
                                location.reload();
                            }
                        });
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function() {
                    alert(gplAdmin.strings.error);
                }
            });
        },

        /**
         * Toggle auto-sync
         */
        toggleAutosync: function() {
            var $checkbox = $(this);
            var slug = $checkbox.data('slug');
            var enabled = $checkbox.is(':checked');

            $.ajax({
                url: gplAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'gpl_toggle_autosync',
                    nonce: gplAdmin.nonce,
                    slug: slug,
                    enabled: enabled ? 'true' : 'false'
                },
                success: function(response) {
                    if (response.success) {
                        var $row = $checkbox.closest('tr');
                        var $name = $row.find('.column-name');

                        if (enabled) {
                            if (!$name.find('.gpl-badge-autosync').length) {
                                $name.find('strong').after('<span class="gpl-badge gpl-badge-autosync">Auto-sync</span>');
                            }
                        } else {
                            $name.find('.gpl-badge-autosync').remove();
                        }
                    } else {
                        alert(response.data.message);
                        $checkbox.prop('checked', !enabled);
                    }
                },
                error: function() {
                    alert(gplAdmin.strings.error);
                    $checkbox.prop('checked', !enabled);
                }
            });
        },

        /**
         * Load branches for existing plugin
         */
        loadBranches: function(e) {
            e.preventDefault();

            var $btn = $(this);
            var slug = $btn.data('slug');
            var $select = $btn.siblings('.gpl-branch-select');

            $btn.text(gplAdmin.strings.checking);

            $.ajax({
                url: gplAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'gpl_get_refs',
                    nonce: gplAdmin.nonce,
                    slug: slug
                },
                success: function(response) {
                    if (response.success) {
                        GPL.populateBranchSelect($select, response.data);
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function() {
                    alert(gplAdmin.strings.error);
                },
                complete: function() {
                    $btn.text('Change');
                }
            });
        },

        /**
         * Change branch
         */
        changeBranch: function() {
            var $select = $(this);
            var slug = $select.data('slug');
            var branch = $select.val();
            var $row = $select.closest('tr');
            var $spinner = $row.find('.spinner');

            $spinner.addClass('is-active');

            $.ajax({
                url: gplAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'gpl_change_branch',
                    nonce: gplAdmin.nonce,
                    slug: slug,
                    branch: branch
                },
                success: function(response) {
                    if (response.success) {
                        GPL.updatePluginRow($row, response.data.plugin);
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function() {
                    alert(gplAdmin.strings.error);
                },
                complete: function() {
                    $spinner.removeClass('is-active');
                }
            });
        },

        /**
         * Toggle dropdown menu
         */
        toggleDropdown: function(e) {
            e.preventDefault();
            e.stopPropagation();
            var $dropdown = $(this).siblings('.gpl-dropdown');
            $('.gpl-dropdown').not($dropdown).removeClass('active');
            $dropdown.toggleClass('active');
        },

        /**
         * Update plugin row with new data
         */
        updatePluginRow: function($row, plugin) {
            // Update commit info
            $row.find('.gpl-commit-info code').text(plugin.local_commit.substring(0, 7));

            // Update status
            var statusClass = 'gpl-status-ok';
            var statusText = 'Up to date';

            if (plugin.status === 'update_available') {
                statusClass = 'gpl-status-update';
                statusText = 'Update available';
            } else if (plugin.status === 'error') {
                statusClass = 'gpl-status-error';
                statusText = 'Error';
            }

            $row.find('.gpl-status-badge')
                .removeClass('gpl-status-ok gpl-status-update gpl-status-syncing gpl-status-error')
                .addClass(statusClass)
                .text(statusText);

            // Update last sync time
            $row.find('.column-last-sync').text('Just now');
        },

        /**
         * Save settings
         */
        saveSettings: function(e) {
            e.preventDefault();

            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            var $spinner = $form.find('.spinner');

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');

            var data = {
                action: 'gpl_save_settings',
                nonce: gplAdmin.nonce,
                github_token: $('#gpl-github-token').val(),
                clear_token: $('input[name="clear_token"]').is(':checked') ? '1' : '0',
                auto_sync_interval: $('#gpl-sync-interval').val(),
                export_exclusions: $('#gpl-export-exclusions').val(),
                cleanup_exports_after: $('#gpl-cleanup-hours').val()
            };

            $.ajax({
                url: gplAdmin.ajaxUrl,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function() {
                    alert(gplAdmin.strings.error);
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        GPL.init();
    });

})(jQuery);
