<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Türkiye KDV tevkifatı (VAT withholding) for supplier and customer invoices.
 *
 * The withheld VAT is booked as a dedicated negative "tevkifat" line on the invoice
 * (VAT 0, mapped to the configured accounting account). Dolibarr's own totals,
 * payments and journals then reflect the amount actually payable — no core patching.
 */
class modTRTevkifat extends DolibarrModules
{
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;
		$this->numero = 510777;
		$this->rights_class = 'trtevkifat';
		$this->family = 'financial';
		$this->module_position = '91';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'Türkiye KDV tevkifatı: alış ve satış faturalarında tevkifat satırı ve muhasebe eşlemesi';
		$this->descriptionlong = 'Tevkifatlı faturalarda kesilen KDV\'yi orana göre eşlenen hesaba (360.50.xxx / 391) giden negatif bir fatura satırı olarak işler; fatura toplamı ödenecek tutarı gösterir, yevmiye kayıtları otomatik doğru oluşur.';
		$this->editor_name = 'M. Burak Şentürk';
		$this->editor_url = 'https://buraksenturk.net';
		$this->version = '2.1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'bill';

		$this->module_parts = array('triggers' => 1, 'hooks' => array('aimcp'));
		$this->dirs = array();
		$this->config_page_url = array('setup.php@trtevkifat');
		$this->depends = array('modFacture', 'modFournisseur');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('trtevkifat@trtevkifat');
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(20, 0);

		$this->const = array(
			array('TRTEVKIFAT_SUPPLIER_ENABLED', 'chaine', '1', 'Tedarikçi faturalarında tevkifat', 0, 'current', 0),
			array('TRTEVKIFAT_CUSTOMER_ENABLED', 'chaine', '1', 'Müşteri faturalarında tevkifat', 0, 'current', 0),
			array('TRTEVKIFAT_SUPPLIER_ACCOUNTS', 'chaine', '20=360.50.002;30=360.50.001;40=360.50.003;50=360.50.004;70=360.50.005;90=360.50.006', 'Oran(%)=hesap listesi (sorumlu sıfatıyla ödenecek KDV)', 0, 'current', 0),
			array('TRTEVKIFAT_SUPPLIER_ACCOUNT_DEFAULT', 'chaine', '360.50', 'Eşleşmeyen oranlar için hesap', 0, 'current', 0),
			array('TRTEVKIFAT_CUSTOMER_ACCOUNT', 'chaine', '391.10.020', 'Satış tevkifatı satırının hesabı (hesaplanan KDV)', 0, 'current', 0),
			array('TRTEVKIFAT_LINE_LABEL', 'chaine', 'KDV Tevkifatı %RATE% (GİB %CODE%)', 'Tevkifat satırı açıklaması', 0, 'current', 0),
			array('TRTEVKIFAT_LINE_POSITION', 'chaine', 'last', 'first | last', 0, 'current', 0),
			array('TRTEVKIFAT_ALLOW_RATE_OVERRIDE', 'chaine', '1', 'Kod varsayılanından farklı oran girilebilsin', 0, 'current', 0),
			array('TRTEVKIFAT_BLOCK_VALIDATE_IF_STALE', 'chaine', '1', 'Tevkifat satırı eksik/güncel değilse onayı engelle', 0, 'current', 0),
		);

		if (!isModEnabled('trtevkifat')) {
			$conf->trtevkifat = new stdClass();
			$conf->trtevkifat->enabled = 0;
		}

		$this->tabs = array(
			'supplier_invoice:+trtevkifat:TRTevkifatTab:trtevkifat@trtevkifat:getDolGlobalInt("TRTEVKIFAT_SUPPLIER_ENABLED", 1) && $user->hasRight("trtevkifat","read"):/trtevkifat/card.php?element=supplier_invoice&id=__ID__',
			'invoice:+trtevkifat:TRTevkifatTab:trtevkifat@trtevkifat:getDolGlobalInt("TRTEVKIFAT_CUSTOMER_ENABLED", 1) && $user->hasRight("trtevkifat","read"):/trtevkifat/card.php?element=invoice&id=__ID__',
		);
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = 5107771;
		$this->rights[$r][1] = 'Tevkifat bilgilerini görüntüle';
		$this->rights[$r][4] = 'read';
		$r++;
		$this->rights[$r][0] = 5107772;
		$this->rights[$r][1] = 'Tevkifat uygula / kaldır';
		$this->rights[$r][4] = 'write';

		$this->menu = array();
	}

	public function init($options = '')
	{
		$this->createExtraFields();
		$this->cleanupLegacy();
		return $this->_init(array(), $options);
	}

	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}

	/**
	 * Withholding metadata lives on the invoice itself (survives module reinstall, exportable, list-filterable).
	 */
	private function createExtraFields()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		dol_include_once('/trtevkifat/class/trtevkifat.class.php');
		$ef = new ExtraFields($this->db);
		$lang = 'trtevkifat@trtevkifat';
		$options = array('' => '');
		foreach (TRTevkifat::CODES as $code => $def) {
			$options[$code] = $code.' - '.dol_trunc($def[1], 60).' ('.$def[0].'%)';
		}
		$enabled = 'isModEnabled("trtevkifat")';
		// list=5: shown on list and card, never in create/edit forms — the line must be (re)computed from the tab.
		$fields = array(
			array('trtevkifat_code', 'TRTevkifatCode', 'select', 150, '', array('options' => $options), 5, 'TRTevkifatCodeHelp'),
			array('trtevkifat_rate', 'TRTevkifatRate', 'double', 151, '5,2', array(), 5, 'TRTevkifatRateHelp'),
			array('trtevkifat_amount', 'TRTevkifatAmount', 'price', 152, '', array(), 5, ''),
			array('trtevkifat_line', 'TRTevkifatLine', 'int', 153, 11, array(), 0, ''),
		);
		foreach (array('facture_fourn', 'facture') as $el) {
			$ef->fetch_name_optionals_label($el, true);
			$existing = $ef->attributes[$el]['label'] ?? array();
			foreach ($fields as $f) {
				list($name, $label, $type, $pos, $size, $param, $list, $help) = $f;
				if (isset($existing[$name])) {
					$ef->update($name, $label, $type, $size, $el, 0, 0, $pos, $param, 0, '0', $list, $help, '', '', '', $lang, $enabled);
				} else {
					$ef->addExtraField($name, $label, $type, $pos, $size, $el, 0, 0, '', $param, 0, '0', $list, $help, '', '', $lang, $enabled);
				}
			}
		}
	}

	/**
	 * Earlier versions of this module (numero 500120, journal.php) left rights and menus behind.
	 */
	private function cleanupLegacy()
	{
		$this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'user_rights WHERE fk_id IN (5001201, 5001202)');
		$this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'rights_def WHERE id IN (5001201, 5001202)');
		$this->db->query('DELETE FROM '.MAIN_DB_PREFIX."menu WHERE url LIKE '/trtevkifat/journal.php%'");
		$this->db->query('DELETE FROM '.MAIN_DB_PREFIX."const WHERE name = 'MAIN_MODULE_TRTEVKIFAT_HOOKS'");
	}
}
