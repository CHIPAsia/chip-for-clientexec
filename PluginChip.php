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

        // echo '<h1>Hello World</h1>';
        return $variables;
    }

    public function singlePayment($params)
    {
        // Set the credentials
        $brandId = $params['plugin_chip_Brand ID'];
        $secretKey = $params['plugin_chip_API Key'];

        // Set the URLs
        $baseURL = rtrim(CE_Lib::getSoftwareURL(), '/') . '/';
        $callbackURL = $baseURL . "plugins/gateways/chip/callback.php";
        $succesURL = $params['invoiceviewURLSuccess'];
        $cancelURL = $params['invoiceviewURLCancel'];

        if (empty($secretKey) || empty($brandId)) {
            // do nothing
        } else {
            $chip   = Chip::get_instance($secretKey, $brandId);
            $result = $chip->payment_methods('MYR');
        }

        $invoiceNo = $params['invoiceNumber'];
        $amount = round($params["invoiceTotal"], 2);
        $firstName = $params['userFirstName'];
        $lastName = $params['userLastName'];
        $email = $params['userEmail'];
        $currencyCode = $params['userCurrency'];
        // $exchangeRate = !empty($params['plugin_uddoktapay_Exchange Rate']) ? $params['plugin_uddoktapay_Exchange Rate'] : 1;

        if ($currencyCode != 'MYR') {
            echo 'Not MYR';
        }

        // exit;

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
                'price'    => round($params['invoiceTotal'] * 100),
              ]),
            ),
        );

        // Call API Create Purchase
        $create_payment = $chip->create_payment($purchase_params);

        header('Location:' . $create_payment['checkout_url'], true, 303);
        exit();

        // Try and catch
        // try {
        //     // Call API Create Purchase
        //     $create_payment = $chip->create_payment($purchase_params);
        //     header('Location:' . $create_payment['checkout_url']);
        //     exit();
        // } catch (Exception $e) {
        //     die("Initialization Error: " . $e->getMessage());
        // }

    }

    public function credit($params) 
    {

    }
}
