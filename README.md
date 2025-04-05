# Fluent Forms Bank Transfer

**Fluent Forms Bank Transfer** is a WordPress plugin that adds a bank transfer payment method to Fluent Forms. It supports QR code generation and receipt upload for manual payment verification.

## Features

- Bank transfer payment method for Fluent Forms.
- QR code support for easy payment.
- Receipt upload for manual payment verification.
- Customizable payment instructions.
- MIME type validation for uploaded files.
- Admin notifications for incomplete settings.

## Installation

1. Download the plugin files and upload them to the `/wp-content/plugins/fluentform-bank-transfer-gateway` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure the plugin settings in the Fluent Forms payment settings.

## Usage

1. Go to **Fluent Forms > Payment Settings** in your WordPress admin dashboard.
2. Select the **Bank Transfer** payment method.
3. Configure the following settings:
   - Bank account details (Account Name, Account Number, Bank Name, etc.).
   - QR code for payment (optional).
   - Payment instructions.
   - Allowed file types and maximum file size for receipt uploads.
4. Save the settings.

## Shortcodes

The plugin does not provide shortcodes directly but integrates seamlessly with Fluent Forms.

## Development

### File Structure

```
fluentform-bank-transfer-gateway/
├── fluentform-bank-transfer.php
├── assets/
│   ├── css/
│   │   ├── admin.css
│   │   └── ff-bank-transfer.css
│   ├── js/
│   │   ├── ff-bank-transfer-admin.js
│   │   └── ff-bank-transfer.js
├── includes/
│   ├── class-bank-transfer-payment.php
│   └── class-bank-transfer-processor.php
├── templates/
│   └── payment-instructions.php
```

### Key Files

- **`fluentform-bank-transfer.php`**: Main plugin file that initializes the plugin.
- **`class-bank-transfer-payment.php`**: Handles payment-related logic.
- **`class-bank-transfer-processor.php`**: Processes bank transfer submissions.
- **`assets/`**: Contains CSS and JavaScript files for both admin and frontend.
- **`templates/`**: Contains templates for displaying payment instructions.

## Hooks and Filters

### Actions

- `plugins_loaded`: Initializes the plugin.
- `wp_enqueue_scripts`: Loads frontend assets.
- `admin_enqueue_scripts`: Loads admin assets.
- `admin_notices`: Displays admin notices for incomplete settings.

### Filters

- `fluentform/submission_notes`: Adds submission notes for bank transfer payments.

## Requirements

- WordPress 5.0 or higher.
- Fluent Forms Pro with the payment module enabled.

## Support

For support, visit [Sushant Karn](https://www.sushantkarn.com.np/).

## License

This plugin is licensed under the GPLv2 or later.
