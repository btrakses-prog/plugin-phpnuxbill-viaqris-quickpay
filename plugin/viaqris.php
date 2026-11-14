<?php

function viaqris_show_payment(){
    global $ui;
    //get trx_id
    $id = _get('id');
    if(empty($id)){
        r2(U . "order/package/", 'e', 'Tidak ada ID Transaksi');
    }
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    $isQuickPaymentSession = ((int)($_SESSION['viaqris_qp_active_trx'] ?? 0) === (int)$id);
    if (!$isQuickPaymentSession) {
        _auth();
        $user = User::_info();
        $ui->assign('_user', $user);
    } else {
        $ui->assign('_user', null);
    }

    $trx = ORM::for_table('tbl_payment_gateway')->find_one($id);

    if(empty($trx)){
        r2(U . "order/package/", 'e', 'Transaksi tidak ditemukan');
    }

    $json = json_decode($trx['pg_request'], true);

    if (!is_array($json) || !isset($json['url_pambayaran'])) {
        r2(U . "order/package/", 'e', 'Format data pembayaran tidak valid.');
    }

    $static_instructions = [
        [
            'title' => 'Pembayaran via QRIS (Scan)',
            'steps' => [
                'Masuk ke aplikasi dompet digital Anda yang telah mendukung QRIS',
                'Pindai/Scan QR Code yang tersedia',
                'Akan muncul detail transaksi. Pastikan data transaksi sudah sesuai',
                'Selesaikan proses pembayaran Anda',
                'Transaksi selesai. Simpan bukti pembayaran Anda'
            ]
        ],
        [
            'title' => 'Pembayaran via QRIS (Mobile - Upload Gambar)',
            'steps' => [
                'Download QR Code pada halaman ini', // Mengacu pada tombol download yang baru kita buat
                'Masuk ke aplikasi dompet digital Anda yang telah mendukung QRIS',
                'Upload QR Code yang telah di download tadi',
                'Akan muncul detail transaksi. Pastikan data transaksi sudah sesuai',
                'Selesaikan proses pembayaran Anda',
                'Transaksi selesai. Simpan bukti pembayaran Anda'
            ]
        ]
    ];
    $ui->assign('instruction', $static_instructions);

    $qr_string = '';

    if(isset($json['url_pambayaran']) && !empty($json['url_pambayaran'])){
        $qr_string = str_replace('\/', '/', $json['url_pambayaran']);
    }

    $ui->assign('qr_string', $qr_string);
    $ui->assign('qr_url', $json['data']['qr_url'] ?? '');
    $ui->assign('checkout_url', $json['data']['checkout_url'] ?? '');
    $ui->assign('payment_name', $json['data']['payment_name'] ?? 'Pembayaran');
    $ui->assign('trxid', $id);
    // Gunakan waktu kedaluwarsa yang disimpan server, bukan timer browser.
    $expiryTimestamp = !empty($trx['expired_date']) ? strtotime($trx['expired_date']) : 0;
    $ui->assign('remaining_seconds', $expiryTimestamp > 0 ? max(0, $expiryTimestamp - time()) : 0);
    $ui->assign('_title', 'ViaQRIS Payment Gateway');
    $ui->display('viaqris_view_payment.tpl');
}


/**
 * Public Quick Payment: customer identifies an existing account by registered WhatsApp.
 * Route: index.php?_route=plugin/viaqris_quickpay
 * This creates a payment for the customer's current active package only.
 */
function viaqris_quickpay()
{
    global $ui;
    $ui->assign('_title', 'Pembayaran / Isi Ulang WiFi');
    $ui->assign('step', 'lookup');
    $ui->assign('error', '');
    $ui->assign('customer', null);
    $ui->assign('plan', null);

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $phone = viaqris_quickpay_normalize_phone(_post('phone'));
        if (strlen($phone) < 9 || strlen($phone) > 15) {
            $ui->assign('error', 'Nomor WhatsApp tidak valid. Gunakan nomor yang terdaftar di akun pelanggan.');
            $ui->display('viaqris_quickpay.tpl');
            return;
        }
        $attempts = (int)($_SESSION['viaqris_qp_attempts'] ?? 0);
        $last = (int)($_SESSION['viaqris_qp_attempt_time'] ?? 0);
        if (time() - $last > 600) $attempts = 0;
        if ($attempts >= 8) {
            $ui->assign('error', 'Terlalu banyak percobaan. Silakan tunggu 10 menit lalu coba kembali.');
            $ui->display('viaqris_quickpay.tpl');
            return;
        }
        $_SESSION['viaqris_qp_attempts'] = ++$attempts;
        $_SESSION['viaqris_qp_attempt_time'] = time();

        // Match common Indonesian formats: 08..., 628..., or +62... .
        $digits = preg_replace('/\D+/', '', $phone);
        $candidates = [$digits];
        if (strpos($digits, '62') === 0) $candidates[] = '0' . substr($digits, 2);
        if (strpos($digits, '0') === 0) $candidates[] = '62' . substr($digits, 1);
        $candidates = array_values(array_unique($candidates));
        $customer = null;
        foreach ($candidates as $candidate) {
            $customer = ORM::for_table('tbl_customers')->where('phonenumber', $candidate)->find_one();
            if ($customer) break;
        }
        if (!$customer || !in_array($customer['status'], ['Active', 'Inactive', 'Limited', 'Suspended'], true)) {
            $ui->assign('error', 'Data pelanggan tidak ditemukan atau akun tidak dapat melakukan pembayaran. Periksa nomor WhatsApp yang terdaftar.');
            $ui->display('viaqris_quickpay.tpl');
            return;
        }

        // Resume the customer's existing unpaid ViaQRIS session when its 1-hour window is still active.
        $pending = ORM::for_table('tbl_payment_gateway')->where('user_id', $customer['id'])
            ->where('gateway', 'viaqris')->where('status', 1)
            ->order_by_desc('id')->find_one();
        if ($pending && !empty($pending['expired_date']) && strtotime($pending['expired_date']) > time()
            && !empty($pending['gateway_trx_id']) && !empty($pending['pg_request'])) {
            if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
            $_SESSION['viaqris_qp_active_trx'] = (int)$pending['id'];
            r2(U . 'plugin/viaqris_show_payment&id=' . $pending['id'], 's', 'Melanjutkan sesi pembayaran sebelumnya. QRIS masih berlaku.');
            return;
        }
        if ($pending && (!empty($pending['expired_date']) && strtotime($pending['expired_date']) <= time())) {
            $pending->status = 3;
            $pending->save();
        }

        // Keep the existing/current package; do not offer package switching here.
        $recharge = ORM::for_table('tbl_user_recharges')
            ->where('customer_id', $customer['id'])
            ->order_by_desc('id')->find_one();
        if (!$recharge) {
            $ui->assign('error', 'Paket pelanggan belum ditemukan. Silakan hubungi admin.');
            $ui->display('viaqris_quickpay.tpl');
            return;
        }
        $plan = ORM::for_table('tbl_plans')->where('id', $recharge['plan_id'])->where('enabled', 1)->find_one();
        if (!$plan) {
            $ui->assign('error', 'Paket pelanggan tidak tersedia atau sudah dinonaktifkan.');
            $ui->display('viaqris_quickpay.tpl');
            return;
        }
        $router = ORM::for_table('tbl_routers')->where('name', $recharge['routers'])->where('enabled', 1)->find_one();
        if (!$router && (int)$plan['is_radius'] !== 1) {
            $ui->assign('error', 'Router paket tidak ditemukan atau tidak aktif. Silakan hubungi admin.');
            $ui->display('viaqris_quickpay.tpl');
            return;
        }
        $secret = (string)($GLOBALS['config']['viaqris_api_key'] ?? '');
        if ($secret === '') {
            $ui->assign('error', 'Payment gateway belum dikonfigurasi.');
            $ui->display('viaqris_quickpay.tpl');
            return;
        }
        $token = hash_hmac('sha256', $customer['id'].'|'.$recharge['id'].'|'.$phone, $secret);
        $_SESSION['viaqris_qp_lookup'] = [
            'customer_id' => (int)$customer['id'], 'recharge_id' => (int)$recharge['id'],
            'phone' => $phone, 'token' => $token, 'created' => time()
        ];
        $ui->assign('step', 'confirm');
        $ui->assign('customer', ['fullname' => $customer['fullname']]);
        $ui->assign('plan', ['name_plan' => $plan['name_plan'], 'price' => $plan['price']]);
        $ui->assign('confirm_token', $token);
    }
    $ui->display('viaqris_quickpay.tpl');
}

/** Create the payment transaction after the customer confirms the matched account. */
function viaqris_quickpay_create()
{
    global $config;
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $lookup = $_SESSION['viaqris_qp_lookup'] ?? null;
    $postedToken = (string)_post('confirm_token');
    if (!$lookup || empty($lookup['created']) || time() - (int)$lookup['created'] > 600 ||
        !hash_equals((string)$lookup['token'], $postedToken)) {
        r2(U . 'plugin/viaqris_quickpay', 'e', 'Sesi pencarian berakhir. Silakan mulai kembali.');
    }
    $customer = ORM::for_table('tbl_customers')->where('id', (int)$lookup['customer_id'])->find_one();
    $recharge = ORM::for_table('tbl_user_recharges')->where('id', (int)$lookup['recharge_id'])
        ->where('customer_id', (int)$lookup['customer_id'])->find_one();
    if (!$customer || !$recharge) r2(U . 'plugin/viaqris_quickpay', 'e', 'Data pelanggan atau paket tidak ditemukan.');

    $plan = ORM::for_table('tbl_plans')->where('id', $recharge['plan_id'])->where('enabled', 1)->find_one();
    if (!$plan) r2(U . 'plugin/viaqris_quickpay', 'e', 'Paket pelanggan tidak tersedia.');
    $router = ORM::for_table('tbl_routers')->where('name', $recharge['routers'])->where('enabled', 1)->find_one();
    if (!$router && (int)$plan['is_radius'] !== 1) r2(U . 'plugin/viaqris_quickpay', 'e', 'Router tidak tersedia.');

    // Avoid creating parallel unpaid QRIS requests for the same customer.
    $pending = ORM::for_table('tbl_payment_gateway')->where('user_id', $customer['id'])
        ->where('gateway', 'viaqris')->where('status', 1)->find_one();
    if ($pending && !empty($pending['expired_date']) && strtotime($pending['expired_date']) > time()) {
        r2(U . 'plugin/viaqris_show_payment&id=' . $pending['id'], 's', 'Anda masih memiliki QRIS yang aktif.');
    }
    if ($pending) { $pending->status = 3; $pending->save(); }

    $trx = ORM::for_table('tbl_payment_gateway')->create();
    $trx->username = $customer['username'];
    $trx->user_id = $customer['id'];
    $trx->gateway = 'viaqris';
    $trx->plan_id = $plan['id'];
    $trx->plan_name = $plan['name_plan'];
    $trx->routers_id = $router ? $router['id'] : 0;
    $trx->routers = $recharge['routers'];
    $trx->price = $plan['price'];
    $trx->created_date = date('Y-m-d H:i:s');
    $trx->status = 1;
    $trx->save();

    // Gateway expects the customer array. Dummy email is used only in the API request;
    // the actual customer email in tbl_customers is not modified.
    $user = $customer->as_array();
    if (empty($user['email']) || strpos($user['email'], '@') === false || $user['email'] === '1') {
        $user['email'] = 'customer' . (int)$customer['id'] . '@customer.invalid';
    }
    unset($_SESSION['viaqris_qp_lookup']);
    $_SESSION['viaqris_qp_active_trx'] = (int)$trx['id'];
    require_once __DIR__ . '/../paymentgateway/viaqris.php';
    viaqris_validate_config();
    viaqris_create_transaction($trx, $user);
}

function viaqris_quickpay_normalize_phone($phone)
{
    $phone = preg_replace('/[^0-9+]/', '', (string)$phone);
    return ltrim($phone, '+');
}


/** QuickPayment browser poll: callback updates tbl_payment_gateway; redirect when billing marks it paid. */
function viaqris_quickpay_status()
{
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)_get('id');
    if ($id < 1 || (int)($_SESSION['viaqris_qp_active_trx'] ?? 0) !== $id) {
        http_response_code(404);
        echo json_encode(['ok' => false]);
        exit;
    }
    $trx = ORM::for_table('tbl_payment_gateway')->where('id', $id)->where('gateway', 'viaqris')->find_one();
    if (!$trx) { http_response_code(404); echo json_encode(['ok' => false]); exit; }
    $paid = (int)$trx['status'] === 2;
    echo json_encode([
        'ok' => true,
        'status' => (int)$trx['status'],
        'paid' => $paid,
        'invoice' => $paid ? (U . 'order/view/' . $id) : null
    ]);
    exit;
}
