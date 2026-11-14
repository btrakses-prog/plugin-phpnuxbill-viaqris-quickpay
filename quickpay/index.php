<?php
declare(strict_types=1);
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) { http_response_code(503); exit('Aplikasi belum dikonfigurasi. Salin config.sample.php menjadi config.php.'); }
$cfg = require $configFile;
date_default_timezone_set($cfg['timezone'] ?? 'Asia/Jakarta');
session_name($cfg['session_name'] ?? 'VIAQRISSESSID');
session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']),'samesite'=>'Lax','path'=>'/']);
session_start();

function db(): PDO {
    static $pdo = null; global $cfg;
    if ($pdo) return $pdo;
    $d = $cfg['db'];
    $dsn = 'mysql:host='.$d['host'].';port='.(int)($d['port'] ?? 3306).';dbname='.$d['name'].';charset='.($d['charset'] ?? 'utf8mb4');
    return $pdo = new PDO($dsn, $d['user'], $d['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
}
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v): string { return 'Rp '.number_format((float)$v, 0, ',', '.'); }
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function check_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { http_response_code(419); exit('Sesi formulir berakhir. Muat ulang halaman.'); } }
function appconfig(PDO $pdo, string $key): string { $s=$pdo->prepare('SELECT value FROM tbl_appconfig WHERE setting=? LIMIT 1'); $s->execute([$key]); return (string)($s->fetchColumn() ?: ''); }
function phone_candidates(string $raw): array {
    $n=preg_replace('/\D+/', '', $raw); if (str_starts_with($n,'62')) $local='0'.substr($n,2); elseif (str_starts_with($n,'0')) $local=$n; else $local=$n;
    $out=[$n,$local]; if (str_starts_with($local,'0')) $out[]='62'.substr($local,1); return array_values(array_unique(array_filter($out)));
}
function fetch_customer(PDO $pdo, string $phone): ?array {
    $c=phone_candidates($phone); if (!$c) return null;
    $in=implode(',',array_fill(0,count($c),'?'));
    $q=$pdo->prepare("SELECT id,username,fullname,email,phonenumber,status FROM tbl_customers WHERE REPLACE(REPLACE(REPLACE(phonenumber,'+',''),' ',''),'-','') IN ($in) LIMIT 2");
    $q->execute($c); $rows=$q->fetchAll(); return count($rows)===1 ? $rows[0] : null;
}
function latest_recharge(PDO $pdo, int $customerId): ?array {
    $q=$pdo->prepare('SELECT id,customer_id,plan_id,routers FROM tbl_user_recharges WHERE customer_id=? ORDER BY id DESC LIMIT 1'); $q->execute([$customerId]); return $q->fetch() ?: null;
}

function recharge_expiry(PDO $pdo, int $rechargeId): ?DateTimeImmutable {
    static $column = false;
    if ($column === false) {
        $allowed = ['expiration','expiration_date','expired_date','expiry_date','expires_at'];
        $cols = $pdo->query('SHOW COLUMNS FROM tbl_user_recharges')->fetchAll(PDO::FETCH_COLUMN);
        $column = null;
        foreach ($allowed as $candidate) if (in_array($candidate, $cols, true)) { $column=$candidate; break; }
    }
    if (!$column) return null;
    $q=$pdo->prepare("SELECT `".$column."` FROM tbl_user_recharges WHERE id=? LIMIT 1");
    $q->execute([$rechargeId]); $value=$q->fetchColumn();
    if (!$value || $value==='0000-00-00' || $value==='0000-00-00 00:00:00') return null;
    try { return new DateTimeImmutable((string)$value); } catch(Throwable $e) { return null; }
}
function payment_block_reason(PDO $pdo, array $customer, array $recharge): ?string {
    // Active subscribers may renew only when 7 days or less remain.
    if (strcasecmp((string)$customer['status'],'Active') !== 0) return null;
    $expiry=recharge_expiry($pdo,(int)$recharge['id']);
    if (!$expiry) return null; // Fail open if schema has no recognizable expiry field; don't block legitimate payments.
    $now=new DateTimeImmutable('now');
    $remaining=$expiry->getTimestamp()-$now->getTimestamp();
    if ($remaining > 7*86400) {
        $days=(int)ceil($remaining/86400);
        return 'Tagihan Anda sudah dibayar. Masa aktif paket masih tersisa sekitar '.$days.' hari. Pembayaran berikutnya baru dapat dilakukan saat masa aktif tersisa 7 hari atau kurang.';
    }
    return null;
}
function customer_package_expiry(PDO $pdo, int $customerId): ?string {
    $allowed=['expiration','expiration_date','expired_date','expiry_date','expires_at'];
    $cols=$pdo->query('SHOW COLUMNS FROM tbl_user_recharges')->fetchAll(PDO::FETCH_COLUMN);
    $column=null; foreach($allowed as $candidate) if(in_array($candidate,$cols,true)){ $column=$candidate; break; }
    if(!$column) return null;
    $q=$pdo->prepare("SELECT `{$column}` FROM tbl_user_recharges WHERE customer_id=? ORDER BY id DESC LIMIT 1");
    $q->execute([$customerId]); $value=$q->fetchColumn();
    if(!$value || str_starts_with((string)$value,'0000-00-00')) return null;
    try { return (new DateTimeImmutable((string)$value))->format('d-m-Y H:i:s'); } catch(Throwable $e) { return null; }
}

function make_qris(PDO $pdo, array $customer, array $recharge, array $plan): int {
    global $cfg;
    $merchant=(appconfig($pdo,'viaqris_merchant') ?: appconfig($pdo,'rakanqris_merchant')); $apiKey=(appconfig($pdo,'viaqris_api_key') ?: appconfig($pdo,'rakanqris_api_key')); $apiUrl=(appconfig($pdo,'viaqris_api_url') ?: appconfig($pdo,'rakanqris_api_url'));
    if (!$merchant || !$apiKey || !$apiUrl) throw new RuntimeException('Konfigurasi gateway ViaQRIS belum lengkap di PHPNuxBill.');
    $routerQ=$pdo->prepare('SELECT id FROM tbl_routers WHERE name=? AND enabled=1 LIMIT 1'); $routerQ->execute([$recharge['routers']]); $router=$routerQ->fetch();
    if (!$router && (int)($plan['is_radius'] ?? 0)!==1) throw new RuntimeException('Router paket tidak ditemukan atau tidak aktif.');
    // Reuse only a live transaction that already contains a unique amount.
    // Older V1.0/V1.1 transactions without a unique amount must not be presented as a new QRIS.
    $pending=$pdo->prepare("SELECT id,price,expired_date,gateway_trx_id FROM tbl_payment_gateway WHERE user_id=? AND gateway='viaqris' AND status=1 ORDER BY id DESC LIMIT 1");
    $pending->execute([$customer['id']]); $old=$pending->fetch();
    if ($old && !empty($old['expired_date']) && strtotime($old['expired_date'])>time()
        && !empty($old['gateway_trx_id']) && (int)$old['price']>(int)$plan['price']) {
        return (int)$old['id'];
    }
    if ($old && (!empty($old['expired_date']) && strtotime($old['expired_date'])<=time())) {
        $pdo->prepare('UPDATE tbl_payment_gateway SET status=3 WHERE id=? AND status=1')->execute([$old['id']]);
    }
    if ($old && (int)$old['price']<=(int)$plan['price'] && !empty($old['expired_date']) && strtotime($old['expired_date'])>time()) {
        throw new RuntimeException('Masih ada QRIS lama tanpa kode unik yang aktif. Demi menghindari pembayaran ganda, tunggu QRIS lama kedaluwarsa sebelum membuat QRIS baru. Nominal QRIS lama tetap harus dibayar sesuai QR yang tampil.');
    }

    $now=new DateTimeImmutable('now'); $expiry=$now->modify('+60 minutes');

    // Kode unik tidak boleh dipakai dua kali pada hari kalender yang sama.
    // Mulai hari berikutnya, kode yang pernah dipakai sebelumnya boleh digunakan kembali.
    $basePrice=(int)$plan['price']; $uniqueCode=0; $amount=$basePrice;
    // Rentang dikendalikan dari pengaturan gateway ViaQRIS di PHPNuxBill.
    $uniqueMin=(int)(appconfig($pdo,'viaqris_unique_min') ?: 1);
    $uniqueMax=(int)(appconfig($pdo,'viaqris_unique_max') ?: 999);
    if ($uniqueMin < 1 || $uniqueMax < $uniqueMin || $uniqueMax > 999999) {
        throw new RuntimeException('Rentang kode unik ViaQRIS tidak valid. Periksa pengaturan plugin di PHPNuxBill.');
    }
    for ($attempt=0; $attempt<min(500, max(100, ($uniqueMax-$uniqueMin+1)*2)); $attempt++) {
        $candidate=random_int($uniqueMin,$uniqueMax); $candidateAmount=$basePrice+$candidate;
        $check=$pdo->prepare("SELECT g.id FROM tbl_payment_gateway g LEFT JOIN tbl_plans p ON p.id=g.plan_id WHERE g.gateway='viaqris' AND DATE(g.created_date)=CURDATE() AND (g.price-COALESCE(p.price,g.price))=? LIMIT 1");
        $check->execute([$candidate]);
        if (!$check->fetchColumn()) { $uniqueCode=$candidate; $amount=$candidateAmount; break; }
    }
    if ($uniqueCode===0) throw new RuntimeException('Tidak berhasil mendapatkan kode unik pembayaran. Silakan coba kembali.');

    $ins=$pdo->prepare("INSERT INTO tbl_payment_gateway (username,user_id,gateway,plan_id,plan_name,routers_id,routers,price,created_date,status) VALUES (?,?,?,?,?,?,?,?,?,1)");
    // Kolom price menyimpan nominal transfer total; plan_id tetap menunjuk paket asli.
    $ins->execute([$customer['username'],$customer['id'],'viaqris',$plan['id'],$plan['name_plan'],$router['id'] ?? 0,$recharge['routers'],$amount,$now->format('Y-m-d H:i:s')]);
    $trxId=(int)$pdo->lastInsertId(); $entry='trx-'.$trxId.'-'.(int)round(microtime(true)*1000);
    $email=trim((string)($customer['email'] ?? ''));
    if (!$email || !str_contains($email,'@') || $email==='1') {
        $billingHost=parse_url((string)($cfg['billing_base_url'] ?? ''), PHP_URL_HOST) ?: 'localhost';
        $safeUser=preg_replace('/[^a-zA-Z0-9._-]/','',(string)$customer['username']);
        $email=($safeUser ?: 'customer').'@'.$billingHost;
    }
    $payload=['kunciEntri'=>$entry,'dataBaru'=>['penjual'=>$merchant,'jenis'=>'jasa','tanggal'=>$now->format(DateTimeInterface::ATOM),'nama'=>$customer['fullname'],'email'=>$email,'telepon'=>$customer['phonenumber'],'pesan'=>'Pembayaran Paket: '.$plan['name_plan'],'nama_transaksi'=>$plan['name_plan'],'harga_transaksi'=>(string)$amount,'metode_pembayaran_transaksi'=>'QRIS','status_pembayaran_transaksi'=>'Belum Bayar','kedaluwarsa'=>$expiry->format(DateTimeInterface::ATOM)]];
    $ch=curl_init($apiUrl); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-API-Token: '.$apiKey],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_SLASHES),CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>30]);
    $raw=curl_exec($ch); $err=curl_error($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($raw===false || $http<200 || $http>=300) { $pdo->prepare('UPDATE tbl_payment_gateway SET status=3 WHERE id=?')->execute([$trxId]); $body=trim(preg_replace('/\s+/',' ',(string)$raw)); throw new RuntimeException('Layanan QRIS menolak permintaan (HTTP '.$http.'). '.($err ?: substr($body,0,300))); }
    $result=json_decode($raw,true); $success=is_array($result) && (!empty($result['success']) || (($result['message'] ?? '')==="Data untuk '{$entry}' berhasil diproses!"));
    if (!$success) { $pdo->prepare('UPDATE tbl_payment_gateway SET status=3 WHERE id=?')->execute([$trxId]); throw new RuntimeException('Gateway menolak pembuatan QRIS: '.substr((string)($result['message'] ?? 'Respons tidak dikenali'),0,220)); }
    $detailUrl=$apiUrl.(str_contains($apiUrl,'?')?'&':'?').'id='.rawurlencode($entry);
    $ch=curl_init($detailUrl); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-API-Token: '.$apiKey],CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>30]); $detailRaw=curl_exec($ch); $detailHttp=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $detail=json_decode((string)$detailRaw,true); if (!is_array($detail) || $detailHttp<200 || $detailHttp>=300) { $pdo->prepare('UPDATE tbl_payment_gateway SET status=3 WHERE id=?')->execute([$trxId]); throw new RuntimeException('QRIS dibuat tetapi detail QR tidak berhasil diambil. Hubungi admin sebelum mencoba ulang.'); }
    $upd=$pdo->prepare('UPDATE tbl_payment_gateway SET gateway_trx_id=?,pg_url_payment=?,pg_request=?,expired_date=? WHERE id=? AND status=1');
    $billing=rtrim($cfg['billing_base_url'],'/'); $upd->execute([$entry,$billing.'/index.php?_route=plugin/viaqris_show_payment&id='.$trxId,json_encode($detail,JSON_UNESCAPED_SLASHES),$expiry->format('Y-m-d H:i:s'),$trxId]);
    return $trxId;
}
function transaction(PDO $pdo, int $id): ?array {
    $q=$pdo->prepare("SELECT g.id,g.username,g.user_id,g.gateway_trx_id,g.plan_id,g.plan_name,g.price,g.status,g.pg_request,g.expired_date,g.paid_date,g.trx_invoice,p.price AS base_price,c.fullname,c.phonenumber FROM tbl_payment_gateway g LEFT JOIN tbl_plans p ON p.id=g.plan_id LEFT JOIN tbl_customers c ON c.id=g.user_id WHERE g.id=? AND g.gateway='viaqris' LIMIT 1");
    $q->execute([$id]); return $q->fetch() ?: null;
}

function notify_billing_callback(array $cfg, array $payload): array {
    $base=rtrim((string)($cfg['billing_base_url'] ?? ''), '/');
    $url=rtrim((string)($cfg['billing_callback_url'] ?? ($base.'/callback/viaqris')), '/');
    // Backward compatibility: normalize an old saved RAKAN QRIS callback URL.
    $url=preg_replace('~/callback/rakanqris$~i', '/callback/viaqris', $url);
    if (!$base || !filter_var($url, FILTER_VALIDATE_URL)) {
        return ['ok'=>false,'http'=>0,'body'=>'URL callback billing belum dikonfigurasi dengan benar.'];
    }
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
        CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>25
    ]);
    $body=curl_exec($ch); $err=curl_error($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return ['ok'=>($body!==false && $http>=200 && $http<300),'http'=>$http,'body'=>($body===false?$err:(string)$body)];
}

$pdo=null; $error=''; $page='lookup'; $customer=null; $recharge=null; $plan=null; $trx=null;
try { $pdo=db(); } catch(Throwable $ex) { http_response_code(500); exit('Koneksi database gagal. Periksa config.php dan akses database.'); }
$invoiceBrand = [
    'company' => appconfig($pdo, 'viaqris_invoice_company') ?: 'RAKAN FIBER PRJ',
    'title' => appconfig($pdo, 'viaqris_invoice_title') ?: 'INVOICE PEMBAYARAN',
    'description' => appconfig($pdo, 'viaqris_invoice_description') ?: 'Bukti pembayaran layanan internet',
    'logo' => appconfig($pdo, 'viaqris_invoice_logo'),
];
$providerName = $invoiceBrand['company'] ?: (($cfg['app_name'] ?? '') ?: 'ViaQRIS');
$waAdmin = preg_replace('/\\D+/', '', appconfig($pdo, 'viaqris_support_whatsapp'));
$waMessageTemplate = appconfig($pdo, 'viaqris_support_message') ?: 'Halo Admin, saya mengalami kendala pembayaran QRIS. Mohon bantuannya. ID Transaksi: [ID_TRANSAKSI]';

if ($invoiceBrand['logo'] !== '' && !preg_match('~^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$~', $invoiceBrand['logo'])) { $invoiceBrand['logo'] = ''; }
$action=$_GET['action'] ?? '';
if (in_array($action,['status','check','invoice'],true)) {
    $id=(int)($_GET['id'] ?? 0); $t=transaction($pdo,$id);
    $valid=$t && !empty($_SESSION['trx_token'][$id]) && hash_equals($_SESSION['trx_token'][$id],(string)($_GET['token'] ?? ''));
    if (!$valid) { if ($action==='invoice') { http_response_code(404); exit('Invoice tidak ditemukan.'); } header('Content-Type: application/json'); http_response_code(404); echo json_encode(['ok'=>false,'message'=>'Transaksi tidak ditemukan.']); exit; }
    if ($action==='invoice') { if ((int)$t['status']!==2) { http_response_code(403); exit('Invoice hanya tersedia setelah pembayaran berhasil.'); } $trx=$t; $trx['package_expiry']=customer_package_expiry($pdo,(int)$t['user_id']); $page='invoice'; }
    if ($action==='check') {
        header('Content-Type: application/json');
        if ((int)$t['status']===2) { echo json_encode(['ok'=>true,'paid'=>true,'invoice'=>'?action=invoice&id='.$id.'&token='.rawurlencode($_GET['token'])]); exit; }
        if ((int)$t['status']===3) { echo json_encode(['ok'=>true,'paid'=>false,'expired'=>true,'message'=>'Transaksi kedaluwarsa atau dibatalkan.']); exit; }
        $apiUrl=(appconfig($pdo,'viaqris_api_url') ?: appconfig($pdo,'rakanqris_api_url')); $apiKey=(appconfig($pdo,'viaqris_api_key') ?: appconfig($pdo,'rakanqris_api_key'));
        if (!$apiUrl || !$apiKey || !$t['gateway_trx_id']) { echo json_encode(['ok'=>false,'message'=>'Konfigurasi pemeriksaan gateway belum lengkap.']); exit; }
        $ch=curl_init($apiUrl.(str_contains($apiUrl,'?')?'&':'?').'id='.rawurlencode($t['gateway_trx_id']));
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-API-Token: '.$apiKey],CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20]);
        $raw=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch); $remote=json_decode((string)$raw,true);
        if ($raw===false || $http<200 || $http>=300 || !is_array($remote)) { echo json_encode(['ok'=>false,'message'=>'Gagal memeriksa gateway'.($err?': '.$err:' (HTTP '.$http.').')]); exit; }
        $remoteStatus=(string)($remote['status_pembayaran_transaksi'] ?? $remote['status'] ?? 'Tidak diketahui');
        // Once ViaQRIS confirms success, forward a ViaQRIS-shaped JSON notification to the
        // existing PHPNuxBill /callback/viaqris handler. That handler remains responsible
        // for Package::rechargeUser(), invoice generation and marking the gateway row PAID.
        if ($remoteStatus==='Berhasil' && (int)$t['status']!==2) {
            $amount=$remote['total_bayar'] ?? $remote['harga_transaksi'] ?? $remote['amount'] ?? $t['price'];
            $notify=[
                'transactionId'=>(string)$t['gateway_trx_id'],
                'status'=>'Berhasil',
                'amount'=>(int)round((float)$amount),
                'senderName'=>(string)($remote['senderName'] ?? $remote['nama_pembayar'] ?? $remote['nama'] ?? $t['fullname'] ?? $t['username']),
                'senderPhone'=>(string)($remote['senderPhone'] ?? $remote['telepon_pembayar'] ?? $t['phonenumber'] ?? '')
            ];
            $cb=notify_billing_callback($cfg,$notify);
            $fresh=transaction($pdo,$id);
            if ((int)$fresh['status']===2) {
                echo json_encode(['ok'=>true,'paid'=>true,'remote_status'=>$remoteStatus,'callback_http'=>$cb['http'],'invoice'=>'?action=invoice&id='.$id.'&token='.rawurlencode($_GET['token'])]); exit;
            }
            $cbBody=substr(preg_replace('/\\s+/',' ',(string)$cb['body']),0,260);
            echo json_encode(['ok'=>false,'paid'=>false,'remote_status'=>$remoteStatus,'callback_http'=>$cb['http'],'message'=>'ViaQRIS menyatakan Berhasil, tetapi PHPNuxBill belum mengesahkan pelunasan. Callback: '.($cbBody ?: 'tidak ada respons')]); exit;
        }
        $fresh=transaction($pdo,$id);
        if ((int)$fresh['status']===2) { echo json_encode(['ok'=>true,'paid'=>true,'remote_status'=>$remoteStatus,'invoice'=>'?action=invoice&id='.$id.'&token='.rawurlencode($_GET['token'])]); exit; }
        echo json_encode(['ok'=>true,'paid'=>false,'remote_status'=>$remoteStatus,'message'=>$remoteStatus==='Berhasil'?'Gateway menyatakan pembayaran berhasil, tetapi status lokal belum berubah. Periksa callback PHPNuxBill.':'Pembayaran belum terkonfirmasi.']); exit;
    }
}
if ($action==='status') {
    header('Content-Type: application/json'); $id=(int)($_GET['id'] ?? 0); $t=transaction($pdo,$id);
    if (!$t || empty($_SESSION['trx_token'][$id]) || !hash_equals($_SESSION['trx_token'][$id],(string)($_GET['token'] ?? ''))) { http_response_code(404); echo json_encode(['ok'=>false]); exit; }
    $expired=!empty($t['expired_date']) && strtotime($t['expired_date'])<=time();
    echo json_encode(['ok'=>true,'status'=>(int)$t['status'],'expired'=>$expired,'expires'=>strtotime($t['expired_date'] ?: 'now')]); exit;
}
if ($action==='payment') {
    $id=(int)($_GET['id'] ?? 0); $trx=transaction($pdo,$id);
    if (!$trx || empty($_SESSION['trx_token'][$id])) { http_response_code(404); exit('Transaksi tidak ditemukan.'); }
    $page='payment';
} elseif ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    try {
        if (($_POST['action'] ?? '')==='lookup') {
            $phone=trim((string)($_POST['phone'] ?? ''));
            if (strlen(preg_replace('/\D/','',$phone))<9 || strlen(preg_replace('/\D/','',$phone))>15) throw new RuntimeException('Nomor WhatsApp tidak valid.');
            $customer=fetch_customer($pdo,$phone);
            if (!$customer || !in_array($customer['status'],['Active','Inactive','Limited','Suspended'],true)) throw new RuntimeException('Pelanggan tidak ditemukan. Periksa nomor WhatsApp yang terdaftar.');
            $recharge=latest_recharge($pdo,(int)$customer['id']); if (!$recharge) throw new RuntimeException('Paket pelanggan belum ditemukan. Hubungi admin.');
            $blocked=payment_block_reason($pdo,$customer,$recharge); if ($blocked) throw new RuntimeException($blocked);
            $q=$pdo->prepare('SELECT id,name_plan,price,is_radius,enabled FROM tbl_plans WHERE id=? AND enabled=1 LIMIT 1'); $q->execute([$recharge['plan_id']]); $plan=$q->fetch() ?: null;
            if (!$plan) throw new RuntimeException('Paket pelanggan tidak tersedia.');
            $_SESSION['lookup']=['customer_id'=>(int)$customer['id'],'recharge_id'=>(int)$recharge['id'],'created'=>time()]; $page='confirm';
        } elseif (($_POST['action'] ?? '')==='create') {
            $l=$_SESSION['lookup'] ?? null; if (!$l || time()-(int)$l['created']>600) throw new RuntimeException('Sesi pencarian berakhir. Masukkan nomor WhatsApp kembali.');
            $q=$pdo->prepare('SELECT id,username,fullname,email,phonenumber,status FROM tbl_customers WHERE id=? LIMIT 1'); $q->execute([(int)$l['customer_id']]); $customer=$q->fetch() ?: null;
            $q=$pdo->prepare('SELECT id,customer_id,plan_id,routers FROM tbl_user_recharges WHERE id=? AND customer_id=? LIMIT 1'); $q->execute([(int)$l['recharge_id'],(int)$l['customer_id']]); $recharge=$q->fetch() ?: null;
            if (!$customer || !$recharge) throw new RuntimeException('Data pelanggan/paket tidak ditemukan.');
            $q=$pdo->prepare('SELECT id,name_plan,price,is_radius,enabled FROM tbl_plans WHERE id=? AND enabled=1 LIMIT 1'); $q->execute([$recharge['plan_id']]); $plan=$q->fetch() ?: null; if (!$plan) throw new RuntimeException('Paket tidak tersedia.');
            $id=make_qris($pdo,$customer,$recharge,$plan); unset($_SESSION['lookup']); $_SESSION['trx_token'][$id]=bin2hex(random_bytes(24)); header('Location: ?action=payment&id='.$id); exit;
        }
    } catch(Throwable $ex) { $error=$ex->getMessage(); }
}
if ($page==='payment' && $trx) {
    $json=json_decode((string)$trx['pg_request'],true) ?: []; $qr=(string)($json['url_pambayaran'] ?? $json['data']['qr_url'] ?? ''); $checkout=(string)($json['data']['checkout_url'] ?? ''); $paymentName=(string)($json['data']['payment_name'] ?? 'QRIS');
    $token=$_SESSION['trx_token'][$trx['id']]; $expiry=strtotime($trx['expired_date'] ?: 'now');
    $waMessage = str_replace(
        ['[ID_TRANSAKSI]', '[NAMA_PROVIDER]'],
        [(string)$trx['gateway_trx_id'], (string)$providerName],
        $waMessageTemplate
    );
    $waUrl = $waAdmin !== '' ? 'https://wa.me/'.$waAdmin.'?text='.rawurlencode($waMessage) : '';

}
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($providerName)?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="assets/style.css" rel="stylesheet"><link href="assets/quickpay-v1102.css?v=1102" rel="stylesheet"><style>.payment-guidance{border-radius:14px;background:#f8fafc;color:#334155;line-height:1.55}.whatsapp-support{border-radius:12px;font-weight:600;padding:.75rem 1rem}.whatsapp-support svg{flex:0 0 auto}</style></head><body><nav class="navbar navbar-light quickpay-nav"><div class="container justify-content-center"><span class="navbar-brand fw-bold"><?=e($providerName)?></span></div></nav><main class="container py-5"><div class="row justify-content-center"><div class="col-12 col-md-8 col-lg-6">
<?php if ($error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?>
<?php if ($page==='payment' && $trx): ?>
<div class="card shadow-lg border-0 quickpay-card payment-card"><div class="card-body text-center p-4 p-md-5"><div class="text-muted small">PEMBAYARAN INTERNET</div><h4 class="mt-2"><?=e($trx['plan_name'])?></h4><div class="text-muted small">Harga paket: <?=money($trx['base_price'] ?? $trx['price'])?></div><div class="display-6 fw-bold text-primary mb-1"><?=money($trx['price'])?></div><div class="alert alert-info py-2 mb-3"><div class="small fw-semibold">NOMINAL YANG HARUS DIBAYAR / DIPINDAI</div><div class="fs-4 fw-bold"><?=money($trx['price'])?></div><div class="small">Kode unik: <strong><?=number_format(max(0,(int)$trx['price']-(int)($trx['base_price'] ?? $trx['price'])),0,',','.')?></strong> — sudah termasuk dalam nominal QRIS</div></div><div class="badge text-bg-warning mb-3" id="payState">Menunggu pembayaran</div>
<?php if ($qr): ?><div class="qr-wrap"><img src="<?=e($qr)?>" alt="QRIS pembayaran" class="img-fluid"></div><?php elseif($checkout): ?><a class="btn btn-primary" href="<?=e($checkout)?>" target="_blank" rel="noopener">Buka halaman pembayaran</a><?php else: ?><div class="alert alert-warning">Data QR tidak tersedia. Hubungi admin dan jangan membuat pembayaran berulang.</div><?php endif; ?>
<p class="mb-1 mt-3">Berlaku selama</p><div class="timer" id="timer">--:--</div><p class="small text-muted">ID transaksi: <?=e($trx['gateway_trx_id'])?></p><div id="paidNotice" class="alert alert-success d-none">Pembayaran berhasil diterima. Membuka invoice...</div><div id="checkResult" class="small text-muted mt-2" aria-live="polite"></div>
<div class="alert alert-light border text-start mt-3 mb-3 payment-guidance">
<strong>Informasi pembayaran</strong>
<p class="mb-2 mt-2">Pembayaran akan diperiksa secara otomatis. Jika pembayaran berhasil dan telah dikonfirmasi oleh sistem billing, Anda akan otomatis dialihkan ke halaman invoice.</p>
<p class="mb-2">Jika belum dialihkan, silakan tekan tombol <strong>Cek pembayaran</strong>.</p>
<p class="mb-0">Jika mengalami kendala atau error, silakan hubungi admin melalui WhatsApp.</p>
</div>
<button type="button" class="btn btn-success w-100 mt-2" id="checkPayment">Cek pembayaran</button>
<?php if ($waUrl): ?><a class="btn btn-outline-success w-100 mt-2 d-flex align-items-center justify-content-center gap-2 whatsapp-support" href="<?=e($waUrl)?>" target="_blank" rel="noopener noreferrer">
<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" fill="currentColor"><path d="M20.52 3.48A11.8 11.8 0 0 0 12.1 0C5.55 0 .22 5.33.22 11.9c0 2.1.55 4.15 1.6 5.96L.12 24l6.3-1.65a11.9 11.9 0 0 0 5.68 1.45h.01c6.56 0 11.9-5.34 11.9-11.9 0-3.18-1.24-6.17-3.49-8.42ZM12.1 21.78a9.9 9.9 0 0 1-5.05-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.87 9.87 0 0 1-1.52-5.25c0-5.46 4.45-9.9 9.91-9.9a9.83 9.83 0 0 1 7 2.9 9.83 9.83 0 0 1 2.9 7c0 5.46-4.44 9.9-9.9 9.9Zm5.43-7.42c-.3-.15-1.78-.88-2.06-.98-.28-.1-.48-.15-.68.15-.2.3-.78.98-.96 1.18-.18.2-.35.23-.65.08-.3-.15-1.27-.47-2.42-1.5-.9-.8-1.5-1.78-1.68-2.08-.18-.3-.02-.46.13-.61.13-.13.3-.35.45-.53.15-.18.2-.3.3-.5.1-.2.05-.38-.02-.53-.08-.15-.68-1.63-.93-2.23-.24-.58-.49-.5-.68-.51h-.58c-.2 0-.53.08-.8.38-.28.3-1.05 1.03-1.05 2.5s1.08 2.9 1.23 3.1c.15.2 2.12 3.24 5.14 4.55.72.31 1.28.5 1.72.64.72.23 1.38.2 1.9.12.58-.09 1.78-.73 2.03-1.43.25-.7.25-1.3.18-1.43-.08-.13-.28-.2-.58-.35Z"/></svg>
Hubungi Admin WhatsApp</a><?php endif; ?><div id="expiredNotice" class="alert alert-danger d-none">QRIS telah kedaluwarsa. Silakan kembali dan buat permintaan baru.</div><a href="?" class="btn btn-outline-secondary w-100 mt-2">Kembali ke awal</a></div></div>
<script>const expiresAt=<?=((int)$expiry)*1000?>, tid=<?= (int)$trx['id']?>, token=<?=json_encode($token)?>;const timer=document.getElementById('timer');function tick(){let s=Math.max(0,Math.floor((expiresAt-Date.now())/1000));timer.textContent=String(Math.floor(s/60)).padStart(2,'0')+':'+String(s%60).padStart(2,'0');if(s<=0){document.getElementById('expiredNotice').classList.remove('d-none');document.getElementById('payState').textContent='Kedaluwarsa';} }tick();setInterval(tick,1000);async function poll(){try{const r=await fetch('?action=status&id='+tid+'&token='+encodeURIComponent(token),{cache:'no-store'});const d=await r.json();if(d.ok&&d.status===2){document.getElementById('payState').textContent='Lunas';document.getElementById('payState').className='badge text-bg-success mb-3';document.getElementById('paidNotice').classList.remove('d-none');window.location.href='?action=invoice&id='+tid+'&token='+encodeURIComponent(token);}else if(d.ok&&(d.status===3||d.expired)){document.getElementById('expiredNotice').classList.remove('d-none');}}catch(e){}}async function manualCheck(){const b=document.getElementById('checkPayment'),m=document.getElementById('checkResult');b.disabled=true;b.textContent='Memeriksa...';m.textContent='';try{const r=await fetch('?action=check&id='+tid+'&token='+encodeURIComponent(token),{cache:'no-store'});const d=await r.json();if(d.ok&&d.paid&&d.invoice){window.location.href=d.invoice;return;}m.textContent=d.message||('Status gateway: '+(d.remote_status||'Belum diketahui'));}catch(e){m.textContent='Tidak dapat memeriksa pembayaran. Coba lagi.';}finally{b.disabled=false;b.textContent='Cek pembayaran';}}document.getElementById('checkPayment').addEventListener('click',manualCheck);poll();setInterval(poll,5000);</script>
<?php elseif ($page==='invoice' && $trx && (int)$trx['status']===2): ?>
<div class="card shadow-lg border-0 invoice-card quickpay-card"><div class="card-body p-4"><div class="d-flex justify-content-between align-items-start flex-wrap gap-3"><div class="d-flex align-items-center gap-3"><?php if ($invoiceBrand['logo']): ?><img src="<?=e($invoiceBrand['logo'])?>" alt="Logo perusahaan" style="max-width:110px;max-height:76px;object-fit:contain"><?php endif; ?><div><div class="text-muted small"><?=e($invoiceBrand['company'])?></div><h3 class="mb-1"><?=e($invoiceBrand['title'])?></h3><div class="text-muted small"><?=e($invoiceBrand['description'])?></div></div></div><span class="badge text-bg-success">LUNAS</span></div><hr><div class="row g-3"><div class="col-6"><div class="text-muted small">Nomor invoice</div><strong><?=e($trx['trx_invoice'] ?: ('INV-RQ-'.str_pad((string)$trx['id'],6,'0',STR_PAD_LEFT)))?></strong></div><div class="col-6 text-end"><div class="text-muted small">Tanggal pembayaran</div><strong><?=e($trx['paid_date'] ?: '-')?></strong></div><div class="col-12"><div class="text-muted small">Pelanggan</div><strong><?=e($trx['fullname'] ?: $trx['username'])?></strong><div><?=e($trx['phonenumber'] ?: '-')?></div></div><div class="col-12 col-sm-6"><div class="text-muted small">Tanggal expired paket</div><strong><?=e($trx['package_expiry'] ?: 'Tidak tersedia dari data billing')?></strong></div></div><hr><div class="table-responsive"><table class="table"><tr><td>Paket internet</td><td class="text-end fw-semibold"><?=e($trx['plan_name'])?></td></tr><tr><td>Harga paket</td><td class="text-end"><?=money($trx['base_price'] ?? $trx['price'])?></td></tr><tr><td>Kode unik</td><td class="text-end"><?=money(max(0,(int)$trx['price']-(int)($trx['base_price'] ?? $trx['price'])))?></td></tr><tr class="table-light"><th>Total dibayar</th><th class="text-end text-primary fs-5"><?=money($trx['price'])?></th></tr></table></div><div class="text-center small text-muted">ID transaksi: <?=e($trx['gateway_trx_id'])?><br>Metode pembayaran: QRIS</div><div class="d-flex gap-2 mt-4 no-print"><button type="button" class="btn btn-primary flex-fill" onclick="window.print()">Cetak / Simpan PDF</button><a href="?" class="btn btn-outline-secondary flex-fill">Selesai</a></div></div></div>
<style>@media print{body{background:#fff!important}.navbar,footer,.no-print{display:none!important}main.container{padding:0!important}.invoice-card{box-shadow:none!important;border:0!important}.invoice-card .card-body{padding:0!important}}</style>
<?php elseif ($page==='confirm' && $customer && $plan): ?>
<div class="card shadow-lg border-0 quickpay-card"><div class="card-body p-4 p-md-5"><div class="quickpay-icon mb-3">✓</div><div class="text-uppercase small fw-semibold text-success mb-2">Akun ditemukan</div><h3 class="fw-bold mb-4">Konfirmasi pembayaran</h3><div class="quickpay-summary p-3 mb-4"><div class="small text-secondary">Nama pelanggan</div><div class="fw-bold mb-3"><?=e($customer['fullname'])?></div><div class="small text-secondary">Nomor WhatsApp</div><div class="fw-semibold mb-3"><?=e($customer['phonenumber'])?></div><div class="small text-secondary">Paket internet</div><div class="fw-semibold mb-3"><?=e($plan['name_plan'])?></div><div class="d-flex justify-content-between align-items-center border-top pt-3"><span class="text-secondary">Harga paket</span><strong class="fs-5"><?=money($plan['price'])?></strong></div></div><p class="small text-secondary">Paket mengikuti paket terakhir yang tercatat. Kode unik akan ditambahkan otomatis pada nominal QRIS.</p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="create"><button class="btn btn-primary btn-lg w-100 quickpay-button">Lanjutkan ke pembayaran <span aria-hidden="true">→</span></button></form><a href="?" class="btn btn-link w-100 mt-2">Kembali</a></div></div>
<?php else: ?>
<div class="card shadow-lg border-0 quickpay-card"><div class="card-body p-4 p-md-5"><div class="quickpay-icon mb-3" aria-hidden="true"><svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7.5h18M5 4h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"/><path d="m8 13 2.5 2.5L16.5 10"/></svg></div><div class="text-uppercase small fw-semibold text-primary mb-2">Pembayaran internet</div><h3 class="fw-bold mb-2">Bayar tagihan dengan mudah</h3><p class="text-secondary mb-4">Masukkan nomor WhatsApp yang terdaftar. Kami akan menemukan akun dan paket internet Anda.</p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="lookup"><label class="form-label fw-semibold">Nomor WhatsApp</label><input type="tel" name="phone" class="form-control form-control-lg mb-3 quickpay-input" placeholder="08xxxxxxxxxx" required minlength="9" maxlength="18" autocomplete="tel"><button class="btn btn-primary btn-lg w-100 quickpay-button">Cari akun pelanggan <span aria-hidden="true">→</span></button></form><div class="quickpay-trust mt-4"><span>✓ Aman</span><span>✓ QRIS</span><span>✓ Proses otomatis</span></div></div></div>
<?php endif; ?><p class="text-center small text-muted mt-4">Pembayaran aman melalui QRIS • <?=e($invoiceBrand['company'])?></p></div></div></main><footer class="text-center py-3 small text-muted">&copy; <?=date('Y')?> <?=e($providerName)?></footer></body></html>
