/*!
 * Simple jQuery Sortable Plugin for Multilang Container
 */
(function(jQuery) {
    'use strict';

    // Ensure jQuery is loaded
    if (typeof jQuery === 'undefined') {
        console.error('jQuery is required for sortable functionality');
        return;
    }

    // Use $ as alias within this scope
    var $ = jQuery;

    $.fn.sortable = function(options) {
        var settings = $.extend({
            handle: false,
            cursor: 'move',
            placeholder: 'ui-sortable-placeholder',
            tolerance: 'pointer',
            start: function() {},
            update: function() {},
            stop: function() {}
        }, options);

        return this.each(function() {
            var $container = $(this);
            var isDragging = false;
            var draggedElement = null;
            var placeholder = null;
            var startY = 0;

            // Create placeholder element
            function createPlaceholder() {
                return $('<div class="' + settings.placeholder + '" style="height: 50px; margin: 10px 0;"></div>');
            }

            // Handle mouse down on draggable items
            $container.on('mousedown', '> *', function(e) {
                // If handle is specified, check if we clicked on it or inside it
                if (settings.handle && !$(e.target).closest(settings.handle).length) {
                    return;
                }

                e.preventDefault();
                e.stopPropagation();

                // The sortable item is always the direct child of the container
                draggedElement = $(this);
                isDragging = false;
                startY = e.pageY;

                // Add dragging class and create placeholder
                placeholder = createPlaceholder();
                draggedElement.after(placeholder);

                // Store original styles
                var originalStyle = {
                    position: draggedElement.css('position'),
                    zIndex: draggedElement.css('z-index'),
                    cursor: draggedElement.css('cursor')
                };
                draggedElement.data('original-style', originalStyle);

                // Bind mouse events
                $(document).on('mousemove.sortable', handleMouseMove);
                $(document).on('mouseup.sortable', handleMouseUp);

                // Call start callback with placeholder reference
                settings.start(e, { 
                    item: draggedElement,
                    placeholder: placeholder
                });
            });

            function handleMouseMove(e) {
                if (!draggedElement) return;

                // Check if we've moved enough to start dragging
                if (!isDragging && Math.abs(e.pageY - startY) > 5) {
                    isDragging = true;
                    draggedElement.addClass('ui-sortable-helper').css({
                        position: 'relative',
                        zIndex: '1000',
                        cursor: settings.cursor,
                        opacity: '0.8'
                    });
                }

                if (!isDragging) return;

                // Find where to place the placeholder
                var mouseY = e.pageY;
                var $items = $container.children(':not(.ui-sortable-helper):not(.' + settings.placeholder + ')');
                var insertBefore = null;

                $items.each(function() {
                    var $item = $(this);
                    var itemTop = $item.offset().top;
                    var itemHeight = $item.outerHeight();
                    var itemMiddle = itemTop + (itemHeight / 2);

                    if (mouseY < itemMiddle) {
                        insertBefore = $item;
                        return false; // break
                    }
                });

                // Move placeholder
                if (insertBefore) {
                    insertBefore.before(placeholder);
                } else {
                    $container.append(placeholder);
                }
            }

            function handleMouseUp(e) {
                $(document).off('mousemove.sortable mouseup.sortable');

                if (draggedElement && isDragging) {
                    // Move item to placeholder position
                    placeholder.before(draggedElement);
                    
                    // Restore original styles
                    var originalStyle = draggedElement.data('original-style') || {};
                    draggedElement.removeClass('ui-sortable-helper').css({
                        position: originalStyle.position || '',
                        zIndex: originalStyle.zIndex || '',
                        cursor: originalStyle.cursor || '',
                        opacity: ''
                    });

                    // Call update callback
                    settings.update(e, { 
                        item: draggedElement,
                        placeholder: placeholder
                    });
                }

                // Clean up
                if (placeholder) {
                    placeholder.remove();
                }
                
                settings.stop(e, { 
                    item: draggedElement,
                    placeholder: placeholder
                });
                
                draggedElement = null;
                placeholder = null;
                isDragging = false;
            }
        });
    };

})(window.jQuery || window.$);