/**
 * PixelFly Admin JavaScript
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Test connection button
        $('#pixelfly-test-connection').on('click', function() {
            var $button = $(this);
            var $result = $('#pixelfly-test-result');
            
            $button.prop('disabled', true).text(pixelflyAdmin.strings.testing);
            $result.removeClass('success error').text('');

            $.post(pixelflyAdmin.ajaxUrl, {
                action: 'pixelfly_test_connection',
                nonce: pixelflyAdmin.nonce
            }, function(response) {
                $button.prop('disabled', false).text('Test Connection');
                
                if (response.success) {
                    $result.addClass('success').text(pixelflyAdmin.strings.success);
                } else {
                    $result.addClass('error').text(response.data.message || pixelflyAdmin.strings.error);
                }
            }).fail(function() {
                $button.prop('disabled', false).text('Test Connection');
                $result.addClass('error').text(pixelflyAdmin.strings.error);
            });
        });

        // Fire single event
        $(document).on('click', '.pixelfly-fire-event', function() {
            var $button = $(this);
            var eventId = $button.data('event-id');
            var $row = $button.closest('tr');
            
            $button.prop('disabled', true).text(pixelflyAdmin.strings.firing);

            $.post(pixelflyAdmin.ajaxUrl, {
                action: 'pixelfly_fire_event',
                nonce: pixelflyAdmin.nonce,
                event_id: eventId
            }, function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() {
                        $(this).remove();
                        updateStats();
                    });
                } else {
                    $button.prop('disabled', false).text('Fire');
                    alert(response.data.message || 'Failed to fire event');
                }
            }).fail(function() {
                $button.prop('disabled', false).text('Fire');
                alert('Request failed');
            });
        });

        // Delete event
        $(document).on('click', '.pixelfly-delete-event', function() {
            if (!confirm(pixelflyAdmin.strings.confirmDelete)) {
                return;
            }

            var $button = $(this);
            var eventId = $button.data('event-id');
            var $row = $button.closest('tr');

            $.post(pixelflyAdmin.ajaxUrl, {
                action: 'pixelfly_delete_event',
                nonce: pixelflyAdmin.nonce,
                event_id: eventId
            }, function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() {
                        $(this).remove();
                        updateStats();
                    });
                } else {
                    alert(response.data.message || 'Failed to delete event');
                }
            });
        });

        // Fire all events
        $('#pixelfly-fire-all').on('click', function() {
            if (!confirm(pixelflyAdmin.strings.confirmFireAll)) {
                return;
            }

            var $button = $(this);
            $button.prop('disabled', true).text(pixelflyAdmin.strings.firing);

            $.post(pixelflyAdmin.ajaxUrl, {
                action: 'pixelfly_fire_all_events',
                nonce: pixelflyAdmin.nonce
            }, function(response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    $button.prop('disabled', false).text('Fire All Pending Events');
                    alert(response.data.message || 'Failed to fire events');
                }
            }).fail(function() {
                $button.prop('disabled', false).text('Fire All Pending Events');
                alert('Request failed');
            });
        });

        // Update stats after action
        function updateStats() {
            var pending = $('.pixelfly-pending-events tbody tr').length;
            $('.pixelfly-stats .pending .stat-number').text(pending);
            
            if (pending === 0) {
                $('#pixelfly-fire-all').hide();
                if ($('.pixelfly-no-events').length === 0) {
                    $('table.wp-list-table').replaceWith('<div class="pixelfly-no-events"><p>No pending events found.</p></div>');
                }
            }
        }
    });
})(jQuery);
