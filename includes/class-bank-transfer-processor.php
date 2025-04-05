<?php
namespace FluentFormBankTransfer;

if (!defined('ABSPATH')) exit;

class BankTransferProcessor
{
    protected $method = 'bank_transfer';
    protected $validStatuses = ['pending', 'paid', 'failed', 'refunded'];

    public function __construct()
    {
        $this->init();
    }

    public function init()
    {
        add_filter('fluentform/submission_notes', [$this, 'getSubmissionNotes'], 10, 3);
    }

    public function createPaymentRecord($data)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fluentform_bank_transfer_payments';
        
        $wpdb->insert($table, [
            'form_id' => $data['form_id'],
            'submission_id' => $data['submission_id'],
            'transaction_hash' => $this->generateTransactionHash(),
            'amount' => $data['amount'],
            'payment_note' => $data['payment_note'] ?? '',
            'receipt_url' => $data['receipt_url'] ?? '',
            'qr_proof_url' => $data['qr_proof_url'] ?? '',
            'status' => 'pending',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ]);
        
        return $wpdb->insert_id;
    }

    public function getPaymentRecord($submissionId)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fluentform_bank_transfer_payments';
        
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE submission_id = %d", $submissionId)
        );
    }

    public function updatePaymentStatus($submissionId, $status)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fluentform_bank_transfer_payments';
        
        if (!in_array($status, $this->validStatuses)) {
            return false;
        }
        
        return $wpdb->update(
            $table,
            ['status' => $status],
            ['submission_id' => $submissionId]
        );
    }

    public function getSubmissionNotes($notes, $submissionId, $formId)
    {
        $payment = $this->getPaymentRecord($submissionId);
        
        if ($payment) {
            if (!empty($payment->payment_note)) {
                $notes[] = [
                    'id' => 'bt_note_' . $payment->id,
                    'note' => __('Payment Note: ', 'fluentform-bank-transfer') . $payment->payment_note,
                    'created_at' => $payment->created_at,
                    'created_by' => 'system',
                    'is_payment_note' => true
                ];
            }
            
            $notes[] = [
                'id' => 'bt_status_' . $payment->id,
                'note' => __('Payment Status: ', 'fluentform-bank-transfer') . ucfirst($payment->status),
                'created_at' => $payment->updated_at,
                'created_by' => 'system',
                'is_payment_note' => true
            ];
        }
        
        return $notes;
    }

    public function handleManualPaymentVerification($submissionId, $status)
    {
        if (!in_array($status, $this->validStatuses)) {
            return false;
        }
        
        $updated = $this->updatePaymentStatus($submissionId, $status);
        
        if ($updated && $status === 'paid') {
            do_action('fluentform/bank_transfer_payment_paid', $submissionId);
        }
        
        return $updated;
    }

    protected function generateTransactionHash()
    {
        return 'BT_' . md5(uniqid() . microtime() . mt_rand());
    }

    public function sendPaymentNotification($submissionId, $formId, $data)
    {
        $notificationManager = new \FluentForm\App\Services\Integrations\GlobalNotificationManager();
        
        $notificationData = [
            'payment_note' => $data['payment_note'] ?? '',
            'receipt_url' => $data['receipt_url'] ?? '',
            'qr_code_url' => $data['qr_proof_url'] ?? '',
            'admin_view_url' => $this->getAdminViewUrl($formId, $submissionId)
        ];

        $notificationManager->notify(
            $formId,
            $submissionId,
            'bank_transfer_payment',
            $notificationData
        );

        if (apply_filters('fluentform/send_bank_transfer_confirmation', true, $formId)) {
            $this->sendUserConfirmation($submissionId, $formId, $notificationData);
        }
    }

    protected function sendUserConfirmation($submissionId, $formId, $data)
    {
        $notification = [
            'send_to' => [
                'type' => 'email',
                'email' => '{inputs.email}'
            ],
            'subject' => __('Your Bank Transfer Payment Submission', 'fluentform-bank-transfer'),
            'message' => $this->getUserConfirmationMessage($data)
        ];

        (new \FluentForm\App\Services\Integrations\GlobalNotificationManager())
            ->notify($formId, $submissionId, 'user_notification', $notification);
    }

    protected function getUserConfirmationMessage($data)
    {
        ob_start(); ?>
        <p><?php _e('Thank you for your bank transfer payment submission!', 'fluentform-bank-transfer'); ?></p>
        
        <?php if (!empty($data['payment_note'])) : ?>
            <p><strong><?php _e('Your Payment Note:', 'fluentform-bank-transfer'); ?></strong><br>
            <?php echo esc_html($data['payment_note']); ?></p>
        <?php endif; ?>

        <p><?php _e('We will verify your payment and update the status shortly.', 'fluentform-bank-transfer'); ?></p>
        
        <?php if (!empty($data['receipt_url'])) : ?>
            <p><strong><?php _e('Payment Receipt:', 'fluentform-bank-transfer'); ?></strong><br>
            <a href="<?php echo esc_url($data['receipt_url']); ?>" target="_blank">
                <?php _e('Download Receipt', 'fluentform-bank-transfer'); ?>
            </a></p>
        <?php endif; ?>
        
        <?php if (!empty($data['qr_code_url'])) : ?>
            <p><strong><?php _e('QR Payment Proof:', 'fluentform-bank-transfer'); ?></strong><br>
            <a href="<?php echo esc_url($data['qr_code_url']); ?>" target="_blank">
                <?php _e('View QR Proof', 'fluentform-bank-transfer'); ?>
            </a></p>
        <?php endif;
        
        return ob_get_clean();
    }

    protected function getAdminViewUrl($formId, $submissionId)
    {
        return admin_url('admin.php?page=fluent_forms&route=entries&form_id='.$formId.'#/entries/'.$submissionId);
    }

    public function logError(\Exception $e, $submissionId, $formId)
    {
        error_log('Bank Transfer Error (Submission '.$submissionId.'): ' . $e->getMessage());
        
        do_action('fluentform/log_data', [
            'parent_source_id' => $formId,
            'source_type' => 'submission_item',
            'source_id' => $submissionId,
            'component' => 'Payment',
            'status' => 'error',
            'title' => 'Bank Transfer Processing Error',
            'description' => $e->getMessage()
        ]);
    }
}