<?php
/**
 * API оплаты НедоFEST через YooKassa.
 *
 * Методы:
 *   POST api.php?method=create_payment  — создаёт платёж и перенаправляет
 *                                         покупателя на страницу YooKassa.
 *   POST api.php                         — принимает webhook YooKassa.
 *
 * Для работы нужны переменные окружения YOOKASSA_SHOP_ID и
 * YOOKASSA_SECRET_KEY. Секретный ключ не должен находиться в репозитории.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Moscow');

/**
 * Отправляет JSON-ответ и завершает запрос.
 */
function respondJson($statusCode, array $body)
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Читает значение конфигурации из окружения или $_SERVER.
 */
function configValue($name, $default = '')
{
    $value = getenv($name);
    if ($value !== false && trim((string) $value) !== '') {
        return trim((string) $value);
    }

    if (isset($_SERVER[$name]) && trim((string) $_SERVER[$name]) !== '') {
        return trim((string) $_SERVER[$name]);
    }

    return $default;
}

/**
 * Читает boolean-переменную окружения.
 */
function configBool($name, $default = false)
{
    $value = configValue($name, $default ? '1' : '0');
    return in_array(strtolower((string) $value), array('1', 'true', 'yes', 'on'), true);
}

/**
 * Подключение к базе данных.
 */
function connectDatabase()
{
    // Эти значения оставлены совместимыми с текущим сервером. Их можно
    // переопределить через DB_HOST, DB_USER, DB_PASSWORD, DB_NAME и DB_PORT.
    $host = configValue('DB_HOST', 'localhost');
    $user = configValue('DB_USER', 'nedofest');
    $password = configValue('DB_PASSWORD', 'Ci2mxPG*T36OcczW');
    $database = configValue('DB_NAME', 'nedofest');
    $port = (int) configValue('DB_PORT', '3306');

    // Не выводим предупреждения mysqli в тело HTTP-ответа.
    mysqli_report(MYSQLI_REPORT_OFF);

    $db = mysqli_connect($host, $user, $password, $database, $port);
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
 * Добавляет нужные для новой платёжной схемы поля в существующую таблицу.
 *
 * Старый проект создавал только name, email, datetime и payed, поэтому
 * миграция выполняется автоматически и старую базу удалять не требуется.
 */
function ensurePaymentColumns($db)
{
    $columns = array(
        'order_id' => 'VARCHAR(64) NULL',
        'payment_id' => 'VARCHAR(100) NULL',
        'ticket_id' => 'VARCHAR(64) NULL',
    );

    foreach ($columns as $column => $definition) {
        $check = mysqli_query(
            $db,
            "SHOW COLUMNS FROM `tmpuser` WHERE Field = '" . $column . "'"
        );

        if (!$check) {
            error_log('nedofest API: cannot inspect tmpuser.' . $column . ': ' . mysqli_error($db));
            return false;
        }

        $exists = mysqli_num_rows($check) > 0;
        mysqli_free_result($check);

        if ($exists) {
            continue;
        }

        $alter = mysqli_query(
            $db,
            'ALTER TABLE `tmpuser` ADD COLUMN `' . $column . '` ' . $definition
        );

        if (!$alter) {
            error_log('nedofest API: cannot add tmpuser.' . $column . ': ' . mysqli_error($db));
            return false;
        }
    }

    return true;
}

/**
 * Генерирует внутренний идентификатор заказа.
 */
function generateOrderId()
{
    if (function_exists('random_bytes')) {
        try {
            return bin2hex(random_bytes(16));
        } catch (Exception $exception) {
            // Используем fallback ниже на старых/ограниченных хостингах.
        }
    }

    return str_replace('.', '', uniqid('', true));
}

/**
 * Цена билета в формате YooKassa, например 200.00.
 */
function generateTicketId()
{
    return 'NF-' . date('Ymd') . '-' . strtoupper(substr(generateOrderId(), 0, 12));
}

function configuredPrice()
{
    $price = configValue('YOOKASSA_PRICE', '200.00');
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $price) || (float) $price <= 0) {
        return '200.00';
    }

    return number_format((float) $price, 2, '.', '');
}

/**
 * Сравнение денежных значений без ошибок плавающей точки.
 */
function amountsEqual($actual, $expected)
{
    if (!is_scalar($actual)) {
        return false;
    }

    return number_format((float) $actual, 2, '.', '') === number_format((float) $expected, 2, '.', '');
}

/**
 * Email из подтверждённого объекта платежа.
 *
 * Для платежей с чеком используется receipt.customer.email. Для этой системы
 * email дополнительно записывается в metadata, поэтому платёж можно связать
 * с заказом даже если онлайн-касса в YooKassa ещё не подключена.
 */
function extractPaymentEmail(array $payment)
{
    $metadata = isset($payment['metadata']) && is_array($payment['metadata'])
        ? $payment['metadata']
        : array();

    $candidates = array(
        isset($payment['receipt']['customer']['email'])
            ? $payment['receipt']['customer']['email']
            : null,
        isset($payment['receipt']['email'])
            ? $payment['receipt']['email']
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
 * Внутренний order_id из metadata платежа.
 */
function extractOrderId(array $payment)
{
    if (!isset($payment['metadata']['order_id']) || !is_string($payment['metadata']['order_id'])) {
        return null;
    }

    $orderId = trim($payment['metadata']['order_id']);
    return $orderId !== '' ? $orderId : null;
}

/**
 * URL, на который YooKassa вернёт покупателя после оплаты.
 */
function extractTicketId(array $payment)
{
    if (!isset($payment['metadata']['ticket_id']) || !is_string($payment['metadata']['ticket_id'])) {
        return null;
    }

    $ticketId = trim($payment['metadata']['ticket_id']);
    return $ticketId !== '' ? $ticketId : null;
}

function applicationUrl()
{
    $configured = rtrim(configValue('APP_URL', ''), '/');
    if ($configured !== '') {
        return $configured;
    }

    // Безопасный fallback для текущего домена. Для другого домена задайте APP_URL.
    return 'https://xn--d1abb3ahthn.tsconnect.ru';
}

/**
 * Выполняет запрос к YooKassa API.
 *
 * Возвращает массив с ok, status и data. Сначала используется cURL, а затем
 * stream-клиент как fallback для хостингов без расширения cURL.
 */
function yookassaRequest($httpMethod, $path, $body = null, $idempotenceKey = null)
{
    $shopId = configValue('YOOKASSA_SHOP_ID', '432630');
    $secretKey = configValue('YOOKASSA_SECRET_KEY', 'test_36Ni_S6TcEKorKmgquvjI1pm_uAaOvHAizFDK3XmA3o');

    if ($shopId === '' || $secretKey === '') {
        error_log('nedofest API: YOOKASSA_SHOP_ID or YOOKASSA_SECRET_KEY is not configured');
        return array(
            'ok' => false,
            'status' => 500,
            'error' => 'YooKassa credentials are not configured',
        );
    }

    $apiUrl = rtrim(configValue('YOOKASSA_API_URL', 'https://api.yookassa.ru'), '/');
    $url = $apiUrl . '/' . ltrim($path, '/');
    $jsonBody = $body === null
        ? null
        : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($body !== null && $jsonBody === false) {
        error_log('nedofest API: cannot encode YooKassa request');
        return array(
            'ok' => false,
            'status' => 500,
            'error' => 'Cannot encode YooKassa request',
        );
    }

    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($shopId . ':' . $secretKey),
    );

    if ($idempotenceKey !== null) {
        $headers[] = 'Idempotence-Key: ' . $idempotenceKey;
    }

    $responseBody = false;
    $statusCode = 0;
    $transportError = '';

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        $options = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($httpMethod),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $shopId . ':' . $secretKey,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        );

        if ($jsonBody !== null) {
            $options[CURLOPT_POSTFIELDS] = $jsonBody;
        }

        curl_setopt_array($curl, $options);
        $responseBody = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $transportError = (string) curl_error($curl);
        curl_close($curl);
    } else {
        $context = stream_context_create(array(
            'http' => array(
                'method' => strtoupper($httpMethod),
                'header' => implode("\r\n", $headers),
                'content' => $jsonBody === null ? '' : $jsonBody,
                'timeout' => 30,
                'ignore_errors' => true,
            ),
        ));

        $responseBody = @file_get_contents($url, false, $context);
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                    $statusCode = (int) $matches[1];
                    break;
                }
            }
        }

        if ($responseBody === false) {
            $transportError = 'file_get_contents failed';
        }
    }

    if ($responseBody === false) {
        error_log('nedofest API: YooKassa transport error: ' . $transportError);
        return array(
            'ok' => false,
            'status' => 502,
            'error' => 'YooKassa is unavailable',
        );
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        error_log('nedofest API: invalid YooKassa response, HTTP ' . $statusCode);
        return array(
            'ok' => false,
            'status' => 502,
            'error' => 'Invalid YooKassa response',
        );
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        $description = isset($decoded['description']) ? $decoded['description'] : 'unknown error';
        error_log('nedofest API: YooKassa HTTP ' . $statusCode . ': ' . $description);
        return array(
            'ok' => false,
            'status' => $statusCode,
            'error' => 'YooKassa rejected the request',
        );
    }

    return array(
        'ok' => true,
        'status' => $statusCode,
        'data' => $decoded,
    );
}

/**
 * Формирует объект создания платежа.
 */
function buildPaymentPayload($name, $email, $orderId, $ticketId)
{
    $returnUrl = applicationUrl() . '/ticket.php?order_id='
        . rawurlencode($orderId) . '&ticket_id=' . rawurlencode($ticketId);

    $payload = array(
        'amount' => array(
            'value' => configuredPrice(),
            'currency' => 'RUB',
        ),
        'capture' => true,
        'description' => 'Билет НедоFEST, ' . $ticketId,
        'confirmation' => array(
            'type' => 'redirect',
            'return_url' => $returnUrl,
        ),
        'metadata' => array(
            'order_id' => $orderId,
            'ticket_id' => $ticketId,
            'email' => $email,
        ),
    );

    // Чек включается явно, потому что для него в личном кабинете YooKassa
    // должна быть подключена онлайн-касса и настроена система налогообложения.
    if (configBool('YOOKASSA_RECEIPT_ENABLED', false)) {
        $vatCode = (int) configValue('YOOKASSA_VAT_CODE', '1');
        if ($vatCode < 1 || $vatCode > 12) {
            $vatCode = 1;
        }

        $payload['receipt'] = array(
            'customer' => array(
                'full_name' => $name,
                'email' => $email,
            ),
            'items' => array(
                array(
                    'description' => 'Билет НедоFEST',
                    'quantity' => '1.00',
                    'amount' => array(
                        'value' => configuredPrice(),
                        'currency' => 'RUB',
                    ),
                    'vat_code' => $vatCode,
                    'payment_mode' => 'full_prepayment',
                    'payment_subject' => 'service',
                ),
            ),
        );

        $taxSystemCode = configValue('YOOKASSA_TAX_SYSTEM_CODE', '');
        if ($taxSystemCode !== '' && ctype_digit($taxSystemCode)) {
            $payload['receipt']['tax_system_code'] = (int) $taxSystemCode;
        }
    }

    return $payload;
}

/**
 * Проверяет наличие строки по одному из заранее разрешённых столбцов.
 */
function rowExistsByColumn($db, $column, $value)
{
    $allowedColumns = array('order_id', 'payment_id', 'email');
    if (!in_array($column, $allowedColumns, true)) {
        return false;
    }

    $statement = mysqli_prepare(
        $db,
        'SELECT 1 FROM `tmpuser` WHERE `' . $column . '` = ? LIMIT 1'
    );
    if (!$statement) {
        error_log('nedofest API: cannot prepare row lookup: ' . mysqli_error($db));
        return false;
    }

    mysqli_stmt_bind_param($statement, 's', $value);
    if (!mysqli_stmt_execute($statement)) {
        error_log('nedofest API: cannot execute row lookup: ' . mysqli_stmt_error($statement));
        mysqli_stmt_close($statement);
        return false;
    }

    mysqli_stmt_store_result($statement);
    $exists = mysqli_stmt_num_rows($statement) > 0;
    mysqli_stmt_free_result($statement);
    mysqli_stmt_close($statement);

    return $exists;
}

/**
 * Проверяет строку, которую разрешено обновить email-fallback режимом.
 */
function rowExistsByEmailForPayment($db, $email, $paymentId)
{
    $statement = mysqli_prepare(
        $db,
        'SELECT 1 FROM `tmpuser` WHERE `email` = ? '
        . "AND (`payment_id` IS NULL OR `payment_id` = '' OR `payment_id` = ?) LIMIT 1"
    );
    if (!$statement) {
        error_log('nedofest API: cannot prepare email row lookup: ' . mysqli_error($db));
        return false;
    }

    mysqli_stmt_bind_param($statement, 'ss', $email, $paymentId);
    if (!mysqli_stmt_execute($statement)) {
        error_log('nedofest API: cannot execute email row lookup: ' . mysqli_stmt_error($statement));
        mysqli_stmt_close($statement);
        return false;
    }

    mysqli_stmt_store_result($statement);
    $exists = mysqli_stmt_num_rows($statement) > 0;
    mysqli_stmt_free_result($statement);
    mysqli_stmt_close($statement);

    return $exists;
}

/**
 * Помечает строки по order_id/payment_id/email и сохраняет payment_id.
 */
function markRowsPaid($db, $column, $value, $paymentId, $emailFallback = false)
{
    $allowedColumns = array('order_id', 'payment_id');
    if ($emailFallback) {
        $allowedColumns[] = 'email';
    }

    if (!in_array($column, $allowedColumns, true)) {
        return array('ok' => false, 'found' => false, 'updated_rows' => 0);
    }

    if ($column === 'email' && $emailFallback) {
        // В fallback-режиме не затрагиваем строку, уже связанную с другим
        // платежом. В новой схеме обычно срабатывает order_id.
        $sql = "UPDATE `tmpuser` SET `payed` = 1, `payment_id` = ? "
            . "WHERE `email` = ? AND (`payment_id` IS NULL OR `payment_id` = '' OR `payment_id` = ?)";
        $statement = mysqli_prepare($db, $sql);
        if (!$statement) {
            error_log('nedofest API: cannot prepare email payment update: ' . mysqli_error($db));
            return array('ok' => false, 'found' => false, 'updated_rows' => 0);
        }

        mysqli_stmt_bind_param($statement, 'sss', $paymentId, $value, $paymentId);
    } else {
        $statement = mysqli_prepare(
            $db,
            'UPDATE `tmpuser` SET `payed` = 1, `payment_id` = ? WHERE `' . $column . '` = ?'
        );
        if (!$statement) {
            error_log('nedofest API: cannot prepare payment update: ' . mysqli_error($db));
            return array('ok' => false, 'found' => false, 'updated_rows' => 0);
        }

        mysqli_stmt_bind_param($statement, 'ss', $paymentId, $value);
    }

    if (!mysqli_stmt_execute($statement)) {
        error_log('nedofest API: cannot mark payment as paid: ' . mysqli_stmt_error($statement));
        mysqli_stmt_close($statement);
        return array('ok' => false, 'found' => false, 'updated_rows' => 0);
    }

    $updatedRows = mysqli_stmt_affected_rows($statement);
    mysqli_stmt_close($statement);

    $found = $updatedRows > 0;
    if (!$found) {
        $found = ($column === 'email' && $emailFallback)
            ? rowExistsByEmailForPayment($db, $value, $paymentId)
            : rowExistsByColumn($db, $column, $value);
    }

    return array(
        'ok' => true,
        'found' => $found,
        'updated_rows' => $updatedRows,
    );
}

/**
 * Обновляет локальный заказ после подтверждённого платежа.
 */
function markPaymentPaid($db, $paymentId, $orderId, $email)
{
    if ($orderId !== null) {
        $result = markRowsPaid($db, 'order_id', $orderId, $paymentId);
        if (!$result['ok']) {
            return $result;
        }
        if ($result['found']) {
            $result['matched_by'] = 'order_id';
            return $result;
        }
    }

    // Позволяет обработать платежи, созданные до появления order_id.
    $result = markRowsPaid($db, 'payment_id', $paymentId, $paymentId);
    if (!$result['ok']) {
        return $result;
    }
    if ($result['found']) {
        $result['matched_by'] = 'payment_id';
        return $result;
    }

    if ($email !== null) {
        $result = markRowsPaid($db, 'email', $email, $paymentId, true);
        if ($result['ok'] && $result['found']) {
            $result['matched_by'] = 'email';
            return $result;
        }
    }

    return array(
        'ok' => true,
        'found' => false,
        'updated_rows' => 0,
    );
}

/**
 * Обрабатывает webhook YooKassa.
 *
 * Важный момент: тело webhook не считается доказательством оплаты. Платёж
 * повторно запрашивается по API YooKassa, после чего проверяются id, статус,
 * признак paid и сумма.
 */
function handlePaymentWebhook($payload, $method)
{
    $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
    if ($requestMethod !== 'POST') {
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

    $event = isset($payload['event']) && is_string($payload['event'])
        ? $payload['event']
        : $method;
    $successEvents = array('payment.succeeded', 'payment.success');

    if (!in_array($event, $successEvents, true)) {
        // Нерелевантное событие подтверждаем, чтобы YooKassa не повторяла его.
        respondJson(200, array(
            'ok' => true,
            'handled' => false,
            'event' => $event,
        ));
    }

    $notificationObject = isset($payload['object']) && is_array($payload['object'])
        ? $payload['object']
        : array();
    $paymentId = isset($notificationObject['id']) && is_string($notificationObject['id'])
        ? trim($notificationObject['id'])
        : '';

    if ($paymentId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $paymentId)) {
        respondJson(400, array(
            'ok' => false,
            'error' => 'Payment id is missing or invalid',
        ));
    }

    // Получаем канонический объект из YooKassa, а не доверяем присланному JSON.
    $verifiedResponse = yookassaRequest(
        'GET',
        '/v3/payments/' . rawurlencode($paymentId)
    );

    if (!$verifiedResponse['ok']) {
        respondJson(502, array(
            'ok' => false,
            'error' => 'Cannot verify payment in YooKassa',
        ));
    }

    $payment = $verifiedResponse['data'];
    if (!isset($payment['id']) || $payment['id'] !== $paymentId) {
        respondJson(502, array(
            'ok' => false,
            'error' => 'YooKassa returned another payment id',
        ));
    }

    if (!isset($payment['status']) || $payment['status'] !== 'succeeded' ||
        (isset($payment['paid']) && $payment['paid'] !== true)) {
        // 5xx заставит YooKassa повторить webhook, если API ещё не успел
        // показать окончательный статус.
        respondJson(502, array(
            'ok' => false,
            'error' => 'Payment is not confirmed as succeeded',
            'status' => isset($payment['status']) ? $payment['status'] : null,
        ));
    }

    $amount = isset($payment['amount']) && is_array($payment['amount'])
        ? $payment['amount']
        : array();
    if (!isset($amount['currency']) || $amount['currency'] !== 'RUB' ||
        !isset($amount['value']) || !amountsEqual($amount['value'], configuredPrice())) {
        respondJson(400, array(
            'ok' => false,
            'error' => 'Payment amount or currency is invalid',
        ));
    }

    $email = extractPaymentEmail($payment);
    if ($email === null) {
        respondJson(422, array(
            'ok' => false,
            'error' => 'Customer email is missing in verified payment',
        ));
    }

    $orderId = extractOrderId($payment);
    $ticketId = extractTicketId($payment);
    $db = connectDatabase();
    if (!$db) {
        respondJson(500, array(
            'ok' => false,
            'error' => 'Database connection failed',
        ));
    }

    if (!ensurePaymentColumns($db)) {
        mysqli_close($db);
        respondJson(500, array(
            'ok' => false,
            'error' => 'Payment columns are not available in database',
        ));
    }

    $result = markPaymentPaid($db, $paymentId, $orderId, $email);
    mysqli_close($db);

    if (!$result['ok']) {
        respondJson(500, array(
            'ok' => false,
            'error' => 'Database query failed',
        ));
    }

    if (!$result['found']) {
        // Если заказ ещё не появился в БД, пусть YooKassa повторит webhook.
        respondJson(500, array(
            'ok' => false,
            'error' => 'Local order was not found',
            'payment_id' => $paymentId,
            'email' => $email,
        ));
    }

    respondJson(200, array(
        'ok' => true,
        'handled' => true,
        'event' => $event,
        'payment_id' => $paymentId,
        'order_id' => $orderId,
        'ticket_id' => $ticketId,
        'email' => $email,
        'payed' => 1,
        'updated_rows' => $result['updated_rows'],
        'matched_by' => isset($result['matched_by']) ? $result['matched_by'] : null,
    ));
}

/**
 * Создаёт локальный заказ, платёж YooKassa и отправляет покупателя на оплату.
 */
function createPayment()
{
    $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
    if ($requestMethod !== 'POST') {
        respondJson(405, array(
            'ok' => false,
            'error' => 'Payment creation accepts POST requests only',
        ));
    }

    $name = isset($_POST['name']) ? trim((string) $_POST['name']) : '';
    $email = isset($_POST['email']) ? trim((string) $_POST['email']) : '';

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respondJson(400, array(
            'ok' => false,
            'error' => 'Name and valid email are required',
        ));
    }

    $db = connectDatabase();
    if (!$db) {
        respondJson(500, array(
            'ok' => false,
            'error' => 'Database connection failed',
        ));
    }

    if (!ensurePaymentColumns($db)) {
        mysqli_close($db);
        respondJson(500, array(
            'ok' => false,
            'error' => 'Payment columns are not available in database',
        ));
    }

    $orderId = generateOrderId();
    $ticketId = generateTicketId();
    $datetime = date('d.m.Y H:i:s');
    $statement = mysqli_prepare(
        $db,
        'INSERT INTO `tmpuser` (`name`, `email`, `datetime`, `payed`, `order_id`, `payment_id`, `ticket_id`) '
        . 'VALUES (?, ?, ?, 0, ?, NULL, ?)'
    );

    if (!$statement) {
        error_log('nedofest API: cannot prepare order insert: ' . mysqli_error($db));
        mysqli_close($db);
        respondJson(500, array(
            'ok' => false,
            'error' => 'Database query failed',
        ));
    }

    mysqli_stmt_bind_param($statement, 'sssss', $name, $email, $datetime, $orderId, $ticketId);
    if (!mysqli_stmt_execute($statement)) {
        error_log('nedofest API: cannot save order: ' . mysqli_stmt_error($statement));
        mysqli_stmt_close($statement);
        mysqli_close($db);
        respondJson(500, array(
            'ok' => false,
            'error' => 'Database query failed',
        ));
    }
    mysqli_stmt_close($statement);
    mysqli_close($db);

    $idempotenceKey = generateOrderId();
    $paymentResponse = yookassaRequest(
        'POST',
        '/v3/payments',
        buildPaymentPayload($name, $email, $orderId, $ticketId),
        $idempotenceKey
    );

    if (!$paymentResponse['ok']) {
        respondJson(502, array(
            'ok' => false,
            'error' => 'Cannot create payment in YooKassa',
            'order_id' => $orderId,
        ));
    }

    $payment = $paymentResponse['data'];
    $paymentId = isset($payment['id']) && is_string($payment['id'])
        ? $payment['id']
        : '';
    $confirmationUrl = isset($payment['confirmation']['confirmation_url'])
        && is_string($payment['confirmation']['confirmation_url'])
        ? $payment['confirmation']['confirmation_url']
        : '';

    if ($paymentId === '' || $confirmationUrl === '') {
        error_log('nedofest API: YooKassa response has no payment id or confirmation URL');
        respondJson(502, array(
            'ok' => false,
            'error' => 'Invalid payment data from YooKassa',
            'order_id' => $orderId,
        ));
    }

    $db = connectDatabase();
    if (!$db) {
        respondJson(500, array(
            'ok' => false,
            'error' => 'Database connection failed while saving payment id',
            'payment_id' => $paymentId,
        ));
    }

    $statement = mysqli_prepare(
        $db,
        'UPDATE `tmpuser` SET `payment_id` = ? WHERE `order_id` = ?'
    );
    if (!$statement) {
        error_log('nedofest API: cannot prepare payment id update: ' . mysqli_error($db));
        mysqli_close($db);
        respondJson(500, array(
            'ok' => false,
            'error' => 'Database query failed while saving payment id',
            'payment_id' => $paymentId,
        ));
    }

    mysqli_stmt_bind_param($statement, 'ss', $paymentId, $orderId);
    if (!mysqli_stmt_execute($statement)) {
        error_log('nedofest API: cannot save payment id: ' . mysqli_stmt_error($statement));
        mysqli_stmt_close($statement);
        mysqli_close($db);
        respondJson(500, array(
            'ok' => false,
            'error' => 'Database query failed while saving payment id',
            'payment_id' => $paymentId,
        ));
    }

    mysqli_stmt_close($statement);
    mysqli_close($db);

    // Обычная HTML-форма должна сразу перейти на страницу оплаты YooKassa.
    header('Location: ' . $confirmationUrl, true, 303);
    exit;
}

if (!defined('NEDOFEST_LIBRARY_ONLY')) {
    $method = isset($_GET['method']) && is_string($_GET['method'])
        ? $_GET['method']
        : '';

    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody, true);
    $isPaymentWebhook = in_array($method, array('payment.success', 'payment.succeeded'), true)
        || (is_array($payload) && isset($payload['event']));

    if ($isPaymentWebhook) {
        handlePaymentWebhook($payload, $method);
    }

    switch ($method) {
        case 'create_payment':
        case 'tmpuser':
            createPayment();
            break;

        default:
            http_response_code(404);
            exit('Not Found');
    }
}
?>