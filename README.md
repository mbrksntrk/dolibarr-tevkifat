# TRTevkifat — Dolibarr için KDV Tevkifatı (Türkiye)

Alış ve satış faturalarında KDV tevkifatını Dolibarr'ın kendi fatura/muhasebe akışıyla uyumlu şekilde işleyen modül. Çekirdeğe müdahale yok; tevkifat tek bir fatura satırı olarak temsil edilir, toplamlar ve yevmiye kayıtları otomatik doğru oluşur.

| | |
|---|---|
| Dolibarr | 20.0+ (24.0 üzerinde geliştirildi ve test edildi) |
| PHP | 8.1+ |
| Lisans | GPL-3.0-or-later + yazar atfı şartı ([ATTRIBUTION.md](ATTRIBUTION.md)) |
| Sürüm | 2.1.1 |

Ayrıntılar: [Wiki](https://github.com/mbrksntrk/dolibarr-tevkifat/wiki).

## Nasıl çalışır

Tevkifat, faturaya eklenen **tek bir negatif satır** olarak temsil edilir:

- açıklama: `KDV Tevkifatı 3/10 (GİB 625)` (şablon ayarlanabilir)
- KDV %0, miktar 1, tutar = −(toplam KDV × oran)
- muhasebe hesabı orana göre ayarlardan (alışta `360.50.00x`, satışta `391.10.020`)
- satır `special_code = 99` ile işaretlenir; modül ve İşNet e-Fatura modülü bu satırı normal satırlardan ayırır

Böylece fatura toplamı **ödenecek tutarı** gösterir, ödeme/mutabakat ve alış-satış yevmiyeleri çekirdek Dolibarr ile aynen çalışır; çekirdeğe hook/patch yoktur.

Faturaya ait bilgiler (kod, oran, tutar, satır id) faturanın ek alanlarında tutulur (`trtevkifat_code/rate/amount/line`); listelerde filtrelenebilir, dışa aktarılabilir.

## Kurulum

1. Modül klasörünü `htdocs/custom/trtevkifat/` altına kopyalayın ya da [Releases](https://github.com/mbrksntrk/dolibarr-tevkifat/releases) sayfasındaki `module_trtevkifat-x.y.z.zip` dosyasını Kurulum → Modüller → **Harici modül yükle** ile kurun.
2. Kurulum → Modüller → **KDV Tevkifatı (TR)** → aktif edin (ek alanlar ve tetikleyici otomatik kurulur).
3. Ayar sayfasında hesap eşlemesini hesap planınıza göre kontrol edin; sayfa her hesabın planda olup olmadığını gösterir.
4. Kullanıcı yetkileri: *Tevkifat bilgilerini görüntüle* / *Tevkifat uygula-kaldır* (fatura oluşturma yetkisi de gerekir).

## Kullanım

1. Taslak fatura → **Tevkifat** sekmesi.
2. GİB kodunu seçin (601–627, tebliğ oranı otomatik gelir; ayar izin veriyorsa oran değiştirilebilir).
3. **Tevkifat uygula** → satır eklenir, hesaplama tablosu ödenecek tutarı gösterir. Satırlar değişirse sekme "yeniden hesaplayın" uyarısı verir; **Yeniden hesapla** satırı günceller. **Tevkifatı kaldır** satırı siler.
4. Yalnızca taslakta değiştirilebilir. Her uygulama/kaldırma faturanın **Olaylar** sekmesine yazılır.

## Ayarlar (Kurulum → Modüller → KDV Tevkifatı ⚙)

| Ayar | Varsayılan |
|---|---|
| Tedarikçi / müşteri faturalarında tevkifat | açık / açık |
| Kod varsayılanından farklı oran girilebilsin | açık |
| Oran → hesap (alış) | `20=360.50.002; 30=360.50.001; 40=360.50.003; 50=360.50.004; 70=360.50.005; 90=360.50.006` |
| Eşleşmeyen oran için hesap (alış) | `360.50` |
| Tevkifat hesabı (satış) | `391.10.020` |
| Satır açıklaması | `KDV Tevkifatı %RATE% (GİB %CODE%)` |
| Satırın yeri | son satır |
| Tevkifat tutarsızsa onayı engelle | açık |

Ayar sayfası tüm hesapların aktif hesap planında olup olmadığını denetler; ayar değişiklikleri güvenlik denetim günlüğüne yazılır.

## İşNet e-Fatura ile birlikte

`isnetefatura` modülü yüklüyse satış faturasında bu modülün kodu/oranı UBL'deki `WitholdingTaxes` bloğuna aktarılır, tevkifat satırı UBL'ye satır olarak **girmez**; `TotalPayableAmount` tevkifat düşülmüş gelir.

## Muhasebe notu

Alış faturasında tevkifat satırı `360.50.00x` (2 no'lu KDV beyannamesi ile ödenecek) hesabına, satış faturasında `391.10.020` hesabına gider; böylece hesaplanan/indirilecek KDV yalnızca tahsil edilen/ödenen kısımla kalır. Hesap kodları kendi hesap planınıza göre ayarlanmalıdır.

## v1.x'ten geçiş

v2 tamamen yeniden yazıldı (v1: hook tabanlı, tek kod 625, `llx_trtevkifat` tablosu). Modülü kapatıp açmak eski yetki/menü kayıtlarını temizler; eski tablo silinmez, v1 ile girilmiş tevkifatlar yeni sekmede görünmez (faturadaki satır olduğu gibi kalır).

## MCP araçları (Dolibarr 24 AI modülü)

Modül, AI modülünün MCP sunucusuna dört araç ekler (Kurulum → AI → araç yapılandırmasında görünür; MCP kullanıcısının Dolibarr yetkileriyle çalışır):

| Araç | Ne yapar |
|---|---|
| `tevkifat_codes` | 601–627 kod sözlüğü, oranlar ve oran→hesap eşlemesi |
| `tevkifat_info` | Faturanın tevkifat durumu ve hesaplaması (salt okunur) |
| `tevkifat_apply` | Taslak faturaya tevkifat uygula / yeniden hesapla |
| `tevkifat_remove` | Tevkifat satırını kaldır |

Örnek: "PROV7 alış faturasına 625 koduyla tevkifat uygula" → asistan onay ister, satırı ekler, ödenecek tutarı bildirir.

## Katkı

Sorun ve öneriler için [GitHub Issues](https://github.com/mbrksntrk/dolibarr-tevkifat/issues). Kod stili Dolibarr standardı; çekirdeğe dokunmadan trigger/extrafield/hook.

## Lisans

GPL-3.0-or-later ([COPYING](COPYING)). GPLv3 7(b) maddesi kapsamında ek şart: yazar atfı korunmalıdır — ayrıntı [ATTRIBUTION.md](ATTRIBUTION.md). Ücretsiz ve ticari kullanım, değiştirme ve dağıtım serbesttir.

## Yazar

**M. Burak Şentürk**
- Web: [buraksenturk.net](https://buraksenturk.net)
- E-posta: mburaksenturk@gmail.com
- GitHub: [@mbrksntrk](https://github.com/mbrksntrk)
- Proje: [github.com/mbrksntrk/dolibarr-tevkifat](https://github.com/mbrksntrk/dolibarr-tevkifat)


---

**English:** Dolibarr module for Turkish VAT withholding (KDV tevkifatı) on supplier and customer invoices. The withheld VAT is booked as a single marked negative invoice line mapped by rate to the configured accounting account, so invoice totals, payments and journals are right without patching the core. GİB code dictionary 601–627, validation guard against stale withholding, audit events, MCP tools for the Dolibarr 24 AI module. Author: M. Burak Şentürk — https://buraksenturk.net. License: GPL-3.0-or-later with an attribution-preservation term (see ATTRIBUTION.md).
