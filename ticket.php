<?php
require_once __DIR__ . '/ticket_common.php';
require_once __DIR__ . '/ticket_pdf.php';

$orderId = ticketQueryValue('order_id');
$paymentIdFromQuery = ticketQueryValue('payment_id');
$ticketIdFromQuery = ticketQueryValue('ticket_id');
$emailFromQuery = ticketQueryValue('email');
$nameFromQuery = ticketQueryValue('name');

if ($orderId !== '' && !ticketIsValidId($orderId)) {
    ticketHtmlError(400, 'Неверный идентификатор заказа.');
}
if ($paymentIdFromQuery !== '' && !ticketIsValidId($paymentIdFromQuery)) {
    ticketHtmlError(400, 'Неверный идентификатор платежа.');
}
if ($ticketIdFromQuery !== '' && !ticketIsValidId($ticketIdFromQuery)) {
    ticketHtmlError(400, 'Неверный идентификатор билета.');
}

$db = connectDatabase();
if (!$db) {
    ticketHtmlError(500, 'Не удалось подключиться к базе данных.');
}
if (!ensurePaymentColumns($db)) {
    mysqli_close($db);
    ticketHtmlError(500, 'В базе данных отсутствуют поля платежа.');
}

$ticket = null;
if ($orderId !== '') {
    $ticket = findTicketBy($db, 'order_id', $orderId);
} elseif ($paymentIdFromQuery !== '') {
    $ticket = findTicketBy($db, 'payment_id', $paymentIdFromQuery);
} elseif ($ticketIdFromQuery !== '') {
    $ticket = findTicketBy($db, 'ticket_id', $ticketIdFromQuery);
}

if ($ticket === null) {
    mysqli_close($db);
    ticketHtmlError(404, 'Билет с указанными данными не найден.');
}

// Параметры из URL не являются источником истины — они только дополнительно
// сверяются с записью из БД.
if ($ticketIdFromQuery !== '' && !hash_equals($ticket['ticket_id'], $ticketIdFromQuery)) {
    mysqli_close($db);
    ticketHtmlError(403, 'Идентификатор билета не совпадает.');
}
if ($paymentIdFromQuery !== '' && !hash_equals($ticket['payment_id'], $paymentIdFromQuery)) {
    mysqli_close($db);
    ticketHtmlError(403, 'Идентификатор платежа не совпадает.');
}
if ($emailFromQuery !== '' && strcasecmp($ticket['email'], $emailFromQuery) !== 0) {
    mysqli_close($db);
    ticketHtmlError(403, 'Email не совпадает с заказом.');
}
if ($nameFromQuery !== '' && $ticket['name'] !== $nameFromQuery) {
    mysqli_close($db);
    ticketHtmlError(403, 'ФИО не совпадает с заказом.');
}

if ($ticket['payment_id'] === '') {
    mysqli_close($db);
    ticketHtmlError(409, 'Для заказа ещё не сохранен идентификатор платежа.');
}

// Проверяем оплату напрямую. Это покрывает случай, когда пользователь
// вернулся с YooKassa раньше, чем webhook успел прийти на сайт.
$verification = verifyTicketPayment($ticket['payment_id'], $ticket);
if (!$verification['ok']) {
    mysqli_close($db);
    ticketHtmlError(409, $verification['error']);
}

if ((int) $ticket['payed'] !== 1) {
    $markResult = markPaymentPaid(
        $db,
        $ticket['payment_id'],
        $ticket['order_id'] !== '' ? $ticket['order_id'] : null,
        $ticket['email']
    );

    if (!$markResult['ok'] || !$markResult['found']) {
        mysqli_close($db);
        ticketHtmlError(500, 'Платёж подтверждён, но не удалось обновить запись билета.');
    }

    $ticket['payed'] = 1;
}

$verificationUrl = applicationUrl() . '/verify.php?ticket_id='
    . rawurlencode($ticket['ticket_id']);
$fontPath = __DIR__ . '/assets/DejaVuSans.ttf';

try {
    $pdf = new NedofestTicketPdf($ticket, $verificationUrl, $fontPath);
    $pdfData = $pdf->output();
} catch (Exception $exception) {
    error_log('nedofest ticket: PDF generation failed: ' . $exception->getMessage());
    mysqli_close($db);
    ticketHtmlError(500, 'Не удалось сформировать PDF-билет.');
}

mysqli_close($db);

$filename = preg_replace('/[^A-Za-z0-9_-]/', '_', $ticket['ticket_id']);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="ticket-' . $filename . '.pdf"');
header('Content-Length: ' . strlen($pdfData));
header('Cache-Control: private, no-store, max-age=0');
echo $pdfData;