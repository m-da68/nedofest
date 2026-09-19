<?php
require_once __DIR__ . '/ticket_common.php';

$ticketId = ticketQueryValue('ticket_id');
$wantJson = ticketQueryValue('format') === 'json'
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if (!ticketIsValidId($ticketId)) {
    $body = array(
        'exists' => false,
        'valid' => false,
        'error' => 'Invalid ticket id',
    );
    if ($wantJson) {
        ticketJson(400, $body);
    }
    ticketHtmlError(400, 'Неверный идентификатор билета.');
}

$db = connectDatabase();
if (!$db) {
    if ($wantJson) {
        ticketJson(500, array('exists' => false, 'valid' => false, 'error' => 'Database unavailable'));
    }
    ticketHtmlError(500, 'Не удалось подключиться к базе данных.');
}
if (!ensurePaymentColumns($db)) {
    mysqli_close($db);
    if ($wantJson) {
        ticketJson(500, array('exists' => false, 'valid' => false, 'error' => 'Payment columns are missing'));
    }
    ticketHtmlError(500, 'В базе данных отсутствуют поля билета.');
}

$ticket = findTicketBy($db, 'ticket_id', $ticketId);
if ($ticket === null) {
    mysqli_close($db);
    $body = array(
        'exists' => false,
        'valid' => false,
        'ticket_id' => $ticketId,
        'error' => 'Ticket not found',
    );
    if ($wantJson) {
        ticketJson(404, $body);
    }
    ticketHtmlError(404, 'Билет с таким идентификатором не найден.');
}

$valid = ((int) $ticket['payed'] === 1 && $ticket['payment_id'] !== '');
$body = array(
    'exists' => true,
    'valid' => $valid,
    'ticket_id' => $ticket['ticket_id'],
    'name' => $ticket['name'],
    'email' => maskedTicketEmail($ticket['email']),
    'payment_id' => $ticket['payment_id'],
    'payed' => (int) $ticket['payed'],
    'created_at' => $ticket['datetime'],
    'event' => array(
        'name' => 'НедоFEST',
        'date' => '26 сентября 2026',
        'time' => '19:00',
        'place' => 'Тамбов, Астраханская 2В',
    ),
);

mysqli_close($db);

if ($wantJson) {
    ticketJson(200, $body);
}

http_response_code($valid ? 200 : 409);
header('Content-Type: text/html; charset=utf-8');
$status = $valid ? 'БИЛЕТ ДЕЙСТВИТЕЛЕН' : 'ОПЛАТА НЕ ПОДТВЕРЖДЕНА';
$color = $valid ? '#237a45' : '#8c1d18';
$name = htmlspecialchars($ticket['name'], ENT_QUOTES, 'UTF-8');
$safeTicketId = htmlspecialchars($ticket['ticket_id'], ENT_QUOTES, 'UTF-8');
$safePaymentId = htmlspecialchars($ticket['payment_id'], ENT_QUOTES, 'UTF-8');
$safeEmail = htmlspecialchars(maskedTicketEmail($ticket['email']), ENT_QUOTES, 'UTF-8');

echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>Проверка билета НедоFEST</title>'
    . '<style>body{font-family:Arial,sans-serif;background:#eae1cb;color:#17130f;display:grid;place-items:center;min-height:100vh;margin:0;padding:20px}.box{width:min(600px,100%);background:#fffaf0;border:2px solid #17130f;padding:30px;box-sizing:border-box;box-shadow:8px 8px 0 #17130f}h1{margin:0 0 24px;color:' . $color . ';font-size:28px}.row{border-top:1px solid #cfc4ae;padding:12px 0}.label{font-size:12px;color:#756b5b;text-transform:uppercase}.value{font-size:18px;margin-top:4px;word-break:break-word}</style>'
    . '</head><body><main class="box"><h1>' . $status . '</h1>'
    . '<div class="row"><div class="label">Билет</div><div class="value">' . $safeTicketId . '</div></div>'
    . '<div class="row"><div class="label">ФИО</div><div class="value">' . $name . '</div></div>'
    . '<div class="row"><div class="label">Email</div><div class="value">' . $safeEmail . '</div></div>'
    . '<div class="row"><div class="label">Платёж YooKassa</div><div class="value">' . $safePaymentId . '</div></div>'
    . '<div class="row"><div class="label">Мероприятие</div><div class="value">НедоFEST, 26 сентября 2026, 19:00<br>Тамбов, Астраханская 2В</div></div>'
    . '</main></body></html>';