<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

require_once DOL_DOCUMENT_ROOT.'/ai/class/mcptool.class.php';
dol_include_once('/trtevkifat/class/trtevkifat.class.php');

/**
 * MCP tools exposed by the TRTevkifat module (Dolibarr AI module, hook 'addMcpTools').
 * Every tool runs with the rights of the MCP user.
 */
class ToolTRTevkifat extends McpTool
{
	public function getCategories(): array
	{
		return ['billing', 'accountancy'];
	}

	public function getDefinitions(): array
	{
		$invoiceRef = [
			"invoice_type" => [
				"type" => "string",
				"enum" => ["supplier", "customer"],
				"description" => "supplier = purchase invoice (tedarikçi/alış faturası), customer = sales invoice (müşteri/satış faturası)."
			],
			"invoice_id" => ["type" => "integer", "description" => "Dolibarr invoice id. Provide this or invoice_ref."],
			"invoice_ref" => ["type" => "string", "description" => "Invoice reference (e.g. (PROV7), FA2609-0004) or, for supplier invoices, the supplier's invoice number."],
		];
		return [
			[
				"name" => "tevkifat_codes",
				"description" => "List Turkish VAT withholding (KDV tevkifatı) GİB codes 601–627 with their default rates and descriptions, and the module's rate→accounting account mapping. Use it to pick the right code for a service/goods description.",
				"inputSchema" => ["type" => "object", "properties" => new stdClass()],
			],
			[
				"name" => "tevkifat_info",
				"description" => "Show the VAT withholding (KDV tevkifatı) state of an invoice: code, rate, withheld amount, whether the withholding line exists and is up to date, and the calculation (VAT total, withheld, payable). Read-only.",
				"inputSchema" => ["type" => "object", "properties" => $invoiceRef, "required" => ["invoice_type"]],
			],
			[
				"name" => "tevkifat_apply",
				"description" => "Apply or recalculate VAT withholding (KDV tevkifatı) on a DRAFT invoice: adds/replaces the negative withholding line (VAT 0, mapped to the configured accounting account) so the invoice total equals the payable amount. Ask the user for confirmation before calling.",
				"inputSchema" => [
					"type" => "object",
					"properties" => $invoiceRef + [
						"code" => ["type" => "string", "description" => "GİB withholding code 601–627 (e.g. 625 for advertising services)."],
						"rate" => ["type" => "number", "description" => "Withholding rate in percent (30 = 3/10). Omit to use the code's default rate."],
					],
					"required" => ["invoice_type", "code"],
				],
			],
			[
				"name" => "tevkifat_remove",
				"description" => "Remove the VAT withholding (KDV tevkifatı) line and clear the withholding data from a DRAFT invoice. Ask the user for confirmation before calling.",
				"inputSchema" => ["type" => "object", "properties" => $invoiceRef, "required" => ["invoice_type"]],
			],
		];
	}

	public function execute(string $toolName, array $args)
	{
		global $langs;
		$langs->load('trtevkifat@trtevkifat');
		if (!isModEnabled('trtevkifat')) {
			return ["error" => "TRTevkifat module is not enabled."];
		}
		switch ($toolName) {
			case 'tevkifat_codes':
				return $this->codes();
			case 'tevkifat_info':
				return $this->info($args);
			case 'tevkifat_apply':
				return $this->apply($args);
			case 'tevkifat_remove':
				return $this->remove($args);
		}
		return ["error" => "Tool function '$toolName' not found."];
	}

	private function codes()
	{
		if (!$this->user->hasRight('trtevkifat', 'read')) {
			return ["error" => "Permission denied: trtevkifat read"];
		}
		$codes = [];
		foreach (TRTevkifat::CODES as $code => $def) {
			$codes[] = ["code" => $code, "rate_percent" => $def[0], "rate_label" => TRTevkifat::rateLabel($def[0]), "description" => $def[1]];
		}
		return [
			"codes" => $codes,
			"supplier_accounts_by_rate" => TRTevkifat::parseAccountMap(getDolGlobalString('TRTEVKIFAT_SUPPLIER_ACCOUNTS')),
			"supplier_account_default" => getDolGlobalString('TRTEVKIFAT_SUPPLIER_ACCOUNT_DEFAULT'),
			"customer_account" => getDolGlobalString('TRTEVKIFAT_CUSTOMER_ACCOUNT'),
			"rate_override_allowed" => (bool) getDolGlobalInt('TRTEVKIFAT_ALLOW_RATE_OVERRIDE', 1),
		];
	}

	/** @return array|CommonObject error array or invoice */
	private function resolveInvoice(array $args)
	{
		$type = ($args['invoice_type'] ?? '') === 'customer' ? 'invoice' : 'supplier_invoice';
		$isSupplier = $type === 'supplier_invoice';
		if ($isSupplier ? !$this->user->hasRight('fournisseur', 'facture', 'lire') : !$this->user->hasRight('facture', 'lire')) {
			return ["error" => "Permission denied: invoice read"];
		}
		if (!$this->user->hasRight('trtevkifat', 'read')) {
			return ["error" => "Permission denied: trtevkifat read"];
		}
		$id = (int) ($args['invoice_id'] ?? 0);
		$ref = trim((string) ($args['invoice_ref'] ?? ''));
		if ($id <= 0 && $ref !== '') {
			$table = $isSupplier ? 'facture_fourn' : 'facture';
			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$table." WHERE entity IN (".getEntity($isSupplier ? 'supplier_invoice' : 'invoice').") AND (ref = '".$this->db->escape($ref)."'";
			if ($isSupplier) {
				$sql .= " OR ref_supplier = '".$this->db->escape($ref)."'";
			}
			$sql .= ") ORDER BY rowid DESC LIMIT 1";
			$res = $this->db->query($sql);
			if ($res && ($o = $this->db->fetch_object($res))) {
				$id = (int) $o->rowid;
			}
		}
		if ($id <= 0) {
			return ["error" => "Invoice not found (give invoice_id or invoice_ref)."];
		}
		$svc = new TRTevkifat($this->db);
		$inv = $svc->fetchInvoice($type, $id);
		if (!$inv) {
			return ["error" => "Invoice not found: ".$svc->error];
		}
		return $inv;
	}

	private function describe($inv, TRTevkifat $svc)
	{
		$info = $svc->info($inv);
		$calc = $svc->compute($inv, $info['rate']);
		$isSupplier = TRTevkifat::isSupplier($inv);
		return [
			"invoice_type" => $isSupplier ? 'supplier' : 'customer',
			"invoice_id" => (int) $inv->id,
			"ref" => $inv->ref,
			"supplier_ref" => $isSupplier ? $inv->ref_supplier : null,
			"thirdparty" => $inv->thirdparty->name ?? null,
			"status" => (int) $inv->statut,
			"is_draft" => (int) $inv->statut === 0,
			"withholding" => [
				"applied" => $info['applied'],
				"stale" => $info['stale'],
				"code" => $info['code'] ?: null,
				"code_description" => $info['code'] !== '' ? (TRTevkifat::CODES[$info['code']][1] ?? null) : null,
				"rate_percent" => $info['rate'],
				"rate_label" => $info['rate'] > 0 ? TRTevkifat::rateLabel($info['rate']) : null,
				"withheld_amount" => $info['amount'],
				"line_id" => $info['line'] ? (int) $info['line']->id : null,
				"accounting_account" => $info['code'] !== '' ? TRTevkifat::accountNumberFor($inv, $info['rate']) : null,
			],
			"calculation" => [
				"total_ht" => round($calc['ht'], 2),
				"total_vat" => round($calc['tva'], 2),
				"total_ttc_gross" => round($calc['ttc'], 2),
				"withheld" => $calc['withheld'],
				"payable" => round($calc['payable'], 2),
				"vat_by_rate" => $calc['vat_by_rate'],
			],
			"invoice_total_ttc" => (float) $inv->total_ttc,
			"link" => dol_buildpath('/trtevkifat/card.php', 2).'?element='.($isSupplier ? 'supplier_invoice' : 'invoice').'&id='.$inv->id,
		];
	}

	private function info(array $args)
	{
		$inv = $this->resolveInvoice($args);
		if (is_array($inv)) {
			return $inv;
		}
		return $this->describe($inv, new TRTevkifat($this->db));
	}

	private function canWrite($inv)
	{
		if (!$this->user->hasRight('trtevkifat', 'write')) {
			return false;
		}
		return TRTevkifat::isSupplier($inv) ? $this->user->hasRight('fournisseur', 'facture', 'creer') : $this->user->hasRight('facture', 'creer');
	}

	private function apply(array $args)
	{
		$inv = $this->resolveInvoice($args);
		if (is_array($inv)) {
			return $inv;
		}
		if (!$this->canWrite($inv)) {
			return ["error" => "Permission denied: trtevkifat write / invoice create"];
		}
		if (!TRTevkifat::isEnabledFor($inv)) {
			return ["error" => "Withholding is disabled for this invoice type in module setup."];
		}
		$svc = new TRTevkifat($this->db);
		$r = $svc->apply($inv, (string) ($args['code'] ?? ''), (float) ($args['rate'] ?? 0), $this->user);
		if ($r <= 0) {
			return ["error" => $svc->error];
		}
		$inv = $svc->fetchInvoice(TRTevkifat::isSupplier($inv) ? 'supplier_invoice' : 'invoice', $inv->id);
		return ["success" => true, "message" => "Withholding line added; invoice total now equals the payable amount."] + $this->describe($inv, $svc);
	}

	private function remove(array $args)
	{
		$inv = $this->resolveInvoice($args);
		if (is_array($inv)) {
			return $inv;
		}
		if (!$this->canWrite($inv)) {
			return ["error" => "Permission denied: trtevkifat write / invoice create"];
		}
		$svc = new TRTevkifat($this->db);
		$r = $svc->remove($inv, $this->user);
		if ($r <= 0) {
			return ["error" => $svc->error];
		}
		$inv = $svc->fetchInvoice(TRTevkifat::isSupplier($inv) ? 'supplier_invoice' : 'invoice', $inv->id);
		return ["success" => true, "message" => "Withholding removed."] + $this->describe($inv, $svc);
	}
}
