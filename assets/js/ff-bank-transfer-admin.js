// assets/js/ff-bank-transfer-admin.js - Admin JavaScript
jQuery(document).ready(function($) {
    // QR Code Upload
    $(document).on('click', '.ff_upload_qr_code', function(e) {
        e.preventDefault();
        const button = $(this);
        const targetInput = $(button.data('target'));
        const previewContainer = button.closest('.ff_qr_code_upload_wrapper').find('.ff_qr_code_preview');
        
        const frame = wp.media({
            title: ffBankTransferAdmin.title,
            button: {
                text: ffBankTransferAdmin.button
            },
            multiple: false,
            library: {
                type: 'image'
            }
        });

        frame.on('select', function() {
            const attachment = frame.state().get('selection').first().toJSON();
            targetInput.val(attachment.url).trigger('change');
            previewContainer.html(
                '<img src="' + attachment.url + '" style="max-width:300px;max-height:300px;display:block;">' +
                '<small style="display:block;margin-top:5px;">' + attachment.filename + '</small>'
            );
            button.siblings('.ff_remove_qr_code').show();
        });

        frame.open();
    });

    // QR Code Remove
    $(document).on('click', '.ff_remove_qr_code', function(e) {
        e.preventDefault();
        const button = $(this);
        const wrapper = button.closest('.ff_qr_code_upload_wrapper');
        wrapper.find('input').val('').trigger('change');
        wrapper.find('.ff_qr_code_preview').html('');
        button.hide();
    });

    // Validate settings before submission
    $(document).on('submit', '#fluentform-payment-settings-form', function(e) {
        let errors = false;
        const $form = $(this);
        
        // Required fields validation
        const requiredFields = {
            'account_name': 'Account name is required',
            'account_number': 'Account number is required',
            'bank_name': 'Bank name is required'
        };
        
        $.each(requiredFields, function(field, message) {
            const $field = $form.find('[name="settings[' + field + ']"]');
            if (!$field.val().trim()) {
                alert(message);
                $('html, body').animate({
                    scrollTop: $field.offset().top - 100
                }, 300);
                errors = true;
                return false; // break loop
            }
        });
        
        if (errors) {
            e.preventDefault();
            return;
        }
        
        // Validate account number format
        const accountNumber = $form.find('[name="settings[account_number]"]').val();
        if (accountNumber && !/^[0-9]{9,18}$/.test(accountNumber)) {
            alert('Account number must be 9-18 digits');
            e.preventDefault();
            return;
        }
        
        // Validate SWIFT code format
        const swiftCode = $form.find('[name="settings[swift_code]"]').val();
        if (swiftCode && !/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/.test(swiftCode)) {
            alert('Invalid SWIFT code format. It should be 8 or 11 characters (letters and numbers)');
            e.preventDefault();
            return;
        }
        
        // Validate file types
        const fileTypes = $form.find('[name="settings[allowed_file_types]"]').val();
        if (fileTypes) {
            const validTypes = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
            const types = fileTypes.split(',').map(t => t.trim().toLowerCase());
            
            for (const type of types) {
                if (!validTypes.includes(type)) {
                    alert('Invalid file type: ' + type + '. Allowed types: ' + validTypes.join(', '));
                    e.preventDefault();
                    return;
                }
            }
        }
        
        // Validate file size
        const fileSize = $form.find('[name="settings[max_file_size]"]').val();
        if (fileSize && (parseFloat(fileSize) < 0.1 || parseFloat(fileSize) > 20)) {
            alert('File size must be between 0.1MB and 20MB');
            e.preventDefault();
            return;
        }
    });
});