<?php
/**
 * Payzum — Crypto & Stablecoin payment module for Zen Cart.
 *
 * After the order is created, redirects the buyer to a Payzum hosted checkout; the order is
 * settled by the signed IPN handler (payzum_ipn.php at the store root). Non-custodial.
 */

if (!defined('IS_ADMIN_FLAG')) {
	die('Illegal Access');
}

class payzum extends base
{
	public $code;
	public $title;
	public $description;
	public $enabled;
	public $sort_order;
	public $order_status;

	/**
	 * Module version, shown by admin → Modules → Payment. Bump it on every release so a store
	 * owner can tell at a glance which build is installed.
	 */
	public $moduleVersion = '1.0.0';

	public function __construct()
	{
		$this->code = 'payzum';
		$this->title = defined('MODULE_PAYMENT_PAYZUM_TEXT_TITLE') ? MODULE_PAYMENT_PAYZUM_TEXT_TITLE : 'Payzum (Crypto & Stablecoins)';
		$this->description = defined('MODULE_PAYMENT_PAYZUM_TEXT_DESCRIPTION') ? MODULE_PAYMENT_PAYZUM_TEXT_DESCRIPTION : '';
		$this->sort_order = defined('MODULE_PAYMENT_PAYZUM_SORT_ORDER') ? MODULE_PAYMENT_PAYZUM_SORT_ORDER : 0;
		$this->enabled = (defined('MODULE_PAYMENT_PAYZUM_STATUS') && MODULE_PAYMENT_PAYZUM_STATUS == 'True');

		if (defined('MODULE_PAYMENT_PAYZUM_ORDER_STATUS_ID') && (int)MODULE_PAYMENT_PAYZUM_ORDER_STATUS_ID > 0) {
			$this->order_status = (int)MODULE_PAYMENT_PAYZUM_ORDER_STATUS_ID;
		}

		if (is_null($this->sort_order)) {
			return false;
		}
	}

	public function update_status()
	{
		return;
	}

	public function javascript_validation()
	{
		return '';
	}

	public function selection()
	{
		return array('id' => $this->code, 'module' => $this->title);
	}

	public function pre_confirmation_check()
	{
		return false;
	}

	public function confirmation()
	{
		return array('title' => $this->description);
	}

	public function process_button()
	{
		return '';
	}

	public function before_process()
	{
		return false;
	}

	/**
	 * The order now exists: create the Payzum invoice via the official payzum/payzum-php SDK
	 * (vendored under payzum/vendor/) and redirect to the hosted checkout.
	 */
	public function after_process()
	{
		global $insert_id, $order;

		require_once DIR_FS_CATALOG . 'includes/modules/payment/payzum/vendor/autoload.php';

		$orderId = (int)$insert_id;
		$apiKey = defined('MODULE_PAYMENT_PAYZUM_API_KEY') ? trim((string)MODULE_PAYMENT_PAYZUM_API_KEY) : '';

		// The SDK entry point, pointed at the configured environment. Anything that is not
		// exactly "staging" means production.
		$staging = defined('MODULE_PAYMENT_PAYZUM_ENVIRONMENT') && 'staging' === trim((string)MODULE_PAYMENT_PAYZUM_ENVIRONMENT);
		$payzum = $staging ? \Payzum\Payzum::sandbox($apiKey) : new \Payzum\Payzum($apiKey);

		$callback = HTTP_SERVER . DIR_WS_CATALOG . 'payzum_ipn.php';

		try {
			// The amount travels as a string end to end: the SDK writes it into the JSON as an
			// exact number. Casting to float would round it on the way out.
			$payment = $payzum->payments->create(
				priceAmount: sprintf('%.2F', (float)$order->info['total']),
				priceCurrency: strtolower($order->info['currency']),
				payCurrency: $this->payCurrency(),
				orderId: (string)$orderId,
				orderDescription: 'Zen Cart order ' . $orderId,
				ipnCallbackUrl: $callback,
				// The order id is unique per store and this runs once, right after the order is
				// inserted — the key exists to make a transport retry safe, never to dedup
				// renders (there are none).
				idempotencyKey: 'zencart-' . $orderId,
			);
		} catch (\Payzum\Errors\PayzumException $e) {
			// The order stays in its default status; the customer sees checkout_success and can
			// be invoiced manually. Leave a trace for the shop owner.
			error_log('[payzum] create failed for order ' . $orderId . ': ' . $e->getMessage());
			return;
		}

		if (!empty($payment['invoice_url'])) {
			zen_redirect($payment['invoice_url']);
		}
		// On failure, fall through — the order stays in its default status; the customer sees checkout_success.
		return;
	}

	/**
	 * The coin to charge in. Defaults to the literal 'all', which defers the choice to the buyer
	 * on the Payzum hosted checkout — limited to the merchant's accepted tokens and enforced
	 * server-side. Safer than a fixed ticker, which the module cannot validate up front: one can
	 * be listed by /v1/currencies, have a published /v1/min-amount, and still be rejected at
	 * create time with CURRENCY_NOT_SUPPORTED. The old default, usdttrc20 (USDT on Tron), is
	 * floored at $100 — a normal order total came back AMOUNT_BELOW_MINIMUM.
	 */
	private function payCurrency()
	{
		$configured = defined('MODULE_PAYMENT_PAYZUM_PAY_CURRENCY')
			? strtolower(trim((string)MODULE_PAYMENT_PAYZUM_PAY_CURRENCY))
			: '';

		return '' !== $configured ? $configured : 'all';
	}

	public function get_error()
	{
		return false;
	}

	public function check()
	{
		if (!isset($this->_check)) {
			$check_query = $GLOBALS['db']->Execute("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_PAYMENT_PAYZUM_STATUS'");
			$this->_check = $check_query->RecordCount();
		}
		return $this->_check;
	}

	public function install()
	{
		$db = $GLOBALS['db'];
		$db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Enable Payzum', 'MODULE_PAYMENT_PAYZUM_STATUS', 'True', 'Do you want to accept Payzum crypto payments?', '6', '0', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
		$db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('API Key', 'MODULE_PAYMENT_PAYZUM_API_KEY', '', 'Your Payzum API key (64-hex).', '6', '10', now())");
		$db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Settlement currency', 'MODULE_PAYMENT_PAYZUM_PAY_CURRENCY', 'all', 'Leave as \'all\' to let the buyer pick the coin on the Payzum checkout, limited to the tokens your merchant account accepts. Only set a single ticker (e.g. usdtmatic) to force one coin — a ticker listed by the API can still be rejected at checkout if your account has not enabled that chain.', '6', '20', now())");
		$db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Webhook secret', 'MODULE_PAYMENT_PAYZUM_WEBHOOK_SECRET', '', 'IPN signing secret (verifies HMAC-SHA-512).', '6', '30', now())");
		// There is deliberately no signature-header setting: the SDK's Verifier owns the fixed
		// header (x-nowpayments-sig). The old setting is how installs broke.
		$db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Environment', 'MODULE_PAYMENT_PAYZUM_ENVIRONMENT', 'production', 'production (merchant.payzum.com) or staging (staging.payzum.com — separate API keys).', '6', '40', 'zen_cfg_select_option(array(\'production\', \'staging\'), ', now())");
		$db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) VALUES ('Set Order Status', 'MODULE_PAYMENT_PAYZUM_ORDER_STATUS_ID', '0', 'Set the paid order status.', '6', '50', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
		// Zen Cart ships no "cancelled" status, so this cannot be hard-coded the way OpenCart's
		// id 7 can. Without it an expired invoice would leave the order sitting in the status it
		// was created with — indistinguishable from one still awaiting payment, which is how
		// unpaid goods get shipped. Left at 0 the IPN falls back to a status the store named
		// "Cancelled"/"Failed", and refuses to guess beyond that.
		$db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) VALUES ('Set Failed Order Status', 'MODULE_PAYMENT_PAYZUM_FAILED_ORDER_STATUS_ID', '0', 'Where an expired or failed Payzum invoice leaves the order. Zen Cart has no \'Cancelled\' status out of the box — add one under Localization > Orders Status and select it here. Left at \'--none--\' the IPN looks for a status named Cancelled or Failed and, failing that, leaves the order where it is with a warning in its history.', '6', '55', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
		$db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Sort order of display.', 'MODULE_PAYMENT_PAYZUM_SORT_ORDER', '0', 'Sort order of display. Lowest is displayed first.', '6', '60', now())");
	}

	public function remove()
	{
		$GLOBALS['db']->Execute("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key IN ('" . implode("', '", $this->keys()) . "')");
	}

	public function keys()
	{
		return array(
			'MODULE_PAYMENT_PAYZUM_STATUS',
			'MODULE_PAYMENT_PAYZUM_API_KEY',
			'MODULE_PAYMENT_PAYZUM_PAY_CURRENCY',
			'MODULE_PAYMENT_PAYZUM_WEBHOOK_SECRET',
			'MODULE_PAYMENT_PAYZUM_ENVIRONMENT',
			'MODULE_PAYMENT_PAYZUM_ORDER_STATUS_ID',
			'MODULE_PAYMENT_PAYZUM_FAILED_ORDER_STATUS_ID',
			'MODULE_PAYMENT_PAYZUM_SORT_ORDER',
		);
	}
}
