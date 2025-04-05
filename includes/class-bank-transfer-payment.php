<?php
namespace FluentFormBankTransfer;

if (!defined('ABSPATH')) exit;

class BankTransferPaymentMethod extends \FluentFormPro\Payments\PaymentMethods\BasePaymentMethod
{
    protected $key = 'bank_transfer';
    protected $processor;

    public function __construct()
    {
        parent::__construct($this->key);
        $this->processor = new BankTransferProcessor();
        $this->init();
    }

    public function init()
    {
        add_filter('fluentform/payment_method_settings_validation_' . $this->key, [$this, 'validateSettings'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);

        if (!$this->isEnabled()) {
            return;
        }

        add_filter('fluentform/available_payment_methods', [$this, 'pushPaymentMethodToForm']);
        add_action('fluentform/rendering_payment_method_' . $this->key, [$this, 'renderPaymentMethod'], 10, 3);
        add_filter('fluentform/response_render_' . $this->key, [$this, 'renderResponse'], 10, 3);
        add_action('fluentform/before_payment_method_render', [$this, 'enqueueAssets']);
        add_action('fluentform/after_submission_payment_details', [$this, 'renderPaymentDetails'], 10, 1);
        add_filter('fluentform/transaction_data_' . $this->key, [$this, 'formatTransaction'], 10, 1);
    }

    public function enqueueAdminAssets()
    {
        if (isset($_GET['page']) && $_GET['page'] == 'fluent_forms_settings' && isset($_GET['tab']) && $_GET['tab'] == 'payment_settings') {
            wp_enqueue_media();
            wp_enqueue_script(
                'ff-bank-transfer-admin',
                plugins_url('assets/js/ff-bank-transfer-admin.js', FF_BANK_TRANSFER_FILE),
                ['jquery'],
                FF_BANK_TRANSFER_VERSION,
                true
            );
            wp_localize_script('ff-bank-transfer-admin', 'ffBankTransferAdmin', [
                'title'  => __('Select or Upload QR Code', 'fluentform-bank-transfer'),
                'button' => __('Use this image', 'fluentform-bank-transfer'),
            ]);
        }
    }

    public function enqueueAssets()
    {
        wp_enqueue_script(
            'ff-bank-transfer',
            plugins_url('assets/js/ff-bank-transfer.js', FF_BANK_TRANSFER_FILE),
            ['jquery', 'fluent-form-submission'],
            FF_BANK_TRANSFER_VERSION,
            true
        );

        wp_localize_script('ff-bank-transfer', 'ffBankTransfer', [
            'text' => [
                'invalid_file' => __('Invalid file type. Allowed types: ', 'fluentform-bank-transfer'),
            ],
            'allowed_types' => $this->getGlobalSettings()['allowed_file_types'] ?? 'jpg,jpeg,png,pdf'
        ]);
    }

    protected function handleFileUpload($fieldName)
    {
        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        if (empty($_FILES[$fieldName]['name'])) {
            throw new \Exception(__('Please select a file to upload', 'fluentform-bank-transfer'));
        }

        if (!is_uploaded_file($_FILES[$fieldName]['tmp_name'])) {
            throw new \Exception(__('Invalid file upload attempt', 'fluentform-bank-transfer'));
        }

        $settings = $this->getGlobalSettings();
        $allowedTypes = array_map('trim', explode(',', $settings['allowed_file_types'] ?? 'jpg,jpeg,png,pdf'));
        $maxSize = ($settings['max_file_size'] ?? 2) * 1024 * 1024;

        $file = $_FILES[$fieldName];
        $filetype = wp_check_filetype($file['name']);
        $ext = strtolower($filetype['ext']);

        if (!in_array($ext, $allowedTypes)) {
            throw new \Exception(
                sprintf(
                    __('Invalid file type. Allowed: %s', 'fluentform-bank-transfer'),
                    implode(', ', $allowedTypes)
                )
            );
        }

        $serverMaxSize = min(wp_max_upload_size(), $maxSize);
        if ($file['size'] > $serverMaxSize) {
            throw new \Exception(
                sprintf(
                    __('File too large. Max size: %sMB', 'fluentform-bank-transfer'),
                    round($serverMaxSize / (1024 * 1024), 1)
                )
            );
        }

        $upload = wp_handle_upload($file, [
            'test_form' => false,
            'mimes' => $this->getMimeTypes($allowedTypes),
            'upload_error_handler' => function($file, $message) {
                throw new \Exception($message);
            }
        ]);

        if (isset($upload['error'])) {
            throw new \Exception($upload['error']);
        }

        return [
            'url' => esc_url_raw($upload['url']),
            'path' => sanitize_text_field($upload['file']),
            'name' => sanitize_file_name(basename($upload['file']))
        ];
    }

    protected function getMimeTypes($extensions)
    {
        $mimeMapping = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];

        $mimes = [];
        foreach ($extensions as $ext) {
            if (isset($mimeMapping[$ext])) {
                $mimes[$ext] = $mimeMapping[$ext];
            }
        }

        return $mimes;
    }

    public function validateSettings($errors, $settings)
    {
        $requiredFields = ['account_name', 'account_number', 'bank_name'];
        foreach ($requiredFields as $field) {
            if (empty($settings[$field])) {
                $errors[$field] = sprintf(
                    __('%s is required', 'fluentform-bank-transfer'),
                    ucfirst(str_replace('_', ' ', $field))
                );
            }
        }

        if (!empty($settings['account_number']) && !preg_match('/^[0-9]{9,18}$/', $settings['account_number'])) {
            $errors['account_number'] = __('Account number must be 9-18 digits', 'fluentform-bank-transfer');
        }

        if (!empty($settings['swift_code']) && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $settings['swift_code'])) {
            $errors['swift_code'] = __('Invalid SWIFT code format', 'fluentform-bank-transfer');
        }

        if (!empty($settings['allowed_file_types'])) {
            $types = array_map('trim', explode(',', $settings['allowed_file_types']));
            $validTypes = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
            
            foreach ($types as $type) {
                if (!in_array(strtolower($type), $validTypes)) {
                    $errors['allowed_file_types'] = __('Invalid file type specified', 'fluentform-bank-transfer');
                    break;
                }
            }
        }

        if (!empty($settings['max_file_size']) && ($settings['max_file_size'] < 0.1 || $settings['max_file_size'] > 20)) {
            $errors['max_file_size'] = __('File size must be between 0.1MB and 20MB', 'fluentform-bank-transfer');
        }

        return $errors;
    }

    public function pushPaymentMethodToForm($methods)
    {
        $globalSettings = $this->getGlobalSettings();
        
        $methods[$this->key] = [
            'title' => __('Bank Transfer', 'fluentform-bank-transfer'),
            'enabled' => 'yes',
            'method_value' => $this->key,
            'settings' => [
                'option_label' => [
                    'type' => 'text',
                    'template' => 'inputText',
                    'value' => __('Pay via Bank Transfer', 'fluentform-bank-transfer'),
                    'label' => __('Method Label', 'fluentform-bank-transfer')
                ],
                'receipt_required' => [
                    'type' => 'yes-no-checkbox',
                    'label' => __('Require Receipt Upload', 'fluentform-bank-transfer'),
                    'value' => $globalSettings['receipt_required'] ?? 'yes'
                ],
                'qr_code_enabled' => [
                    'type' => 'yes-no-checkbox',
                    'label' => __('Enable QR Code Upload', 'fluentform-bank-transfer'),
                    'value' => $globalSettings['qr_code_enabled'] ?? 'yes'
                ]
            ]
        ];
        
        return $methods;
    }

    public function renderPaymentMethod($form, $method, $elements)
    {
        $settings = $this->getGlobalSettings();
        $formSettings = $method['settings'];
        $maxUploadSize = ($settings['max_file_size'] ?? 2) * 1024 * 1024;
        @ini_set('upload_max_filesize', $maxUploadSize);
        @ini_set('post_max_size', $maxUploadSize);
        ?>
        <div class="ff-bank-transfer-container">
            <div class="ff-bank-details">
                <?php if (!empty($settings['qr_code'])) : ?>
                    <div class="ff-qr-code-wrapper">
                        <img src="<?php echo esc_url($settings['qr_code']); ?>" 
                             alt="<?php esc_attr_e('Payment QR Code', 'fluentform-bank-transfer'); ?>">
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
                                <?php echo esc_html($settings[$field]); ?>
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
                <label for="ff_bank_transfer_note">
                    <?php esc_html_e('Payment Reference/Note', 'fluentform-bank-transfer'); ?>
                </label>
                <textarea name="ff_bank_transfer_note" id="ff_bank_transfer_note" 
                          class="ff-form-control" rows="3"
                          placeholder="<?php esc_attr_e('Add payment reference or note', 'fluentform-bank-transfer'); ?>"></textarea>
            </div>

            <div class="ff-receipt-upload">
                <label for="ff_payment_receipt">
                    <?php esc_html_e('Upload Payment Receipt', 'fluentform-bank-transfer'); ?>
                    <?php if ($formSettings['receipt_required']['value'] == 'yes') : ?>
                        <span class="ff-required">*</span>
                    <?php endif; ?>
                </label>
                <input type="file" name="ff_payment_receipt" id="ff_payment_receipt"
                       <?php echo ($formSettings['receipt_required']['value'] == 'yes') ? 'required' : ''; ?>
                       accept="<?php echo esc_attr($settings['allowed_file_types'] ?? 'image/*,.pdf'); ?>">
                <p class="ff-help-text">
                    <?php 
                    printf(
                        esc_html__('Accepted formats: %s (Max %sMB)', 'fluentform-bank-transfer'),
                        esc_html(strtoupper(str_replace(',', ', ', $settings['allowed_file_types'] ?? 'jpg,png,pdf'))),
                        esc_html($settings['max_file_size'] ?? 2)
                    ); 
                    ?>
                </p>
            </div>

            <?php if ($formSettings['qr_code_enabled']['value'] == 'yes') : ?>
                <div class="ff-qr-upload">
                    <label for="ff_qr_code">
                        <?php esc_html_e('Upload QR Payment Proof (Optional)', 'fluentform-bank-transfer'); ?>
                    </label>
                    <input type="file" name="ff_qr_code" id="ff_qr_code"
                           accept="image/*">
                    <p class="ff-help-text">
                        <?php esc_html_e('Upload screenshot of QR payment confirmation', 'fluentform-bank-transfer'); ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function renderResponse($response, $field, $form_id)
    {
        if ($field['element'] !== 'bank_transfer') {
            return $response;
        }

        $paymentData = [
            'form_id' => $form_id,
            'submission_id' => $response['submission_id'],
            'amount' => $response['payment_total'],
            'payment_note' => sanitize_textarea_field($_POST['ff_bank_transfer_note'] ?? '')
        ];

        try {
            if (!empty($_FILES['ff_payment_receipt']['name'])) {
                $upload = $this->handleFileUpload('ff_payment_receipt');
                if ($upload && !isset($upload['error'])) {
                    $paymentData['receipt_url'] = $upload['url'];
                    $response['payment_receipt'] = $upload;
                }
            }

            $form = wpFluent()->table('fluentform_forms')->find($form_id);
            if (!$form) {
                throw new \Exception(__('Form not found', 'fluentform-bank-transfer'));
            }

            $formSettings = json_decode($form->form_settings, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception(__('Invalid form settings', 'fluentform-bank-transfer'));
            }

            $qrEnabled = !empty($formSettings['payment_methods']['bank_transfer']['settings']['qr_code_enabled']['value']);
            
            if ($qrEnabled && !empty($_FILES['ff_qr_code']['name'])) {
                $upload = $this->handleFileUpload('ff_qr_code');
                if ($upload && !isset($upload['error'])) {
                    $paymentData['qr_proof_url'] = $upload['url'];
                    $response['qr_code_proof'] = $upload;
                }
            }

            $this->processor->createPaymentRecord($paymentData);

        } catch (\Exception $e) {
            $response['errors']['bank_transfer'] = $e->getMessage();
        }

        return $response;
    }

    public function formatTransaction($transaction)
    {
        if (is_array($transaction)) {
            $transaction = (object) $transaction;
        }

        $payment = $this->processor->getPaymentRecord($transaction->submission_id);
        if (!$payment) {
            return $transaction;
        }

        $transaction->payment_note = $payment->payment_note ?? '';
        
        if (!empty($payment->receipt_url)) {
            $transaction->receipt_url = esc_url_raw($payment->receipt_url);
            $transaction->receipt_html = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url($payment->receipt_url),
                __('View Receipt', 'fluentform-bank-transfer')
            );
        }

        if (!empty($payment->qr_proof_url)) {
            $transaction->qr_code_url = esc_url_raw($payment->qr_proof_url);
            $transaction->qr_code_html = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url($payment->qr_proof_url),
                __('View QR Proof', 'fluentform-bank-transfer')
            );
        }

        return $transaction;
    }

    public function renderPaymentDetails($submission)
    {
        $payment = $this->processor->getPaymentRecord($submission->id);
        if (!$payment) {
            return;
        }
        ?>
        <div class="ff-payment-details">
            <h4><?php esc_html_e('Bank Transfer Payment Details', 'fluentform-bank-transfer'); ?></h4>
            
            <?php if (!empty($payment->payment_note)) : ?>
                <div class="ff-payment-note">
                    <strong><?php esc_html_e('Payment Note:', 'fluentform-bank-transfer'); ?></strong>
                    <p><?php echo esc_html($payment->payment_note); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($payment->receipt_url)) : ?>
                <div class="ff-payment-receipt">
                    <strong><?php esc_html_e('Payment Receipt:', 'fluentform-bank-transfer'); ?></strong>
                    <p>
                        <a href="<?php echo esc_url($payment->receipt_url); ?>" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e('View Receipt', 'fluentform-bank-transfer'); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($payment->qr_proof_url)) : ?>
                <div class="ff-qr-proof">
                    <strong><?php esc_html_e('QR Payment Proof:', 'fluentform-bank-transfer'); ?></strong>
                    <p>
                        <a href="<?php echo esc_url($payment->qr_proof_url); ?>" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e('View QR Proof', 'fluentform-bank-transfer'); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>
            
            <div class="ff-payment-status">
                <strong><?php esc_html_e('Payment Status:', 'fluentform-bank-transfer'); ?></strong>
                <p><?php echo esc_html(ucfirst($payment->status)); ?></p>
            </div>
        </div>
        <style>
            .ff-payment-details {
                margin: 20px 0;
                padding: 15px;
                background: #f8f9fa;
                border-radius: 4px;
            }
            .ff-payment-details > div {
                margin-bottom: 10px;
            }
            .ff-payment-details strong {
                display: inline-block;
                min-width: 120px;
            }
        </style>
        <?php
    }

    public function getGlobalFields()
    {
        return [
            'label' => __('Bank Transfer Settings', 'fluentform-bank-transfer'),
            'fields' => [
                [
                    'settings_key' => 'is_active',
                    'type' => 'yes-no-checkbox',
                    'label' => __('Enable Payment Method', 'fluentform-bank-transfer'),
                    'checkbox_label' => __('Enable Bank Transfer', 'fluentform-bank-transfer')
                ],
                [
                    'settings_key' => 'account_name',
                    'type' => 'input-text',
                    'label' => __('Account Name', 'fluentform-bank-transfer'),
                    'placeholder' => __('Account Holder Name', 'fluentform-bank-transfer')
                ],
                [
                    'settings_key' => 'account_number',
                    'type' => 'input-text',
                    'label' => __('Account Number', 'fluentform-bank-transfer'),
                    'placeholder' => '1234567890',
                    'help_text' => __('9-18 digit account number', 'fluentform-bank-transfer')
                ],
                [
                    'settings_key' => 'bank_name',
                    'type' => 'input-text',
                    'label' => __('Bank Name', 'fluentform-bank-transfer'),
                    'placeholder' => __('Bank Name', 'fluentform-bank-transfer')
                ],
                [
                    'settings_key' => 'branch',
                    'type' => 'input-text',
                    'label' => __('Branch', 'fluentform-bank-transfer'),
                    'placeholder' => __('Branch Name', 'fluentform-bank-transfer')
                ],
                [
                    'settings_key' => 'swift_code',
                    'type' => 'input-text',
                    'label' => __('SWIFT Code', 'fluentform-bank-transfer'),
                    'placeholder' => 'ABCDEF12 or ABCDEF12345',
                    'help_text' => __('8 or 11 character SWIFT/BIC code', 'fluentform-bank-transfer')
                ],
                [
                    'settings_key' => 'qr_code',
                    'type' => 'custom_html',
                    'label' => __('QR Code', 'fluentform-bank-transfer'),
                    'html' => $this->getQrCodeUploadField()
                ],
                [
                    'settings_key' => 'instructions',
                    'type' => 'rich-text',
                    'label' => __('Payment Instructions', 'fluentform-bank-transfer'),
                    'placeholder' => __('Enter payment instructions...', 'fluentform-bank-transfer')
                ],
                [
                    'settings_key' => 'receipt_required',
                    'type' => 'yes-no-checkbox',
                    'label' => __('Require Receipt Upload', 'fluentform-bank-transfer'),
                    'checkbox_label' => __('Make receipt mandatory', 'fluentform-bank-transfer')
                ],
                [
                    'settings_key' => 'qr_code_enabled',
                    'type' => 'yes-no-checkbox',
                    'label' => __('Enable QR Code Upload', 'fluentform-bank-transfer'),
                    'checkbox_label' => __('Allow users to upload QR payment proof', 'fluentform-bank-transfer')
                ],
                [
                    'settings_key' => 'allowed_file_types',
                    'type' => 'input-text',
                    'label' => __('Allowed File Types', 'fluentform-bank-transfer'),
                    'placeholder' => 'jpg,png,pdf',
                    'help_text' => __('Comma separated file extensions (jpg,png,pdf)', 'fluentform-bank-transfer')
                ],
                [
                    'settings_key' => 'max_file_size',
                    'type' => 'number',
                    'label' => __('Max File Size (MB)', 'fluentform-bank-transfer'),
                    'placeholder' => '2',
                    'help_text' => __('Maximum allowed file size in megabytes (0.1-20MB)', 'fluentform-bank-transfer')
                ]
            ]
        ];
    }

    protected function getQrCodeUploadField()
    {
        $settings = $this->getGlobalSettings();
        $qrCode = $settings['qr_code'] ?? '';
        ob_start();
        ?>
        <div class="ff_qr_code_upload_wrapper">
            <input type="hidden" name="settings[qr_code]" id="ff_qr_code_input" value="<?php echo esc_attr($qrCode); ?>">
            <div class="ff_qr_code_preview" style="margin-bottom: 10px;">
                <?php if ($qrCode) : ?>
                    <img src="<?php echo esc_url($qrCode); ?>" style="max-width: 300px; max-height: 300px;">
                <?php endif; ?>
            </div>
            <button type="button" class="button ff_upload_qr_code" data-target="#ff_qr_code_input">
                <?php esc_html_e('Upload QR Code', 'fluentform-bank-transfer'); ?>
            </button>
            <?php if ($qrCode) : ?>
                <button type="button" class="button ff_remove_qr_code" style="margin-left: 5px;">
                    <?php esc_html_e('Remove QR Code', 'fluentform-bank-transfer'); ?>
                </button>
            <?php endif; ?>
            <p class="description">
                <?php esc_html_e('Upload a QR code image that users can scan to make payments', 'fluentform-bank-transfer'); ?>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    public function getGlobalSettings()
    {
        $defaults = [
            'is_active' => 'no',
            'account_name' => '',
            'account_number' => '',
            'bank_name' => '',
            'branch' => '',
            'swift_code' => '',
            'qr_code' => '',
            'instructions' => '',
            'receipt_required' => 'yes',
            'qr_code_enabled' => 'yes',
            'allowed_file_types' => 'jpg,jpeg,png,pdf',
            'max_file_size' => 2
        ];

        return wp_parse_args(
            get_option('fluentform_payment_settings_' . $this->key, []),
            $defaults
        );
    }

    public function isEnabled()
    {
        $settings = $this->getGlobalSettings();
        return $settings['is_active'] == 'yes';
    }
}