<?php
require_once 'modules/admin/models/PluginCallback.php';
require_once 'modules/billing/models/class.gateway.plugin.php';
require_once 'modules/billing/models/Invoice.php';
require_once 'plugins/gateways/chip/Chip.php';

class PluginChipCallback extends PluginCallback
{

    public function processCallback()
    {
        CE_Lib::log(4, 'CHIP callback invoked');

        // Checking for request / verify x-signature
        if ( !isset( $_SERVER['HTTP_X_SIGNATURE'] ) ) {
            CE_Lib::log(4, 'No X Signature received from headers');
            die('No X Signature received from headers');
        } 

        if ( empty($content = file_get_contents('php://input')) ) {
            die('No input received');
        } 

        $payment = json_decode($content, true);

        if ( $payment['status'] != 'paid' ) {
            exit;
        }

        // Check for invoiceId
        $pluginName = 'chip';
        $invoiceId = $payment['reference'];
        $cPlugin = new Plugin($invoiceId, $pluginName, $this->user);

        // Get Brand ID and API Key
        $brandId = trim($cPlugin->GetPluginVariable("plugin_chip_Brand ID"));
        $secretKey = trim($cPlugin->GetPluginVariable("plugin_chip_API Key"));

        // CE_Lib::log(4, $brandId);

        // Get Public Key
        $chip = Chip::get_instance($secretKey, $brandId);
        $public_key = str_replace( '\n', "\n", $chip->public_key() );

        // CE_Lib::log(4, $public_key);

        if ( openssl_verify( $content,  base64_decode($_SERVER['HTTP_X_SIGNATURE']), $public_key, 'sha256WithRSAEncryption' ) != 1 ) {
        header( 'Forbidden', true, 403 );
            die('Invalid X Signature');
        } else {
            CE_Lib::log(4, 'Verifying X-Signature');
        }

        $totalAmount = $payment['purchase']['total'] / 100;
        $cPlugin->setTransactionID($payment['id']);
        $cPlugin->setAmount($totalAmount);
        $cPlugin->setAction('charge'); // charge or refund


        if ($payment['status'] == 'paid') {
            // There is a delay few seconds
            $transactionId = $payment['id'];
            $transaction = "CHIP: Purchase (ID: $transactionId) with Invoice No: #$invoiceId was successfully PAID";
            CE_Lib::log(4, $transaction);
            

            $cPlugin->PaymentAccepted($totalAmount, $transaction);
            $returnURL = CE_Lib::getSoftwareURL() . "/index.php?fuse=billing&paid=1&controller=invoice&view=invoice&id=" . $invoiceId;
            CE_Lib::log(4, "Return URL: $returnURL");
            header("Location: " . $returnURL);
            exit;
        }
    }
}

