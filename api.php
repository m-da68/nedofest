<?php
/**
 * API сайта.
 *
 * ЮKassa отправляет webhook на этот же файл обычным POST-запросом с JSON в теле.
 * Официальное имя события — payment.succeeded. payment.success оставлен как
 * совместимый псевдоним на случай, если он уже указан в настройках интеграции.
 */

// Ошибки не должны попадать в JSON-ответ webhook и превращать его в невалидный ответ.
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Moscow');

/**
 * Возвращает JSON и завершает выполнение запроса.
 */
function respondJson($statusCode, array $body)
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Подключение к базе данных.
 */
function connectDatabase()
{
    // Не выбрасываем исключения/предупреждения наружу: webhook должен получить
    // понятный HTTP-ответ, а подробности остаются в error_log веб-сервера.
    mysqli_report(MYSQLI_REPORT_OFF);

    $db = mysqli_connect(
        'localhost',
        'nedofest',
        'Ci2mxPG*T36OcczW',
        'nedofest',
        3306
    );

    if (!$db) {
        error_log('nedofest API: database connection failed: ' . mysqli_connect_error());
        return false;
    }

    if (!mysqli_set_charset($db, 'utf8mb4')) {
        error_log('nedofest API: cannot set database charset: ' . mysqli_error($db));
        mysqli_close($db);
        return false;
    }

    return $db;
}

/**
 * Берёт email из разных вариантов объекта платежа.
 *
 * В актуальном API ЮKassa email чека находится в:
 * object.receipt.customer.email
 *
 * Metadata оставлена запасным вариантом для платежей, созданных через API с
 * metadata.email или metadata.customer_email.
 */
function extractPaymentEmail(array $payload)
{
    $object = isset($payload['object']) && is_array($payload['object'])
        ? $payload['object']
        : array();

    $metadata = isset($object['metadata']) && is_array($object['metadata'])
        ? $object['metadata']
        : array();

    $candidates = array(
        isset($object['receipt']['customer']['email'])
            ? $object['receipt']['customer']['email']
            : null,
        // Старый формат receipt, который ещё встречается в интеграциях.
        isset($object['receipt']['email'])
            ? $object['receipt']['email']
            : null,
        isset($object['customer']['email'])
            ? $object['customer']['email']
            : null,
        isset($metadata['email']) ? $metadata['email'] : null,
        isset($metadata['customer_email']) ? $metadata['customer_email'] : null,
    );

    foreach ($candidates as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }

        $email = trim($candidate);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
    }

    return null;
}

/**
 * Обрабатывает уведомление о платеже.
 */
function handlePaymentWebhook($payload, $method)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respondJson(405, array(
            'ok' => false,
            'error' => 'Webhook accepts POST requests only',
        ));
    }

    if (!is_array($payload)) {
        respondJson(400, array(
            'ok' => false,
            'error' => 'Invalid JSON body',
        ));
    }

    // При настройке webhook URL можно указать ?method=payment.success, но
    // событие всё равно должно прийти в JSON. Если query-параметр указан,
    // используем его только когда event отсутствует.
    $event = isset($payload['event']) && is_string($payload['event'])
        ? $payload['event']
        : $method;

    // В документации ЮKassa правильное событие называется payment.succeeded.
    // payment.success поддерживается для совместимости с запросом интеграции.
    $successEvents = array('payment.succeeded', 'payment.success');
    if (!in_array($event, $successEvents, true)) {
        // Нерелевантное событие подтверждаем, чтобы ЮKassa не повторяла его.
        respondJson(200, array(
            'ok' => true,
            'handled' => false,
            'event' => $event,
        ));
    }

    if (!isset($payload['object']) || !is_array($payload['object'])) {
        respondJson(400, array(
            'ok' => false,
            'error' => 'Payment object is missing',
        ));
    }

    $paymentObject = $payload['object'];
    $paymentStatus = isset($paymentObject['status']) ? $paymentObject['status'] : null;

    // Защита от ручной отправки payment.success для незавершённого платежа.
    // У старых тестовых уведомлений status может отсутствовать, поэтому в этом
    // случае достаточно самого события.
    if ($paymentStatus !== null && $paymentStatus !== 'succeeded') {
        respondJson(400, array(
            'ok' => false,
            'error' => 'Payment is not succeeded',
            'status' => $paymentStatus,
        ));
    }

    $email = extractPaymentEmail($payload);
    if ($email === null) {
        respondJson(422, array(
            'ok' => false,
            'error' => 'Customer email is missing in payment notification',
        ));
    }

    $db = connectDatabase();
    if (!$db) {
        respondJson(500, array(
            'ok' => false,
            'error' => 'Database connection failed',
        ));
    }

    // Подготовленный запрос не позволяет email из webhook попасть в SQL-код.
    // Повторная доставка одного и того же webhook безопасна: запись просто
    // останется со значением payed = 1.
    $statement = mysqli_prepare(
        $db,
        'UPDATE `tmpuser` SET `payed` = 1 WHERE `email` = ?'
    );

    if (!$statement) {
        error_log('nedofest API: cannot prepare payment update: ' . mysqli_error($db));
        mysqli_close($db);
        respondJson(500, array(
            'ok' => false,
            'error' => 'Database query failed',
        ));
    }

    mysqli_stmt_bind_param($statement, 's', $email);
    $updated = mysqli_stmt_execute($statement);

    if (!$updated) {
        error_log('nedofest API: cannot mark payment as paid: ' . mysqli_stmt_error($statement));
        mysqli_stmt_close($statement);
        mysqli_close($db);
        respondJson(500, array(
            'ok' => false,
            'error' => 'Database query failed',
        ));
    }

    $updatedRows = mysqli_stmt_affected_rows($statement);
    $paymentId = isset($paymentObject['id']) && is_string($paymentObject['id'])
        ? $paymentObject['id']
        : null;

    mysqli_stmt_close($statement);
    mysqli_close($db);

    // Ответное тело ЮKassa игнорирует, но оно удобно для проверки webhook
    // вручную и содержит email, по которому была отмечена запись.
    respondJson(200, array(
        'ok' => true,
        'handled' => true,
        'event' => $event,
        'payment_id' => $paymentId,
        'email' => $email,
        'payed' => 1,
        'updated_rows' => $updatedRows,
    ));
}

$method = isset($_GET['method']) && is_string($_GET['method'])
    ? $_GET['method']
    : '';

// JSON есть только в уведомлениях ЮKassa; обычная HTML-форма tmpuser
// продолжает работать через $_POST.
$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
$isPaymentWebhook = in_array($method, array('payment.success', 'payment.succeeded'), true)
    || (is_array($payload) && isset($payload['event']));

if ($isPaymentWebhook) {
    handlePaymentWebhook($payload, $method);
}

switch ($method) {
    case 'tmpuser':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            exit('Method Not Allowed');
        }

        $name = isset($_POST['name']) ? trim((string) $_POST['name']) : '';
        $email = isset($_POST['email']) ? trim((string) $_POST['email']) : '';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            exit('Name and valid email are required');
        }

        $db = connectDatabase();
        if (!$db) {
            http_response_code(500);
            exit('DB connection failed');
        }

        $datetime = date('d.m.Y H:i:s');
        $statement = mysqli_prepare(
            $db,
            'INSERT INTO `tmpuser` (`name`, `email`, `datetime`, `payed`) VALUES (?, ?, ?, 0)'
        );

        if (!$statement) {
            error_log('nedofest API: cannot prepare user insert: ' . mysqli_error($db));
            mysqli_close($db);
            http_response_code(500);
            exit('Database query failed');
        }

        mysqli_stmt_bind_param($statement, 'sss', $name, $email, $datetime);
        if (!mysqli_stmt_execute($statement)) {
            error_log('nedofest API: cannot save temporary user: ' . mysqli_stmt_error($statement));
            mysqli_stmt_close($statement);
            mysqli_close($db);
            http_response_code(500);
            exit('Database query failed');
        }

        mysqli_stmt_close($statement);
        mysqli_close($db);

        // Передаём email в платёжную форму, чтобы он был доступен в receipt
        // уведомления payment.succeeded и совпал с записью в tmpuser.
        $paymentUrl = 'https://yookassa.ru/my/i/aq6tjs9etntQ/l?cps_email=' . rawurlencode($email);
        header('Location: ' . $paymentUrl);
        exit;

    default:
        http_response_code(404);
        exit('Not Found');
}
?>
