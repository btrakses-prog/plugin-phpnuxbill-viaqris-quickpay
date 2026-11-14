{include file="user-ui/header.tpl"}
<div class="row justify-content-center">
 <div class="col-12 col-md-8 col-lg-6">
  <div class="panel panel-primary panel-hovered" style="border:0;border-radius:20px;overflow:hidden;box-shadow:0 12px 35px rgba(20,45,90,.10);">
   <div class="panel-heading" style="padding:22px 24px;font-size:18px;font-weight:700;">Pembayaran Internet</div>
   <div class="panel-body" style="padding:26px 24px;">
    {if $error}<div class="alert alert-danger" style="border-radius:12px;">{$error}</div>{/if}
    {if $step == 'confirm'}
      <div class="alert alert-success" style="border-radius:12px;">Pelanggan ditemukan. Periksa data sebelum melanjutkan.</div>
      <div style="background:#f6f8fc;border:1px solid #e7edf5;border-radius:15px;padding:18px;margin-bottom:18px;">
       <div class="text-muted small">Nama pelanggan</div><strong>{$customer.fullname}</strong>
       <hr style="margin:12px 0;">
       <div class="text-muted small">Paket internet</div><strong>{$plan.name_plan}</strong>
       <hr style="margin:12px 0;">
       <div class="text-muted small">Harga paket</div><strong style="font-size:20px;color:#1769e8;">Rp {$plan.price|number_format:0:',':'.'}</strong>
      </div>
      <p class="text-muted small">Nominal QRIS akan ditambahkan kode unik sesuai pengaturan admin.</p>
      <form method="post" action="{$_url}plugin/viaqris_quickpay_create">
       <input type="hidden" name="confirm_token" value="{$confirm_token|escape}">
       <button type="submit" class="btn btn-primary btn-lg btn-block" style="border-radius:12px;">Lanjutkan ke pembayaran</button>
       <a href="{$_url}plugin/viaqris_quickpay" class="btn btn-default btn-block" style="border-radius:12px;margin-top:8px;">Kembali</a>
      </form>
    {else}
      <div style="width:52px;height:52px;border-radius:16px;background:#eaf1ff;color:#1769e8;display:flex;align-items:center;justify-content:center;font-size:27px;margin-bottom:16px;">⌁</div>
      <h3 style="font-weight:700;margin-top:0;">Bayar tagihan dengan mudah</h3>
      <p class="text-muted">Masukkan nomor WhatsApp yang terdaftar. Kami akan menemukan akun dan paket internet Anda.</p>
      <form method="post" action="{$_url}plugin/viaqris_quickpay">
       <div class="form-group"><label>Nomor WhatsApp</label><input type="tel" name="phone" class="form-control input-lg" style="height:54px;border-radius:12px;" placeholder="08xxxxxxxxxx" required minlength="9" maxlength="18" autocomplete="tel"></div>
       <button type="submit" class="btn btn-primary btn-lg btn-block" style="border-radius:12px;">Cari akun pelanggan&nbsp; →</button>
      </form>
      <div class="text-center text-muted small" style="margin-top:20px;">✓ Aman &nbsp;&nbsp; ✓ QRIS &nbsp;&nbsp; ✓ Proses otomatis</div>
    {/if}
   </div>
   <div class="panel-footer text-center" style="padding:14px 20px;background:#fafbfd;border:0;"><small class="text-muted">Jika nomor tidak ditemukan, pastikan nomor WhatsApp sama dengan data pelanggan atau hubungi admin.</small></div>
  </div>
 </div>
</div>
{include file="user-ui/footer.tpl"}
