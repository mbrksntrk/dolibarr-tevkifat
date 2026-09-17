<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Blocks validation of an invoice whose withholding is inconsistent:
 * a code is set but the withholding line is missing, or the invoice lines changed
 * after the line was computed (amount no longer matches).
 */
class InterfaceGuard extends DolibarrTriggers
{
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'trtevkifat';
		$this->description = 'Refuses to validate invoices with a stale or missing KDV tevkifatı line.';
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'bill';
	}

	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('trtevkifat') || !is_object($object)) {
			return 0;
		}
		if ($action !== 'BILL_VALIDATE' && $action !== 'BILL_SUPPLIER_VALIDATE') {
			return 0;
		}
		if (!getDolGlobalInt('TRTEVKIFAT_BLOCK_VALIDATE_IF_STALE', 1)) {
			return 0;
		}
		dol_include_once('/trtevkifat/class/trtevkifat.class.php');
		if (!TRTevkifat::isEnabledFor($object)) {
			return 0;
		}
		if (!is_array($object->array_options) || empty($object->array_options)) {
			$object->fetch_optionals();
		}
		if (empty($object->lines)) {
			$object->fetch_lines();
		}
		$svc = new TRTevkifat($this->db);
		$info = $svc->info($object);
		if ($info['code'] === '') {
			return 0;
		}
		$langs->load('trtevkifat@trtevkifat');
		if (!$info['applied']) {
			$this->errors[] = $langs->trans('TRTevkifatErrValidateLineMissing', $info['code']);
			return -1;
		}
		if ($info['stale']) {
			$calc = $svc->compute($object, $info['rate']);
			$this->errors[] = $langs->trans('TRTevkifatErrValidateStale', price($info['amount']), price($calc['withheld']));
			return -1;
		}
		return 0;
	}
}
