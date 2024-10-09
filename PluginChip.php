<?php
require_once 'modules/admin/models/GatewayPlugin.php';
require_once 'modules/billing/models/class.gateway.plugin.php';
require_once 'plugins/gateways/chip/Chip.php';

/**
 * @package Plugins
 */
class PluginChip extends GatewayPlugin
{
    public function getVariables()
    {
        $variables = array(
            lang("Plugin Name") => array(
                "type"          => "hidden",
                "description"   => "",
                "value"         => "CHIP"
            ),
            lang('API Key') => array(
                'type'          => 'password',
                'description'   => "Live API Key. For test mode, use the test API key from your CHIP Merchant Portal, `Developer > API Key`.",
                'value'         => ''
            ),
            lang("Brand ID") => array(
                "type"          => "text",
                "description"   => "Use the Brand ID from your CHIP Merchant Portal, `Developer > Brands`.",
                "value"         => ''
            ),
            lang("Signup Name") => array(
                "type"          => "text",
                "description"   => lang("Select the name to display in the signup process for this payment type. Example: Mollie iDeal."),
                "value"         => "CHIP"
            )
        );

        return $variables;
    }

    public function singlePayment($params)
    {
        CE_Lib::log(4, 'SinglePayment() initiated');

        // Set the credentials
        $brandId = $params['plugin_chip_Brand ID'];
        $secretKey = $params['plugin_chip_API Key'];

        // Set the URLs
        $baseURL = rtrim(CE_Lib::getSoftwareURL(), '/') . '/';
        $callbackURL = $baseURL . "plugins/gateways/chip/callback.php";
        $succesURL = $params['invoiceviewURLSuccess'];
        $cancelURL = $params['invoiceviewURLCancel'];

        // Checking if brand ID and API Key not empty
        if ((! empty($secretKey)) || (! empty($brandId))) {
            $chip   = Chip::get_instance($secretKey, $brandId);
            $result = $chip->payment_methods('MYR');
        }

        // Initialize
        $invoiceNo = $params['invoiceNumber'];
        $invoiceTotal = round($params["invoiceTotal"], 2);
        $firstName = $params['userFirstName'];
        $lastName = $params['userLastName'];
        $email = $params['userEmail'];
        $currencyCode = $params['userCurrency'];

        // Checking for currency
        if ($currencyCode != 'MYR') {
            CE_Lib::log(4, 'Currency is not MYR (RM)');
            exit();
        }

        // Checking for refund
        if (isset($params['refund']) && $params['refund']) {
            CE_Lib::log(4, 'Refund running in singlePayment()');

            // Create plug in class to interact with CE.
            $cPlugin = new Plugin($params['invoiceNumber'], 'chip', $this->user);
            $cPlugin->setAmount($invoiceTotal);
            $cPlugin->setAction('refund');

            // Get transid
            $transactionId = $params['invoiceRefundTransactionId'];

            // Refund thru CHIP API
            $result = $chip->refund_payment($transactionId, array('amount' => round($invoiceTotal  * 100)));    

            // If error
            if ( !array_key_exists( 'id', $result ) OR $result['status'] != 'success') {
                CE_Lib::log(4, array(
                    'status'  => 'error',
                    'rawdata' => json_encode($result),
                    'transid' => $transactionId,
                  ));

                  $cPlugin->PaymentRejected($this->user->lang("There was an error performing this operation.")." ".$result['status']);
                  return $this->user->lang("There was an error performing this operation.")." ".$result['status'];
            } else {
                CE_Lib::log(4, array(
                    'status'  => 'success',
                    'rawdata' => json_encode($result),
                    'transid' => $result['id'],
                    'fees'    => $result['payment']['fee_amount'] / 100,
                  ));

                $cPlugin->PaymentAccepted($invoiceTotal, "CHIP refund of RM { $amount } was successfully processed.", $transactionId);
                return array('AMOUNT' => $invoiceTotal);
            }
        }

        // Initialize parameter to create purchase
        $purchase_params = array(
            'client' => array(
                'email' => $params['userEmail']
            ),
            'success_redirect' => $succesURL,
            // 'failure_redirect' => '',
            'cancel_redirect' => $cancelURL,
            'success_callback' => $callbackURL,
            'creator_agent'    => 'Clientexec',
            'reference'        => $invoiceNo,
            // 'client_id'        => $client['id'],
            'platform'         => 'api', // 'clientexec'
            // 'send_receipt'     => $params['purchaseSendReceipt'] == 'on',
            // 'due'              => time() + (abs( (int)$params['dueStrictTiming'] ) * 60),
            'brand_id'         => $brandId,
            'purchase'         => array(
            //   'timezone'   => $params['purchaseTimeZone'],
              'currency'   => $currencyCode,
            //   'due_strict' => $params['dueStrict'] == 'on',
              'products'   => array([
                'name'     => substr($params['invoiceDescription'], 0, 256),
                'price'    => round($invoiceTotal * 100),
              ]),
            ),
        );

        // Call API Create Purchase
        $create_payment = $chip->create_payment($purchase_params);

        header('Location:' . $create_payment['checkout_url'], true, 303);
        exit();
    }

    public function credit($params) 
    {
        CE_Lib::log(4, 'Refund in credit() initiated');

        // Refund is true
        $params['refund'] = true;
        return $this->singlePayment($params);
    }
}
