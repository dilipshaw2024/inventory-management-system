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

    $(function () {
        hidePageLoader();

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
