// GD Workflow Bridge Pro - Admin Dashboard & Settings
(function($) {
    'use strict';

    function showLicenseMessage(message, type) {
        const noticeType = type === 'error' ? 'notice notice-error' : 'notice notice-success';
        $('#gdwb-license-message').html(`<div class="${noticeType}"><p>${message}</p></div>`);
    }

    function clearLicenseMessage() {
        $('#gdwb-license-message').empty();
    }

    function getAdminData() {
        return typeof gdwb_admin !== 'undefined' ? gdwb_admin : {};
    }

    function getAjaxUrl() {
        const admin = getAdminData();
        if (admin.ajax_url) {
            return admin.ajax_url;
        }
        if (typeof ajaxurl !== 'undefined') {
            return ajaxurl;
        }
        return '/wp-admin/admin-ajax.php';
    }

    function getLicenseNonce($form) {
        const admin = getAdminData();
        const fieldNonce = $form.find('[name="nonce"]').val();
        return fieldNonce || admin.license_nonce || '';
    }

    function licenseRequest(action, data, callback, errorCallback) {
        $.ajax({
            url: getAjaxUrl(),
            type: 'POST',
            dataType: 'json',
            data: $.extend({ action: action }, data),
            success: callback,
            error: errorCallback
        });
    }

    $(document).ready(function() {
        $('#gdwb-license-form').on('submit', function(e) {
            e.preventDefault();

            clearLicenseMessage();
            const $form = $(this);
            const $submitBtn = $form.find('button[type="submit"]');
            const licenseKey = $form.find('[name="license_key"]').val().trim();
            const nonce = getLicenseNonce($form);

            if (!licenseKey) {
                showLicenseMessage('Please enter a license key.', 'error');
                alert('Please enter a license key');
                return false;
            }

            if (licenseKey.length < 20) {
                showLicenseMessage('License key must be at least 20 characters long.', 'error');
                alert('License key must be at least 20 characters long (A-Z, 0-9, hyphens).');
                return false;
            }

            if (!/^[A-Za-z0-9\-]+$/.test(licenseKey)) {
                showLicenseMessage('Invalid key format. Use letters, numbers, and hyphens only.', 'error');
                alert('Invalid format! Use only letters (A-Z), numbers (0-9), and hyphens.');
                return false;
            }

            $submitBtn.prop('disabled', true).text('Activating...');

            licenseRequest('gdwb_activate_license', {
                license_key: licenseKey,
                nonce: nonce
            }, function(response) {
                if (response.success) {
                    showLicenseMessage(response.data.message || 'License activated successfully.', 'success');
                    setTimeout(function() {
                        window.location.reload();
                    }, 600);
                } else {
                    showLicenseMessage(response.data?.message || 'License activation failed.', 'error');
                    $submitBtn.prop('disabled', false).text('Activate License');
                }
            }, function(xhr) {
                const message = xhr.responseJSON?.data?.message || xhr.responseText || 'Network error';
                showLicenseMessage('AJAX Error: ' + message, 'error');
                $submitBtn.prop('disabled', false).text('Activate License');
            });

            return false;
        });

        $(document).on('click', '#gdwb-license-deactivate', function(e) {
            e.preventDefault();
            clearLicenseMessage();

            const nonce = getLicenseNonce($(this).closest('form'));
            const $button = $(this);
            $button.prop('disabled', true).text('Deactivating...');

            licenseRequest('gdwb_deactivate_license', { nonce: nonce }, function(response) {
                if (response.success) {
                    showLicenseMessage(response.data.message || 'License deactivated successfully.', 'success');
                    setTimeout(function() {
                        window.location.reload();
                    }, 600);
                } else {
                    showLicenseMessage(response.data?.message || 'License deactivation failed.', 'error');
                    $button.prop('disabled', false).text('Deactivate License');
                }
            }, function(xhr) {
                const message = xhr.responseJSON?.data?.message || xhr.responseText || 'Network error';
                showLicenseMessage('AJAX Error: ' + message, 'error');
                $button.prop('disabled', false).text('Deactivate License');
            });
        });

        function loadDashboardStats() {
            const admin = getAdminData();
            const $root = $('#gdwb-dashboard-root');
            if (!$root.length || !admin.rest_url || !admin.nonce) {
                return;
            }

            $.ajax({
                url: admin.rest_url + 'stats',
                type: 'GET',
                headers: { 'X-WP-Nonce': admin.nonce },
                success: function(data) {
                    $root.html(`
                        <div class="gdwb-stats">
                            <div class="stat-card">
                                <h3>Total Projects</h3>
                                <p class="stat-value">${data.total_projects || 0}</p>
                            </div>
                            <div class="stat-card">
                                <h3>Total Files</h3>
                                <p class="stat-value">${data.total_files || 0}</p>
                            </div>
                            <div class="stat-card">
                                <h3>This Month</h3>
                                <p class="stat-value">${data.this_month || 0}</p>
                            </div>
                        </div>
                    `);
                },
                error: function(xhr) {
                    console.error('Stats endpoint error:', xhr.status, xhr.statusText);
                    $root.html('<p>Error loading stats. Make sure you\'re logged in.</p>');
                }
            });
        }

        loadDashboardStats();
    });

})(jQuery);
