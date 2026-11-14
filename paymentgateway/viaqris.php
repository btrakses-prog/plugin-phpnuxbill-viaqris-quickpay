<?php

/**
 * PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *
 * Payment Gateway viaqris.gmcloud.xx.kg
 **/


/** Read new ViaQRIS settings, falling back to legacy RAKAN QRIS keys. */
function viaqris_config_value($key)
{
    global $config;
    $newKey = 'viaqris_' . $key;
    $oldKey = 'rakanqris_' . $key;
    return $config[$newKey] ?? ($config[$oldKey] ?? '');
}

function viaqris_validate_config()
{
    global $config;
    if (empty(viaqris_config_value('api_url'))) {
        sendTelegram("viaqris payment gateway not configured");
        r2(U . 'order/package', 'w', Lang::T("Admin has not yet setup viaqris payment gateway, please tell admin"));
    }
}

function viaqris_show_config()
{
    global $ui, $config;
    $ui->assign('_title', 'ViaQRIS - Payment Gateway');
    $ui->display('viaqris_v191.tpl');
}

function viaqris_save_config()
{
    global $admin, $_L;
    $viaqris_merchant = _post('viaqris_merchant');
    $viaqris_api_key = _post('viaqris_api_key');
    $viaqris_api_url = _post('viaqris_api_url');
    $viaqris_unique_min = (int)_post('viaqris_unique_min');
    $viaqris_unique_max = (int)_post('viaqris_unique_max');
    // Invoice branding settings shared with the standalone payment app.
    $brandSettings = [
        'viaqris_invoice_company' => trim((string)_post('viaqris_invoice_company')),
        'viaqris_invoice_title' => trim((string)_post('viaqris_invoice_title')),
        'viaqris_invoice_description' => trim((string)_post('viaqris_invoice_description')),
    ];
    // WhatsApp support contact used by the standalone payment page.
    $waNumber = preg_replace('/\\D+/', '', (string)_post('viaqris_support_whatsapp'));
    if ($waNumber !== '' && (strlen($waNumber) < 10 || strlen($waNumber) > 15)) {
        r2(U . 'paymentgateway/viaqris', 'e', 'Nomor WhatsApp admin harus 10–15 digit, gunakan format internasional tanpa tanda +.');
    }
    $brandSettings['viaqris_support_whatsapp'] = $waNumber;
    $waMessage = trim((string)_post('viaqris_support_message'));
    $brandSettings['viaqris_support_message'] = $waMessage !== '' ? mb_substr($waMessage, 0, 500) : 'Halo Admin, saya mengalami kendala pembayaran QRIS. Mohon bantuannya. ID Transaksi: [ID_TRANSAKSI]';

    if (!empty($_POST['viaqris_remove_logo'])) { $brandSettings['viaqris_invoice_logo'] = ''; }
    if (!empty($_FILES['viaqris_invoice_logo_file']['tmp_name']) && is_uploaded_file($_FILES['viaqris_invoice_logo_file']['tmp_name'])) {
        $file = $_FILES['viaqris_invoice_logo_file'];
        if ((int)$file['size'] > 512000) { r2(U . 'paymentgateway/viaqris', 'e', 'Ukuran logo maksimal 500 KB.'); }
        $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : '';
        $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) { r2(U . 'paymentgateway/viaqris', 'e', 'Format logo harus PNG, JPG, atau WEBP.'); }
        $brandSettings['viaqris_invoice_logo'] = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file['tmp_name']));
    }
    if ($viaqris_unique_min < 1 || $viaqris_unique_max < $viaqris_unique_min || $viaqris_unique_max > 999999) {
        r2(U . 'paymentgateway/viaqris', 'e', 'Rentang kode unik tidak valid. Minimum minimal 1, maksimum harus lebih besar/sama dengan minimum dan tidak boleh lebih dari 999999.');
    }
    $d = ORM::for_table('tbl_appconfig')->where('setting', 'viaqris_merchant')->find_one();
    if ($d) {
        $d->value = $viaqris_merchant;
        $d->save();
    } else {
        $d = ORM::for_table('tbl_appconfig')->create();
        $d->setting = 'viaqris_merchant';
        $d->value = $viaqris_merchant;
        $d->save();
    }
    $d = ORM::for_table('tbl_appconfig')->where('setting', 'viaqris_api_key')->find_one();
    if ($d) {
        $d->value = $viaqris_api_key;
        $d->save();
    } else {
        $d = ORM::for_table('tbl_appconfig')->create();
        $d->setting = 'viaqris_api_key';
        $d->value = $viaqris_api_key;
        $d->save();
    }

    $d = ORM::for_table('tbl_appconfig')->where('setting', 'viaqris_api_url')->find_one();
    if ($d) {
        $d->value = $viaqris_api_url;
        $d->save();
    } else {
        $d = ORM::for_table('tbl_appconfig')->create();
        $d->setting = 'viaqris_api_url';
        $d->value = $viaqris_api_url;
        $d->save();
    }
    

    foreach (['viaqris_unique_min' => $viaqris_unique_min, 'viaqris_unique_max' => $viaqris_unique_max] as $setting => $value) {
        $d = ORM::for_table('tbl_appconfig')->where('setting', $setting)->find_one();
        if ($d) { $d->value = (string)$value; $d->save(); }
        else { $d = ORM::for_table('tbl_appconfig')->create(); $d->setting = $setting; $d->value = (string)$value; $d->save(); }
    }

    foreach ($brandSettings as $setting => $value) {
        $d = ORM::for_table('tbl_appconfig')->where('setting', $setting)->find_one();
        if ($d) { $d->value = $value; $d->save(); }
        else { $d = ORM::for_table('tbl_appconfig')->create(); $d->setting = $setting; $d->value = $value; $d->save(); }
    }

    _log('[' . $admin['username'] . ']: viaqris ' . Lang::T('Settings_Saved_Successfully'), 'Admin', $admin['id']);

    r2(U . 'paymentgateway/viaqris', 's', Lang::T('Settings_Saved_Successfully'));
}


function viaqris_create_transaction($trx, $user)
{
    global $config;

    $timestamp = round(microtime(true) * 1000);
    $expiry = date('c', strtotime('+60 minutes')); // Masa berlaku QRIS 30 menit

    $data = [
        'kunciEntri' => "trx-{$trx['id']}-{$timestamp}",
        'dataBaru' => [
            'penjual' => viaqris_config_value('merchant'),
            'jenis' => "jasa",
            'tanggal' => date('c'),
            'nama' => $user['fullname'],
            'email' => (empty($user['email'])) ? 'wa' . preg_replace('/[^0-9]/', '', (string)$user['phonenumber']) . '@customer.invalid' : $user['email'],
            'telepon' => $user['phonenumber'],
            'pesan' => "Pembayaran Paket: {$trx['plan_name']}",
            'nama_transaksi' => $trx['plan_name'],
            'harga_transaksi' => (string)$trx['price'],
            'metode_pembayaran_transaksi' => "QRIS",
            'status_pembayaran_transaksi' => "Belum Bayar",
            'kedaluwarsa' => $expiry,
        ]
    ];

    $headers = [
        'Content-Type: application/json',
        'X-API-Token: ' . viaqris_config_value('api_key')
    ];

    $response = Http::postJsonData(viaqris_config_value('api_url'), $data, $headers);
    $result = json_decode($response, true);

    $kunciEntri = "trx-{$trx['id']}-{$timestamp}";
    $pesanBerhasil = "Data untuk '{$kunciEntri}' berhasil diproses!";

    if (
        ($result && isset($result['success']) && $result['success']) ||
        ($result && isset($result['message']) && $result['message'] === $pesanBerhasil)
    ) {
        // permintaan kedua ke endpoint ?id=[ID_TRANSAKSI]
        $idTransaksi = $kunciEntri;
        $urlDetail = viaqris_config_value('api_url') . '?id=' . urlencode($idTransaksi);
        $responseDetail = Http::getData($urlDetail, $headers);
        $resultDetail = json_decode($responseDetail, true);

        // proses jika sukses atau pesan sesuai
        $d = ORM::for_table('tbl_payment_gateway')
            ->where('id', $trx['id'])
            ->where('status', 1)
            ->find_one();

        $d->gateway_trx_id = $idTransaksi;
        $d->pg_url_payment = U . 'plugin/viaqris_show_payment&id=' . $d['id'];
        $d->pg_request = json_encode($resultDetail);
        $d->expired_date = date('Y-m-d H:i:s', strtotime($expiry));
        $d->save();
        r2(U . "plugin/viaqris_show_payment&id=" . $d['id'], 's', Lang::T("Create Transaction Success"));
    } else {
        $errorMessage = $result['message'] ?? 'Kesalahan tidak diketahui dari ViaQRIS.';
        sendTelegram("viQris payment failed\n\n" . json_encode($errorMessage, JSON_PRETTY_PRINT));
        r2(U . 'order/package', 'e', Lang::T("Failed to create transaction."));
    }
}


function viaqris_get_status($trx, $user)
{
    global $config;

    
    $api_url = viaqris_config_value('api_url') . '?id=' . urlencode($trx['gateway_trx_id']);
    $headers = [
        'Content-Type: application/json',
        'X-API-Token: ' . viaqris_config_value('api_key')
    ];

    $raw_response = Http::getData($api_url, $headers);
    if ($raw_response === false) {
        sendTelegram("viaqris payment status failed - HTTP request failed for TRX ID: {$trx['gateway_trx_id']}");
        r2(U . "order/view/" . $trx['id'], 'w', Lang::T("Failed to connect to payment gateway. Please try again."));
    }

    $api_response = json_decode($raw_response, true);
    if (!is_array($api_response) || !isset($api_response['id'])) {
        sendTelegram("viaqris payment status failed - Invalid API response structure for TRX ID: {$trx['gateway_trx_id']}:\n\n" . $raw_response);
        r2(U . "order/view/" . $trx['id'], 'w', Lang::T("Failed to get transaction status. Invalid API response format."));
    }

    $result = $api_response;
    $status_pembayaran_api = $result['status_pembayaran_transaksi'] ?? 'Unknown';
    if ($status_pembayaran_api == 'Belum Bayar') {
        r2(U . "order/view/" . $trx['id'], 'w', Lang::T("Transaction still unpaid."));
    } else if (in_array($status_pembayaran_api, ['Berhasil']) && $trx['status'] != 2) {
        if (!Package::rechargeUser($user['id'], $trx['routers'], $trx['plan_id'], $trx['gateway'],  $result['nama'])) {
            sendTelegram("viaqris payment status - Failed to activate package for user {$user['id']} TRX ID: {$trx['id']}");
            r2(U . "order/view/" . $trx['id'], 'd', Lang::T("Failed to activate your Package, try again later."));
        }

        
        $trx->pg_paid_response = json_encode($result);
        $trx->payment_method = 'ViaQRIS';
        $trx->payment_channel = 'QRIS';
        // $paid_at_timestamp = isset($result['tanggal']) ? strtotime($result['tanggal']) : time();
        $trx->paid_date = date('Y-m-d H:i:s');
        $trx->status = 2; // Status: Paid
        $trx->save();

        r2(U . "order/view/" . $trx['id'], 's', Lang::T("Transaction has been paid."));
    } else if (in_array($status_pembayaran_api, ['Dibatalkan', 'Kedaluwarsa'])) {
        $trx->pg_paid_response = json_encode($result);
        $trx->status = 3; // Status: Cancelled/Expired
        $trx->save();
        r2(U . "order/view/" . $trx['id'], 'd', Lang::T("Transaction expired or cancelled."));
    } else if ($trx['status'] == 2) {
        r2(U . "order/view/" . $trx['id'], 'd', Lang::T("Transaction has been paid."));
    } else {
        sendTelegram("viaqris payment status - Unhandled API status for TRX ID: {$trx['gateway_trx_id']}: {$status_pembayaran_api}\n\n" . json_encode($result, JSON_PRETTY_PRINT));
        r2(U . "order/view/" . $trx['id'], 'w', Lang::T("Transaction status is currently: ") . $status_pembayaran_api);
    }
}

// callback
function viaqris_payment_notification()
{
    global $config;
    
    $raw_payload = file_get_contents('php://input');
    $payload = json_decode($raw_payload, true);

    if (!is_array($payload) || !isset($payload['transactionId']) || !isset($payload['status']) || !isset($payload['amount'])) {
        http_response_code(400);
        echo json_encode(['message' => 'Invalid webhook payload. Missing required fields.']);
        error_log("ViaQRIS Webhook: Invalid payload received. Raw: " . $raw_payload);
        exit();
    }

    $transaction_id_api = $payload['transactionId'];
    $status_api = $payload['status'];
    $amount_api = $payload['amount'];

    if ($status_api === 'Berhasil') {
        $trx = ORM::for_table('tbl_payment_gateway')
            ->where('gateway_trx_id', $transaction_id_api)
            ->find_one();

        if ($trx) {
            if ($trx['status'] == 2) {
                http_response_code(200);
                echo json_encode(['message' => 'Webhook received, transaction already paid.']);
                exit();
            }

            $user = ORM::for_table('tbl_customers')->where('username', $trx['username'])->find_one();

            if ($user) {
                if (Package::rechargeUser($user['id'], $trx['routers'], $trx['plan_id'], $trx['gateway'], $payload['senderName'] ?? $payload['message'])) {
                    $trx->pg_paid_response = json_encode($payload);
                    $trx->payment_method = 'ViaQRIS';
                    $trx->payment_channel = 'QRIS';
                    // $paid_at_timestamp = isset($payload['transactionDate']) ? strtotime($payload['transactionDate']) : time();
                    $trx->paid_date = date('Y-m-d H:i:s');
                    $trx->trx_invoice = "INV-" . (Package::_raid() - 1); 
                    $trx->status = 2;
                    $trx->save();

                    http_response_code(200);
                    echo json_encode(['message' => 'Webhook received and transaction updated successfully.']);
                    exit();

                } else {
                    Message::sendTelegram("viaqris_payment_notification: Activation FAILED for TRX ID {$transaction_id_api}: \n\n" . json_encode($payload, JSON_PRETTY_PRINT));
                    http_response_code(500);
                    echo json_encode(['message' => 'Failed to activate user package.']);
                    exit();
                }
            } else {
                http_response_code(404);
                echo json_encode(['message' => 'User associated with transaction not found.']);
                error_log("ViaQRIS Webhook: User for TRX ID {$transaction_id_api} not found. Username: {$trx['username']}");
                exit();
            }
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'Transaction not found in local database.']);
            error_log("ViaQRIS Webhook: Transaction ID {$transaction_id_api} not found locally.");
            exit();
        }
    } else {
        http_response_code(200);
        echo json_encode(['message' => "Webhook received, status '{$status_api}' not processed for update."]);
        error_log("ViaQRIS Webhook: Unhandled status '{$status_api}' for TRX ID {$transaction_id_api}.");
        exit();
    }
}

function viaqris_get_server()
{
}