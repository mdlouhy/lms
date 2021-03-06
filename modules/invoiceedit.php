<?php

/*
 * LMS version 1.11-git
 *
 *  (C) Copyright 2001-2017 LMS Developers
 *
 *  Please, see the doc/AUTHORS for more information about authors!
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License Version 2 as
 *  published by the Free Software Foundation.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307,
 *  USA.
 *
 *  $Id$
 */

include(MODULES_DIR . DIRECTORY_SEPARATOR . 'invoicexajax.inc.php');

$taxeslist = $LMS->GetTaxes();
$action = isset($_GET['action']) ? $_GET['action'] : '';

if(isset($_GET['id']) && ($action == 'edit' || $action == 'convert'))
{
	if ($LMS->isDocumentPublished($_GET['id']) && !ConfigHelper::checkConfig('privileges.superuser'))
		return;

	$invoice = $LMS->GetInvoiceContent($_GET['id']);

	$invoice['proforma'] = isset($_GET['proforma']) ? $action : null;

	$SESSION->remove('invoicecontents');
	$SESSION->remove('invoicecustomer');

	$i = 0;
	foreach ($invoice['content'] as $item) {
		$i++;
		$nitem['tariffid']	= $item['tariffid'];
		$nitem['name']		= $item['description'];
		$nitem['prodid']	= $item['prodid'];
		$nitem['count']		= str_replace(',' ,'.', $item['count']);
		$nitem['discount']	= str_replace(',' ,'.', $item['pdiscount']);
		$nitem['pdiscount']	= str_replace(',' ,'.', $item['pdiscount']);
		$nitem['vdiscount']	= str_replace(',' ,'.', $item['vdiscount']);
		$nitem['jm']		= str_replace(',' ,'.', $item['content']);
		$nitem['valuenetto']	= str_replace(',' ,'.', $item['basevalue']);
		$nitem['valuebrutto']	= str_replace(',' ,'.', $item['value']);
		$nitem['s_valuenetto']	= str_replace(',' ,'.', $item['totalbase']);
		$nitem['s_valuebrutto']	= str_replace(',' ,'.', $item['total']);
		$nitem['tax']		= isset($taxeslist[$item['taxid']]) ? $taxeslist[$item['taxid']]['label'] : '';
		$nitem['taxid']		= $item['taxid'];
		$nitem['posuid']	= $i;
		$SESSION->restore('invoicecontents', $invoicecontents);
		$invoicecontents[] = $nitem;
		$SESSION->save('invoicecontents', $invoicecontents);
	}
	$SESSION->save('invoicecustomer', $LMS->GetCustomer($invoice['customerid'], true));
	$invoice['oldcdate'] = $invoice['cdate'];
	$invoice['oldsdate'] = $invoice['sdate'];
	$invoice['olddeadline'] = $invoice['deadline'] = $invoice['cdate'] + $invoice['paytime'] * 86400;
	$invoice['oldnumber'] = $invoice['number'];
	$invoice['oldnumberplanid'] = $invoice['numberplanid'];

	if ($invoice['proforma'] == 'convert') {
		$currtime = time();
		$invoice['cdate'] = $currtime;
		$invoice['sdate'] = $currtime;
		$invoice['deadline'] = $invoice['cdate'] + $invoice['paytime'] * 86400;

		$customer = $LMS->GetCustomer($invoice['customerid'], true);
		$invoice['numberplanid'] = $DB->GetOne('SELECT n.id FROM numberplans n
			JOIN numberplanassignments a ON (n.id = a.planid)
			WHERE n.doctype = ? AND n.isdefault = 1 AND a.divisionid = ?',
			array(DOC_INVOICE, $customer['divisionid']));

		$invoice['number'] = $LMS->GetNewDocumentNumber(array(
			'doctype' => DOC_INVOICE,
			'planid' => $invoice['numberplanid'],
			'cdate' => $invoice['cdate'],
		));
	}

	$SESSION->save('invoice', $invoice);
	$SESSION->save('invoiceid', $invoice['id']);
}

$SESSION->restore('invoicecontents', $contents);
$SESSION->restore('invoicecustomer', $customer);
$SESSION->restore('invoice', $invoice);
$SESSION->restore('invoiceediterror', $error);
$itemdata = r_trim($_POST);

$ntempl = docnumber(array(
	'number' => $invoice['number'],
	'template' => $invoice['template'],
	'cdate' => $invoice['cdate'],
	'customerid' => $invoice['customerid'],
));
if (isset($invoice['proforma']) && $invoice['proforma'] == 'convert')
	$layout['pagetitle'] = trans('Conversion Pro Forma Invoice $a To Invoice', $ntempl);
elseif($invoice['doctype'] == DOC_INVOICE_PRO)
	$layout['pagetitle'] = trans('Pro Forma Invoice Edit: $a', $ntempl);
else
	$layout['pagetitle'] = trans('Invoice Edit: $a', $ntempl);

if(isset($_GET['customerid']) && $_GET['customerid'] != '' && $LMS->CustomerExists($_GET['customerid']))
	$action = 'setcustomer';

switch($action)
{
	case 'additem':
		if ($invoice['closed'])
			break;

		$itemdata = r_trim($_POST);

		unset($error);

		$itemdata['discount'] = str_replace(',', '.', $itemdata['discount']);
		$itemdata['pdiscount'] = 0;
		$itemdata['vdiscount'] = 0;
		if (preg_match('/^[0-9]+(\.[0-9]+)*$/', $itemdata['discount'])) {
			$itemdata['pdiscount'] = ($itemdata['discount_type'] == DISCOUNT_PERCENTAGE ? floatval($itemdata['discount']) : 0);
			$itemdata['vdiscount'] = ($itemdata['discount_type'] == DISCOUNT_AMOUNT ? floatval($itemdata['discount']) : 0);
		}
		if ($itemdata['pdiscount'] < 0 || $itemdata['pdiscount'] > 99.9 || $itemdata['vdiscount'] < 0)
			$error['discount'] = trans('Wrong discount value!');

		if ($error)
			break;

		foreach(array('count', 'discount', 'pdiscount', 'vdiscount', 'valuenetto', 'valuebrutto') as $key)
			$itemdata[$key] = round((float) str_replace(',', '.', $itemdata[$key]), 2);

		if ($itemdata['count'] > 0 && $itemdata['name'] != '')
		{
			$taxvalue = $taxeslist[$itemdata['taxid']]['value'];
			if ($itemdata['valuenetto'] != 0)
			{
				$itemdata['valuenetto'] = f_round(($itemdata['valuenetto'] - $itemdata['valuenetto'] * f_round($itemdata['pdiscount']) / 100) - $itemdata['vdiscount']);
				$itemdata['valuebrutto'] = $itemdata['valuenetto'] * ($taxvalue / 100 + 1);
				$itemdata['s_valuebrutto'] = f_round(($itemdata['valuenetto'] * $itemdata['count']) * ($taxvalue / 100 + 1));
			}
			elseif ($itemdata['valuebrutto'] != 0)
			{
				$itemdata['valuebrutto'] = f_round(($itemdata['valuebrutto'] - $itemdata['valuebrutto'] * $itemdata['pdiscount'] / 100) - $itemdata['vdiscount']);
				$itemdata['valuenetto'] = round($itemdata['valuebrutto'] / ($taxvalue / 100 + 1), 2);
				$itemdata['s_valuebrutto'] = f_round($itemdata['valuebrutto'] * $itemdata['count']);
			}

			// str_replace here is needed because of bug in some PHP versions (4.3.10)
			$itemdata['s_valuenetto'] = f_round($itemdata['s_valuebrutto'] / ($taxvalue / 100 + 1));
			$itemdata['valuenetto'] = f_round($itemdata['valuenetto']);
			$itemdata['count'] = f_round($itemdata['count']);
			$itemdata['discount'] = f_round($itemdata['discount']);
			$itemdata['pdiscount'] = f_round($itemdata['pdiscount']);
			$itemdata['vdiscount'] = f_round($itemdata['vdiscount']);
			$itemdata['tax'] = $taxeslist[$itemdata['taxid']]['label'];
			$itemdata['posuid'] = (string) getmicrotime();
			$contents[] = $itemdata;
		}
	break;

	case 'deletepos':
		if ($invoice['closed'])
			break;

		if (sizeof($contents))
			foreach($contents as $idx => $row)
				if ($row['posuid'] == $_GET['posuid']) 
					unset($contents[$idx]);
	break;

	case 'setcustomer':

		$olddeadline = $invoice['olddeadline'];
		$oldcdate = $invoice['oldcdate'];
		$oldsdate = $invoice['oldsdate'];
		$oldnumber = $invoice['oldnumber'];
		$oldnumberplanid = $invoice['oldnumberplanid'];
		$closed   = $invoice['closed'];

		unset($invoice);
		unset($customer);
		unset($error);
		$error = NULL;

		if($invoice = $_POST['invoice'])
			foreach($invoice as $key => $val)
				$invoice[$key] = $val;

		$invoice['olddeadline'] = $olddeadline;
		$invoice['oldcdate'] = $oldcdate;
		$invoice['oldsdate'] = $oldsdate;
		$invoice['oldnumber'] = $oldnumber;
		$invoice['oldnumberplanid'] = $oldnumberplanid;

		if($invoice['cdate']) // && !$invoice['cdatewarning'])
		{
			list($year, $month, $day) = explode('/', $invoice['cdate']);
			if(checkdate($month, $day, $year))
			{
				$oldday = date('d', $invoice['oldcdate']);
				$oldmonth = date('m', $invoice['oldcdate']);
				$oldyear = date('Y', $invoice['oldcdate']);

				if($oldday != $day || $oldmonth != $month || $oldyear != $year)
				{
					$invoice['cdate'] = mktime(date('G', time()), date('i', time()), date('s', time()), $month, $day, $year);
				}
				else // save hour/min/sec value if date is the same
					$invoice['cdate'] = $invoice['oldcdate'];
			}
			else
				$error['cdate'] = trans('Incorrect date format!');
		}

		if($invoice['sdate'])
		{
			list($syear, $smonth, $sday) = explode('/', $invoice['sdate']);
			if(checkdate($smonth, $sday, $syear))
			{
				$oldsday = date('d', $invoice['oldsdate']);
				$oldsmonth = date('m', $invoice['oldsdate']);
				$oldsyear = date('Y', $invoice['oldsdate']);

				if($oldsday != $sday || $oldsmonth != $smonth || $oldsyear != $syear)
				{
					$invoice['sdate'] = mktime(date('G', time()), date('i', time()), date('s', time()), $smonth, $sday, $syear);
				}
				else // save hour/min/sec value if date is the same
					$invoice['sdate'] = $invoice['oldsdate'];
			}
			else
				$error['sdate'] = trans('Incorrect date format!');
		}

		if ($invoice['deadline']) {
			list ($dyear, $dmonth, $dday) = explode('/', $invoice['deadline']);
			if (checkdate($dmonth, $dday, $dyear)) {
				$olddday = date('d', $invoice['oldddate']);
				$olddmonth = date('m', $invoice['oldddate']);
				$olddyear = date('Y', $invoice['oldddate']);

				if ($olddday != $dday || $olddmonth != $dmonth || $olddyear != $dyear)
					$invoice['deadline'] = mktime(date('G', time()), date('i', time()), date('s', time()), $dmonth, $dday, $dyear);
				else // save hour/min/sec value if date is the same
					$invoice['deadline'] = $invoice['olddeadline'];
			} else
				$error['deadline'] = trans('Incorrect date format!');
		}

		if ($invoice['deadline'] < $invoice['cdate'])
			$error['deadline'] = trans('Deadline date should be later than consent date!');

		$invoice['customerid'] = $_POST['customerid'];
		$invoice['closed']     = $closed;

		if ($invoice['number']) {
			if (!preg_match('/^[0-9]+$/', $invoice['number']))
				$error['number'] = trans('Invoice number must be integer!');
			elseif (($invoice['oldnumber'] != $invoice['number']
				|| $invoice['oldnumberplanid'] != $invoice['numberplanid']) && $LMS->DocumentExists(array(
					'number' => $invoice['number'],
					'doctype' => $invoice['proforma'] == 'edit' ? DOC_INVOICE_PRO : DOC_INVOICE,
					'planid' => $invoice['numberplanid'],
					'cdate' => $invoice['cdate'],
					'customerid' => $cid,
				)))
				$error['number'] = trans('Invoice number $a already exists!', $invoice['number']);
		}

		if(!$error)
			if($LMS->CustomerExists($invoice['customerid']))
				$customer = $LMS->GetCustomer($invoice['customerid'], true);
	break;

	case 'save':
		if (empty($contents) || empty($customer))
			break;

		$SESSION->restore('invoiceid', $invoice['id']);
		$invoice['type'] = $invoice['doctype'];

		$prev_rec_addr = $DB->GetOne('SELECT recipient_address_id FROM documents WHERE id = ?', array($invoice['id']));
		if (empty($prev_rec_addr))
			$prev_rec_addr = -1;

		if ( $prev_rec_addr != $invoice['recipient_address_id'] ) {
			if ( $prev_rec_addr > 0) {
				$DB->Execute('DELETE FROM addresses WHERE id = ?', array($prev_rec_addr));
			}

			if ($invoice['recipient_address_id'] > 0) {
				$DB->Execute('UPDATE documents SET recipient_address_id = ? WHERE id = ?',
							array(
								$LMS->CopyAddress($invoice['recipient_address_id']),
								$invoice['id']
							));
			}
		}

		$currtime = time();
		$cdate = $invoice['cdate'] ? $invoice['cdate'] : $currtime;
		$sdate = $invoice['sdate'] ? $invoice['sdate'] : $currtime;
		$deadline = $invoice['deadline'] ? $invoice['deadline'] : $currtime;
		$paytime = round(($deadline - $cdate) / 86400);
		$iid   = $invoice['id'];

		$DB->BeginTrans();
		$DB->LockTables(array('documents', 'cash', 'invoicecontents', 'numberplans', 'divisions', 'vdivisions'));

		$division = $DB->GetRow('SELECT name, shortname, address, city, zip, countryid, ten, regon,
			account, inv_header, inv_footer, inv_author, inv_cplace 
			FROM vdivisions WHERE id = ?',array($customer['divisionid']));

		if (!$invoice['number'])
			$invoice['number'] = $LMS->GetNewDocumentNumber(array(
				'doctype' => $invoice['proforma'] == 'edit' ? DOC_INVOICE_PRO : DOC_INVOICE,
				'planid' => $invoice['numberplanid'],
				'cdate' => $invoice['cdate'],
			));
		else {
			if(!preg_match('/^[0-9]+$/', $invoice['number']))
				$error['number'] = trans('Invoice number must be integer!');
			elseif (($invoice['number'] != $invoice['oldnumber'] || $invoice['numberplanid'] != $invoice['oldnumberplanid'])
				&& $LMS->DocumentExists(array(
					'number' => $invoice['number'],
					'doctype' => $invoice['proforma'] == 'edit' ? DOC_INVOICE_PRO : DOC_INVOICE,
					'planid' => $invoice['numberplanid'],
					'cdate' => $invoice['cdate'],
				)))
				$error['number'] = trans('Invoice number $a already exists!', $invoice['number']);

			if ($error) {
				$invoice['number'] = $LMS->GetNewDocumentNumber(array(
					'doctype' => $invoice['proforma'] == 'edit' ? DOC_INVOICE_PRO : DOC_INVOICE,
					'planid' => $invoice['numberplanid'],
					'cdate' => $invoice['cdate'],
				));
				$error = null;
			}
		}

		$args = array(
			'cdate' => $cdate,
			'sdate' => $sdate,
			'paytime' => $paytime,
			'paytype' => $invoice['paytype'],
			SYSLOG::RES_CUST => $customer['id'],
			'name' => $customer['customername'],
			'address' => ($customer['postoffice'] && $customer['postoffice'] != $customer['city'] && $customer['street']
				? $customer['postoffice'] . ', ' : '') . $customer['address'],
			'ten' => $customer['ten'],
			'ssn' => $customer['ssn'],
			'zip' => $customer['zip'],
			'city' => $customer['postoffice'] ? $customer['postoffice'] : $customer['city'],
			SYSLOG::RES_DIV => $customer['divisionid'],
			'div_name' => ($division['name'] ? $division['name'] : ''),
			'div_shortname' => ($division['shortname'] ? $division['shortname'] : ''),
			'div_address' => ($division['address'] ? $division['address'] : ''), 
			'div_city' => ($division['city'] ? $division['city'] : ''), 
			'div_zip' => ($division['zip'] ? $division['zip'] : ''),
			'div_' . SYSLOG::getResourceKey(SYSLOG::RES_COUNTRY) => ($division['countryid'] ? $division['countryid'] : null),
			'div_ten'=> ($division['ten'] ? $division['ten'] : ''),
			'div_regon' => ($division['regon'] ? $division['regon'] : ''),
			'div_account' => ($division['account'] ? $division['account'] : ''),
			'div_inv_header' => ($division['inv_header'] ? $division['inv_header'] : ''),
			'div_inv_footer' => ($division['inv_footer'] ? $division['inv_footer'] : ''),
			'div_inv_author' => ($division['inv_author'] ? $division['inv_author'] : ''),
			'div_inv_cplace' => ($division['inv_cplace'] ? $division['inv_cplace'] : ''),
		);

		$args['type'] = $invoice['proforma'] == 'edit' ? DOC_INVOICE_PRO : DOC_INVOICE;
		$args['number'] = $invoice['number'];
		if ($invoice['numberplanid'])
			$args['fullnumber'] = docnumber(array(
				'number' => $invoice['number'],
				'template' => $DB->GetOne('SELECT template FROM numberplans WHERE id = ?', array($invoice['numberplanid'])),
				'cdate' => $invoice['cdate'],
				'customerid' => $customer['id'],
			));
		else
			$args['fullnumber'] = null;
		$args[SYSLOG::RES_NUMPLAN] = $invoice['numberplanid'];
		//$args['recipient_address_id'] = $invoice
		$args[SYSLOG::RES_DOC] = $iid;
		$DB->Execute('UPDATE documents SET cdate = ?, sdate = ?, paytime = ?, paytype = ?, customerid = ?,
				name = ?, address = ?, ten = ?, ssn = ?, zip = ?, city = ?, divisionid = ?,
				div_name = ?, div_shortname = ?, div_address = ?, div_city = ?, div_zip = ?, div_countryid = ?,
				div_ten = ?, div_regon = ?, div_account = ?, div_inv_header = ?, div_inv_footer = ?,
				div_inv_author = ?, div_inv_cplace = ?, type = ?, number = ?, fullnumber = ?, numberplanid = ?
				WHERE id = ?', array_values($args));
		if ($SYSLOG)
			$SYSLOG->AddMessage(SYSLOG::RES_DOC, SYSLOG::OPER_UPDATE, $args,
				array('div_' . SYSLOG::getResourceKey(SYSLOG::RES_COUNTRY)));

		if (!$invoice['closed']) {
			if ($SYSLOG) {
				if ($invoice['doctype'] == DOC_INVOICE) {
					$cashids = $DB->GetCol('SELECT id FROM cash WHERE docid = ?', array($iid));
					foreach ($cashids as $cashid) {
						$args = array(
							SYSLOG::RES_CASH => $cashid,
							SYSLOG::RES_DOC => $iid,
							SYSLOG::RES_CUST => $customer['id'],
						);
						$SYSLOG->AddMessage(SYSLOG::RES_CASH, SYSLOG::OPER_DELETE, $args);
					}
				}
				$itemids = $DB->GetCol('SELECT itemid FROM invoicecontents WHERE docid = ?', array($iid));
				foreach ($itemids as $itemid) {
					$args = array(
						SYSLOG::RES_DOC => $iid,
						SYSLOG::RES_CUST => $customer['id'],
						'itemid' => $itemid,
					);
					$SYSLOG->AddMessage(SYSLOG::RES_INVOICECONT, SYSLOG::OPER_DELETE, $args);
				}
			}
			$DB->Execute('DELETE FROM invoicecontents WHERE docid = ?', array($iid));
			$DB->Execute('DELETE FROM cash WHERE docid = ?', array($iid));

			$itemid=0;
			foreach ($contents as $idx => $item) {
				$itemid++;

				$args = array(
					SYSLOG::RES_DOC => $iid,
					'itemid' => $itemid,
					'value' => str_replace(',', '.', $item['valuebrutto']),
					SYSLOG::RES_TAX => $item['taxid'],
					'prodid' => $item['prodid'],
					'content' => $item['jm'],
					'count' => str_replace(',', '.', $item['count']),
					'pdiscount' => str_replace(',', '.', $item['pdiscount']),
					'vdiscount' => str_replace(',', '.', $item['vdiscount']),
					'name' => $item['name'],
					SYSLOG::RES_TARIFF => empty($item['tariffid']) ? null : $item['tariffid'],
				);
				$DB->Execute('INSERT INTO invoicecontents (docid, itemid, value,
					taxid, prodid, content, count, pdiscount, vdiscount, description, tariffid)
					VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array_values($args));
				if ($SYSLOG) {
					$args[SYSLOG::RES_CUST] = $customer['id'];
					$SYSLOG->AddMessage(SYSLOG::RES_INVOICECONT, SYSLOG::OPER_ADD, $args);
				}

				if ($invoice['doctype'] == DOC_INVOICE || ConfigHelper::checkConfig('phpui.proforma_invoice_generates_commitment')
					|| $invoice['proforma'] == 'convert')
					$LMS->AddBalance(array(
						'time' => $cdate,
						'value' => $item['valuebrutto']*$item['count']*-1,
						'taxid' => $item['taxid'],
						'customerid' => $customer['id'],
						'comment' => $item['name'],
						'docid' => $iid,
						'itemid' => $itemid
						));
			}
		} elseif ($invoice['doctype'] == DOC_INVOICE || ConfigHelper::checkConfig('phpui.proforma_invoice_generates_commitment')) {
			if ($SYSLOG) {
				$cashids = $DB->GetCol('SELECT id FROM cash WHERE docid = ?', array($iid));
				foreach ($cashids as $cashid) {
					$args = array(
						SYSLOG::RES_CASH => $cashid,
						SYSLOG::RES_DOC => $iid,
						SYSLOG::RES_CUST => $customer['id'],
					);
					$SYSLOG->AddMessage(SYSLOG::RES_CASH, SYSLOG::OPER_UPDATE, $args);
				}
			}
			$DB->Execute('UPDATE cash SET customerid = ? WHERE docid = ?',
				array($customer['id'], $iid));
		}

		$DB->UnLockTables();
		$DB->CommitTrans();

		if (isset($_GET['print']))
			$SESSION->save('invoiceprint', array('invoice' => $invoice['id'],
				'original' => !empty($_GET['original']) ? 1 : 0,
			'copy' => !empty($_GET['copy']) ? 1 : 0,
				'duplicate' => !empty($_GET['duplicate']) ? 1 : 0));

		$SESSION->redirect('?m=invoicelist' . (isset($invoice['proforma']) && $invoice['proforma'] == 'edit' ? '&proforma=1' : ''));
	break;
}

$SESSION->save('invoice', $invoice);
$SESSION->save('invoicecontents', $contents);
$SESSION->save('invoicecustomer', $customer);
$SESSION->save('invoiceediterror', $error);

if($action != '')
{
	// redirect needed because we don't want to destroy contents of invoice in order of page refresh
	$SESSION->redirect('?m=invoiceedit');
}

if (!ConfigHelper::checkConfig('phpui.big_networks'))
	$SMARTY->assign('customers', $LMS->GetCustomerNames());

$SMARTY->assign('error', $error);
$SMARTY->assign('contents', $contents);
$SMARTY->assign('customer', $customer);
$SMARTY->assign('invoice', $invoice);
$SMARTY->assign('tariffs', $LMS->GetTariffs());
$SMARTY->assign('taxeslist', $taxeslist);

$args = array(
	'doctype' => isset($invoice['proforma']) && $invoice['proforma'] == 'edit' ? DOC_INVOICE_PRO : DOC_INVOICE,
	'cdate' => date('Y/m', $invoice['cdate']),
);
if (isset($customer) && !empty($customer)) {
	$args['customerid'] = $customer['id'];
	$args['division'] = $DB->GetOne('SELECT divisionid FROM customers WHERE id = ?', array($customer['id']));
}
$SMARTY->assign('numberplanlist', $LMS->GetNumberPlans($args));

$SMARTY->display('invoice/invoiceedit.html');

?>
