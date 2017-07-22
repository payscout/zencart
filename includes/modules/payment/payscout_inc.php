<?php
/**
 * Payscout Inc payment method class
 *
 * @package paymentMethod
 * @copyright Copyright 2017 Payscout Development Team
 * @copyright Portions Copyright 2017 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: Author: Payscout Inc  July 20 2017  Modified in v1.5.5e $
 */
/**
 * Payscout Inc Payment Module
 * You must have SSL active on your server to be compliant with merchant TOS
 */
class payscout_inc extends base {
  /**
   * $code determines the internal 'code' name used to designate "this" payment module
   *
   * @var string
   */
  var $code;
  /**
   * $title is the displayed name for this payment method
   *
   * @var string
   */
  var $title;
  /**
   * $description is a soft name for this payment method
   *
   * @var string
   */
  var $description;
  /**
   * $enabled determines whether this module shows or not... in catalog.
   *
   * @var boolean
   */
  var $enabled;
  /**
   * $delimiter determines what separates each field of returned data from payscout
   *
   * @var string (single char)
   */
  var $delimiter = '|';
  /**
   * $encapChar denotes what character is used to encapsulate the response fields
   *
   * @var string (single char)
   */
  var $encapChar = '*';
  /**
   * log file folder
   *
   * @var string
   */
  var $_logDir = '';
  /**
   * communication vars
   */
  var $authorize = '';
  var $commErrNo = 0;
  var $commError = '';
  /**
   * this module collects card-info onsite
   */
  var $collectsCardDataOnsite = TRUE;
  /**
   * debug content var
   */
  var $reportable_submit_data = array();
  /**
   * Given that this module can be used to interact with other gateways (authnet emulators),
   * this var is used to declare which gateway to work with
   */
  private $mode = 'INC';
  /**
   * @var string the currency enabled in this gateway's merchant account
   */
  private $gateway_currency;

  /**
   * Constructor
   */
  function __construct() {
    global $order, $messageStack;
    $this->code = 'payscout_inc';
    $this->enabled = ((MODULE_PAYMENT_PAYSCOUT_INC_STATUS == 'True') ? true : false); // Whether the module is installed or not
    if (IS_ADMIN_FLAG === true) {
      // Payment module title in Admin
      $this->title = MODULE_PAYMENT_PAYSCOUT_INC_TEXT_ADMIN_TITLE;
      if ($this->enabled) {
        if (MODULE_PAYMENT_PAYSCOUT_INC_USERNAME == '' || MODULE_PAYMENT_PAYSCOUT_INC_PASSWORD == '') {
          $this->title .=  '<span class="alert"> (Not Configured)</span>';
        } elseif (MODULE_PAYMENT_PAYSCOUT_INC_TESTMODE == 'Test') {
          $this->title .= '<span class="alert"> (in Testing mode)</span>';
        }
        if (!function_exists('curl_init')) $messageStack->add_session(MODULE_PAYMENT_PAYSCOUT_INC_TEXT_ERROR_CURL_NOT_FOUND, 'error');
       
      }
    } else {
      $this->title = MODULE_PAYMENT_PAYSCOUT_INC_TEXT_CATALOG_TITLE; // Payment module title in Catalog
    }
    $this->description = MODULE_PAYMENT_PAYSCOUT_INC_TEXT_DESCRIPTION; // Descriptive Info about module in Admin
    $this->sort_order = MODULE_PAYMENT_PAYSCOUT_INC_SORT_ORDER; // Sort Order of this payment option on the customer payment page
    $this->form_action_url = zen_href_link(FILENAME_CHECKOUT_PROCESS, '', 'SSL', false); // Page to go to upon submitting page info
    $this->order_status = (int)DEFAULT_ORDERS_STATUS_ID;
    if ((int)MODULE_PAYMENT_PAYSCOUT_INC_ORDER_STATUS_ID > 0) {
      $this->order_status = (int)MODULE_PAYMENT_PAYSCOUT_INC_ORDER_STATUS_ID;
    }
    // Reset order status to pending if capture pending:
    if (MODULE_PAYMENT_PAYSCOUT_INC_AUTHORIZATION_TYPE == 'debit') $this->order_status = 1;

    #$this->_logDir = defined('DIR_FS_LOGS') ? DIR_FS_LOGS : DIR_FS_SQL_CACHE;

    #if (is_object($order)) $this->update_status();

    
    // set the currency for the gateway (others will be converted to this one before submission)
    $this->gateway_currency = MODULE_PAYMENT_PAYSCOUT_INC_CURRENCY;
  }
  /**
   * calculate zone matches and flag settings to determine whether this module should display to customers or not
   *
   */
  function update_status() {
    global $order, $db;
    if (IS_ADMIN_FLAG === false) {
      // if store is not running in SSL, cannot offer credit card module, for PCI reasons
      if (MODULE_PAYMENT_PAYSCOUT_INC_TESTMODE == 'Live' && (!defined('ENABLE_SSL') || ENABLE_SSL != 'true')) $this->enabled = FALSE;
    }
    // check other reasons for the module to be deactivated:
    if ($this->enabled && (int)MODULE_PAYMENT_PAYSCOUT_INC_ZONE > 0 && isset($order->billing['country']['id'])) {
      $check_flag = false;
      $check = $db->Execute("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_PAYSCOUT_INC_ZONE . "' and zone_country_id = '" . (int)$order->billing['country']['id'] . "' order by zone_id");
      while (!$check->EOF) {
        if ($check->fields['zone_id'] < 1) {
          $check_flag = true;
          break;
        } elseif ($check->fields['zone_id'] == $order->billing['zone_id']) {
          $check_flag = true;
          break;
        }
        $check->MoveNext();
      }

      if ($check_flag == false) {
        $this->enabled = false;
      }
    }

    // other status checks?
    if ($this->enabled) {
      // other checks here
    }
  }
  /**
   * JS validation which does error-checking of data-entry if this module is selected for use
   * (Number, Owner, and CVV Lengths)
   *
   * @return string
   */
  function javascript_validation() {
    $js = '  if (payment_value == "' . $this->code . '") {' . "\n" .
    '    var cc_owner_first_name = document.checkout_payment.payscout_inc_cc_owner_first_name.value;' . "\n" .
	'    var cc_owner_last_name = document.checkout_payment.payscout_inc_cc_owner_last_name.value;' . "\n" .
    '    var cc_number = document.checkout_payment.payscout_inc_cc_number.value;' . "\n" .
	'    var cc_billing_dob = document.checkout_payment.payscout_inc_cc_billing_dob.value;' . "\n";
    
      $js .= '    var cc_cvv = document.checkout_payment.payscout_inc_cc_cvv.value;' . "\n";
    
    $js .= '    if (cc_owner_first_name == "" || cc_owner_first_name.length < ' . CC_OWNER_MIN_LENGTH . ') {' . "\n" .
    '      error_message = error_message + "' . MODULE_PAYMENT_PAYSCOUT_INC_TEXT_JS_CC_OWNER_FIRST_NAME . '";' . "\n" .
    '      error = 1;' . "\n" .
    '    }' . "\n" .
	
	'    if (cc_owner_last_name == "" || cc_owner_last_name.length < ' . CC_OWNER_MIN_LENGTH . ') {' . "\n" .
    '      error_message = error_message + "' . MODULE_PAYMENT_PAYSCOUT_INC_TEXT_JS_CC_OWNER_LAST_NAME . '";' . "\n" .
    '      error = 1;' . "\n" .
    '    }' . "\n" .
	
    '    if (cc_number == "" || cc_number.length < ' . CC_NUMBER_MIN_LENGTH . ') {' . "\n" .
    '      error_message = error_message + "' . MODULE_PAYMENT_PAYSCOUT_INC_TEXT_JS_CC_NUMBER . '";' . "\n" .
    '      error = 1;' . "\n" .
    '    }' . "\n";
  
      $js .= '    if (cc_cvv == "" || cc_cvv.length < "3" || cc_cvv.length > "4") {' . "\n".
      '      error_message = error_message + "' . MODULE_PAYMENT_PAYSCOUT_INC_TEXT_JS_CC_CVV . '";' . "\n" .
      '      error = 1;' . "\n" .
      '    }' . "\n" ;
	  
	  $js .= '    if (cc_billing_dob == "" || cc_billing_dob.length < "10" || cc_billing_dob.length > "10") {' . "\n".
      '      error_message = error_message + "' . MODULE_PAYMENT_PAYSCOUT_INC_TEXT_JS_CC_BILLING_DOB . '";' . "\n" .
      '      error = 1;' . "\n" .
      '    }' . "\n" ;
   
    $js .= '  }' . "\n";

    return $js;
  }
  /**
   * Display Credit Card Information Submission Fields on the Checkout Payment Page
   *
   * @return array
   */
  function selection() {
    global $order;

    for ($i=1; $i<13; $i++) {
      $expires_month[] = array('id' => sprintf('%02d', $i), 'text' => strftime('%B - (%m)',mktime(0,0,0,$i,1,2000)));
    }

    $today = getdate();
    for ($i=$today['year']; $i < $today['year']+15; $i++) {
      $expires_year[] = array('id' => strftime('%y',mktime(0,0,0,1,1,$i)), 'text' => strftime('%Y',mktime(0,0,0,1,1,$i)));
    }
    $onFocus = ' onfocus="methodSelect(\'pmt-' . $this->code . '\')"';

    $selection = array('id' => $this->code,
                       'module' => MODULE_PAYMENT_PAYSCOUT_INC_TEXT_CATALOG_TITLE,
                       'fields' => array(array('title' => MODULE_PAYMENT_PAYSCOUT_INC_TEXT_CREDIT_CARD_OWNER_FIRST_NAME,
                                               'field' => zen_draw_input_field('payscout_inc_cc_owner_first_name', $order->billing['firstname'], 'id="'.$this->code.'-cc-owner-first-name"'. $onFocus . ' autocomplete="off"'),
                                               'tag' => $this->code.'-cc-owner-first-name'),											   
										array('title' => MODULE_PAYMENT_PAYSCOUT_INC_TEXT_CREDIT_CARD_OWNER_LAST_NAME,
                                               'field' => zen_draw_input_field('payscout_inc_cc_owner_last_name', $order->billing['lastname'], 'id="'.$this->code.'-cc-owner-last-name"'. $onFocus . ' autocomplete="off"'),
                                               'tag' => $this->code.'-cc-owner-last-name'),										
										array('title' => MODULE_PAYMENT_PAYSCOUT_INC_TEXT_BILLING_DOB,
                                               'field' => zen_draw_input_field('payscout_inc_cc_billing_dob', '', 'id="'.$this->code.'-cc-billing-dob"'. $onFocus . ' autocomplete="off"'),
                                               'tag' => $this->code.'-cc-billing-dob'),	   											   
                                         array('title' => MODULE_PAYMENT_PAYSCOUT_INC_TEXT_CREDIT_CARD_NUMBER,
                                               'field' => zen_draw_input_field('payscout_inc_cc_number', '', 'id="'.$this->code.'-cc-number"' . $onFocus . ' autocomplete="off"'),
                                               'tag' => $this->code.'-cc-number'),
                                         array('title' => MODULE_PAYMENT_PAYSCOUT_INC_TEXT_CREDIT_CARD_EXPIRES,
                                               'field' => zen_draw_pull_down_menu('payscout_inc_cc_expires_month', $expires_month, strftime('%m'), 'id="'.$this->code.'-cc-expires-month"' . $onFocus) . '&nbsp;' . zen_draw_pull_down_menu('payscout_inc_cc_expires_year', $expires_year, '', 'id="'.$this->code.'-cc-expires-year"' . $onFocus),
                                               'tag' => $this->code.'-cc-expires-month')));
   
      $selection['fields'][] = array('title' => MODULE_PAYMENT_PAYSCOUT_INC_TEXT_CVV,
                                   'field' => zen_draw_input_field('payscout_inc_cc_cvv', '', 'size="4" maxlength="4"' . ' id="'.$this->code.'-cc-cvv"' . $onFocus . ' autocomplete="off"') . ' ' . '<a href="javascript:popupWindow(\'' . zen_href_link(FILENAME_POPUP_CVV_HELP) . '\')">' . MODULE_PAYMENT_PAYSCOUT_INC_TEXT_POPUP_CVV_LINK . '</a>',
                                   'tag' => $this->code.'-cc-cvv');
   
    return $selection;
  }
  /**
   * Evaluates the Credit Card Type for acceptance and the validity of the Credit Card Number & Expiration Date
   *
   */
  function pre_confirmation_check() {
    global $messageStack;

    include(DIR_WS_CLASSES . 'cc_validation.php');

    $cc_validation = new cc_validation();
    $result = $cc_validation->validate($_POST['payscout_inc_cc_number'], $_POST['payscout_inc_cc_expires_month'], $_POST['payscout_inc_cc_expires_year'], $_POST['payscout_inc_cc_cvv']);
    $error = '';
    switch ($result) {
      case -1:
      $error = sprintf(TEXT_CCVAL_ERROR_UNKNOWN_CARD, substr($cc_validation->cc_number, 0, 4));
      break;
      case -2:
      case -3:
      case -4:
      $error = TEXT_CCVAL_ERROR_INVALID_DATE;
      break;
      case false:
      $error = TEXT_CCVAL_ERROR_INVALID_NUMBER;
      break;
    }

    if ( ($result == false) || ($result < 1) ) {
      $messageStack->add_session('checkout_payment', $error . '<!-- ['.$this->code.'] -->', 'error');
      zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
    }

    $this->cc_card_type = $cc_validation->cc_type;
    $this->cc_card_number = $cc_validation->cc_number;
    $this->cc_expiry_month = $cc_validation->cc_expiry_month;
    $this->cc_expiry_year = $cc_validation->cc_expiry_year;
  }
  /**
   * Display Credit Card Information on the Checkout Confirmation Page
   *
   * @return array
   */
  function confirmation() {
    $confirmation = array('fields' => array(array('title' => MODULE_PAYMENT_PAYSCOUT_INC_TEXT_CREDIT_CARD_TYPE,
                                                  'field' => $this->cc_card_type),
                                            array('title' => MODULE_PAYMENT_PAYSCOUT_INC_TEXT_CREDIT_CARD_OWNER_FIRST_NAME,
                                                  'field' => $_POST['payscout_inc_cc_owner_first_name']),
										    array('title' => MODULE_PAYMENT_PAYSCOUT_INC_TEXT_CREDIT_CARD_OWNER_LAST_NAME,
                                                  'field' => $_POST['payscout_inc_cc_owner_last_name']),
												  array('title' => MODULE_PAYMENT_PAYSCOUT_INC_TEXT_BILLING_DOB,
                                                  'field' => $_POST['payscout_inc_cc_billing_dob']),
                                            array('title' => MODULE_PAYMENT_PAYSCOUT_INC_TEXT_CREDIT_CARD_NUMBER,
                                                  'field' => substr($this->cc_card_number, 0, 4) . str_repeat('X', (strlen($this->cc_card_number) - 8)) . substr($this->cc_card_number, -4)),
                                            array('title' => MODULE_PAYMENT_PAYSCOUT_INC_TEXT_CREDIT_CARD_EXPIRES,
                                                  'field' => strftime('%B, %Y', mktime(0,0,0,$_POST['payscout_inc_cc_expires_month'], 1, '20' . $_POST['payscout_inc_cc_expires_year']))) ));
    return $confirmation;
  }
  /**
   * Build the data and actions to process when the "Submit" button is pressed on the order-confirmation screen.
   * This sends the data to the payment gateway for processing.
   * (These are hidden fields on the checkout confirmation page)
   *
   * @return string
   */
  function process_button() {
    $process_button_string = zen_draw_hidden_field('cc_owner_first_name', $_POST['payscout_inc_cc_owner_first_name']) .
							 zen_draw_hidden_field('cc_owner_last_name', $_POST['payscout_inc_cc_owner_last_name']) .
							 zen_draw_hidden_field('cc_billing_dob', $_POST['payscout_inc_cc_billing_dob']) .
                             zen_draw_hidden_field('cc_expires', $this->cc_expiry_month . substr($this->cc_expiry_year, -2)) .
                             zen_draw_hidden_field('cc_type', $this->cc_card_type) .
                             zen_draw_hidden_field('cc_number', $this->cc_card_number);
  
    $process_button_string .= zen_draw_hidden_field('cc_cvv', $_POST['payscout_inc_cc_cvv']);
    
    $process_button_string .= zen_draw_hidden_field(zen_session_name(), zen_session_id());

    return $process_button_string;
  }
  function process_button_ajax() {
    $processButton = array('ccFields'=>array('cc_number'=>'payscout_inc_cc_number', 'cc_owner_first_name'=>'payscout_inc_cc_owner_first_name', 'cc_owner_last_name'=>'cc_owner_last_name', 'cc_billing_dob'=>'payscout_inc_cc_billing_dob', 'cc_cvv'=>'payscout_inc_cc_cvv', 'cc_expires'=>array('name'=>'concatExpiresFields', 'args'=>"['payscout_inc_cc_expires_month','payscout_inc_cc_expires_year']"), 'cc_expires_month'=>'payscout_inc_cc_expires_month', 'cc_expires_year'=>'payscout_inc_cc_expires_year', 'cc_type' => $this->cc_card_type), 'extraFields'=>array(zen_session_name()=>zen_session_id()));
        return $processButton;
  }
  /**
   * Store the CC info to the order and process any results that come back from the payment gateway
   */
  function before_process() {
    global $response, $db, $order, $messageStack;
    $order->info['cc_owner_first_name']   = $_POST['cc_owner_first_name'];
	$order->info['cc_owner_last_name']   = $_POST['cc_owner_last_name'];
	$order->info['cc_billing_dob']   = $_POST['cc_billing_dob'];
    $order->info['cc_number']  = str_pad(substr($_POST['cc_number'], -4), strlen($_POST['cc_number']), "X", STR_PAD_LEFT);
    $order->info['cc_expires'] = '';  // $_POST['cc_expires'];
    $order->info['cc_cvv']     = '***';
    $sessID = zen_session_id();

    $this->include_x_type = TRUE;

    // DATA PREPARATION SECTION
    unset($submit_data);  // Cleans out any previous data stored in the variable

        
    // Create a variable that holds the order time
    $order_time = date("F j, Y, g:i a");

    // Calculate the next expected order id (adapted from code written by Eric Stamper - 01/30/2004 Released under GPL)
    $last_order_id = $db->Execute("select orders_id from " . TABLE_ORDERS . " order by orders_id desc limit 1");
    $new_order_id = $last_order_id->fields['orders_id'];
    $new_order_id = ($new_order_id + 1);
	
	$pass_through = $new_order_id;

    // add randomized suffix to order id to produce uniqueness ... since it's unwise to submit the same order-number twice to authorize.net
    $new_order_id = (string)$new_order_id . '-' . zen_create_random_value(6, 'chars');
	
	$month = substr($_POST['cc_expires'],0,2);
	$year = '20' . substr($_POST['cc_expires'],2,3);

    // Populate an array that contains all of the data to be sent to Authorize.net
    $submit_data = array(                         
                         'client_username' => trim(MODULE_PAYMENT_PAYSCOUT_INC_CLIENT_USERNAME),
                         'client_password' => trim(MODULE_PAYMENT_PAYSCOUT_INC_CLIENT_PASSWORD),
						 'client_token' => trim(MODULE_PAYMENT_PAYSCOUT_INC_CLIENT_TOKEN),                                    
                         'initial_amount' => number_format($order->info['total'], 2),
                         'currency' => $order->info['currency'],                         
                         'account_number' => $_POST['cc_number'],
                         'expiration_month' => $month,
						 'expiration_year'  =>$year,
						 'processing_type' => 'DEBIT', 
                         'cvv2' => $_POST['cc_cvv'],
						 'billing_date_of_birth' => date('Ymd', strtotime($_POST['cc_biling_dob'])),                        
                         'pass_through' => $pass_through,
                         'billing_first_name' => $order->billing['firstname'],
                         'billing_last_name' => $order->billing['lastname'],                        
                         'billing_address_line_1' => $order->billing['street_address'],
                         'billing_city' => $order->billing['city'],
                         'billing_state' => $order->billing['state'],
                         'billing_postal_code' => $order->billing['postcode'],
                         'billing_country' => $order->billing['country']['title'],
                         'billing_phone_number' => $order->customer['telephone'],
                         'billing_email_address' => $order->customer['email_address']                         
                         );

    $this->notify('NOTIFY_PAYMENT_AUTHNET_EMULATOR_CHECK', array(), $submit_data);
    

    // force conversion to supported currencies: USD, GBP, CAD, EUR, AUD, NZD
    if ($order->info['currency'] != $this->gateway_currency) {
      global $currencies;
      $submit_data['initial_amount'] = number_format($order->info['total'] * $currencies->get_value($this->gateway_currency), 2);
      $submit_data['currency'] = $this->gateway_currency;      
    }

    $this->submit_extras = array();
    $this->notify('NOTIFY_PAYMENT_AUTHNET_PRESUBMIT_HOOK', array(), $submit_data);

    unset($response);	
		
    $response = $this->_sendRequest($submit_data);
	
			
    $this->notify('NOTIFY_PAYMENT_AUTHNET_POSTSUBMIT_HOOK', array(), $response);
	
    $response_code = $response['result_code'];
    $response_text = $response['status'];
    $raw_message = $response['raw_message'];
	$message = $response['message'];
    $this->transaction_id = $response['transaction_id'];
	$this->gateway_status = $response_text;
    
   	$response_msg_to_customer = '';
	if($response_code != '00')
	{
		$gateway_error = $message;
		if($raw_message != "") $gateway_error = $raw_message;
		
		$response['ErrorDetails'] = $gateway_error;	
		$response_msg_to_customer = $gateway_error;
	}
	
	
    if($response_code == '00') {
      $this->order_status = 1;
      
	  $this->gateway_status = $response_text;
	  
	  $gateway_error = $message;
		if($raw_message != "") $gateway_error = $raw_message;	
	  
        $response['ErrorDetails'] = 'Staus: '. $response_text . ' Transaction Id: ' . $response['transaction_id'] . ' Message: ' . $gateway_error;
		$response_msg_to_customer = $gateway_error;
		$this->transaction_id .= ' ***Message: ' .$gateway_error;
    }

    $this->_debugActions($response, $order_time, $sessID);

    if (isset($response['error']) && $response['error']!= '') {
      $messageStack->add_session('checkout_payment', MODULE_PAYMENT_PAYSCOUT_INC_TEXT_COMM_ERROR . ' (' . $response['error'] . ')', 'caution');
      zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
    }

    
    // If the response code is not 1 (approved) then redirect back to the payment page with the appropriate error message
	
		
    if ($response_code != '00') {
      $messageStack->add_session('checkout_payment', $response_msg_to_customer . ' - ' . MODULE_PAYMENT_PAYSCOUT_INC_TEXT_DECLINED_MESSAGE, 'error');
      zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
    }
    if($response_code == '00'){
        $this->order_status = (int)MODULE_PAYMENT_PAYSCOUT_INC_REVIEW_ORDER_STATUS_ID;
    }
    if ($response_code != '') {
      $_SESSION['payment_method_messages'] = $response_msg_to_customer;
    }
    $order->info['cc_type'] = $response_text;
  }
  /**
   * Post-process activities. Updates the order-status history data with the auth code from the transaction.
   *
   * @return boolean
   */
  function after_process() {
    global $insert_id, $db, $order, $currencies;
    $sql = "insert into " . TABLE_ORDERS_STATUS_HISTORY . " (comments, orders_id, orders_status_id, customer_notified, date_added) values (:orderComments, :orderID, :orderStatus, -1, now() )";
    $currency_comment = '';
    if ($order->info['currency'] != $this->gateway_currency) {
      $currency_comment = ' (' . number_format($order->info['total'] * $currencies->get_value($this->gateway_currency), 2) . ' ' . $this->gateway_currency . ')';
    }
    $sql = $db->bindVars($sql, ':orderComments', 'Credit Card payment.  Status: ' . $this->gateway_status . ' TransID: ' . $this->transaction_id . ' ' . $currency_comment, 'string');
    $sql = $db->bindVars($sql, ':orderID', $insert_id, 'integer');
    $sql = $db->bindVars($sql, ':orderStatus', $this->order_status, 'integer');
    $db->Execute($sql);
    return false;
  }
  /**
    * Build admin-page components
    *
    * @param int $zf_order_id
    * @return string
    */
  function admin_notification($zf_order_id) {
    global $db;
    $output = '';
    $aimdata = new stdClass;
    $aimdata->fields = array();
    require(DIR_FS_CATALOG . DIR_WS_MODULES . 'payment/payscout/payscout_admin_notification.php');
    return $output;
  }
  /**
   * Used to display error message details
   *
   * @return array
   */
  function get_error() {
    $error = array('title' => MODULE_PAYMENT_PAYSCOUT_INC_TEXT_ERROR,
                   'error' => stripslashes(urldecode($_GET['error'])));
    return $error;
  }
  /**
   * Check to see whether module is installed
   *
   * @return boolean
   */
  function check() {
    global $db;
    if (!isset($this->_check)) {
      $check_query = $db->Execute("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_PAYSCOUT_INC_STATUS'");
      $this->_check = $check_query->RecordCount();
    }
    return $this->_check;
  }
  /**
   * Install the payment module and its configuration settings
   *
   */
  function install() {
    global $db, $messageStack;
    if (defined('MODULE_PAYMENT_PAYSCOUT_INC_STATUS')) {
      $messageStack->add_session('Payscout Inc module already installed.', 'error');
      zen_redirect(zen_href_link(FILENAME_MODULES, 'set=payment&module=payscout_inc', 'NONSSL'));
      return 'failed';
    }
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Payscout Module', 'MODULE_PAYMENT_PAYSCOUT_INC_STATUS', 'True', 'Do you want to accept Payscout Inc payments Method?', '6', '0', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
   
	$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Username', 'MODULE_PAYMENT_PAYSCOUT_INC_CLIENT_USERNAME', '', 'The API Username used for the Payscout service', '6', '0', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added, use_function) values ('API Password', 'MODULE_PAYMENT_PAYSCOUT_INC_CLIENT_PASSWORD', '', 'Transaction Key used for encrypting TP data<br />(See your Payscout Account->Security Settings->API Username, Password and Token Id for details.)', '6', '0', now(), 'zen_cfg_password_display')");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added, use_function) values ('API Token', 'MODULE_PAYMENT_PAYSCOUT_INC_CLIENT_TOKEN', '', 'Encryption token used for validating received transaction data.', '6', '0', now(), 'zen_cfg_password_display')");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Transaction Mode', 'MODULE_PAYMENT_PAYSCOUT_INC_TESTMODE', 'Test', 'Transaction mode used for processing orders.<br><strong>Live</strong>=Live processing with real account credentials<br><strong>Test</strong>=Simulations with real account credentials)', '6', '0', 'zen_cfg_select_option(array(\'Test\', \'Live\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Authorization Type', 'MODULE_PAYMENT_PAYSCOUT_INC_AUTHORIZATION_TYPE', 'Debit', 'Do you want submitted credit card transactions to be debit only?', '6', '0', 'zen_cfg_select_option(array(\'Debit\', \'Credit\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Database Storage', 'MODULE_PAYMENT_PAYSCOUT_INC_STORE_DATA', 'True', 'Do you want to save the gateway communications data to the database?', '6', '0', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
  
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sort order of display.', 'MODULE_PAYMENT_PAYSCOUT_INC_SORT_ORDER', '0', 'Sort order of display of payment modules to the customer. Lowest is displayed first.', '6', '0', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Payment Zone', 'MODULE_PAYMENT_PAYSCOUT_INC_ZONE', '0', 'If a zone is selected, only enable this payment method for that zone.', '6', '2', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Completed Order Status', 'MODULE_PAYMENT_PAYSCOUT_INC_ORDER_STATUS_ID', '2', 'Set the status of orders made with this payment module to this value', '6', '0', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Refunded Order Status', 'MODULE_PAYMENT_PAYSCOUT_INC_REFUNDED_ORDER_STATUS_ID', '1', 'Set the status of refunded orders to this value', '6', '0', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Needed For Review Order Status', 'MODULE_PAYMENT_PAYSCOUT_INC_REVIEW_ORDER_STATUS_ID', '1', 'Set the status of orders made with this payment module, BUT are needing to be reviewed for processing', '6', '0', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
   
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Currency Supported', 'MODULE_PAYMENT_PAYSCOUT_INC_CURRENCY', 'USD', 'Which currency is your Gateway Account configured to accept?<br>(Purchases in any other currency will be pre-converted to this currency before submission using the exchange rates in your store admin.)', '6', '0', 'zen_cfg_select_option(array(\'USD\'), ', now())");
	
	$db->Execute("CREATE TABLE IF NOT EXISTS `".DB_PREFIX."payscout` (id int(11) NOT NULL auto_increment PRIMARY KEY, customer_id int (11) NOT NULL DEFAULT '0', order_id int (11) NOT NULL DEFAULT '0', response_code int (2) NOT NULL DEFAULT '0', response_text varchar(255) NOT NULL, authorization_type varchar (255) NOT NULL, transaction_id bigint (20) NULL, sent LONGTEXT NOT NULL, received LONGTEXT NOT NULL, time varchar (255) NOT NULL, session_id varchar (255) NOT NULL) ENGINE=MYISAM");
  }
  /**
   * Remove the module and all its settings
   *
   */
  function remove() {
    global $db;
    $db->Execute("delete from " . TABLE_CONFIGURATION . " where configuration_key like 'MODULE\_PAYMENT\_PAYSCOUT\_INC\_%'");
	$db->Execute("DROP TABLE `" . DB_PREFIX . "payscout`");
  }
  /**
   * Internal list of configuration keys used for configuration of the module
   *
   * @return array
   */
  function keys() {
    if (defined('MODULE_PAYMENT_PAYSCOUT_INC_STATUS')) {
      global $db;
      if (!defined('MODULE_PAYMENT_PAYSCOUT_INC_CURRENCY')) {
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Currency Supported', 'MODULE_PAYMENT_PAYSCOUT_INC_CURRENCY', 'USD', 'Which currency is your Payscout Gateway Account configured to accept?<br>(Purchases in any other currency will be pre-converted to this currency before submission using the exchange rates in your store admin.)', '6', '0', 'zen_cfg_select_option(array(\'USD\'), ', now())");
      }
    }
    return array('MODULE_PAYMENT_PAYSCOUT_INC_STATUS', 'MODULE_PAYMENT_PAYSCOUT_INC_CLIENT_USERNAME', 'MODULE_PAYMENT_PAYSCOUT_INC_CLIENT_PASSWORD', 'MODULE_PAYMENT_PAYSCOUT_INC_CLIENT_TOKEN', 'MODULE_PAYMENT_PAYSCOUT_INC_TESTMODE', 'MODULE_PAYMENT_PAYSCOUT_INC_CURRENCY', 'MODULE_PAYMENT_PAYSCOUT_INC_AUTHORIZATION_TYPE', 'MODULE_PAYMENT_PAYSCOUT_INC_STORE_DATA','MODULE_PAYMENT_PAYSCOUT_INC_SORT_ORDER', 'MODULE_PAYMENT_PAYSCOUT_INC_ZONE', 'MODULE_PAYMENT_PAYSCOUT_INC_ORDER_STATUS_ID', 'MODULE_PAYMENT_PAYSCOUT_INC_REFUNDED_ORDER_STATUS_ID');
  }
  /**
   * Send communication request
   */
  function _sendRequest($submit_data) {
    global $request_type;

    // Populate an array that contains all of the data to be sent to Authorize.net
    $submit_data = array_merge(array(                         
                         'client_username' => trim(MODULE_PAYMENT_PAYSCOUT_INC_CLIENT_USERNAME),
						 'client_password' => trim(MODULE_PAYMENT_PAYSCOUT_INC_CLIENT_PASSWORD),
						 'client_token' => trim(MODULE_PAYMENT_PAYSCOUT_INC_CLIENT_TOKEN),                         
                         ), $submit_data);

   
    // set URL
    $this->mode = 'INC';
    $this->notify('NOTIFY_PAYMENT_AUTHNET_MODE_SELECTION', $this->mode, $submit_data);

    switch ($this->mode) {      
      case 'INC':
        $url = 'https://gateway.payscout.com/api/process'; 
		if(MODULE_PAYMENT_PAYSCOUT_INC_TESTMODE == 'Test')
		{
			$url = 'https://mystaging.paymentecommerce.com/api/process';	
		}
        break;
      default:
      
        
      break;
    }

    // concatenate the submission data into $data variable after sanitizing to protect delimiters
    $data = '';
	
	
	
    foreach($submit_data as $key => $post_data) {
      $data .= $key . '=' . urlencode($post_data) . '&';
    }
    // Remove the last "&" from the string
    $data = substr($data, 0, -1);


    // prepare a copy of submitted data for error-reporting purposes
    $this->reportable_submit_data = $submit_data;
    $this->reportable_submit_data['x_client_id'] = '*******';
    $this->reportable_submit_data['x_tran_key'] = '*******';
    if (isset($this->reportable_submit_data['x_card_num'])) $this->reportable_submit_data['x_card_num'] = str_repeat('X', strlen($this->reportable_submit_data['x_card_num'] - 4)) . substr($this->reportable_submit_data['x_card_num'], -4);
    if (isset($this->reportable_submit_data['x_exp_date'])) $this->reportable_submit_data['x_exp_date'] = '****';
    if (isset($this->reportable_submit_data['x_card_code'])) $this->reportable_submit_data['x_card_code'] = '****';
    $this->reportable_submit_data['url'] = $url;


    // Post order info data to payscout via CURL - Requires that PHP has CURL support installed

    // Send CURL communication	
	
		
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_PORT, 443);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
	curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 40);
	curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);	
	$response = curl_exec($ch);   
    curl_close ($ch);
	
	if (curl_error($curl)) {
			$response_object['error'] = 'CURL ERROR: ' . curl_errno($ch) . '::' . curl_error($ch);
		} elseif ($response) {
			$response_object = (array)json_decode($response);
		}
			
    return $response_object;
  }
  
  /**
   * Used to do any debug logging / tracking / storage as required.
   */
  function _debugActions($response, $order_time= '', $sessID = '') {
    global $db;
    if ($order_time == '') $order_time = date("F j, Y, g:i a");
    
   	$gateway_error = $response['message'];
		if($response['raw_message'] != "") $gateway_error = $response['raw_message'];
   
      $errorMessage = date('M-d-Y h:i:s') .
                      "\n=================================\n\n" .                      
                      'Gateway Response Code: ' . $response['result_code'] . ".\nResponse Text: " . $response['status'] . "\n\n" .
                      'Message: ' . $gateway_error . "\n\n" ;
      
    // DATABASE SECTION
    // Insert the send and receive response data into the database.
    // This can be used for testing or for implementation in other applications
    // This can be turned on and off if the Admin Section
    if (MODULE_PAYMENT_PAYSCOUT_INC_STORE_DATA == 'True'){     
      
	  $db_response_text = 'Status: ' . $response['status'];
	  $db_response_text .= ' Gateway Response: ' . $response['result_code'];
	  
	  if($response['result_code'] == '00')
	  {
		$db_response_text .= 'Transaction Id' . $response['transaction_id'];		  
	  }
	  
	  if(isset($response['result_code']))
	  {
		$gateway_message = $response['message'];
		if($response['raw_message']!="")
		{
			$gateway_message = $response['raw_message'];
		}
		
		$db_response_text .= 'Message' . $gateway_message;		  
	  }     

      // Insert the data into the database
      $sql = "insert into `" . DB_PREFIX . "payscout`  (id, customer_id, order_id, response_code, response_text, authorization_type, transaction_id, sent, received, time, session_id) values (NULL, :custID, :orderID, :respCode, :respText, :authType, :transID, :sentData, :recvData, :orderTime, :sessID )";
      $sql = $db->bindVars($sql, ':custID', $_SESSION['customer_id'], 'integer');
      $sql = $db->bindVars($sql, ':orderID', preg_replace('/[^0-9]/', '', $response['pass_through']), 'integer');
      $sql = $db->bindVars($sql, ':respCode', $response['result_code'], 'integer');
      $sql = $db->bindVars($sql, ':respText', $db_response_text, 'string');
      $sql = $db->bindVars($sql, ':authType', $response['status'], 'string');
      if (trim($this->transaction_id) != '') {
        $sql = $db->bindVars($sql, ':transID', substr($this->transaction_id, 0, strpos($this->transaction_id, ' ')), 'string');
      } else {
        $sql = $db->bindVars($sql, ':transID', 'NULL', '');
      }
      $sql = $db->bindVars($sql, ':sentData', print_r($this->reportable_submit_data, true), 'string');
      $sql = $db->bindVars($sql, ':recvData', print_r($response, true), 'string');
      $sql = $db->bindVars($sql, ':orderTime', $order_time, 'string');
      $sql = $db->bindVars($sql, ':sessID', $sessID, 'string');
      $db->Execute($sql);
    }
  }
  /**
   * Check and fix table structure if appropriate
   */
  function tableCheckup() {
    global $db, $sniffer;
    $fieldOkay1 = (method_exists($sniffer, 'field_type')) ? $sniffer->field_type(TABLE_PAYSCOUT, 'transaction_id', 'bigint(20)', true) : -1;
    if ($fieldOkay1 !== true) {
      $db->Execute("ALTER TABLE `" . DB_PREFIX . "payscout` CHANGE transaction_id transaction_id bigint(20) default NULL");
    }
  }
 /**
   * Used to submit a refund for a given transaction.
   */
  function _doRefund($oID, $amount = 0) {
    global $db, $messageStack;
    $new_order_status = (int)MODULE_PAYMENT_PAYSCOUT_INC_REFUNDED_ORDER_STATUS_ID;
    if ($new_order_status == 0) $new_order_status = 1;
    $proceedToRefund = true;
    $refundNote = strip_tags(zen_db_input($_POST['refnote']));
    if (isset($_POST['refconfirm']) && $_POST['refconfirm'] != 'on') {
      $messageStack->add_session(MODULE_PAYMENT_PAYSCOUT_INC_TEXT_REFUND_CONFIRM_ERROR, 'error');
      $proceedToRefund = false;
    }
    if (isset($_POST['buttonrefund']) && $_POST['buttonrefund'] == MODULE_PAYMENT_PAYSCOUT_INC_ENTRY_REFUND_BUTTON_TEXT) {
      $refundAmt = (float)$_POST['refamt'];
      $new_order_status = (int)MODULE_PAYMENT_PAYSCOUT_INC_REFUNDED_ORDER_STATUS_ID;
      if ($refundAmt == 0) {
        $messageStack->add_session(MODULE_PAYMENT_PAYSCOUT_INC_TEXT_INVALID_REFUND_AMOUNT, 'error');
        $proceedToRefund = false;
      }
    }
    if (isset($_POST['cc_number']) && trim($_POST['cc_number']) == '') {
      $messageStack->add_session(MODULE_PAYMENT_PAYSCOUT_INC_TEXT_CC_NUM_REQUIRED_ERROR, 'error');
    }
    if (isset($_POST['trans_id']) && trim($_POST['trans_id']) == '') {
      $messageStack->add_session(MODULE_PAYMENT_PAYSCOUT_INC_TEXT_TRANS_ID_REQUIRED_ERROR, 'error');
      $proceedToRefund = false;
    }

    /**
     * Submit refund request to gateway
     */
    if ($proceedToRefund) {
      $submit_data = array('x_type' => 'CREDIT',
                           'x_card_num' => trim($_POST['cc_number']),
                           'x_amount' => number_format($refundAmt, 2),
                           'x_trans_id' => trim($_POST['trans_id'])
                           );
      unset($response);
      $response = $this->_sendRequest($submit_data);
      $response_code = $response[0];
      $response_text = $response[3];
      $response_alert = $response_text . ($this->commError == '' ? '' : ' Communications Error - Please notify webmaster.');
      $this->reportable_submit_data['Note'] = $refundNote;
      $this->_debugActions($response);

      if ($response_code != '1') {
        $messageStack->add_session($response_alert, 'error');
      } else {
        // Success, so save the results
        $sql_data_array = array('orders_id' => $oID,
                                'orders_status_id' => (int)$new_order_status,
                                'date_added' => 'now()',
                                'comments' => 'REFUND INITIATED. Trans ID:' . $response[6] . ' ' . $response[4]. "\n" . ' Gross Refund Amt: ' . $response[9] . "\n" . $refundNote,
                                'customer_notified' => 0
                             );
        zen_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
        $db->Execute("update " . TABLE_ORDERS  . "
                      set orders_status = '" . (int)$new_order_status . "'
                      where orders_id = '" . (int)$oID . "'");
        $messageStack->add_session(sprintf(MODULE_PAYMENT_PAYSCOUT_INC_TEXT_REFUND_INITIATED, $response[9], $response[6]), 'success');
        return true;
      }
    }
    return false;
  }

  /**
   * Used to capture part or all of a given previously-authorized transaction.
   */
  function _doCapt($oID, $amt = 0, $currency = 'USD') {
    global $db, $messageStack;

    //@TODO: Read current order status and determine best status to set this to
    $new_order_status = (int)MODULE_PAYMENT_PAYSCOUT_INC_ORDER_STATUS_ID;
    if ($new_order_status == 0) $new_order_status = 1;

    $proceedToCapture = true;
    $captureNote = strip_tags(zen_db_input($_POST['captnote']));
    if (isset($_POST['captconfirm']) && $_POST['captconfirm'] == 'on') {
    } else {
      $messageStack->add_session(MODULE_PAYMENT_PAYSCOUT_INC_TEXT_CAPTURE_CONFIRM_ERROR, 'error');
      $proceedToCapture = false;
    }
    if (isset($_POST['btndocapture']) && $_POST['btndocapture'] == MODULE_PAYMENT_PAYSCOUT_INC_ENTRY_CAPTURE_BUTTON_TEXT) {
      $captureAmt = (float)$_POST['captamt'];
/*
      if ($captureAmt == 0) {
        $messageStack->add_session(MODULE_PAYMENT_PAYSCOUT_INC_TEXT_INVALID_CAPTURE_AMOUNT, 'error');
        $proceedToCapture = false;
      }
*/
    }
    if (isset($_POST['captauthid']) && trim($_POST['captauthid']) != '') {
      // okay to proceed
    } else {
      $messageStack->add_session(MODULE_PAYMENT_PAYSCOUT_INC_TEXT_TRANS_ID_REQUIRED_ERROR, 'error');
      $proceedToCapture = false;
    }
    /**
     * Submit capture request to Authorize.net
     */
    if ($proceedToCapture) {
      // Populate an array that contains all of the data to be sent to Authorize.net
      unset($submit_data);
      $submit_data = array(
                           'x_type' => 'PRIOR_AUTH_CAPTURE',
                           'x_amount' => number_format($captureAmt, 2),
                           'x_trans_id' => strip_tags(trim($_POST['captauthid'])),
//                         'x_invoice_num' => $new_order_id,
//                         'x_po_num' => $order->info['po_number'],
//                         'x_freight' => $order->info['shipping_cost'],
//                         'x_tax_exempt' => 'FALSE', /* 'TRUE' or 'FALSE' */
//                         'x_tax' => $order->info['tax'],
                           );

      $response = $this->_sendRequest($submit_data);
      $response_code = $response[0];
      $response_text = $response[3];
      $response_alert = $response_text . ($this->commError == '' ? '' : ' Communications Error - Please notify webmaster.');
      $this->reportable_submit_data['Note'] = $captureNote;
      $this->_debugActions($response);

      if ($response_code != '1' || ($response[0]==1 && $response[2] == 311) ) {
        $messageStack->add_session($response_alert, 'error');
      } else {
        // Success, so save the results
        $sql_data_array = array('orders_id' => (int)$oID,
                                'orders_status_id' => (int)$new_order_status,
                                'date_added' => 'now()',
                                'comments' => 'FUNDS COLLECTED. Auth Code: ' . $response[4] . "\n" . 'Trans ID: ' . $response[6] . "\n" . ' Amount: ' . ($response[9] == 0.00 ? 'Full Amount' : $response[9]) . "\n" . 'Time: ' . date('Y-m-D h:i:s') . "\n" . $captureNote,
                                'customer_notified' => 0
                             );
        zen_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
        $db->Execute("update " . TABLE_ORDERS  . "
                      set orders_status = '" . (int)$new_order_status . "'
                      where orders_id = '" . (int)$oID . "'");
        $messageStack->add_session(sprintf(MODULE_PAYMENT_PAYSCOUT_INC_TEXT_CAPT_INITIATED, ($response[9] == 0.00 ? 'Full Amount' : $response[9]), $response[6], $response[4]), 'success');
        return true;
      }
    }
    return false;
  }
  /**
   * Used to void a given previously-authorized transaction.
   */
  function _doVoid($oID, $note = '') {
    global $db, $messageStack;

    $new_order_status = (int)MODULE_PAYMENT_PAYSCOUT_INC_REFUNDED_ORDER_STATUS_ID;
    if ($new_order_status == 0) $new_order_status = 1;
    $voidNote = strip_tags(zen_db_input($_POST['voidnote'] . $note));
    $voidAuthID = trim(strip_tags(zen_db_input($_POST['voidauthid'])));
    $proceedToVoid = true;
    if (isset($_POST['ordervoid']) && $_POST['ordervoid'] == MODULE_PAYMENT_PAYSCOUT_INC_ENTRY_VOID_BUTTON_TEXT) {
      if (isset($_POST['voidconfirm']) && $_POST['voidconfirm'] != 'on') {
        $messageStack->add_session(MODULE_PAYMENT_PAYSCOUT_INC_TEXT_VOID_CONFIRM_ERROR, 'error');
        $proceedToVoid = false;
      }
    }
    if ($voidAuthID == '') {
      $messageStack->add_session(MODULE_PAYMENT_PAYSCOUT_INC_TEXT_TRANS_ID_REQUIRED_ERROR, 'error');
      $proceedToVoid = false;
    }
    // Populate an array that contains all of the data to be sent to gateway
    $submit_data = array('x_type' => 'VOID',
                         'x_trans_id' => trim($voidAuthID) );
    /**
     * Submit void request to Gateway
     */
    if ($proceedToVoid) {
      $response = $this->_sendRequest($submit_data);
      $response_code = $response[0];
      $response_text = $response[3];
      $response_alert = $response_text . ($this->commError == '' ? '' : ' Communications Error - Please notify webmaster.');
      $this->reportable_submit_data['Note'] = $voidNote;
      $this->_debugActions($response);

      if ($response_code != '1' || ($response[0]==1 && $response[2] == 310) ) {
        $messageStack->add_session($response_alert, 'error');
      } else {
        // Success, so save the results
        $sql_data_array = array('orders_id' => (int)$oID,
                                'orders_status_id' => (int)$new_order_status,
                                'date_added' => 'now()',
                                'comments' => 'VOIDED. Trans ID: ' . $response[6] . ' ' . $response[4] . "\n" . $voidNote,
                                'customer_notified' => 0
                             );
        zen_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
        $db->Execute("update " . TABLE_ORDERS  . "
                      set orders_status = '" . (int)$new_order_status . "'
                      where orders_id = '" . (int)$oID . "'");
        $messageStack->add_session(sprintf(MODULE_PAYMENT_PAYSCOUT_INC_TEXT_VOID_INITIATED, $response[6], $response[4]), 'success');
        return true;
      }
    }
    return false;
  }

}
