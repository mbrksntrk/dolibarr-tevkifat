<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

/**
 * KDV tevkifatı service.
 *
 * A withholding is represented as a single dedicated invoice line (special_code = SPECIAL_CODE):
 * negative amount, VAT 0, booked on the accounting account chosen from the module settings.
 * Everything the module knows about the invoice is stored in extrafields on the invoice row
 * (code, rate, amount, line id), so nothing is lost when the module is reinstalled.
 */
class TRTevkifat
{
	/** Line marker; also skipped by the İşNet e-Fatura module when it builds the UBL. */
	const SPECIAL_CODE = 99;

	/** GİB tevkifat code → [default rate %, description] (KDV Genel Uygulama Tebliği, ekim 2021+). */
	const CODES = array(
		'601' => array(40, 'Yapım işleri ile bu işlerle birlikte ifa edilen mühendislik-mimarlık ve etüt-proje hizmetleri'),
		'602' => array(90, 'Etüt, plan-proje, danışmanlık, denetim ve benzeri hizmetler'),
		'603' => array(70, 'Makine, teçhizat, demirbaş ve taşıtlara ait tadil, bakım ve onarım hizmetleri'),
		'604' => array(50, 'Yemek servis hizmeti'),
		'605' => array(50, 'Organizasyon hizmeti'),
		'606' => array(90, 'İşgücü temin hizmetleri'),
		'607' => array(90, 'Özel güvenlik hizmeti'),
		'608' => array(90, 'Yapı denetim hizmetleri'),
		'609' => array(70, 'Fason olarak yaptırılan tekstil ve konfeksiyon işleri, çanta ve ayakkabı dikim işleri ve bu işlere aracılık hizmetleri'),
		'610' => array(90, 'Turistik mağazalara verilen müşteri bulma / götürme hizmetleri'),
		'611' => array(90, 'Spor kulüplerinin yayın, reklam ve isim hakkı gelirlerine konu işlemleri'),
		'612' => array(90, 'Temizlik hizmeti'),
		'613' => array(90, 'Çevre ve bahçe bakım hizmetleri'),
		'614' => array(50, 'Servis taşımacılığı hizmeti'),
		'615' => array(70, 'Her türlü baskı ve basım hizmetleri'),
		'616' => array(50, 'Diğer hizmetler (5018 sayılı Kanuna ekli cetveller kapsamındaki idare, kurum ve kuruluşlara)'),
		'617' => array(70, 'Hurda metalden elde edilen külçe teslimleri'),
		'618' => array(70, 'Hurda metalden elde edilenler dışındaki bakır, çinko, alüminyum ve kurşun külçe teslimleri'),
		'619' => array(70, 'Bakır, çinko, alüminyum ve kurşun ürünlerinin teslimi'),
		'620' => array(70, 'İstisnadan vazgeçenlerin hurda ve atık teslimi'),
		'621' => array(90, 'Metal, plastik, lastik, kauçuk, kâğıt ve cam hurda ve atıklardan elde edilen hammadde teslimi'),
		'622' => array(90, 'Pamuk, tiftik, yün ve yapağı ile ham post ve deri teslimleri'),
		'623' => array(50, 'Ağaç ve orman ürünleri teslimi'),
		'624' => array(20, 'Yük taşımacılığı hizmeti'),
		'625' => array(30, 'Ticari reklam hizmetleri'),
		'626' => array(20, 'Diğer teslimler'),
		'627' => array(50, 'Demir-çelik ürünlerinin teslimi'),
	);

	public $db;
	public $error = '';
	public $errors = array();

	public function __construct($db)
	{
		$this->db = $db;
	}

	// ---------------------------------------------------------------- object helpers

	/**
	 * @param  string $element 'supplier_invoice' | 'invoice'
	 * @return FactureFournisseur|Facture|null
	 */
	public function fetchInvoice($element, $id)
	{
		if ($element === 'supplier_invoice') {
			require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
			$o = new FactureFournisseur($this->db);
		} elseif ($element === 'invoice') {
			require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
			$o = new Facture($this->db);
		} else {
			$this->error = 'Unknown element '.$element;
			return null;
		}
		if ($o->fetch($id) <= 0) {
			$this->error = $o->error ?: 'Not found';
			return null;
		}
		$o->fetch_thirdparty();
		$o->fetch_optionals();
		return $o;
	}

	public static function isSupplier($invoice)
	{
		return $invoice instanceof FactureFournisseur;
	}

	public static function isEnabledFor($invoice)
	{
		return self::isSupplier($invoice) ? getDolGlobalInt('TRTEVKIFAT_SUPPLIER_ENABLED', 1) : getDolGlobalInt('TRTEVKIFAT_CUSTOMER_ENABLED', 1);
	}

	public static function isTevkifatLine($line)
	{
		return (int) ($line->special_code ?? 0) === self::SPECIAL_CODE;
	}

	/** @return object|null the tevkifat line currently on the invoice */
	public static function findLine($invoice)
	{
		foreach ((array) $invoice->lines as $l) {
			if (self::isTevkifatLine($l)) {
				return $l;
			}
		}
		return null;
	}

	/** Code list for select boxes: code => "code - label (rate%)" */
	public static function codeOptions($langs = null)
	{
		$out = array();
		foreach (self::CODES as $code => $def) {
			$out[$code] = $code.' - '.$def[1].' ('.self::rateLabel($def[0]).')';
		}
		return $out;
	}

	/** 30 → "3/10", 25 → "%25" */
	public static function rateLabel($rate)
	{
		$rate = (float) $rate;
		if ($rate > 0 && fmod($rate, 10) == 0) {
			return (int) ($rate / 10).'/10';
		}
		return '%'.rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
	}

	// ---------------------------------------------------------------- settings

	/** Parse "20=360.50.002;30=360.50.001" → [20 => '360.50.002', ...] */
	public static function parseAccountMap($str)
	{
		$map = array();
		foreach (preg_split('/[;,\n]+/', (string) $str) as $pair) {
			if (strpos($pair, '=') === false) {
				continue;
			}
			list($rate, $acc) = array_map('trim', explode('=', $pair, 2));
			if ($rate !== '' && $acc !== '') {
				$map[(string) (float) $rate] = $acc;
			}
		}
		return $map;
	}

	/** Accounting account number for an invoice type and rate. */
	public static function accountNumberFor($invoice, $rate)
	{
		if (!self::isSupplier($invoice)) {
			return trim(getDolGlobalString('TRTEVKIFAT_CUSTOMER_ACCOUNT', '391.10.020'));
		}
		$map = self::parseAccountMap(getDolGlobalString('TRTEVKIFAT_SUPPLIER_ACCOUNTS'));
		$key = (string) (float) $rate;
		if (isset($map[$key])) {
			return $map[$key];
		}
		return trim(getDolGlobalString('TRTEVKIFAT_SUPPLIER_ACCOUNT_DEFAULT', '360.50'));
	}

	/**
	 * rowid of llx_accounting_account for a number in the active chart.
	 * @return int  >0 rowid, 0 if not found
	 */
	public function accountRowid($number)
	{
		if ($number === '') {
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingaccount.class.php';
		$aa = new AccountingAccount($this->db);
		$r = $aa->fetch(0, $number, 1);
		return $r > 0 ? (int) $aa->id : 0;
	}

	public function lineLabel($code, $rate)
	{
		$tpl = getDolGlobalString('TRTEVKIFAT_LINE_LABEL', 'KDV Tevkifatı %RATE% (GİB %CODE%)');
		return str_replace(array('%RATE%', '%CODE%', '%DESC%'), array(self::rateLabel($rate), $code, self::CODES[$code][1] ?? ''), $tpl);
	}

	// ---------------------------------------------------------------- computation

	/**
	 * Totals of the invoice without the tevkifat line and the resulting withholding.
	 *
	 * @return array{ht:float,tva:float,ttc:float,rate:float,withheld:float,payable:float,vat_by_rate:array}
	 */
	public function compute($invoice, $rate)
	{
		$ht = $tva = $ttc = 0.0;
		$byRate = array();
		foreach ((array) $invoice->lines as $l) {
			if (self::isTevkifatLine($l)) {
				continue;
			}
			$ht += (float) $l->total_ht;
			$tva += (float) $l->total_tva;
			$ttc += (float) $l->total_ttc;
			$k = (string) price2num($l->tva_tx);
			$byRate[$k] = ($byRate[$k] ?? 0) + (float) $l->total_tva;
		}
		$withheld = (float) price2num($tva * (float) $rate / 100, 'MT');
		return array(
			'ht' => $ht,
			'tva' => $tva,
			'ttc' => $ttc,
			'rate' => (float) $rate,
			'withheld' => $withheld,
			'payable' => $ttc - $withheld,
			'vat_by_rate' => $byRate,
		);
	}

	/** Current stored state (from extrafields), with live line check. */
	public function info($invoice)
	{
		$opt = $invoice->array_options ?? array();
		$line = self::findLine($invoice);
		return array(
			'code' => (string) ($opt['options_trtevkifat_code'] ?? ''),
			'rate' => (float) ($opt['options_trtevkifat_rate'] ?? 0),
			'amount' => (float) ($opt['options_trtevkifat_amount'] ?? 0),
			'line_id' => (int) ($opt['options_trtevkifat_line'] ?? 0),
			'line' => $line,
			'applied' => $line !== null,
			'stale' => $line !== null && abs((float) $line->total_ht + (float) ($opt['options_trtevkifat_amount'] ?? 0)) > 0.005,
		);
	}

	// ---------------------------------------------------------------- actions

	/**
	 * Apply (or re-apply) withholding on a draft invoice.
	 *
	 * @param  string $code  GİB code 601–627
	 * @param  float  $rate  Rate in %, 0 → code default
	 * @return int           >0 line id, <0 error (see ->error)
	 */
	public function apply($invoice, $code, $rate, User $user)
	{
		global $langs;
		$langs->load('trtevkifat@trtevkifat');

		$code = trim((string) $code);
		if (!isset(self::CODES[$code])) {
			$this->error = $langs->trans('TRTevkifatErrUnknownCode', $code);
			return -1;
		}
		$rate = (float) price2num($rate);
		if ($rate <= 0) {
			$rate = (float) self::CODES[$code][0];
		}
		if (!getDolGlobalInt('TRTEVKIFAT_ALLOW_RATE_OVERRIDE', 1) && $rate != self::CODES[$code][0]) {
			$this->error = $langs->trans('TRTevkifatErrRateLocked', self::rateLabel(self::CODES[$code][0]));
			return -1;
		}
		if ($rate > 100) {
			$this->error = $langs->trans('TRTevkifatErrRateRange');
			return -1;
		}
		if ((int) $invoice->statut !== 0) {
			$this->error = $langs->trans('TRTevkifatErrNotDraft');
			return -1;
		}

		$calc = $this->compute($invoice, $rate);
		// Credit notes carry negative VAT: the withholding line then becomes positive, which is what we want.
		if (abs($calc['tva']) < 0.005) {
			$this->error = $langs->trans('TRTevkifatErrNoVat');
			return -1;
		}

		$accountNumber = self::accountNumberFor($invoice, $rate);
		$accountId = $this->accountRowid($accountNumber);
		if ($accountNumber !== '' && $accountId <= 0) {
			$this->error = $langs->trans('TRTevkifatErrAccountMissing', $accountNumber);
			return -1;
		}

		$this->db->begin();

		$existing = self::findLine($invoice);
		if ($existing && $this->deleteLine($invoice, $existing->id) < 0) {
			$this->db->rollback();
			return -1;
		}

		if (getDolGlobalString('TRTEVKIFAT_LINE_POSITION', 'last') === 'first') {
			foreach ((array) $invoice->lines as $l) {
				if (!self::isTevkifatLine($l)) {
					$invoice->updateRangOfLine($l->id, (int) $l->rang + 1);
				}
			}
			$rang = 1;
		} else {
			$rang = -1;
		}

		$label = $this->lineLabel($code, $rate);
		$amount = -$calc['withheld'];
		$lineId = $this->addLine($invoice, $label, $amount, $accountId, $rang);
		if ($lineId <= 0) {
			$this->db->rollback();
			return -1;
		}

		$invoice->array_options['options_trtevkifat_code'] = $code;
		$invoice->array_options['options_trtevkifat_rate'] = $rate;
		$invoice->array_options['options_trtevkifat_amount'] = $calc['withheld'];
		$invoice->array_options['options_trtevkifat_line'] = $lineId;
		if ($invoice->insertExtraFields() < 0) {
			$this->error = $invoice->error;
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();

		$this->audit($invoice, 'AC_TRTEVKIFAT_APPLY', $langs->trans('TRTevkifatAuditApplied', $code, self::rateLabel($rate), price($calc['withheld'])),
			$langs->trans('TRTevkifatAuditDetail', price($calc['tva']), $accountNumber, price($calc['payable'])), $user);
		return $lineId;
	}

	/** Remove the withholding line and clear metadata. */
	public function remove($invoice, User $user)
	{
		global $langs;
		$langs->load('trtevkifat@trtevkifat');
		if ((int) $invoice->statut !== 0) {
			$this->error = $langs->trans('TRTevkifatErrNotDraft');
			return -1;
		}
		$this->db->begin();
		$line = self::findLine($invoice);
		if ($line && $this->deleteLine($invoice, $line->id) < 0) {
			$this->db->rollback();
			return -1;
		}
		$prev = $this->info($invoice);
		$invoice->array_options['options_trtevkifat_code'] = '';
		$invoice->array_options['options_trtevkifat_rate'] = 0;
		$invoice->array_options['options_trtevkifat_amount'] = 0;
		$invoice->array_options['options_trtevkifat_line'] = 0;
		if ($invoice->insertExtraFields() < 0) {
			$this->error = $invoice->error;
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		$this->audit($invoice, 'AC_TRTEVKIFAT_REMOVE', $langs->trans('TRTevkifatAuditRemoved', $prev['code'], price($prev['amount'])), '', $user);
		return 1;
	}

	// ---------------------------------------------------------------- Dolibarr line I/O (the only place that knows the two APIs)

	private function addLine($invoice, $label, $amount, $accountId, $rang)
	{
		if (self::isSupplier($invoice)) {
			// addline($desc, $pu, $txtva, $txlocaltax1, $txlocaltax2, $qty, $fk_product, $remise_percent, $date_start, $date_end,
			//         $fk_code_ventilation, $info_bits, $price_base_type, $type, $rang, $notrigger, $array_options, $fk_unit, $origin_id, $pu_devise, $ref_supplier, $special_code)
			$r = $invoice->addline($label, $amount, 0, 0, 0, 1, 0, 0, '', '', $accountId, 0, 'HT', 1, $rang, 0, array(), null, 0, 0, '', self::SPECIAL_CODE);
		} else {
			// addline($desc, $pu_ht, $qty, $txtva, $txlocaltax1, $txlocaltax2, $fk_product, $remise_percent, $date_start, $date_end,
			//         $fk_code_ventilation, $info_bits, $fk_remise_except, $price_base_type, $pu_ttc, $type, $rang, $special_code)
			$r = $invoice->addline($label, $amount, 1, 0, 0, 0, 0, 0, '', '', $accountId, 0, 0, 'HT', 0, 1, $rang, self::SPECIAL_CODE);
		}
		if ($r <= 0) {
			$this->error = $invoice->error ?: 'addline failed';
			$this->errors = $invoice->errors;
		}
		return $r;
	}

	private function deleteLine($invoice, $lineId)
	{
		$r = $invoice->deleteLine($lineId); // same first argument on both classes
		if ($r < 0) {
			$this->error = $invoice->error ?: 'deleteLine failed';
			return -1;
		}
		$invoice->fetch_lines();
		return 1;
	}

	private function audit($invoice, $code, $label, $note, User $user)
	{
		dol_syslog('TRTevkifat '.$code.' '.$invoice->element.'#'.$invoice->id.' '.$label.' '.$note, LOG_INFO);
		if (!isModEnabled('agenda')) {
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		$ac = new ActionComm($this->db);
		$ac->type_code = 'AC_OTH_AUTO';
		$ac->code = $code;
		$ac->label = dol_trunc($label, 250, 'right', 'UTF-8', 1);
		$ac->note_private = $note;
		$ac->datep = dol_now();
		$ac->datef = $ac->datep;
		$ac->percentage = -1;
		$ac->socid = (int) $invoice->socid;
		$ac->fk_element = (int) $invoice->id;
		$ac->elementtype = self::isSupplier($invoice) ? 'invoice_supplier' : 'invoice';
		$ac->userownerid = (int) $user->id;
		$ac->authorid = (int) $user->id;
		$ac->fk_project = (int) ($invoice->fk_project ?? 0);
		return $ac->create($user);
	}
}
