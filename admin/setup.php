<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/events.class.php';
dol_include_once('/trtevkifat/class/trtevkifat.class.php');

$langs->loadLangs(array('admin', 'bills', 'accountancy', 'trtevkifat@trtevkifat'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

$sections = array(
	'TRTevkifatSectionScope' => array(
		'TRTEVKIFAT_SUPPLIER_ENABLED' => array('type' => 'yesno'),
		'TRTEVKIFAT_CUSTOMER_ENABLED' => array('type' => 'yesno'),
		'TRTEVKIFAT_ALLOW_RATE_OVERRIDE' => array('type' => 'yesno'),
		'TRTEVKIFAT_BLOCK_VALIDATE_IF_STALE' => array('type' => 'yesno'),
	),
	'TRTevkifatSectionAccounts' => array(
		'TRTEVKIFAT_SUPPLIER_ACCOUNTS' => array('type' => 'textarea'),
		'TRTEVKIFAT_SUPPLIER_ACCOUNT_DEFAULT' => array('type' => 'text', 'size' => 16),
		'TRTEVKIFAT_CUSTOMER_ACCOUNT' => array('type' => 'text', 'size' => 16),
	),
	'TRTevkifatSectionLine' => array(
		'TRTEVKIFAT_LINE_LABEL' => array('type' => 'text', 'size' => 60),
		'TRTEVKIFAT_LINE_POSITION' => array('type' => 'select', 'options' => array('last' => $langs->trans('TRTevkifatPosLast'), 'first' => $langs->trans('TRTevkifatPosFirst'))),
	),
);
$fields = array();
foreach ($sections as $s) {
	$fields += $s;
}

if ($action === 'update') {
	$error = 0;
	$changed = array();
	$db->begin();
	foreach ($fields as $code => $def) {
		if ($def['type'] === 'yesno') {
			$val = GETPOST($code, 'int') ? '1' : '0';
		} else {
			$val = trim(GETPOST($code, $def['type'] === 'textarea' ? 'nohtml' : 'alphanohtml'));
		}
		if ($def['type'] === 'textarea') {
			$val = implode(';', array_filter(array_map('trim', preg_split('/[;\r\n]+/', $val))));
		}
		if (getDolGlobalString($code) !== $val) {
			$changed[] = $code.': "'.getDolGlobalString($code).'" -> "'.$val.'"';
		}
		if (dolibarr_set_const($db, $code, $val, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}
	if ($error) {
		$db->rollback();
		setEventMessages($langs->trans('Error'), null, 'errors');
	} else {
		$db->commit();
		if ($changed) {
			$e = new Events($db);
			$e->type = 'TRTEVKIFAT_SETUP';
			$e->dateevent = dol_now();
			$e->label = dol_trunc('Tevkifat settings changed: '.implode('; ', $changed), 250, 'right', 'UTF-8', 1);
			$e->description = 'Tevkifat settings changed: '.implode('; ', $changed);
			$e->user_agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
			$e->ip = getUserRemoteIP();
			$e->create($user);
		}
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

llxHeader('', $langs->trans('TRTevkifatSetup'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('TRTevkifatSetup'), $linkback, 'title_setup');
print '<span class="opacitymedium">'.$langs->trans('TRTevkifatSetupDesc').'</span><br><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

foreach ($sections as $sectionKey => $sectionFields) {
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td class="titlefieldmiddle">'.$langs->trans($sectionKey).'</td><td>'.$langs->trans('Value').'</td><td></td></tr>';
	foreach ($sectionFields as $code => $def) {
		$current = getDolGlobalString($code);
		print '<tr class="oddeven"><td>'.$langs->trans($code).'</td><td>';
		if ($def['type'] === 'select') {
			print '<select class="flat" name="'.$code.'">';
			foreach ($def['options'] as $k => $label) {
				print '<option value="'.dol_escape_htmltag($k).'"'.($current === (string) $k ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
			}
			print '</select>';
		} elseif ($def['type'] === 'yesno') {
			print '<input type="checkbox" name="'.$code.'" value="1"'.($current === '1' ? ' checked' : '').'>';
		} elseif ($def['type'] === 'textarea') {
			print '<textarea class="flat" rows="3" cols="60" name="'.$code.'">'.dol_escape_htmltag(str_replace(';', ";\n", $current), 0, 1).'</textarea>';
		} else {
			print '<input type="text" class="flat" size="'.($def['size'] ?? 30).'" name="'.$code.'" value="'.dol_escape_htmltag($current).'">';
		}
		print '</td><td class="opacitymedium small">'.$langs->trans($code.'Help').'</td></tr>';
	}
	print '</table><br>';
}
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
print '</form>';

// Account check: every configured account must exist in the active chart
$svc = new TRTevkifat($db);
print load_fiche_titre($langs->trans('TRTevkifatAccountCheck'), '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('TRTevkifatRate').'</td><td>'.$langs->trans('AccountAccounting').'</td><td>'.$langs->trans('Status').'</td></tr>';
$rows = array();
foreach (TRTevkifat::parseAccountMap(getDolGlobalString('TRTEVKIFAT_SUPPLIER_ACCOUNTS')) as $rate => $acc) {
	$rows[] = array($langs->trans('SupplierInvoice').' '.TRTevkifat::rateLabel($rate), $acc);
}
$rows[] = array($langs->trans('SupplierInvoice').' ('.$langs->trans('Default').')', getDolGlobalString('TRTEVKIFAT_SUPPLIER_ACCOUNT_DEFAULT'));
$rows[] = array($langs->trans('CustomerInvoice'), getDolGlobalString('TRTEVKIFAT_CUSTOMER_ACCOUNT'));
foreach ($rows as $r) {
	$ok = $r[1] !== '' && $svc->accountRowid($r[1]) > 0;
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($r[0]).'</td><td>'.dol_escape_htmltag($r[1]).'</td><td>';
	print $ok ? img_picto('', 'tick').' '.$langs->trans('TRTevkifatAccountOk') : img_warning().' '.$langs->trans('TRTevkifatAccountMissing');
	print '</td></tr>';
}
print '</table><br>';

// Code dictionary
print load_fiche_titre($langs->trans('TRTevkifatCodeList'), '', '');
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('TRTevkifatCode').'</td><td>'.$langs->trans('TRTevkifatRate').'</td><td>'.$langs->trans('Description').'</td></tr>';
foreach (TRTevkifat::CODES as $code => $def) {
	print '<tr class="oddeven"><td>'.$code.'</td><td>'.TRTevkifat::rateLabel($def[0]).'</td><td>'.dol_escape_htmltag($def[1]).'</td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
