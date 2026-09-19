<?php
/**
 * Общие функции генератора и проверки билетов.
 */
if (!defined('NEDOFEST_LIBRARY_ONLY')) {
    define('NEDOFEST_LIBRARY_ONLY', true);
}
require_once __DIR__ . '/api.php';

function ticketQueryValue($name)
{
    return isset($_GET[$name]) ? trim((string) $_GET[$name]) : '';
}

function ticketIsValidId($value)
{
    return $value !== '' && preg_match('/^[A-Za-z0-9_-]{1,100}$/', $value);
}

/**
 * Загружает одну строку tmpuser по безопасно выбранному идентификатору.
 */
function findTicketBy($db, $column, $value)
{
    $allowedColumns = array('order_id', 'payment_id', 'ticket_id');
    if (!in_array($column, $allowedColumns, true) || $value === '') {
        return null;
    }

    $statement = mysqli_prepare(
        $db,
        'SELECT `name`, `email`, `datetime`, `payed`, `order_id`, `payment_id`, `ticket_id` '
        . 'FROM `tmpuser` WHERE `' . $column . '` = ? LIMIT 1'
    );
    if (!$statement) {
        error_log('nedofest ticket: cannot prepare ticket lookup: ' . mysqli_error($db));
        return null;
    }

    mysqli_stmt_bind_param($statement, 's', $value);
    if (!mysqli_stmt_execute($statement)) {
        error_log('nedofest ticket: cannot execute ticket lookup: ' . mysqli_stmt_error($statement));
        mysqli_stmt_close($statement);
        return null;
    }

    mysqli_stmt_bind_result(
        $statement,
        $name,
        $email,
        $datetime,
        $payed,
        $orderId,
        $paymentId,
        $ticketId
    );

    $found = mysqli_stmt_fetch($statement);
    mysqli_stmt_close($statement);

    if (!$found) {
        return null;
    }

    return array(
        'name' => (string) $name,
        'email' => (string) $email,
        'datetime' => (string) $datetime,
        'payed' => (int) $payed,
        'order_id' => (string) $orderId,
        'payment_id' => (string) $paymentId,
        'ticket_id' => (string) $ticketId,
    );
}

/**
 * Проверяет платёж напрямую через API YooKassa.
 */
function verifyTicketPayment($paymentId, array $ticket)
{
    if (!ticketIsValidId($paymentId)) {
        return array('ok' => false, 'error' => 'Payment id is missing or invalid');
    }

    $response = yookassaRequest('GET', '/v3/payments/' . rawurlencode($paymentId));
    if (!$response['ok']) {
        return array('ok' => false, 'error' => 'Payment cannot be verified in YooKassa');
    }

    $payment = $response['data'];
    if (!isset($payment['id']) || $payment['id'] !== $paymentId) {
        return array('ok' => false, 'error' => 'Payment id mismatch');
    }

    if (!isset($payment['status']) || $payment['status'] !== 'succeeded' ||
        (isset($payment['paid']) && $payment['paid'] !== true)) {
        return array('ok' => false, 'error' => 'Payment is not completed yet');
    }

    $amount = isset($payment['amount']) && is_array($payment['amount'])
        ? $payment['amount']
        : array();
    if (!isset($amount['currency']) || $amount['currency'] !== 'RUB' ||
        !isset($amount['value']) || !amountsEqual($amount['value'], configuredPrice())) {
        return array('ok' => false, 'error' => 'Payment amount or currency is invalid');
    }

    $email = extractPaymentEmail($payment);
    if ($email === null || strcasecmp($email, $ticket['email']) !== 0) {
        return array('ok' => false, 'error' => 'Payment email does not match the order');
    }

    $orderId = extractOrderId($payment);
    if ($orderId !== null && $ticket['order_id'] !== '' && $orderId !== $ticket['order_id']) {
        return array('ok' => false, 'error' => 'Payment order id does not match the ticket');
    }

    $ticketId = extractTicketId($payment);
    if ($ticketId !== null && $ticket['ticket_id'] !== '' && $ticketId !== $ticket['ticket_id']) {
        return array('ok' => false, 'error' => 'Payment ticket id does not match the ticket');
    }

    return array(
        'ok' => true,
        'payment' => $payment,
        'email' => $email,
    );
}

function ticketHtmlError($statusCode, $message)
{
    http_response_code($statusCode);
    header('Content-Type: text/html; charset=utf-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Билет НедоFEST</title>'
        . '<style>body{font-family:Arial,sans-serif;background:#eae1cb;color:#17130f;display:grid;place-items:center;min-height:100vh;margin:0;padding:20px}.box{max-width:560px;background:#fffaf0;border:2px solid #17130f;padding:30px;box-shadow:8px 8px 0 #17130f}h1{margin-top:0;color:#8c1d18}p{line-height:1.5}</style>'
        . '</head><body><main class="box"><h1>Билет пока недоступен</h1><p>'
        . $safeMessage . '</p><p>Если вы уже оплатили билет, подождите несколько секунд и обновите страницу.</p></main></body></html>';
    exit;
}

function ticketJson($statusCode, array $body)
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function maskedTicketEmail($email)
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return $email;
    }
    $name = $parts[0];
    $visible = substr($name, 0, 1);
    return $visible . str_repeat('*', max(2, min(6, strlen($name) - 1))) . '@' . $parts[1];
}