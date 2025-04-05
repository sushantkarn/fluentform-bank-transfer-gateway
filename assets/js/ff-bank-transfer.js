// assets/js/ff-bank-transfer.js - Frontend JavaScript
jQuery(document).ready(function($) {
    // Validate file uploads before submission
    $(document).on('fluentform_before_submission', function(e, formId, data) {
        if (data.payment_method === 'bank_transfer') {
            // Validate receipt if required
            const $receiptInput = $('#ff_payment_receipt');
            if (data.ff_payment_receipt_required === 'yes' && (!$receiptInput.val() || $receiptInput.val() === '')) {
                alert(fluent_form_global_var.i18n.required_fields_message);
                $('html, body').animate({
                    scrollTop: $receiptInput.closest('.ff-receipt-upload').offset().top - 100
                }, 300);
                e.preventDefault();
                return;
            }

            // Validate file types and sizes
            const allowedTypes = ffBankTransfer.allowed_types.split(',');
            const maxSizeMB = ffBankTransfer.max_file_size || 2;
            const maxSizeBytes = maxSizeMB * 1024 * 1024;
            
            ['ff_payment_receipt', 'ff_qr_code'].forEach(function(field) {
                const $input = $('#' + field);
                const file = $input[0]?.files[0];
                
                if (file) {
                    // Check file type
                    const ext = file.name.split('.').pop().toLowerCase();
                    if (!allowedTypes.includes(ext)) {
                        alert(ffBankTransfer.text.invalid_file + allowedTypes.join(', '));
                        $input.val('');
                        $input.siblings('.ff-file-preview').remove();
                        $('html, body').animate({
                            scrollTop: $input.offset().top - 100
                        }, 300);
                        e.preventDefault();
                        return;
                    }
                    
                    // Check file size
                    if (file.size > maxSizeBytes) {
                        alert(ffBankTransfer.text.file_too_big.replace('%d', maxSizeMB));
                        $input.val('');
                        $input.siblings('.ff-file-preview').remove();
                        $('html, body').animate({
                            scrollTop: $input.offset().top - 100
                        }, 300);
                        e.preventDefault();
                        return;
                    }
                }
            });
        }
    });

    // Handle file previews and validation
    $(document).on('change', 'input[type="file"]', function() {
        const $input = $(this);
        const file = this.files[0];
        const allowedTypes = ffBankTransfer.allowed_types.split(',');
        const maxSizeMB = ffBankTransfer.max_file_size || 2;
        const maxSizeBytes = maxSizeMB * 1024 * 1024;
        
        $input.siblings('.ff-error').remove();
        
        if (file) {
            // Validate file type
            const ext = file.name.split('.').pop().toLowerCase();
            if (!allowedTypes.includes(ext)) {
                $input.val('');
                $input.after('<div class="ff-error" style="color:#dc3232;margin-top:5px;">' + 
                    ffBankTransfer.text.invalid_file + allowedTypes.join(', ') + '</div>');
                return;
            }
            
            // Validate file size
            if (file.size > maxSizeBytes) {
                $input.val('');
                $input.after('<div class="ff-error" style="color:#dc3232;margin-top:5px;">' + 
                    ffBankTransfer.text.file_too_big.replace('%d', maxSizeMB) + '</div>');
                return;
            }
            
            // Show preview for images
            if (file.type.match('image.*')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    $input.siblings('.ff-file-preview').remove();
                    $input.after(
                        '<div class="ff-file-preview" style="margin-top:10px;">' +
                        '<img src="' + e.target.result + '" style="max-width:200px;max-height:200px;display:block;">' +
                        '<small style="display:block;margin-top:5px;">' + file.name + ' (' + 
                        Math.round(file.size / 1024) + 'KB)</small>' +
                        '</div>'
                    );
                };
                reader.readAsDataURL(file);
            } else {
                $input.siblings('.ff-file-preview').remove();
                $input.after(
                    '<div class="ff-file-preview" style="margin-top:10px;">' +
                    '<small>' + file.name + ' (' + Math.round(file.size / 1024) + 'KB)</small>' +
                    '</div>'
                );
            }
        } else {
            $input.siblings('.ff-file-preview').remove();
        }
    });
});