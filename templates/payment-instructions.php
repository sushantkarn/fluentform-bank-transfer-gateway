<div class="ff-bank-transfer-container">
    <div class="ff-bank-details">
        <?php if (!empty($settings['qr_code'])) : ?>
            <div class="ff-qr-code-wrapper">
                <img src="<?php echo esc_url($settings['qr_code']); ?>" 
                     alt="<?php esc_attr_e('Payment QR Code', 'fluentform-bank-transfer'); ?>"
                     loading="lazy"
                     width="250"
                     height="250">
                <p class="ff-qr-code-help">
                    <?php esc_html_e('Scan this QR code to make payment', 'fluentform-bank-transfer'); ?>
                </p>
            </div>
        <?php endif; ?>
        
        <h4><?php esc_html_e('Bank Transfer Details', 'fluentform-bank-transfer'); ?></h4>
        <ul class="ff-bank-details-list">
            <?php foreach ([
                'account_name' => __('Account Name', 'fluentform-bank-transfer'),
                'account_number' => __('Account Number', 'fluentform-bank-transfer'),
                'bank_name' => __('Bank Name', 'fluentform-bank-transfer'),
                'branch' => __('Branch', 'fluentform-bank-transfer'),
                'swift_code' => __('SWIFT Code', 'fluentform-bank-transfer')
            ] as $field => $label) : ?>
                <?php if (!empty($settings[$field])) : ?>
                    <li>
                        <strong><?php echo esc_html($label); ?>:</strong> 
                        <span><?php echo esc_html($settings[$field]); ?></span>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </div>

    <?php if (!empty($settings['instructions'])) : ?>
        <div class="ff-payment-instructions">
            <h4><?php esc_html_e('Payment Instructions', 'fluentform-bank-transfer'); ?></h4>
            <div class="ff-instructions-content">
                <?php echo wp_kses_post(wpautop($settings['instructions'])); ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="ff-payment-note-field">
        <label for="ff_bank_transfer_note_<?php echo esc_attr($form->id); ?>">
            <?php esc_html_e('Payment Reference/Note', 'fluentform-bank-transfer'); ?>
            <span class="ff-help-text">
                <?php esc_html_e('(e.g., Invoice number or your name)', 'fluentform-bank-transfer'); ?>
            </span>
        </label>
        <textarea name="ff_bank_transfer_note" 
                  id="ff_bank_transfer_note_<?php echo esc_attr($form->id); ?>"
                  class="ff-form-control" 
                  rows="3"
                  maxlength="200"
                  placeholder="<?php esc_attr_e('Enter payment reference or note...', 'fluentform-bank-transfer'); ?>"></textarea>
    </div>

    <div class="ff-receipt-upload">
        <label for="ff_payment_receipt_<?php echo esc_attr($form->id); ?>">
            <?php esc_html_e('Upload Payment Receipt', 'fluentform-bank-transfer'); ?>
            <?php if ($formSettings['receipt_required']['value'] == 'yes') : ?>
                <span class="ff-required" aria-label="<?php esc_attr_e('Required field', 'fluentform-bank-transfer'); ?>">*</span>
            <?php endif; ?>
        </label>
        <input type="file" 
               name="ff_payment_receipt" 
               id="ff_payment_receipt_<?php echo esc_attr($form->id); ?>"
               <?php echo ($formSettings['receipt_required']['value'] == 'yes') ? 'required' : ''; ?>
               accept="<?php echo esc_attr($this->getFileAcceptAttribute($settings['allowed_file_types'] ?? 'jpg,jpeg,png,pdf')); ?>"
               aria-describedby="receipt_help_<?php echo esc_attr($form->id); ?>">
        <p class="ff-help-text" id="receipt_help_<?php echo esc_attr($form->id); ?>">
            <?php 
            printf(
                esc_html__('Accepted formats: %s (Max %sMB)', 'fluentform-bank-transfer'),
                esc_html($this->getFormattedFileTypes($settings['allowed_file_types'] ?? 'jpg,jpeg,png,pdf')),
                esc_html($settings['max_file_size'] ?? 2)
            ); 
            ?>
        </p>
    </div>

    <?php if ($formSettings['qr_code_enabled']['value'] == 'yes') : ?>
        <div class="ff-qr-upload">
            <label for="ff_qr_code_<?php echo esc_attr($form->id); ?>">
                <?php esc_html_e('Upload QR Payment Proof (Optional)', 'fluentform-bank-transfer'); ?>
            </label>
            <input type="file" 
                   name="ff_qr_code" 
                   id="ff_qr_code_<?php echo esc_attr($form->id); ?>"
                   accept="image/*"
                   aria-describedby="qr_help_<?php echo esc_attr($form->id); ?>">
            <p class="ff-help-text" id="qr_help_<?php echo esc_attr($form->id); ?>">
                <?php esc_html_e('Upload screenshot of QR payment confirmation', 'fluentform-bank-transfer'); ?>
            </p>
        </div>
    <?php endif; ?>
</div>