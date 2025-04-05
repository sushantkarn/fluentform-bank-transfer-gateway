<?php

/**
 * Plugin Name: Fluent Forms Bank Transfer
 * Description: Bank transfer payment method with QR code and receipt upload support for Fluent Forms
 * Version: 1.1.1
 * Author: Sushant Karn
 * Author URI: https://www.sushantkarn.com.np/
 * Plugin URI: https://www.sushantkarn.com.np/
 * Text Domain: fluentform-bank-transfer
 * License: GPLv2 or later
 */

defined('ABSPATH') || exit;

// Define plugin constants
define('FF_BANK_TRANSFER_VERSION', '1.1.1');
define('FF_BANK_TRANSFER_FILE', __FILE__);
define('FF_BANK_TRANSFER_PATH', plugin_dir_path(__FILE__));
define('FF_BANK_TRANSFER_URL', plugin_dir_url(__FILE__));
define('FF_BANK_TRANSFER_SLUG', 'fluentform-bank-transfer');
define('FF_BANK_TRANSFER_DEBUG', defined('WP_DEBUG') && WP_DEBUG);

// Database installation and upgrade
register_activation_hook(__FILE__, 'fluentform_bank_transfer_install');
function fluentform_bank_transfer_install()
{
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'fluentform_bank_transfer_payments';

    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        form_id bigint(20) NOT NULL,
        submission_id bigint(20) NOT NULL,
        transaction_hash varchar(100) NOT NULL,
        amount decimal(10,2) NOT NULL,
        payment_note text NULL,
        receipt_url varchar(255) NULL,
        qr_proof_url varchar(255) NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY form_id (form_id),
        KEY submission_id (submission_id),
        KEY transaction_hash (transaction_hash)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Initialize default settings
    $defaults = [
        'is_active'           => 'no',
        'account_name'        => '',
        'account_number'      => '',
        'bank_name'          => '',
        'branch'             => '',
        'swift_code'         => '', // Changed from ifsc to swift_code
        'qr_code'            => '',
        'instructions'       => __('Please transfer the amount to our bank account and upload the payment receipt. We will verify your payment manually.', 'fluentform-bank-transfer'),
        'receipt_required'   => 'yes',
        'qr_code_enabled'    => 'yes',
        'allowed_file_types' => 'jpg,jpeg,png,pdf',
        'max_file_size'      => 2 // In MB
    ];

    update_option('fluentform_payment_settings_bank_transfer', wp_parse_args(
        get_option('fluentform_payment_settings_bank_transfer', []),
        $defaults
    ));

    add_option('fluentform_bank_transfer_db_version', '1.0');
}

/**
 * Get the accept attribute value for file input
 */
if (!function_exists('ffbt_get_file_accept_attribute')) {
    function ffbt_get_file_accept_attribute($fileTypes)
    {
        $types = array_map('trim', explode(',', strtolower($fileTypes)));
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];

        $accept = [];
        foreach ($types as $type) {
            if (isset($mimeTypes[$type])) {
                $accept[] = $mimeTypes[$type];
            }
        }

        return implode(',', $accept);
    }
}

/**
 * Format file types for display
 */
if (!function_exists('ffbt_get_formatted_file_types')) {
    function ffbt_get_formatted_file_types($fileTypes)
    {
        $types = array_map('trim', explode(',', strtolower($fileTypes)));
        return implode(', ', array_map('strtoupper', $types));
    }
}

// Cleanup on uninstall
register_uninstall_hook(__FILE__, 'fluentform_bank_transfer_uninstall');
function fluentform_bank_transfer_uninstall()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'fluentform_bank_transfer_payments';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
    delete_option('fluentform_bank_transfer_db_version');
    delete_option('fluentform_payment_settings_bank_transfer');
}

/**
 * Validate file uploads with MIME type checking
 */
if (!function_exists('ffbt_validate_upload')) {
    function ffbt_validate_upload($file, $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'])
    {
        if (empty($file['name']) || empty($file['tmp_name'])) return false;

        $filetype = wp_check_filetype($file['name']);
        $ext = strtolower($filetype['ext']);
        $mime = strtolower($filetype['type']);

        $settings = get_option('fluentform_payment_settings_bank_transfer');
        $max_size = (!empty($settings['max_file_size']) ? $settings['max_file_size'] : 2) * 1024 * 1024;

        $valid_mimes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf'
        ];

        return in_array($ext, $allowed_types) &&
            $file['size'] <= $max_size &&
            isset($valid_mimes[$ext]) &&
            $mime === $valid_mimes[$ext];
    }
}

// Load the plugin
add_action('plugins_loaded', function () {
    // Check for Fluent Forms Pro
    if (!defined('FLUENTFORMPRO_DIR_PATH') || !class_exists('FluentFormPro\Payments\PaymentMethods\BasePaymentMethod')) {
        add_action('admin_notices', function () {
            if (!current_user_can('manage_options')) return;

            $message = sprintf(
                __('%sFluent Forms Bank Transfer requires %sFluent Forms Pro%s with payment module enabled.%s', 'fluentform-bank-transfer'),
                '<div class="notice notice-error"><p>',
                '<a href="https://wpmanageninja.com/fluent-form/" target="_blank" rel="noopener noreferrer">',
                '</a>',
                '</p></div>'
            );
            echo wp_kses_post($message);
        });
        return;
    }

    // Load classes
    require_once FF_BANK_TRANSFER_PATH . 'includes/class-bank-transfer-payment.php';
    require_once FF_BANK_TRANSFER_PATH . 'includes/class-bank-transfer-processor.php';

    try {
        // Initialize
        $processor = new FluentFormBankTransfer\BankTransferProcessor();
        new FluentFormBankTransfer\BankTransferPaymentMethod();

        // Add submission notes filter
        add_filter('fluentform/submission_notes', [$processor, 'getSubmissionNotes'], 10, 3);

        // Load frontend assets
        add_action('wp_enqueue_scripts', function () {
            if (!is_admin()) {
                wp_enqueue_style(
                    'ff-bank-transfer',
                    FF_BANK_TRANSFER_URL . 'assets/css/ff-bank-transfer.css',
                    [],
                    FF_BANK_TRANSFER_VERSION
                );

                wp_enqueue_script(
                    'ff-bank-transfer',
                    FF_BANK_TRANSFER_URL . 'assets/js/ff-bank-transfer.js',
                    ['jquery', 'fluentform-advanced'],
                    FF_BANK_TRANSFER_VERSION,
                    true
                );

                wp_localize_script('ff-bank-transfer', 'ffBankTransfer', [
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('ff_bank_transfer_nonce'),
                    'text' => [
                        'invalid_file' => sprintf(
                            __('Invalid file type. Allowed: %s', 'fluentform-bank-transfer'),
                            implode(', ', explode(',', get_option('fluentform_payment_settings_bank_transfer')['allowed_file_types']))
                        ),
                        'file_too_big' => sprintf(
                            __('File too large. Max size: %dMB', 'fluentform-bank-transfer'),
                            get_option('fluentform_payment_settings_bank_transfer')['max_file_size']
                        )
                    ]
                ]);
            }
        });

        // Load admin assets
        add_action('admin_enqueue_scripts', function ($hook) {
            if (strpos($hook, 'fluent_forms') !== false) {
                wp_enqueue_media();
                wp_enqueue_script(
                    'ff-bank-transfer-admin',
                    FF_BANK_TRANSFER_URL . 'assets/js/ff-bank-transfer-admin.js',
                    ['jquery'],
                    FF_BANK_TRANSFER_VERSION,
                    true
                );

                wp_localize_script('ff-bank-transfer-admin', 'ffBankTransferAdmin', [
                    'title' => __('Select or Upload QR Code', 'fluentform-bank-transfer'),
                    'button' => __('Use this image', 'fluentform-bank-transfer')
                ]);
            }
        });

        // Admin notices
        add_action('admin_notices', function () {
            if (!current_user_can('manage_options')) return;

            $settings = get_option('fluentform_payment_settings_bank_transfer');

            if (!empty($settings['is_active']) && $settings['is_active'] === 'yes') {
                if ($settings['receipt_required'] === 'no') {
                    echo '<div class="notice notice-warning"><p>';
                    echo esc_html__('Bank Transfer: Receipt uploads are currently optional. Strongly recommend enabling them in settings.', 'fluentform-bank-transfer');
                    echo '</p></div>';
                }

                if (empty($settings['account_number']) || empty($settings['bank_name'])) {
                    echo '<div class="notice notice-error"><p>';
                    echo esc_html__('Bank Transfer: Bank account details are incomplete. Please configure them in Fluent Forms payment settings.', 'fluentform-bank-transfer');
                    echo '</p></div>';
                }
            }
        });
    } catch (\Exception $e) {
        if (FF_BANK_TRANSFER_DEBUG) {
            error_log('Fluent Forms Bank Transfer Init Error: ' . $e->getMessage());
        }
    }
}, 20);

// Register text domain
add_action('init', function () {
    load_plugin_textdomain(
        'fluentform-bank-transfer',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages/'
    );
});
