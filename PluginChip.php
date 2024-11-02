<?php
require_once 'modules/admin/models/GatewayPlugin.php';
require_once 'modules/billing/models/class.gateway.plugin.php';
require_once __DIR__ . '/class.chip.api.php';

/**
 * @package Plugins
 */
class PluginChip extends GatewayPlugin
{
  public function getVariables()
  {
    $variables = array(
      lang('Plugin Name') => array(
        'type' => 'hidden',
        'description' => '',
        'value' => 'CHIP'
      ),
      lang('Secret Key') => array(
        'type' => 'text',
        'description' => 'Live Key. For test mode, use the test key from your CHIP Merchant Portal, `Developer > Key`.',
        'value' => ''
      ),
      lang('Brand ID') => array(
        'type' => 'text',
        'description' => 'Use the Brand ID from your CHIP Merchant Portal, `Developer > Brands`.',
        'value' => ''
      ),
      lang('Due Strict') => array(
        'type' => 'yesno',
        'description' => 'Enforce due strict payment timeframe to block payment after due strict timing is passed.',
        'value' => ''
      ),
      lang('Due Strict Timing') => array(
        'type' => 'text',
        'description' => 'Due strict timing in minutes. Default value is: 60.',
        'value' => '60'
      ),
      lang('Purchase Send Receipt') => array(
        'type' => 'yesno',
        'description' => 'Select Yes to ask CHIP to send receipt upon successful payment. If activated, CHIP will send purchase receipt upon payment completion.',
        'value' => ''
      ),
      lang('Payment Method Whitelist') => array(
        'type' => 'text',
        'description' => 'Set payment method whitelist separated by comma. Acceptable value: fpx, fpx_b2b1, mastercard, maestro, visa, razer_atome, razer_grabpay, razer_maybankqr, razer_shopeepay, razer_tng, duitnow_qr. Leave blank if unsure.',
        'value' => ''
      ),
      lang('Public Key') => array(
        'type' => 'textarea',
        'description' => 'CHIP Account Public Key. Will be auto populated upon save. Leave blank.',
        'value' => '',
      ),
      lang('Signup Name') => array(
        'type' => 'text',
        'description' => lang('Select the name to display in the signup process for this payment type. Example: FPX / Credit Card / E-Wallet.'),
        'value' => 'CHIP'
      ),
      lang('Auto Payment') => array(
        'type' => 'hidden',
        'description' => lang('No description'),
        'value' => '0'
      ),
    );

    return $variables;
  }

  public function singlePayment($params)
  {
    return $this->autopayment($params);
  }

  public function credit($params)
  {
    $cPlugin = new Plugin($params['invoiceNumber'], 'chip', $this->user);
    $cPlugin->setAmount($params['invoiceTotal']);

    $cPlugin->setAction('refund');
    $transactionId = $params['invoiceRefundTransactionId'];

    $brand_id = $params['plugin_chip_Brand ID'];
    $secret_key = $params['plugin_chip_Secret Key'];

    $chip = ChipApi::get_instance($secret_key, $brand_id);
    $result = $chip->refund_payment($transactionId, []);

    if (!array_key_exists('id', $result) or $result['status'] != 'success') {
      CE_Lib::log(4, array(
        'status' => 'error',
        'rawdata' => json_encode($result),
        'transid' => $transactionId,
      ));

      $cPlugin->PaymentRejected($this->user->lang("There was an error performing this operation.") . " " . $result['status']);
      return $this->user->lang("There was an error performing this operation.") . " " . $result['status'];
    } else {
      $refund_id = $result['id'];

      CE_Lib::log(4, array(
        'status' => 'success',
        'rawdata' => json_encode($result),
        'transid' => $refund_id,
        'fees' => number_format($result['payment']['fee_amount'] / 100, 2, '.', ''),
      ));

      $chip_refund_amount = number_format($result['payment']['amount'] / 100, 2, '.', '');

      $cPlugin->PaymentAccepted($chip_refund_amount, "CHIP refund of RM {$chip_refund_amount} was successfully processed. Refund ID: {$refund_id}", $result['id']);
      return array('AMOUNT' => $chip_refund_amount);
    }
  }

  public function autopayment($params)
  {
    CE_Lib::log(4, 'AutoPayment() initiated');

    if ($params['currencytype'] != 'MYR') {
      return $this->user->lang('Currency ' . $params['currencytype'] . 'is not supported.');
    }

    // Set the credentials
    $brand_id = $params['plugin_chip_Brand ID'];
    $secret_key = $params['plugin_chip_Secret Key'];

    if (empty($secret_key) or empty($brand_id)) {
      return $this->user->lang('Secret Key or Brand ID not set.');
    }

    $this->maybe_save_public_key($secret_key, $brand_id);

    $baseURL = rtrim(CE_Lib::getSoftwareURL(), '/') . '/';

    $purchase_params = array(
      'client' => array(
        'full_name' => substr(preg_replace('/[^A-Za-z0-9\@\/\\\(\)\.\-\_\,\&\']\ /', '', str_replace('’', '\'', $params['userFirstName'] . ' ' . $params['userLastName'])), 0, 128),
        'email' => $params['userEmail'],
        'street_address' => $params['userAddress'],
        'city' => $params['userCity'],
        'zip_code' => $params['userZipcode'],
        'country' => $params['userCountry'],
        'state' => $params['userState'], // this is in number. need to convert
        'personal_code' => $params['CustomerID'],
      ),
      'success_redirect' => "{$baseURL}plugins/gateways/chip/callback.php?invoice_number=" . $params['invoiceNumber'],
      'failure_redirect' => $params['invoiceviewURLCancel'],
      'cancel_redirect' => $params['invoiceviewURLCancel'],
      'success_callback' => "{$baseURL}plugins/gateways/chip/callback.php",
      'creator_agent' => 'Clientexec 1.0.0',
      'reference' => $params['invoiceNumber'],
      'platform' => 'api', // 'clientexec'
      'send_receipt' => $params['plugin_chip_Purchase Send Receipt'] == '1',
      'brand_id' => $brand_id,
      'purchase' => array(
        'timezone' => 'Asia/Kuala_Lumpur',
        'currency' => $params['currencytype'],
        'due_strict' => $params['plugin_chip_Due Strict'] == '1',
        'products' => array(
          [
            'name' => substr($params['invoiceDescription'], 0, 256),
            'price' => round($params['invoiceTotal'] * 100),
          ]
        ),
      ),
    );

    if (!empty($params['plugin_chip_Due Strict Timing'])) {
      $purchase_params['due'] = time() + abs((int)$params['plugin_chip_Due Strict Timing']) * 60;
    }

    foreach ($purchase_params['client'] as $key => $value) {
      if (empty($value)) {
        unset($purchase_params['client'][$key]);
      }
    }

    if (!empty($payment_method_whitelist = str_replace(' ', '', strtolower($params['plugin_chip_Payment Method Whitelist'])))) {
      $payment_method_whitelist = explode(',', $payment_method_whitelist);
      $diff = array_diff($payment_method_whitelist, ['fpx', 'fpx_b2b1', 'mastercard', 'maestro', 'visa', 'razer_atome', 'razer_grabpay', 'razer_maybankqr', 'razer_shopeepay', 'razer_tng', 'duitnow_qr']);
      if (empty($diff)) {
        $purchase_params['payment_method_whitelist'] = $payment_method_whitelist;
      }
    }

    // Call API Create Purchase
    $chip = ChipApi::get_instance($secret_key, $brand_id);
    $create_payment = $chip->create_payment($purchase_params);

    $cPlugin = new Plugin($params['invoiceNumber'], 'chip', $this->user);
    $cPlugin->setTransactionID($create_payment['id']);
    $cPlugin->setAmount($params['invoiceTotal']);

    $transaction = 'CHIP payment of ' . number_format($params['invoiceTotal'], 2) . " was marked 'pending'. Original Signup Invoice: " . $params['invoiceNumber'] . " (Purchase ID: " . $create_payment['id'] . ")";
    $cPlugin->PaymentPending($transaction, $create_payment['id']);

    header('Location:' . $create_payment['checkout_url'], true, 303);
    exit();
  }

  private function maybe_save_public_key($secret_key, $brand_id)
  {

    $public_key = $this->settings->get('plugin_chip_Public Key');
    if (!str_contains($public_key, $brand_id)) {

      $chip = ChipApi::get_instance($secret_key, $brand_id);
      $chip_public_key = $chip->public_key();

      $new_public_key = $chip_public_key . $brand_id;
      $this->settings->updateValue('plugin_chip_Public Key', $new_public_key);
    }
  }
}
