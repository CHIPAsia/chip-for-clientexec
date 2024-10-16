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
            ),
            lang('Auto Payment') => array(
                'type'        => 'hidden',
                'description' => lang('No description'),
                'value'       => '1'
            ),
            lang('CC Stored Outside') => array(
                'type'        => 'hidden',
                'description' => lang('If this plugin is Auto Payment, is Credit Card stored outside of Clientexec? 1 = YES, 0 = NO'),
                'value'       => '1'
            ),
            lang('Billing Profile ID') => array(
                'type'        => 'hidden',
                'description' => lang('Is this plugin storing a Billing-Profile-ID? 1 = YES, 0 = NO'),
                'value'       => '1'
            ),
        );

        return $variables;
    }

    public function singlePayment($params)
    {
        // CE_Lib::log(4, $params);
        // die();
        return $this->autopayment($params);
    }

    public function credit($params) 
    {
        // CE_Lib::log(4, $this->settings->get('plugin_chip_Brand ID'));
        // die();
        // $params['refund'] = true;
        // return $this->singlePayment($params);

        // $user = new User($params['CustomerID']);
        // $this->getBillingProfileID($user);
        die();
    }

    public function autopayment($params) 
    {
        // Check for autopayment
        CE_Lib::log(4, 'AutoPayment() initiated');

        // die();

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

        // Create plug in class to interact with CE.
        $cPlugin = new Plugin($params['invoiceNumber'], 'chip', $this->user);
        $cPlugin->setAmount($invoiceTotal);

        try {
            // User
            $user = new User($params['CustomerID']);
            CE_Lib::log(4, 'Create user!');
            CE_Lib::log(4, $user);
            $this->getBillingProfileID($user);
           
            die();

        } catch (Exception $e) {
            CE_Lib::log(1, $this->user->lang("There was an error performing this operation.")." ".$e->getMessage());

            $cPlugin->PaymentRejected($this->user->lang("There was an error performing this operation.")." ".$e->getMessage());
            return $this->user->lang("There was an error performing this operation.")." ".$e->getMessage();
        }

        die();

        // Checking for refund
        if (isset($params['refund']) && $params['refund']) {
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

    // Get customer CHIP Profile
    public function getBillingProfileID($user)
    {
        CE_Lib::log(4, 'In getBillingProfileID()');
        CE_Lib::log(4,  $user);
        
        die();
        // test purpose
        $secretKey = $this->settings->get('plugin_chip_API Key');
        $brandId = $this->settings->get('plugin_chip_Brand ID');

        $profile_id = '';
        $payment_method = '';
        $Billing_Profile_ID = '';

        $secretKey = $this->settings->get('plugin_chip_API Key');
        $brandId = $this->settings->get('plugin_chip_Brand ID');

        CE_Lib::log(4, "Billig-Profile-ID");
        CE_Lib::log(4, $user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID));
        // die();

        if ($user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != '') {
            $profile_id_array = unserialize($Billing_Profile_ID);
            CE_Lib::log(4, "Displaying Profile ID Array");
            CE_Lib::log(4, $profile_id_array);
            
            if (is_array($profile_id_array) && isset($profile_id_array[basename(dirname(__FILE__))])) {
                $profile_id = $profile_id_array[basename(dirname(__FILE__))];
            }
        }

        CE_Lib::log(4, "Displaying Profile ID");
        CE_Lib::log(4, $profile_id);

        $profile_id_values_array = explode('|', $profile_id);
        $profile_id = $profile_id_values_array[0];

        if (isset($profile_id_values_array[1])) {
            $payment_method = $profile_id_values_array[1];
            CE_Lib::log(4, $payment_method);
        } else {
            CE_Lib::log(4, 'Else profile id value not set');
            if ($profile_id != '') {
                try {
                    // Get the client 
                    $chip   = Chip::get_instance($secretKey, $brandId);
                    $customers_api = $chip->get_client_by_email($user->fields['email'])['results'][0];

                    try {
                        // $result = $customers_api->retrieveCustomer($profile_id);


                        // Retrieve list of recurring tokens of clients
                        $recurring_tokens = $chip->list_tokens($customers_api['id'])['results'];
                        CE_Lib::log(4, $recurring_tokens);
                        die();

                    } catch (Exception $e) {
                        $profile_id = '';
                    }
                } catch (Exception $e) {
                    $profile_id = '';
                }
            } else {
                CE_Lib::log(4, 'Profile ID is empty');
            }
        }

        $profile_id_values_array[0] = $profile_id;
        $profile_id_values_array[1] = $payment_method;

        // $this->setBillingProfileID($user, $profile_id_values_array);

        // return $profile_id_values_array;

        CE_Lib::log(4, 'End of getBillingProfileID()');
    }

    // set Billing-Profile-ID
    public function setBillingProfileID($user, $profile_id_values_array)
    {
        $profile_id = $profile_id_values_array[0];
        $payment_method = $profile_id_values_array[1];
        $Billing_Profile_ID = '';
        $profile_id_array = array();

        if ($user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != '') {
            $profile_id_array = unserialize($Billing_Profile_ID);
        }

        if (!is_array($profile_id_array)) {
            $profile_id_array = array();
        }

        if ($profile_id != '' && $payment_method != '') {
            $profile_id_array[basename(dirname(__FILE__))] = $profile_id.'|'.$payment_method;
        } else {
            unset($profile_id_array[basename(dirname(__FILE__))]);
        }

        $user->updateCustomTag('Billing-Profile-ID', serialize($profile_id_array));
        $user->save();
    }
}
