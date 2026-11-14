{include file="user-ui/header.tpl"}
<!-- user-pages -->

<div class="row">
    <div class="col-md-2"></div>
    <div class="col-md-8">
        <div class="panel mb20 panel-primary panel-hovered">
            <div class="panel-heading">{$payment_name}</div>
            <div class="panel-body">
                <div class="alert alert-info text-center" id="paymentCountdownBox">
                    <strong>Batas waktu pembayaran</strong><br>
                    <span id="paymentCountdown" style="font-size: 30px; font-weight: bold; font-variant-numeric: tabular-nums;">--:--</span>
                    <div class="small">Selesaikan pembayaran sebelum waktu habis.</div>
                </div>
                <div class="alert alert-danger" id="paymentExpiredBox" style="display:none;">
                    <strong>Waktu pembayaran telah habis.</strong><br>
                    QRIS untuk transaksi ini sudah kedaluwarsa. Silakan mulai kembali dari halaman pembayaran dan masukkan nomor WhatsApp untuk meminta QRIS baru.
                    <div class="small">Transaksi lama tidak dapat digunakan kembali.</div>
                </div>
                {if $qr_string}
                    <div class="panel mb20 panel-primary panel-hovered">
                        <div class="panel-heading">QRIS</div>
                        <div class="panel-body">
                            <center>
                                <img id="qrImage" src="{$qr_string}" alt="QR Code Pembayaran" style="max-width: 280px; height: auto; border: 1px solid #eee; padding: 10px; display: block; margin: 0 auto;">
                                <br>
                                <div class="button-group mt-3">
                                    <button onclick="downloadQR()" class="btn btn-success btn-sm">Download QR</button>
                                </div>
                            </center>
                        </div>
                    </div>
                    <script>
                        function downloadQR() {
                            var qrImage = document.getElementById('qrImage');
                            var imageUrl = qrImage.src;

                            var link = document.createElement('a');
                            link.href = imageUrl;
                            link.download = 'QRIS_Pembayaran_{$trxid}.png';

                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                            alert('QR Code berhasil diunduh! 🎉');
                        }
                    </script>
                {else}
                    <div class="alert alert-warning" role="alert">
                        <strong>Peringatan!</strong> Kode QR pembayaran tidak tersedia. Silakan hubungi administrator atau coba metode pembayaran lain.
                        <p><strong>DEBUG:</strong> Nilai qr_string saat ini: '{$qr_string}'</p>
                    </div>
                {/if}

                <!-- Bagian ini akan menampilkan instruksi statis yang baru -->
                {foreach $instruction $i}
                    <div class="panel mb20 panel-primary panel-hovered">
                        <div class="panel-heading">{$i['title']}</div>
                        <div class="panel-body">
                            <ol>
                                {foreach $i['steps'] $a}
                                    <li>{$a}</li>
                                {/foreach}
                            </ol>
                        </div>
                    </div>
                {/foreach}
            </div>
            <div class="panel-footer">
                <div class="row">
                    <div class="col-md-6" id="checkPaymentButton">
                        <a href="{$_url}order/view/{$trxid}/check" class="btn btn-success btn-block">sudah bayar? Cek
                            Pembayaran</a>
                    </div>
                    <div class="col-md-6" id="externalPaymentButton">
                        <a href="{$checkout_url}" class="btn btn-info btn-block">Lihat Halaman Pembayaran</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    // Sisa waktu dihitung oleh PHP berdasarkan expired_date dan waktu server.
    var remaining = Number('{$remaining_seconds|default:0}');
    var timer = document.getElementById('paymentCountdown');
    var countdownBox = document.getElementById('paymentCountdownBox');
    var expiredBox = document.getElementById('paymentExpiredBox');
    var qrImage = document.getElementById('qrImage');
    var checkButton = document.getElementById('checkPaymentButton');
    var externalButton = document.getElementById('externalPaymentButton');
    var transactionId = '{$trxid|escape:'javascript'}';
    var statusUrl = '{$_url}plugin/viaqris_quickpay_status&id=' + encodeURIComponent(transactionId);
    var invoiceRedirected = false;

    // Only QuickPayment sessions have this session-bound status endpoint. When the billing
    // callback changes status to Paid (2), send the customer directly to the invoice.
    async function checkBillingPaid() {
        if (invoiceRedirected) return;
        try {
            var response = await fetch(statusUrl, { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) return;
            var data = await response.json();
            if (data && data.ok && data.paid && data.invoice) {
                invoiceRedirected = true;
                window.location.href = data.invoice;
            }
        } catch (e) { /* keep waiting; callback may still be processing */ }
    }
    checkBillingPaid();
    var paidPollId = setInterval(checkBillingPaid, 3000);

    if (!timer) return;

    function updateCountdown() {
        remaining = Math.max(0, remaining);
        var minutes = Math.floor(remaining / 60);
        var seconds = remaining % 60;
        timer.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');

        if (remaining <= 0) {
            clearInterval(intervalId);
            clearInterval(paidPollId);
            countdownBox.style.display = 'none';
            expiredBox.style.display = 'block';
            if (qrImage) qrImage.style.filter = 'grayscale(1) opacity(.35)';
            if (checkButton) checkButton.style.display = 'none';
            if (externalButton) externalButton.style.display = 'none';
        }
    }

    updateCountdown();
    var intervalId = setInterval(function () { remaining--; updateCountdown(); }, 1000);
})();
</script>

{include file="user-ui/footer.tpl"}