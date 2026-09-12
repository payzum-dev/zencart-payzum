<?php
/**
 * Payzum IPN handler for Zen Cart. Place this file in your store root (next to index.php).
 *
 * The SDK's Verifier does the dangerous parts: it reads the correct, fixed signature header
 * itself (x-nowpayments-sig — case-insensitively, CGI form included), verifies HMAC-SHA-512 over
 * the RAW bytes in constant time, and enforces the 10-minute replay window on the signed
 * event_at. Deduplication uses the order's own status history: every applied event leaves a
 * comment tagged with its event id, and a redelivery of the same id is a no-op. The whole
 * check-then-act runs under a per-order advisory lock so two concurrent deliveries cannot both
 * credit the order.
 *
 * Only `finished` moves the order to the configured paid status (it also covers overpayment),
 * and only when the invoice was priced at what the order is worth. `expired` and `failed` move
 * it to the configured failed status — never downgrading an order that is already paid. The
 * rest — and an unknown status, which is a contract change — land in the status history as
 * comments, never as a 500: that would just burn the delivery retries.
 */

require 'includes/application_top.php';
require_once DIR_FS_CATALOG . 'includes/modules/payment/payzum/vendor/autoload.php';

use Payzum\Errors\PayzumException;
use Payzum\Errors\SignatureException;
use Payzum\PaymentStatus;
use Payzum\Webhooks\Verifier;

/**
 * Where an expired or failed invoice leaves the order.
 *
 * Zen Cart ships with four order statuses (Pending, Processing, Delivered, Update) and none of
 * them means "cancelled", so unlike OpenCart there is no id to hard-code. The module setting is
 * the answer; the name lookup is a fallback for the stores that added their own "Cancelled"
 * status but never opened the Payzum settings. Returns 0 when neither exists — the caller then
 * refuses to guess and says so in the history instead.
 *
 * @param object $db Zen Cart's queryFactory
 * @return int
 */
function payzum_failed_order_status($db)
{
	if (defined('MODULE_PAYMENT_PAYZUM_FAILED_ORDER_STATUS_ID') && (int)MODULE_PAYMENT_PAYZUM_FAILED_ORDER_STATUS_ID > 0) {
		return (int)MODULE_PAYMENT_PAYZUM_FAILED_ORDER_STATUS_ID;
	}

	// orders_status has one row per language, so the same id can come back more than once.
	$named = $db->Execute(
		"SELECT orders_status_id FROM " . TABLE_ORDERS_STATUS
		. " WHERE orders_status_name LIKE 'Cancel%' OR orders_status_name LIKE 'Fail%'"
		. " ORDER BY orders_status_id LIMIT 1"
	);

	return $named->EOF ? 0 : (int)$named->fields['orders_status_id'];
}

$raw = file_get_contents('php://input');
if ($raw === '' || $raw === false) {
	http_response_code(400);
	die('empty body');
}

$secret = defined('MODULE_PAYMENT_PAYZUM_WEBHOOK_SECRET') ? (string)MODULE_PAYMENT_PAYZUM_WEBHOOK_SECRET : '';
if ('' === $secret) {
	http_response_code(500);
	die('not configured');
}

$headers = function_exists('getallheaders') ? getallheaders() : false;
if (!is_array($headers)) {
	$headers = array_filter($_SERVER, 'is_string');
}

try {
	$verifier = new Verifier($secret);
	$data = $verifier->verifyPaymentIpn($raw, $headers);
} catch (SignatureException $e) {
	http_response_code(401);
	die('bad signature');
} catch (PayzumException $e) {
	http_response_code(400);
	die('bad json');
}

$orderId = isset($data['order_id']) ? (int)$data['order_id'] : 0;

if ($orderId <= 0) {
	http_response_code(404);
	die('no order');
}

// Confirm the order exists before touching anything. Without this check an IPN carrying an unknown
// or non-numeric order_id still answered 200 and inserted an orphan row into
// orders_status_history — a status-history entry for an order that does not exist. The 200 also
// told Payzum the callback had been handled when nothing had been.
$exists = $db->Execute("SELECT orders_id FROM " . TABLE_ORDERS . " WHERE orders_id = " . (int)$orderId . " LIMIT 1");
if ($exists->EOF) {
	http_response_code(404);
	die('order not found');
}

// Serialise the deliveries for this order before the check-then-act below.
//
// "read the order status, then write a new one" is a read and a write with nothing in between:
// two concurrent deliveries both read "not paid yet" and both credited the order, leaving two
// "payment confirmed" rows in the history and two customer notifications for one payment.
//
// GET_LOCK rather than SELECT ... FOR UPDATE: Zen Cart still creates its tables as MyISAM
// (verified on 1.5.8a), where transactions and row locks are silently no-ops — a FOR UPDATE here
// would look like a fix and protect nothing. The advisory lock is engine-independent.
//
// The name is namespaced because GET_LOCK names are scoped to the whole MySQL server, not to a
// database or a connection. 'payzum_ipn_zc_<order>' collided across shops: two Zen Cart installs
// on one server (a staging copy, any shared host) both have an order 42, so one shop's IPN made
// the other's answer 503 and Payzum retried a delivery that was never going to get in.
// DB_DATABASE separates the installs and DB_PREFIX separates two shops inside one database.
// Hashed because GET_LOCK truncates past 64 characters, which would undo the namespacing.
$lockName = 'payzum_' . substr(md5(DB_DATABASE . '|' . DB_PREFIX . '|zc-order|' . (int)$orderId), 0, 32);
// 10s: long enough for the two writes below, short enough that a wedged delivery does not pin a
// web worker for a minute.
$lock = $db->Execute("SELECT GET_LOCK('" . zen_db_input($lockName) . "', 10) AS got");
if ($lock->EOF || (int)$lock->fields['got'] !== 1) {
	// Another delivery is mid-flight. 503 keeps the event alive: Payzum retries it, so the
	// payment is still credited if that in-flight delivery fails.
	http_response_code(503);
	die('busy');
}

/**
 * Release the advisory lock, then answer. Explicit rather than relying on the connection
 * closing: Zen Cart can be configured with persistent MySQL connections, where the lock would
 * outlive the request.
 */
$finish = function ($code, $message) use ($db, $lockName) {
	$db->Execute("SELECT RELEASE_LOCK('" . zen_db_input($lockName) . "')");
	http_response_code($code);
	die($message);
};

// Read the order under the lock — anything read before taking it may already be stale, which is
// the whole point of holding it.
$order = $db->Execute(
	"SELECT orders_status, order_total, currency FROM " . TABLE_ORDERS
	. " WHERE orders_id = " . (int)$orderId . " LIMIT 1"
);
$currentStatus = (int)$order->fields['orders_status'];

// Retries and multi-transition deliveries reuse the event id; a replay must be a no-op. The tag
// travels inside the history comment because Zen Cart has no order meta to keep it in.
$eventId = (string)$verifier->eventId($headers);
$eventTag = '' !== $eventId ? ' [' . preg_replace('/[^A-Za-z0-9_.\-]/', '', $eventId) . ']' : '';
if ('' !== $eventTag) {
	// INSTR, not LIKE '%tag%'. `_` is a single-character wildcard in LIKE and the sanitiser above
	// deliberately keeps it, so the tag [evt_1] also matched a stored [evt-1] or [evtX1];
	// zen_db_input guards against injection, not against wildcards. The consequence was the worst
	// kind: this handler answered "duplicate", Payzum stopped retrying, and a genuinely new
	// payment was never credited. INSTR is a literal substring search with no wildcards at all.
	$seen = $db->Execute(
		"SELECT orders_status_history_id FROM " . TABLE_ORDERS_STATUS_HISTORY
		. " WHERE orders_id = " . (int)$orderId
		. " AND INSTR(comments, '" . zen_db_input($eventTag) . "') > 0 LIMIT 1"
	);
	if (!$seen->EOF) {
		// Logged so a false positive is diagnosable: without this line the only symptom of a
		// wrongly-detected duplicate is a paid order that silently never moves.
		error_log('[payzum] IPN: duplicate event ' . $eventId . ' for order ' . $orderId . ' — ignored.');
		$finish(200, 'duplicate');
	}
}

// The SDK's PaymentStatus models the five values the contract promises and throws on anything
// else. An unknown value is a contract change: record it and acknowledge, never guess.
$rawStatus = isset($data['payment_status']) ? (string)$data['payment_status'] : '';
$status = null;
try {
	$status = PaymentStatus::fromMerchant($rawStatus);
	$comment = 'Payzum: status ' . $status->value . '.';
} catch (PayzumException $e) {
	$comment = 'Payzum: unrecognised status "' . preg_replace('/[^\x20-\x7E]/', '', $rawStatus) . '" — contract change?';
}

$paidStatus = (defined('MODULE_PAYMENT_PAYZUM_ORDER_STATUS_ID') && (int)MODULE_PAYMENT_PAYZUM_ORDER_STATUS_ID > 0)
	? (int)MODULE_PAYMENT_PAYZUM_ORDER_STATUS_ID
	: 2;

/**
 * Append a status-history row, optionally moving the order.
 *
 * @param int    $statusId the status to file the comment under (the current one to leave the
 *                         order where it is)
 * @param string $text     the comment
 * @param bool   $move     also write orders.orders_status
 * @param bool   $notify   mark the customer as notified
 */
$record = function ($statusId, $text, $move = false, $notify = false) use ($db, $orderId) {
	if ($move) {
		$db->Execute("UPDATE " . TABLE_ORDERS . " SET orders_status = " . (int)$statusId . " WHERE orders_id = " . (int)$orderId);
	}
	$db->Execute(
		"INSERT INTO " . TABLE_ORDERS_STATUS_HISTORY
		. " (orders_id, orders_status_id, date_added, customer_notified, comments)"
		. " VALUES (" . (int)$orderId . ", " . (int)$statusId . ", now(), " . ($notify ? 1 : 0) . ", '"
		. zen_db_input($text) . "')"
	);
};

if (null !== $status && $status->isPaid()) {
	// A valid signature proves the delivery is Payzum's, not that the invoice was priced for this
	// order. Compared exactly the way after_process() built it — orders.order_total in the
	// order's own currency — with half a cent of tolerance rather than ==: the amount goes out as
	// sprintf('%.2F', …) and comes back as a JSON number, so a legitimate 19.99 can arrive as
	// 19.989999999999998.
	$expected = (float)$order->fields['order_total'];
	$expectedCurrency = strtolower((string)$order->fields['currency']);
	$paidAmount = isset($data['price_amount']) ? (float)$data['price_amount'] : -1.0;
	$paidCurrency = isset($data['price_currency']) ? strtolower((string)$data['price_currency']) : '';

	if ($paidCurrency !== $expectedCurrency || abs($paidAmount - $expected) > 0.005) {
		// 200, not 5xx: the order total will not change, so all five delivery retries would fail
		// the same way. THIS ONE NEEDS A HUMAN — the buyer has paid something; reconcile it
		// against the Payzum dashboard and complete the order by hand. Both figures go to the
		// error log and to the order history so the gap is visible from either side.
		error_log(
			'[payzum] IPN: NOT crediting order ' . $orderId . ' — the invoice was priced at ' . $paidAmount . ' '
			. $paidCurrency . ' but the order is ' . $expected . ' ' . $expectedCurrency . '. Manual review required.'
		);
		$record(
			$currentStatus,
			'Payzum: invoice priced at ' . $paidAmount . ' ' . strtoupper($paidCurrency) . ', order is '
			. $expected . ' ' . strtoupper($expectedCurrency) . ' — NOT credited, manual review required.' . $eventTag
		);
		$finish(200, 'ok');
	}

	// Terminal-state check. This is also the only protection when a delivery arrives without an
	// x-payzum-event-id header, because the dedup block above is skipped entirely in that case.
	if ($currentStatus === $paidStatus) {
		error_log('[payzum] IPN: order ' . $orderId . ' is already paid — not re-crediting or re-notifying.');
		$finish(200, 'already paid');
	}

	$record($paidStatus, 'Payzum: payment confirmed (finished).' . $eventTag, true, true);
} elseif (null !== $status && $status->isTerminal()) {
	// expired | failed.
	//
	// These used to fall into the same branch as waiting/partially_paid, which rewrote the order
	// with its *current* status: an expired invoice left the order indistinguishable from one
	// placed a minute ago and still awaiting payment, which is how unpaid goods get shipped.
	if ($currentStatus === $paidStatus) {
		// The terminal states are mutually exclusive on Payzum's side, but a delayed or
		// out-of-order delivery must never downgrade an order that is already paid.
		$record($currentStatus, 'Payzum: ' . $status->value . ' received after payment — ignored.' . $eventTag);
	} else {
		$failedStatus = payzum_failed_order_status($db);
		if ($failedStatus > 0) {
			$record($failedStatus, 'Payzum: invoice ' . $status->value . ' — payment was NOT received.' . $eventTag, true);
		} else {
			// Nothing to move it to. Say so loudly rather than silently re-filing the order under
			// its current status, which is what made this defect invisible in the first place.
			error_log(
				'[payzum] IPN: order ' . $orderId . ' is ' . $status->value . ' but no failed order status is configured — '
				. 'the order was left as-is. Set "Set Failed Order Status" in Modules > Payment > Payzum.'
			);
			$record(
				$currentStatus,
				'Payzum: invoice ' . $status->value . ' — payment was NOT received. The order status was left unchanged '
				. 'because no failed order status is configured; do NOT ship this order.' . $eventTag
			);
		}
	}
} else {
	// waiting | partially_paid | unknown — keep the current status; leave the event on record.
	$record($currentStatus, $comment . $eventTag);
}

$finish(200, 'ok');
