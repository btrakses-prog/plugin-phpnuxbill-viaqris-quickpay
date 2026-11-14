{include file="sections/header.tpl"}

<form class="form-horizontal" method="post" enctype="multipart/form-data" role="form" action="{$_url}paymentgateway/viaqris">
    <div class="row">
        <div class="col-sm-12 col-md-12">
            <div class="panel panel-primary panel-hovered panel-stacked mb30">
                <div class="panel-heading">viaqris</div>
                <div class="panel-body">
                    <div class="form-group">
                        <label class="col-md-2 control-label">{Lang::T('Kode Merchant')}</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="viaqris_merchant" name="viaqris_merchant"
                                placeholder="T" value="{$_c['viaqris_merchant']}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-2 control-label">API Key</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="viaqris_api_key" name="viaqris_api_key"
                                value="{$_c['viaqris_api_key']}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-2 control-label">API URL</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="viaqris_api_url" name="viaqris_api_url"
                                value="{$_c['viaqris_api_url']}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-2 control-label">Kode Unik Minimum (Rp)</label>
                        <div class="col-md-6">
                            <input type="number" min="1" max="999999" class="form-control" name="viaqris_unique_min" value="{$_c['viaqris_unique_min']|default:1}" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-2 control-label">Kode Unik Maksimum (Rp)</label>
                        <div class="col-md-6">
                            <input type="number" min="1" max="999999" class="form-control" name="viaqris_unique_max" value="{$_c['viaqris_unique_max']|default:999}" required>
                            <small class="text-muted">Rentang ini digunakan saat Standalone membuat QRIS baru. Default 1–999.</small>
                        </div>
                    </div>
                    <hr>
                    <h4>Identitas Invoice</h4>
                    <p class="text-muted">Pengaturan ini digunakan pada invoice di halaman ViaQRIS Standalone.</p>
                    <div class="form-group">
                        <label class="col-md-2 control-label">Nama perusahaan</label>
                        <div class="col-md-6"><input type="text" class="form-control" name="viaqris_invoice_company" value="{$_c['viaqris_invoice_company']|default:'RAKAN FIBER PRJ'}" maxlength="100" placeholder="Nama perusahaan / ISP"></div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-2 control-label">Judul invoice</label>
                        <div class="col-md-6"><input type="text" class="form-control" name="viaqris_invoice_title" value="{$_c['viaqris_invoice_title']|default:'INVOICE PEMBAYARAN'}" maxlength="100"></div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-2 control-label">Deskripsi invoice</label>
                        <div class="col-md-6"><input type="text" class="form-control" name="viaqris_invoice_description" value="{$_c['viaqris_invoice_description']|default:'Bukti pembayaran layanan internet'}" maxlength="180"></div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-2 control-label">Logo perusahaan</label>
                        <div class="col-md-6">
                            <input type="file" class="form-control" name="viaqris_invoice_logo_file" accept="image/png,image/jpeg,image/webp">
                            <small class="text-muted">PNG, JPG, atau WEBP. Maksimal 500 KB. Logo disimpan di konfigurasi PHPNuxBill dan tampil pada invoice Standalone.</small><br>
                            <label class="checkbox-inline"><input type="checkbox" name="viaqris_remove_logo" value="1"> Hapus logo saat ini</label>
                        </div>
                    </div>
<hr>
                    <h4>Bantuan Pelanggan via WhatsApp</h4>
                    <p class="text-muted">Tombol bantuan akan tampil pada halaman pembayaran QRIS Standalone.</p>
                    <div class="form-group">
                        <label class="col-md-2 control-label">Nomor WhatsApp Admin</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" name="viaqris_support_whatsapp" value="{$_c['viaqris_support_whatsapp']}" placeholder="6281234567890" maxlength="15">
                            <small class="text-muted">Gunakan kode negara, contoh 6281234567890. Tanpa tanda +, spasi, atau angka 0 di depan.</small>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-2 control-label">Pesan WhatsApp otomatis</label>
                        <div class="col-md-6">
                            <textarea class="form-control" name="viaqris_support_message" rows="3" maxlength="500" placeholder="Halo Admin...">{$_c['viaqris_support_message']|default:'Halo Admin, saya mengalami kendala pembayaran QRIS. Mohon bantuannya. ID Transaksi: [ID_TRANSAKSI]'}</textarea>
                            <small class="text-muted">Gunakan [ID_TRANSAKSI] untuk menyisipkan ID transaksi otomatis dan [NAMA_PROVIDER] untuk nama provider.</small>
                        </div>
                    </div>
                                        <div class="form-group">
                        <label class="col-md-2 control-label">{Lang::T('Notification URL')}</label>
                        <div class="col-md-6">
                            <input type="text" readonly class="form-control" onclick="this.select()"
                                value="{$_url}callback/viaqris">
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="col-lg-offset-2 col-lg-10">
                            <button class="btn btn-primary waves-effect waves-light"
                                type="submit">{Lang::T('Save Change')}</button>
                            <a class="btn btn-info waves-effect waves-light" href="https://via-qris-cloud.vercel.app/"
                                target="_blank">{Lang::T('viaqris Register')}</a>
                        </div>
                    </div>
                    <pre>/ip hotspot walled-garden
add dst-host={$_c['viaqris_api_url']}
add dst-host=*.{$_c['viaqris_api_url']}</pre>
                    <small id="emailHelp" class="form-text text-muted">{Lang::T('Set Telegram Bot to get any error and
                        notification')}</small>
                </div>
            </div>

        </div>
    </div>
</form>
{include file="sections/footer.tpl"}