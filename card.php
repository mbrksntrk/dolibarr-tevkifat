<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 *
 * "Tevkifat" tab on the supplier invoice and customer invoice cards.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/invoice.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/fourn.lib.php';
dol_include_once('/trtevkifat/class/trtevkifat.class.php');

$langs->loadLangs(array('bills', 'companies', 'accountancy', 'trtevkifat@trtevkifat'));

$id = GETPOSTINT('id');
$element = GETPOST('element', 'aZ09') === 'invoice' ? 'invoice' : 'supplier_invoice';
$action = GETPOST('action', 'aZ09');

if (!$user->hasRight('trtevkifat', 'read')) {
	accessforbidden();
}

$svc = new TRTevkifat($db);
$object = $svc->fetchInvoice($element, $id);
if (!$object) {
	dol_print_error($db, $svc->error);
	exit;
}
$isSupplier = TRTevkifat::isSupplier($object);
restrictedArea($user, $isSupplier ? 'fournisseur' : 'facture', $object->id, $isSupplier ? 'facture_fourn' : 'facture', $isSupplier ? 'facture' : '');

$canWrite = $user->hasRight('trtevkifat', 'write') && ($isSupplier ? $user->hasRight('fournisseur', 'facture', 'creer') : $user->hasRight('facture', 'creer'));
$isDraft = (int) $object->statut === 0;
$self = $_SERVER['PHP_SELF'].'?element='.$element.'&id='.$object->id;

/* ------------------------------------------------------------------ actions */

if ($action === 'apply' && $canWrite) {
	$r = $svc->apply($object, GETPOST('code', 'alpha'), GETPOST('rate', 'alpha'), $user);
	if ($r > 0) {
		setEventMessages($langs->trans('TRTevkifatApplied'), null, 'mesgs');
	} else {
		setEventMessages($svc->error, $svc->errors, 'errors');
	}
	header('Location: '.$self);
	exit;
}
if ($action === 'remove' && $canWrite) {
	$r = $svc->remove($object, $user);
	setEventMessages($r > 0 ? $langs->trans('TRTevkifatRemoved') : $svc->error, null, $r > 0 ? 'mesgs' : 'errors');
	header('Location: '.$self);
	exit;
}

/* ------------------------------------------------------------------ view */

$form = new Form($db);
$title = $langs->trans('TRTevkifatTab').' - '.$object->ref;
llxHeader('', $title);

if ($isSupplier) {
	$head = facturefourn_prepare_head($object);
	print dol_get_fiche_head($head, 'trtevkifat', $langs->trans('SupplierInvoice'), -1, 'supplier_invoice');
	$linkback = '<a href="'.DOL_URL_ROOT.'/fourn/facture/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
	dol_banner_tab($object, 'id', $linkback, 1, 'rowid', 'ref', '', '', 0, '', '', 1);
} else {
	$head = facture_prepare_head($object);
	print dol_get_fiche_head($head, 'trtevkifat', $langs->trans('InvoiceCustomer'), -1, 'bill');
	$linkback = '<a href="'.DOL_URL_ROOT.'/compta/facture/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
	dol_banner_tab($object, 'id', $linkback, 1, 'rowid', 'ref', '', '', 0, '', '', 1);
}

print '<div class="fichecenter"><div class="underbanner clearboth"></div>';

$info = $svc->info($object);
$enabled = TRTevkifat::isEnabledFor($object);

if (!$enabled) {
	print '<div class="warning">'.$langs->trans('TRTevkifatDisabledForType').'</div>';
}
if ($user->admin) {
	print '<div class="right opacitymedium small"><a href="'.dol_buildpath('/trtevkifat/admin/setup.php', 1).'">'.img_picto('', 'setup', 'class="paddingright"').$langs->trans('TRTevkifatSetup').'</a></div>';
}

// Current state
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('Status').'</td><td>';
if ($info['applied']) {
	print '<span class="badge badge-status4 badge-status">'.$langs->trans('TRTevkifatStateApplied').'</span>';
	if ($info['stale']) {
		print ' <span class="badge badge-status1 badge-status">'.$langs->trans('TRTevkifatStateStale').'</span>';
	}
} elseif ($info['code'] !== '') {
	print '<span class="badge badge-status1 badge-status">'.$langs->trans('TRTevkifatStateLineMissing').'</span>';
} else {
	print '<span class="opacitymedium">'.$langs->trans('TRTevkifatStateNone').'</span>';
}
print '</td></tr>';
if ($info['code'] !== '') {
	$def = TRTevkifat::CODES[$info['code']] ?? array(0, '?');
	print '<tr><td>'.$langs->trans('TRTevkifatCode').'</td><td><b>'.dol_escape_htmltag($info['code']).'</b> - '.dol_escape_htmltag($def[1]).'</td></tr>';
	print '<tr><td>'.$langs->trans('TRTevkifatRate').'</td><td>'.TRTevkifat::rateLabel($info['rate']).'</td></tr>';
	print '<tr><td>'.$langs->trans('TRTevkifatAmount').'</td><td>'.price($info['amount'], 0, $langs, 1, -1, -1, $conf->currency).'</td></tr>';
	print '<tr><td>'.$langs->trans('AccountAccounting').'</td><td>'.dol_escape_htmltag(TRTevkifat::accountNumberFor($object, $info['rate'])).'</td></tr>';
}
print '</table>';

// Simulation / edit form
$selCode = GETPOST('code', 'alpha') ?: ($info['code'] ?: '');
$selRate = (float) (GETPOST('rate', 'alpha') !== '' ? price2num(GETPOST('rate', 'alpha')) : $info['rate']);
if ($selCode !== '' && $selRate <= 0) {
	$selRate = (float) TRTevkifat::CODES[$selCode][0];
}
$previewRate = $selRate > 0 ? $selRate : 0;
$calc = $svc->compute($object, $previewRate);

print '<br><div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('TRTevkifatCalculation').'</th></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('AmountHT').'</td><td class="right">'.price($calc['ht']).'</td></tr>';
foreach ($calc['vat_by_rate'] as $vr => $amt) {
	print '<tr class="oddeven"><td>'.$langs->trans('VAT').' %'.$vr.'</td><td class="right">'.price($amt).'</td></tr>';
}
print '<tr class="oddeven"><td>'.$langs->trans('TotalVAT').'</td><td class="right">'.price($calc['tva']).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('AmountTTC').'</td><td class="right">'.price($calc['ttc']).'</td></tr>';
if ($previewRate > 0) {
	print '<tr class="oddeven"><td>'.$langs->trans('TRTevkifatWithheld', TRTevkifat::rateLabel($previewRate)).'</td><td class="right">- '.price($calc['withheld']).'</td></tr>';
	print '<tr class="oddeven"><td><b>'.$langs->trans('TRTevkifatPayable').'</b></td><td class="right"><b>'.price($calc['payable']).'</b></td></tr>';
}
print '</table></div>';

print '</div>';
print dol_get_fiche_end();

if ($canWrite && $enabled) {
	if ($isDraft) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="element" value="'.$element.'">';
		print '<input type="hidden" name="id" value="'.$object->id.'">';
		print '<input type="hidden" name="action" value="apply">';
		print '<table class="border centpercent tableforfield">';
		print '<tr><td class="titlefield fieldrequired">'.$langs->trans('TRTevkifatCode').'</td><td>';
		print $form->selectarray('code', TRTevkifat::codeOptions(), $selCode, 1, 0, 0, '', 0, 0, 0, '', 'minwidth500 maxwidth750', 1);
		print '</td></tr>';
		print '<tr><td>'.$langs->trans('TRTevkifatRate').' (%)</td><td>';
		$lock = !getDolGlobalInt('TRTEVKIFAT_ALLOW_RATE_OVERRIDE', 1);
		print '<input type="text" name="rate" class="width75 right" value="'.($selRate > 0 ? price2num($selRate) : '').'"'.($lock ? ' readonly' : '').'> ';
		print '<span class="opacitymedium">'.$langs->trans('TRTevkifatRateHint').'</span>';
		print '</td></tr>';
		print '</table>';
		print '<div class="center">';
		print '<input type="submit" class="button button-save" value="'.$langs->trans($info['applied'] ? 'TRTevkifatReapply' : 'TRTevkifatApply').'"> ';
		if ($info['applied'] || $info['code'] !== '') {
			print '<a class="button button-delete" href="'.$self.'&action=remove&token='.newToken().'">'.$langs->trans('TRTevkifatRemove').'</a>';
		}
		print '</div>';
		print '</form>';
		// Live rate default when a code is chosen
		print '<script>
		var trtRates = '.json_encode(array_map(function ($d) { return $d[0]; }, TRTevkifat::CODES)).';
		jQuery(function($){ $("#code").on("change", function(){ var r = trtRates[$(this).val()]; if (r) { $("input[name=rate]").val(r); } }); });
		</script>';
	} else {
		print '<div class="info">'.$langs->trans('TRTevkifatOnlyDraft').'</div>';
	}
}

llxFooter();
$db->close();
