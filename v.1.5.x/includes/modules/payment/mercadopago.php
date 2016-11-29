<?php
include_once "mercadopago/sdk/mercadopago.php";

class mercadopago {

	var $code;
	var $title;
	var $description;
	var $enabled;

	function mercadopago()
	{
		global $order;
		$this->code = 'mercadopago';
		$this->title = MODULE_ADMIN_MERCADOPAGO_TEXT_TITLE;
		$this->description = MODULE_ADMIN_MERCADOPAGO_TEXT_DESCRIPTION;
		$this->sort_order = MODULE_PAYMENT_MERCADOPAGO_SORT_ORDER;

		$this->enabled = ((MODULE_PAYMENT_MERCADOPAGO_STATUS == 'True') ? true : false);

		if ((int) MODULE_PAYMENT_MERCADOPAGO_STATUS_NEW_ORDER > 0) {
			$this->order_status = MODULE_PAYMENT_MERCADOPAGO_STATUS_NEW_ORDER;
		}

		if (is_object($order)){
			$this->update_status();
		}

		if(IS_ADMIN_FLAG){
			$this->_checkCredentials();
			$this->_setTextPaymentMethodsExcludeMercadoPago();
			$this->_updateApiAccountSettings();
			$this->_updateApiAnalytics();
		}

	}

	function update_status()
	{
		global $order, $db;

		if (($this->enabled == true) && ((int) MODULE_PAYMENT_MERCADOPAGO_ZONE > 0)) {

			$check_flag = false;
			$check_query = $db->Execute("SELECT zone_id
                                        FROM " . TABLE_ZONES_TO_GEO_ZONES . " WHERE geo_zone_id = '" . MODULE_PAYMENT_MERCADOPAGO_ZONE . "' AND zone_country_id = '" . $order->billing['country']['id'] . "' order by zone_id");

			while (!$check_query->EOF) {
				if ($check_query->fields['zone_id'] < 1) {
					$check_flag = true;
					break;
				} else
					if ($check_query->fields['zone_id'] == $order->billing['zone_id']) {
						$check_flag = true;
						break;
					}

				$check_query->MoveNext();
			}

			if ($check_flag == false) {
				$this->enabled = false;
			}
		}
	}

	/**
	 * JS validation which does error-checking of data-entry if this module is selected for use
	 * (Number, Owner, and CVV Lengths)
	 * @return string
	 */
	function javascript_validation()
	{
		return false;
	}

	/**
	 * Proccess any data when user will start checkout process
	 * @return boolean
	 */
	function checkout_initialization_method()
	{
		return false;
	}

	/**
	 * Displays payment method name along with Credit Card Information Submission Fields (if any) on the Checkout Payment Page
	 * @return array
	 */
	 function selection()
	 {
		 $data = $this->_getSponsorAndSite();
		 $banner = $this->_getBannerBySiteId($data['site_id']);

		 $selection['id'] = $this->code;
		 $selection['module'] = $this->title;
		 $selection['fields'][] =  array(
			 'field' => '<img src="' . $banner . '"><style>label[for="mercadopago-selection-title"]{width:0px;}</style>',
			 'tag' => "mercadopago-selection-title"
		 );

		return $selection;
	 }

	/**
	 * Normally evaluates the Credit Card Type for acceptance and the validity of the Credit Card Number & Expiration Date
	 * Since paypal module is not collecting info, it simply skips this step.
	 * @return boolean
	 */
	function pre_confirmation_check()
	{
		if (empty($_SESSION['cart']->cartID)) {
			$_SESSION['cartID'] = $_SESSION['cart']->cartID = $_SESSION['cart']->generate_cart_id();
		}
	}

	function confirmation()
	{
		return false;
	}

	/**
	 * Build the data and actions to process when the "Submit" button is pressed on the order-confirmation screen.
	 * This sends the data to the payment gateway for processing.
	 * (These are hidden fields on the checkout confirmation page)
	 * @return string
	 */
	function process_button()
	{
		return false;
	}

	function before_process()
	{
		return false;
	}

	/**
	 * Get errors
	 * @return boolean
	 */
	function get_error()
	{
		return FALSE;
	}

	/**
	 * Post-processing activities
	 * When the order returns from the processor, if PDT was successful, this stores the results in order-status-history and logs data for subsequent reference
	 * @return boolean
	 */
	function after_process()
	{

		//env global
		global $insert_id, $order;
		//init mercado pago
		$mercadopago = new MP(MODULE_PAYMENT_MERCADOPAGO_CLIENT_ID, MODULE_PAYMENT_MERCADOPAGO_CLIENT_SECRET);
		//init preference
		$pref = array();
		//get info user
		$sponsor_and_site = $this->_getSponsorAndSite();
		if($sponsor_and_site['sponsor_id'] != ""){
			$pref['sponsor_id'] = $sponsor_and_site['sponsor_id'];
		}

		//site_id
		$site_id = $sponsor_and_site['site_id'];
		$items = array(
			array(
				"id" => $insert_id, // updated
				"title" => $order->products[0]['name'],
				"description" => $order->products[0]['name'],
				"quantity" => 1,
				"unit_price" => round($order->info['total'], 2),
				"category_id" => MODULE_PAYMENT_MERCADOPAGO_CATEGORIES
				// "picture_url" => $data['image']
			)
		);
		$payer = array(
			"name" => $order->customer['firstname'],
			"surname" => $order->customer['lastname'],
			"email" => $order->customer['email_address'],
			"phone" => array(
				"area_code" => " ",
				"number" => $order->customer['telephone']
			),
			"address" => array(
				"zip_code" => $order->customer['postcode'],
				"street_name" => $order->customer['street_address'],
				"street_number" => " "
			)
			//  ,
			//  "date_created" => $this->getCustomerDateCreated($order->customer['email_address'])
		);
		$shipments = array(
			"receiver_address" => array(
				"zip_code" => $order->delivery['postcode'],
				"street_name" => $order->delivery['street_address'],
				"street_number" => " ",
				"floor" => " ",
				"apartment" => " "
			)
		);
		$back_urls = array(
			"pending" => MODULE_PAYMENT_MERCADOPAGO_REDIRECT_URL,
			"success" => MODULE_PAYMENT_MERCADOPAGO_REDIRECT_URL
		);
		//exclude payment methods
		if (MODULE_PAYMENT_MERCADOPAGO_PAYMENT_METHODS != ''){
			$pref['payment_methods']['excluded_payment_methods'] = array();
			$methods_excludes = preg_split("/[\s,]+/", MODULE_PAYMENT_MERCADOPAGO_PAYMENT_METHODS);
			foreach ($methods_excludes as $exclude) {
				$pref['payment_methods']['excluded_payment_methods'][] = array('id' => $exclude);
			}
		}
		$pref['payment_methods']['installments'] = (int) MODULE_PAYMENT_MERCADOPAGO_INSTALLMENTS;
		$pref['external_reference'] = $insert_id;
		$pref['items'] = $items;
		$pref['payer'] = $payer;
		$pref['shipments'] = $shipments;
		$pref['back_urls'] = $back_urls;
		if (MODULE_PAYMENT_MERCADOPAGO_AUTORETURN == 'True'):
			$pref['auto_return'] = "approved";
		endif;
		$pref['notification_url'] = $this->_getURLIPN();

		$preference = $mercadopago->create_preference($pref);

		// default button production
		$button = $preference['response']['init_point'];
		if(MODULE_PAYMENT_MERCADOPAGO_SANDBOX == 'sandbox'){
			$button = $preference['response']['sandbox_init_point']  ;
		}

		$_SESSION['cart']->reset(true);

		$url = HTTP_SERVER . DIR_WS_CATALOG . 'mercadopago_checkout.php?init_point=' . $button;
		zen_redirect($url);

	}


	/**
	 * Update Order Status
	 * @global type $db
	 * @param type $order_id
	 * @param type $order_status_id
	 */
	public function updateOrderStatus($order_id, $order_status_id)
	{
		global $db;

		$sql_data_array = array(array('fieldName' => 'orders_id', 'value' => $order_id, 'type' => 'integer'),
			array('fieldName' => 'orders_status_id', 'value' => $order_status_id, 'type' => 'integer'),
			array('fieldName' => 'date_added', 'value' => 'now()', 'type' => 'noquotestring'),
			array('fieldName' => 'customer_notified', 'value' => 1, 'type' => 'integer'),
			array('fieldName' => 'comments', 'value' => 'STATUS ATUALIZADO', 'type' => 'string'));
		$db->perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
	}

	/**
	 * Used to display error message details
	 * @return boolean
	 */
	function output_error()
	{
		return false;
	}


	/**
	 * Check to see whether module is installed
	 * @return boolean
	 */
	function check()
	{
		global $db;

		if (!isset($this->_check)) {
			$check_query = $db->Execute("SELECT configuration_value
                                  FROM " . TABLE_CONFIGURATION . "
                                  WHERE configuration_key = 'MODULE_PAYMENT_MERCADOPAGO_STATUS'");
			$this->_check = $check_query->RecordCount();
		}
		return $this->_check;
	}

	function install()
	{
    global $db, $messageStack;

		//check module status
    if (defined('MODULE_PAYMENT_MERCADOPAGO_STATUS')) {
      $messageStack->add_session('Mercado Pago module already installed.', 'error');
      zen_redirect(zen_href_link(FILENAME_MODULES, 'set=payment&module=mercadopago', 'NONSSL'));
      return 'failed';
    }

		//get fields configuration module
		$configuration_fields	= $this->_getFieldsConfiguration();

		//make a query and execute
    foreach ($configuration_fields as $fields) {
      $keys = implode(", ", array_keys($fields));
      $values = implode("', '", array_values($fields));
      $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " ({$keys}) VALUES ('{$values}')");
    }

	}

	function remove()
	{
		global $db;
		$db->Execute("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
	}

	function keys()
	{
		$keys = array();
		$configuration_fields	= $this->_getFieldsConfiguration();
		foreach ($configuration_fields as $fields) {
			$keys[] = $fields['configuration_key'];
		}
		return $keys;
	}

	function _getFieldsConfiguration()
	{

		$configuration_fields = array(
			array(
				"configuration_title" => "Enable Mercado Pago module",
				"configuration_key" => "MODULE_PAYMENT_MERCADOPAGO_STATUS",
				"configuration_value" => "True",
				"configuration_description" => "Do you want to accept payments through Mercado Pago?",
				"configuration_group_id" => "6",
				"sort_order" => "1",
				"set_function" => "zen_cfg_select_option(array(\'True\', \'False\'), ",
				"date_added" => "now()"
			),
			array(
				"configuration_title" => "Client_id",
				"configuration_key" => "MODULE_PAYMENT_MERCADOPAGO_CLIENT_ID",
				"configuration_value" => "",
				"configuration_description" => "Get your credentials: <br/>" . $this->_listCredentials(),
				"configuration_group_id" => "6",
				"sort_order" => "2",
				"date_added" => "now()"
			),
			array(
				"configuration_title" => "Client_secret",
				"configuration_key" => "MODULE_PAYMENT_MERCADOPAGO_CLIENT_SECRET",
				"configuration_value" => "",
				"configuration_description" => "Set the client_secret",
				"configuration_group_id" => "6",
				"sort_order" => "3",
				"date_added" => "now()"
			),

			// status
			array(
				"configuration_title" => "Set the default status for a new order",
				"configuration_key" => "MODULE_PAYMENT_MERCADOPAGO_STATUS_NEW_ORDER",
				"configuration_value" => "1",
				"configuration_description" => "Set the status to automatically update the order.",
				"configuration_group_id" => "6",
				"sort_order" => "6",
				"set_function" => "zen_cfg_pull_down_order_statuses(",
				"use_function" => "zen_get_order_status_name",
				"date_added" => "now()"
			),
			array(
				"configuration_title" => "Set the status for approved payments",
				"configuration_key" => "MODULE_PAYMENT_MERCADOPAGO_STATUS_APPROVED",
				"configuration_value" => "2",
				"configuration_description" => "Set the status to automatically update the order.",
				"configuration_group_id" => "6",
				"sort_order" => "6",
				"set_function" => "zen_cfg_pull_down_order_statuses(",
				"use_function" => "zen_get_order_status_name",
				"date_added" => "now()"
			),
			array(
				"configuration_title" => "Set the status for pending payments",
				"configuration_key" => "MODULE_PAYMENT_MERCADOPAGO_STATUS_PENDING",
				"configuration_value" => "1",
				"configuration_description" => "Set the status to automatically update the order.",
				"configuration_group_id" => "6",
				"sort_order" => "6",
				"set_function" => "zen_cfg_pull_down_order_statuses(",
				"use_function" => "zen_get_order_status_name",
				"date_added" => "now()"
			),
			array(
				"configuration_title" => "Set the status for in process payments",
				"configuration_key" => "MODULE_PAYMENT_MERCADOPAGO_STATUS_IN_PROCESS",
				"configuration_value" => "1",
				"configuration_description" => "Set the status to automatically update the order.",
				"configuration_group_id" => "6",
				"sort_order" => "6",
				"set_function" => "zen_cfg_pull_down_order_statuses(",
				"use_function" => "zen_get_order_status_name",
				"date_added" => "now()"
			),
			array(
				"configuration_title" => "Set the status for rejected payments",
				"configuration_key" => "MODULE_PAYMENT_MERCADOPAGO_STATUS_REJECTED",
				"configuration_value" => "0",
				"configuration_description" => "Set the status to automatically update the order.",
				"configuration_group_id" => "6",
				"sort_order" => "6",
				"set_function" => "zen_cfg_pull_down_order_statuses(",
				"use_function" => "zen_get_order_status_name",
				"date_added" => "now()"
			),
			array(
				"configuration_title" => "Set the status for refunded payments",
				"configuration_key" => "MODULE_PAYMENT_MERCADOPAGO_STATUS_REFUNDED",
				"configuration_value" => "0",
				"configuration_description" => "Set the status to automatically update the order.",
				"configuration_group_id" => "6",
				"sort_order" => "6",
				"set_function" => "zen_cfg_pull_down_order_statuses(",
				"use_function" => "zen_get_order_status_name",
				"date_added" => "now()"
			),
			array(
				"configuration_title" => "Set the status for in mediation payments",
				"configuration_key" => "MODULE_PAYMENT_MERCADOPAGO_STATUS_IN_MEDIATION",
				"configuration_value" => "0",
				"configuration_description" => "Set the status to automatically update the order.",
				"configuration_group_id" => "6",
				"sort_order" => "6",
				"set_function" => "zen_cfg_pull_down_order_statuses(",
				"use_function" => "zen_get_order_status_name",
				"date_added" => "now()"
			),
			array(
				"configuration_title" => "Set the status for cancelled payments",
				"configuration_key" => "MODULE_PAYMENT_MERCADOPAGO_STATUS_CANCELLED",
				"configuration_value" => "0",
				"configuration_description" => "Set the status to automatically update the order.",
				"configuration_group_id" => "6",
				"sort_order" => "6",
				"set_function" => "zen_cfg_pull_down_order_statuses(",
				"use_function" => "zen_get_order_status_name",
				"date_added" => "now()"
			),

			array(
				"configuration_title" => "Category",
				"configuration_key" => "MODULE_PAYMENT_MERCADOPAGO_CATEGORIES",
				"configuration_value" => "others",
				"configuration_description" => "Set the category of your store",
				"configuration_group_id" => "6",
				"sort_order" => "4",
				"set_function" => "zen_cfg_select_option(array(" . $this->_getCategoriesMercadoPago() . "), ",
				"date_added" => "now()"
			),

			array(
				"configuration_title" => "Redirect URL",
				"configuration_key" => "MODULE_PAYMENT_MERCADOPAGO_REDIRECT_URL",
				"configuration_value" => $this->_getUrlRedirectUser(),
				"configuration_description" => "Url redirection when the user finish the checkout.",
				"configuration_group_id" => "6",
				"sort_order" => "3",
				"set_function" => "zen_cfg_textarea( ",
				"date_added" => "now()"
			),


			array(
				"configuration_title" => "Enable Auto Return?",
				"configuration_key" => "MODULE_PAYMENT_MERCADOPAGO_AUTORETURN",
				"configuration_value" => "True",
				"configuration_description" => "Enable automatic redirection to Success URL after approval",
				"configuration_group_id" => "6",
				"sort_order" => "4",
				"set_function" => "zen_cfg_select_option(array(\'True\', \'False\'), ",
				"date_added" => "now()"
			),

			array(
				"configuration_title" => "Type Checkout",
				"configuration_key" => "MODULE_PAYMENT_MERCADOPAGO_TYPE_CHECKOUT",
				"configuration_value" => "Iframe",
				"configuration_description" => "Checkout opening mode",
				"configuration_group_id" => "6",
				"sort_order" => "4",
				"set_function" => "zen_cfg_select_option(array(\'Iframe\', \'Lightbox\', \'Redirect\'), ",
				"date_added" => "now()"
			),

			array(
				"configuration_title" => "Limit installments",
				"configuration_key" => "MODULE_PAYMENT_MERCADOPAGO_INSTALLMENTS",
				"configuration_value" => "24",
				"configuration_description" => "Limit the number of installments",
				"configuration_group_id" => "6",
				"sort_order" => "4",
				"set_function" => "zen_cfg_select_option(array(\'24\',\'18\',\'15\',\'12\',\'11\',\'10\',\'9\',\'8\',\'7\',\'6\',\'5\',\'4\',\'3\',\'2\',\'1\'), ",
				"date_added" => "now()"
			),

			array(
				"configuration_title" => "Exclude Methods",
				"configuration_key" => "MODULE_PAYMENT_MERCADOPAGO_PAYMENT_METHODS",
				"configuration_value" => "",
				"configuration_description" => "Fill your client_id and client_secret to list the payment methods available to exclude and update config. ",
				"configuration_group_id" => "6",
				"sort_order" => "4",
				"set_function" => "zen_cfg_textarea( ",
				"date_added" => "now()"
			),

			array(
				"configuration_title" => "Live or Sandbox",
				"configuration_key" => "MODULE_PAYMENT_MERCADOPAGO_SANDBOX",
				"configuration_value" => "live",
				"configuration_description" => "Set the environment",
				"configuration_group_id" => "6",
				"sort_order" => "4",
				"set_function" => "zen_cfg_select_option(array(\'live\', \'sandbox\'), ",
				"date_added" => "now()"
			),


			array(
				"configuration_title" => "Two Card in Basic Checkout",
				"configuration_key" => "MODULE_PAYMENT_MERCADOPAGO_TWO_CARDS_BASIC_CHECKOUT",
				"configuration_value" => "active",
				"configuration_description" => "Enables the buyer to pay with two cards",
				"configuration_group_id" => "6",
				"sort_order" => "4",
				"set_function" => "zen_cfg_select_option(array(\'active\', \'inactive\'), ",
				"date_added" => "now()"
			),

			array(
				"configuration_title" => "Sort order of display",
				"configuration_key" => "MODULE_PAYMENT_MERCADOPAGO_SORT_ORDER",
				"configuration_value" => "0",
				"configuration_description" => "Sort order of display. Lowest is displayed first.",
				"configuration_group_id" => "6",
				"sort_order" => "5",
				"date_added" => "now()"
			),

		);

		return $configuration_fields;
	}

	function _processIPNMerchantOrder(){
		global $db;
		require(DIR_WS_CLASSES . 'order.php');

		$mercadopago = new MP(MODULE_PAYMENT_MERCADOPAGO_CLIENT_ID, MODULE_PAYMENT_MERCADOPAGO_CLIENT_SECRET);
		$merchant_order = $mercadopago->get_merchant_order($_REQUEST['id']);

		if($merchant_order['status'] == 200){
			if($merchant_order['response']['status'] == "closed"){
				$payment = $merchant_order['response']['payments'][0];
				$payment_status = $payment['status'];
				//two cards
				if(count($merchant_order['response']['payments']) > 1){
					$payment = $this->_overOnePaymentsIPN($merchant_order['response']);
					$payment_status = $payment['status'];
				}
				//get order id
				$order_id = $merchant_order['response']['external_reference'];
				// the actual order status
				$order_status = $payment_status;
				//init env
				$status = MODULE_PAYMENT_MERCADOPAGO_STATUS_PENDING;
				$statustxt = 'Mercado Pago automatic change the status to Pending';
				// verify the status
				switch ($order_status) {
					case 'approved':
						$status = MODULE_PAYMENT_MERCADOPAGO_STATUS_APPROVED;
						$statustxt = 'Mercado Pago automatic change the status to Approved';
						break;

					case 'pending':
						$status = MODULE_PAYMENT_MERCADOPAGO_STATUS_PENDING;
						$statustxt = 'Mercado Pago automatic change the status to Pending';
						break;

					case 'in_process':
						$status = MODULE_PAYMENT_MERCADOPAGO_STATUS_IN_PROCESS;
						$statustxt = 'Mercado Pago automatic change the status to In Process';
						break;

					case 'rejected':
						$status = MODULE_PAYMENT_MERCADOPAGO_STATUS_REJECTED;
						$statustxt = 'Mercado Pago automatic change the status to Rejected';
						break;

					case 'refunded':
						$status = MODULE_PAYMENT_MERCADOPAGO_STATUS_REFUNDED;
						$statustxt = 'Mercado Pago automatic change the status to Refunded';
						break;

					case 'cancelled':
						$status = MODULE_PAYMENT_MERCADOPAGO_STATUS_CANCELLED;
						$statustxt = 'Mercado Pago automatic change the status to Cancelled';
						break;

					case 'in_metiation':
						$status = MODULE_PAYMENT_MERCADOPAGO_STATUS_IN_MEDIATION;
						$statustxt = 'Mercado Pago automatic change the status to Mediation';
						break;

					default:
						$status = MODULE_PAYMENT_MERCADOPAGO_STATUS_PENDING;
						$statustxt = 'Mercado Pago automatic change the status to Pending';
						break;
				}

				// set payment id in text
				$statustxt .= "\n ID: " . $payment['id'];

				// get the order
				$order = new order($order_id);

				if ($order->info['orders_status'] != $status) {

					$db->Execute("update " . TABLE_ORDERS  . "
					set orders_status = '" . (int)$status . "'
					where orders_id = '" . (int)$order_id . "'");

					$data_history = array(
						'orders_id' => $order_id,
						'orders_status_id' => $status,
						'date_added' => 'now()',
						'customer_notified' => '0',
						'comments' => $statustxt);

						zen_db_perform(TABLE_ORDERS_STATUS_HISTORY, $data_history);

						echo "Payment status: {$order_status} \n";
						echo "Message: {$statustxt} \n";
					}
				}
			}
		}

		function _overOnePaymentsIPN($merchant_order){
			$total_amount = $merchant_order['total_amount'];
			$total_paid_approved = 0;
			$payment_return = array(
				"status" => "pending",
				"id" => ""
			);
			foreach($merchant_order['payments'] as $payment){
				//apenas soma quando for aprovado para mudar o status do pedido
				if($payment['status'] == "approved"){
					$total_paid_approved += $payment['total_paid_amount'];
				}
				//caso seja aprovado, authorized ou pendente adiciona os ids para mostrar na tela
				if($payment['status'] == "approved" || $payment['status'] == "authorized" || $payment['status'] == "pending"){
					$separator = "";
					if($payment_return['id'] != ""){
						$separator = " | ";
					}
					$payment_return['id'] .= $separator . $payment['id'];
				}
			}
			if($total_paid_approved >= $total_amount){
				$payment_return['status'] = "approved";
			}
			return $payment_return;
		}

		function _checkCredentials(){
			if(MODULE_PAYMENT_MERCADOPAGO_CLIENT_ID == "" || MODULE_PAYMENT_MERCADOPAGO_CLIENT_SECRET == ""){
				$this->title .= '<span class="alert"> (Not configured)</span>';
			}elseif(defined('MODULE_PAYMENT_MERCADOPAGO_CLIENT_ID') && defined('MODULE_PAYMENT_MERCADOPAGO_CLIENT_SECRET')){
				$mercadopago = new MP(MODULE_PAYMENT_MERCADOPAGO_CLIENT_ID, MODULE_PAYMENT_MERCADOPAGO_CLIENT_SECRET);
				$access_token = $mercadopago->get_access_token_detail();
				if($access_token['status'] != 200 && $access_token['status'] != 201){
					$this->title .= '<span class="alert"> (Not configured - Client_id or client_secret invalid)</span>';
				}
			}
		}

		function _setTextPaymentMethodsExcludeMercadoPago(){
			global $db;

			$text_payment_methods = "";

			if(MODULE_PAYMENT_MERCADOPAGO_CLIENT_ID != "" && MODULE_PAYMENT_MERCADOPAGO_CLIENT_SECRET != ""){
				$site = $this->_getSponsorAndSite();

				$request = array(
					"uri" => "/sites/{$site['site_id']}/payment_methods",
					"params" => array(
						"marketplace" => "NONE"
					)
				);

				$payment_methods = MPRestClient::get($request);
				foreach ($payment_methods['response'] as $pm) {
					if($text_payment_methods == ""){
						$text_payment_methods .= "Payments Methods: <br/> " . $pm['id'];
					}else{
						$text_payment_methods .= "," . $pm['id'];
					}
				}

				$text_payment_methods .= "<br/> To exclude fill the payment methods separated by comma. Follow the example: visa,master";
			}else{
				$text_payment_methods = "Fill your client_id and client_secret to list the payment methods available to exclude and update config";
			}

			//update the text in configuration
			$db->Execute("UPDATE " . TABLE_CONFIGURATION . "  SET configuration_description = '{$text_payment_methods}' WHERE configuration_key = 'MODULE_PAYMENT_MERCADOPAGO_PAYMENT_METHODS'; ");

		}

		function _updateApiAnalytics(){
			if((defined('MODULE_PAYMENT_MERCADOPAGO_CLIENT_ID') && defined('MODULE_PAYMENT_MERCADOPAGO_CLIENT_SECRET')) && (MODULE_PAYMENT_MERCADOPAGO_CLIENT_ID != "" && MODULE_PAYMENT_MERCADOPAGO_CLIENT_SECRET != "")){

				$status_module = MODULE_PAYMENT_MERCADOPAGO_STATUS;
				$status_two_cards = MODULE_PAYMENT_MERCADOPAGO_TWO_CARDS_BASIC_CHECKOUT == "active" ? "true": "false";


				//init mercado pago
				$mercadopago = new MP(MODULE_PAYMENT_MERCADOPAGO_CLIENT_ID, MODULE_PAYMENT_MERCADOPAGO_CLIENT_SECRET);
				//get info user
				$request = array(
					"uri" => "/modules/tracking/settings",
					"params" => array(
						"access_token" => $mercadopago->get_access_token()
					),
					"data" => array(
						"two_cards" => strtolower($status_two_cards),
						"checkout_basic" => $status_module,
						"platform" => "ZenCart",
						"platform_version" => PROJECT_VERSION_MAJOR . ".". PROJECT_VERSION_MINOR,
            "module_version" => "1.0.2",
						"php_version" => phpversion()
					),
					"headers" => array(
							"content-type" => "application/json"
					)
				);

				$analytics = MPRestClient::post($request);

			}

		}

		function _updateApiAccountSettings(){

			if((defined('MODULE_PAYMENT_MERCADOPAGO_CLIENT_ID') && defined('MODULE_PAYMENT_MERCADOPAGO_CLIENT_SECRET')) && (MODULE_PAYMENT_MERCADOPAGO_CLIENT_ID != "" && MODULE_PAYMENT_MERCADOPAGO_CLIENT_SECRET != "")){
				//init mercado pago
				$mercadopago = new MP(MODULE_PAYMENT_MERCADOPAGO_CLIENT_ID, MODULE_PAYMENT_MERCADOPAGO_CLIENT_SECRET);
				//get info user
				$request = array(
					"uri" => "/account/settings",
					"params" => array(
						"access_token" => $mercadopago->get_access_token()
					),
					"data" => array(
						"two_cards" => MODULE_PAYMENT_MERCADOPAGO_TWO_CARDS_BASIC_CHECKOUT
					),
					"headers" => array(
							"content-type" => "application/json"
					)
				);

				$account_settings = MPRestClient::put($request);

			}
		}

		function _getCategoriesMercadoPago(){
			$categories_string = "";
			$request = array(
				"uri" => "/item_categories"
			);
			$categories = MPRestClient::get($request);

			foreach ($categories['response'] as $cat) {

				if($categories_string == ""){
					$categories_string .= "\'{$cat['id']}\'";
				}else{
					$categories_string .= ",\'{$cat['id']}\'";
				}
			}

			return $categories_string;
		}

		function _getSponsorAndSite(){
			$user_info = array(
				"site_id" => "MLA",
				"sponsor_id" => ""
			);
			//check credentials configured
			if(MODULE_PAYMENT_MERCADOPAGO_CLIENT_ID != "" && MODULE_PAYMENT_MERCADOPAGO_CLIENT_SECRET != ""){
				//init mercado pago
				$mercadopago = new MP(MODULE_PAYMENT_MERCADOPAGO_CLIENT_ID, MODULE_PAYMENT_MERCADOPAGO_CLIENT_SECRET);
				//get info user
				$request = array(
					"uri" => "/users/me",
					"params" => array(
						"access_token" => $mercadopago->get_access_token()
					)
				);
				$user = MPRestClient::get($request);
				//check is a test
				if ($user['status'] == 200) {
					$user_info['site_id'] = $user['response']['site_id'];
					if(!in_array("test_user", $user['response']['tags'])){
						$sponsor_id = "";
						switch ($user_info['site_id']) {
							case 'MLA':
							$sponsor_id = 226955287;
							break;
							case 'MLB':
							$sponsor_id = 226955432;
							break;
							case 'MLC':
							$sponsor_id = 226955501;
							break;
							case 'MCO':
							$sponsor_id = 226953715;
							break;
							case 'MLM':
							$sponsor_id = 226953753;
							break;
							case 'MPE':
							$sponsor_id = 226953920;
							break;
							case 'MLV':
							$sponsor_id = 226955741;
							break;
						}
						$user_info['sponsor_id'] = $sponsor_id;
					}
				}
			}
			return $user_info;
		}

		function _getUrlRedirectUser(){
			return HTTP_SERVER . DIR_WS_CATALOG . 'index.php?main_page=checkout_success';
		}

		function _getURLIPN(){
			return HTTP_SERVER . DIR_WS_CATALOG . 'mercadopago_ipn.php';
		}

		function _getBannerBySiteId($site_id){

			$banner = array(
				'MLA' => "http://imgmp.mlstatic.com/org-img/banners/ar/medios/468X60.jpg",
				'MLB' => "http://imgmp.mlstatic.com/org-img/MLB/MP/BANNERS/tipo2_575X40.jpg",
				'MLC' => "https://www.mercadopago.cl/banner/468x60_banner.jpg",
				'MCO' => "https://secure.mlstatic.com/developers/site/cloud/banners/co/468x60_Todos-los-medios-de-pago.jpg",
				'MLM' => "http://imgmp.mlstatic.com/org-img/banners/mx/medios/MLM_468X60.JPG",
				'MPE' => "https://mercadopago.mlstatic.com/images/desktop-logo-mercadopago.png",
				'MLV' => "https://imgmp.mlstatic.com/org-img/banners/ve/medios/468X60.jpg"
			);

			return $banner[$site_id];
		}


		function _listCredentials(){
			$text = '
			<a href=\"https://www.mercadopago.com/mla/herramientas/aplicaciones\" target=\"_blank\"><b>ARGENTINA</b></a>,
			<a href=\"https://www.mercadopago.com/mlb/ferramentas/aplicacoes\" target=\"_blank\" ><b>BRASIL</b></a>,
			<a href=\"https://www.mercadopago.com/mlc/herramientas/aplicaciones\" target=\"_blank\" ><b>CHILE</b></a>,
			<a href=\"https://www.mercadopago.com/mco/herramientas/aplicaciones\" target=\"_blank\" ><b>COLÔMBIA</b></a>,
			<a href=\"https://www.mercadopago.com/mlm/herramientas/aplicaciones\" target=\"_blank\" ><b>MÉXICO</b></a>,
			<a href=\"https://www.mercadopago.com/mpe/herramientas/aplicaciones\" target=\"_blank\" ><b>PERU</b></a>,
			<a href=\"https://www.mercadopago.com/mlv/herramientas/aplicaciones\" target=\"_blank\"><b>VENEZUELA</b></a>';

			return $text;
		}

}
?>
