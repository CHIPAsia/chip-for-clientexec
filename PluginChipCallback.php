<?php
require_once 'modules/admin/models/PluginCallback.php';
require_once 'modules/billing/models/class.gateway.plugin.php';
require_once 'modules/billing/models/Invoice.php';
require_once __DIR__ . '/class.chip.api.php';

class PluginChipCallback extends PluginCallback
{

  public function processCallback()
  {
    if (isset($_GET['invoice_number'])) {
      $this->handle_redirect();
      exit;
    }

    CE_Lib::log(4, 'CHIP callback invoked');

    // Checking for request / verify x-signature
    if (!isset($_SERVER['HTTP_X_SIGNATURE'])) {
      CE_Lib::log(4, 'No X Signature received from headers');
      die('No X Signature received from headers');
    }

    if (empty($content = file_get_contents('php://input'))) {
      die('No input received');
    }

    $purchase = json_decode($content, true);

    if ($purchase['status'] != 'paid') {
      exit;
    }

    $brand_id = $this->settings->get('plugin_chip_Brand ID');
    $public_key = $this->settings->get('plugin_chip_Public Key');

    $public_key = str_replace($brand_id, '', $public_key);
    // str_replace( '\n', "\n", $chip->public_key() );

    if (openssl_verify($content, base64_decode($_SERVER['HTTP_X_SIGNATURE']), $public_key, 'sha256WithRSAEncryption') != 1) {
      header('Forbidden', true, 403);
      die('Invalid X Signature');
    } else {
      CE_Lib::log(4, 'Verifying X-Signature');
    }

    $invoice_number = abs((int)$purchase['reference']);

    $this->lock($invoice_number);

    $invoice = new Invoice($invoice_number);
    if ($invoice->isPaid()) {
      return;
    }

    if ($purchase['status'] == 'paid') {
      $paid_amount = number_format($purchase['payment']['amount'] / 100, 2, '.', '');
      $purchase_id = $purchase['id'];

      $cPlugin = new Plugin($invoice_number, 'chip', $this->user);

      $cPlugin->setTransactionID($purchase_id);
      $cPlugin->setAmount($paid_amount);
      $cPlugin->setAction('charge'); // charge or refund

      $transaction = "CHIP: Purchase ID: {$purchase_id} with Invoice No: #{$invoice_number} was successfully PAID through callback";
      CE_Lib::log(4, $transaction);

      $cPlugin->PaymentAccepted($paid_amount, $transaction);
      return;
    }
  }

  private function handle_redirect()
  {
    $invoice_number = abs((int)$_GET['invoice_number']);

    $clientExecURL = CE_Lib::getSoftwareURL();
    $invoiceviewURLSuccess = $clientExecURL . "/index.php?fuse=billing&paid=1&controller=invoice&view=invoice&id=" . $invoice_number;

    $this->lock($invoice_number);

    $invoice = new Invoice($invoice_number);
    if ($invoice->isPaid()) {
      header('Location:' . $invoiceviewURLSuccess);
      return;
    }

    $selectQuery = "SELECT * "
      . "FROM `invoicetransaction` "
      . "WHERE `accepted` = 1 "
      . "AND `invoiceid` = ? "
      . "AND `response` LIKE 'CHIP payment of%' "
      . "AND `action` = 'NA' "
      . "ORDER BY `id` ASC LIMIT 1 ";
    $result = $this->db->query($selectQuery, $invoice_number);
    $invoicetransaction = $result->fetch();

    $purchase_id = $invoicetransaction['transactionid'];

    $cPlugin = new Plugin($invoicetransaction['invoiceid'], 'chip', $this->user);

    $brand_id = trim($cPlugin->GetPluginVariable('plugin_chip_Brand ID'));
    $secret_key = trim($cPlugin->GetPluginVariable('plugin_chip_Secret Key'));

    $chip = ChipApi::get_instance($secret_key, $brand_id);
    $purchase = $chip->get_payment($purchase_id);

    if ($purchase['status'] == 'paid') {
      $paid_amount = number_format($purchase['payment']['amount'] / 100, 2, '.', '');
      $cPlugin->setTransactionID($purchase['id']);
      $cPlugin->setAmount($paid_amount);
      $cPlugin->setAction('charge'); // charge or refund

      $transaction = "CHIP: Purchase ID: {$purchase_id} with Invoice No: #{$invoice_number} was successfully PAID";
      CE_Lib::log(4, $transaction);

      $cPlugin->PaymentAccepted($paid_amount, $transaction);
      header('Location:' . $invoiceviewURLSuccess);
      return;
    }

    header('Location:' . $clientExecURL . "/index.php?fuse=billing&cancel=1&controller=invoice&view=invoice&id=" . $invoice_number);
    return;
  }

  private function lock($invoice_number)
  {
    $result = $this->db->query("SELECT GET_LOCK('invoice_{$invoice_number}', 5);");
    $result->fetch();
  }
}
