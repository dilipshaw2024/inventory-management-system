(function ($) {
    'use strict';

    var loader = document.querySelector('.inventory-page-loader');
    var networkIndicator = document.querySelector('.inventory-network-indicator');

    function hidePageLoader() {
        if (loader) {
            loader.classList.add('is-hidden');
        }
    }

    function showPageLoader() {
        if (loader) {
            loader.classList.remove('is-hidden');
        }
    }

    function markButtonLoading(button) {
        if (!button || button.classList.contains('inventory-loading-button')) {
            return;
        }
        button.dataset.originalHtml = button.innerHTML;
        button.innerHTML = '<span class="inventory-button-spinner" aria-hidden="true"></span> Processing...';
        button.classList.add('inventory-loading-button');
    }

    function iconForText(value) {
        var text = (value || '').toLowerCase();
        if (text.indexOf('supplier') !== -1) return 'ri-truck-line';
        if (text.indexOf('customer') !== -1) return 'ri-user-3-line';
        if (text.indexOf('product') !== -1) return 'ri-price-tag-3-line';
        if (text.indexOf('category') !== -1) return 'ri-apps-2-line';
        if (text.indexOf('unit') !== -1) return 'ri-ruler-line';
        if (text.indexOf('purchase') !== -1) return 'ri-inbox-archive-line';
        if (text.indexOf('invoice') !== -1 || text.indexOf('amount') !== -1) return 'ri-file-list-3-line';
        if (text.indexOf('stock') !== -1 || text.indexOf('qty') !== -1) return 'ri-stack-line';
        if (text.indexOf('date') !== -1) return 'ri-calendar-line';
        if (text.indexOf('email') !== -1) return 'ri-mail-line';
        if (text.indexOf('mobile') !== -1 || text.indexOf('phone') !== -1) return 'ri-phone-line';
        if (text.indexOf('address') !== -1) return 'ri-map-pin-line';
        if (text.indexOf('description') !== -1) return 'ri-file-text-line';
        if (text.indexOf('status') !== -1) return 'ri-checkbox-circle-line';
        if (text.indexOf('action') !== -1) return 'ri-more-2-line';
        if (text.indexOf('name') !== -1) return 'ri-user-line';
        return 'ri-information-line';
    }

    function addInterfaceIcons() {
        $('label').each(function () {
            var label = $(this);
            if (!label.find('i').length && $.trim(label.text())) {
                label.prepend('<i class="inventory-label-icon ' + iconForText(label.text()) + '" aria-hidden="true"></i>');
            }
        });

        $('.card-title, .page-title-box h4').each(function () {
            var heading = $(this);
            if (!heading.find('i').length && $.trim(heading.text())) {
                heading.prepend('<i class="inventory-heading-icon ' + iconForText(heading.text()) + '" aria-hidden="true"></i>');
            }
        });

        $('#datatable thead th, table thead th').each(function () {
            var heading = $(this);
            if (!heading.find('i').length && $.trim(heading.text())) {
                heading.prepend('<i class="inventory-table-icon ' + iconForText(heading.text()) + '" aria-hidden="true"></i>');
            }
        });

        $('input[type="submit"]').each(function () {
            var input = $(this);
            if (input.data('icon-upgraded')) return;
            var button = $('<button type="submit"></button>');
            $.each(this.attributes, function () {
                if (this.name !== 'type' && this.name !== 'value') button.attr(this.name, this.value);
            });
            button.html('<i class="' + (input.val().toLowerCase().indexOf('update') !== -1 ? 'ri-save-3-line' : 'ri-add-line') + ' me-1" aria-hidden="true"></i>' + $('<span>').text(input.val()).html());
            input.replaceWith(button);
            button.data('icon-upgraded', true);
        });
    }

    $(function () {
        hidePageLoader();
        addInterfaceIcons();

        // Defer image decoding and loading until images are close to the viewport.
        $('img:not([loading])').attr({ loading: 'lazy', decoding: 'async' });

        // Show a friendly empty state on standard DataTable list pages.
        $('#datatable').each(function () {
            var table = $(this);
            var body = table.find('tbody');
            if (!body.find('tr').length) {
                body.append('<tr><td colspan="' + table.find('thead th').length + '" class="inventory-empty-state"><i class="ri-inbox-line"></i>No records available yet.</td></tr>');
            }
        });

        // Add a loading state while a normal form is being submitted.
        $(document).on('submit', 'form', function () {
            var form = $(this);
            if (form.data('skip-loading') || form.find('.is-invalid').length || (this.checkValidity && !this.checkValidity())) {
                return;
            }
            var submit = form.find('button[type="submit"], input[type="submit"]').first();
            markButtonLoading(submit[0]);
            showPageLoader();
        });

        // Submit buttons inside dynamically-created forms should still be accessible.
        $(document).on('click', '[data-loading-button]', function () {
            markButtonLoading(this);
        });
    });

    $(document).on('ajaxStart', function () {
        if (networkIndicator) {
            networkIndicator.classList.add('is-active');
        }
    });

    $(document).on('ajaxStop', function () {
        if (networkIndicator) {
            networkIndicator.classList.remove('is-active');
        }
    });

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            hidePageLoader();
            $('.inventory-loading-button').each(function () {
                this.classList.remove('inventory-loading-button');
                if (this.dataset.originalHtml) {
                    this.innerHTML = this.dataset.originalHtml;
                }
            });
        }
    });
})(jQuery);
