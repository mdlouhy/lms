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

function GetEventList($year=NULL, $month=NULL, $day=NULL, $forward=0, $customerid=0, $userid=0, $type = 0, $privacy = 0, $closed = '') {
	global $AUTH;

	$DB = LMSDB::getInstance();

	$t = time();

	if(!$year) $year   = date('Y', $t);
	if(!$month) $month = date('n', $t);
	if(!$day) $day     = date('j', $t);

	unset($t);

	switch ($privacy) {
		case 0:
			$privacy_condition = '(private = 0 OR (private = 1 AND userid = ' . intval(Auth::GetCurrentUser()) . '))';
			break;
		case 1:
			$privacy_condition = 'private = 0';
			break;
		case 2:
			$privacy_condition = 'private = 1 AND userid = ' . intval(Auth::GetCurrentUser());
			break;
	}

	$startdate = mktime(0,0,0, $month, $day, $year);
	$enddate = mktime(0,0,0, $month, $day+$forward, $year);

	if(!isset($userid) && empty($userid))
		$userfilter = '';
	else
	{
		if(is_array($userid))
		{
			$userfilter = ' AND EXISTS ( SELECT 1 FROM eventassignments WHERE eventid = events.id AND userid IN ('.implode(',', $userid).'))';
			if(in_array('-1', $userid))
				$userfilter = ' AND NOT EXISTS (SELECT 1 FROM eventassignments WHERE eventid = events.id)';
		}
	}

	$list = $DB->GetAll(
		'SELECT events.id AS id, title, note, description, date, begintime, enddate, endtime, customerid, closed, events.type, '
		.$DB->Concat('UPPER(c.lastname)',"' '",'c.name').' AS customername,
		userid, vusers.name AS username, '.$DB->Concat('c.city',"', '",'c.address').' AS customerlocation,
		events.address_id, va.location, nodeid, vn.location AS nodelocation, ticketid
		FROM events
		LEFT JOIN vaddresses va ON va.id = events.address_id
		LEFT JOIN vnodes as vn ON (nodeid = vn.id)
		LEFT JOIN customerview c ON (customerid = c.id)
		LEFT JOIN vusers ON (userid = vusers.id)
		WHERE ((date >= ? AND date < ?) OR (enddate <> 0 AND date < ? AND enddate >= ?)) AND ' . $privacy_condition
		.($customerid ? ' AND customerid = '.intval($customerid) : '')
		. $userfilter
		. (!empty($type) ? ' AND events.type ' . (is_array($type) ? 'IN (' . implode(',', array_filter($type, 'intval')) . ')' : '=' . intval($type)) : '')
		. ($closed != '' ? ' AND closed = ' . intval($closed) : '')
		.' ORDER BY date, begintime',
		 array($startdate, $enddate, $enddate, $startdate, Auth::GetCurrentUser()));

	$list2 = array();
	if ($list)
		foreach ($list as $idx => $row) {
			$row['userlist'] = $DB->GetAll('SELECT userid AS id, vusers.name
					FROM eventassignments, vusers
					WHERE userid = vusers.id AND eventid = ? ',
					array($row['id']));
			$endtime = $row['endtime'];
			if ($row['enddate'] && $row['enddate'] - $row['date']) {
				$days = round(($row['enddate'] - $row['date']) / 86400);
				$row['enddate'] = $row['date'] + 86400;
				$row['endtime'] = 0;
				$dst = date('I', $row['date']);
				$list2[] = $row;
				while ($days) {
					if ($days == 1)
						$row['endtime'] = $endtime;
					$row['date'] += 86400;
					$newdst = date('I', $row['date']);
					if ($newdst != $dst) {
						if ($newdst < $dst)
							$row['date'] += 3600;
						else
							$row['date'] -= 3600;
						$newdst = date('I', $row['date']);
					}
					list ($year, $month, $day) = explode('/', date('Y/n/j', $row['date']));
					$row['date'] = mktime(0, 0, 0, $month, $day, $year);
					$row['enddate'] = $row['date'] + 86400;
					if ($days > 1 || $endtime)
						$list2[] = $row;
					$days--;
					$dst = $newdst;
				}
			} else
				$list2[] = $row;
		}

	return $list2;
}

if ($edate = $SESSION->get('edate'))
	list ($year, $month, $day) = explode('/', $SESSION->get('edate'));

if (!empty($_POST)) {
	$a = $_POST['a'];
	$u = $_POST['u'];

	if (isset($_POST['day']))
		$day = $_POST['day'];
	elseif ($edate) {
		if ($month != $_POST['month'] || $year != $_POST['year'])
			$day = 1;
	} else
		$day = date('j',time());

	$month = $_POST['month'];
	$year = $_POST['year'];

	$type = $_POST['type'];

	if (isset($_POST['privacy']))
		$privacy = intval($_POST['privacy']);
	else
		$privacy = 0;

	if (isset($_POST['closed']))
		$closed = $_POST['closed'];
	else
		$closed = '';
} else {
	if (isset($_GET['day']) && isset($_GET['month']) && isset($_GET['year'])) {
		if (isset($_GET['day']))
			$day = $_GET['day'];
		elseif ($edate) {
			if ($month != $_GET['month'] || $year != $_GET['year'])
				$day = 1;
		} else
			$day = 1;

		$month = $_GET['month'];
		$year = $_GET['year'];
	}

	$SESSION->restore('elu', $u);
	$SESSION->restore('ela', $a);
	$SESSION->restore('elt', $type);
	$SESSION->restore('elp', $privacy);
	$SESSION->restore('elc', $closed);
}

$SESSION->save('elu', $u);
$SESSION->save('ela', $a);
$SESSION->save('elt', $type);
$SESSION->save('elp', $privacy);
$SESSION->save('elc', $closed);

$t = time();

$day   = (isset($day)   ? $day  : date('j', $t));
$month = (isset($month) ? sprintf('%d',$month) : date('n', $t));
$year  = (isset($year)  ? $year : date('Y', $t));

unset($t);

$layout['pagetitle'] = trans('Timetable');

$eventlist = GetEventList($year, $month, $day, ConfigHelper::getConfig('phpui.timetable_days_forward'), $u, $a, $type, $privacy, $closed);
$SESSION->restore('elu', $listdata['customerid']);
$SESSION->restore('ela', $listdata['userid']);
$SESSION->restore('elt', $listdata['type']);
$SESSION->restore('elp', $listdata['privacy']);
$SESSION->restore('elc', $listdata['closed']);

// create calendars
for ($i = 0; $i < ConfigHelper::getConfig('phpui.timetable_days_forward'); $i++) {
	$dt = mktime(0, 0, 0, $month, $day+$i, $year);
	$daylist[$i] = $dt;
}

$date = mktime(0, 0, 0, $month, $day, $year);
$daysnum = date('t', $date);
for ($i = 1; $i < $daysnum + 1; $i++) {
	$date = mktime(0, 0, 0, $month, $i, $year);
	$days['day'][] = date('j',$date);
	$days['dow'][] = date('w',$date);
	$days['sel'][] = ($i == $day);
}

$SESSION->save('backto', $_SERVER['QUERY_STRING']);
$SESSION->save('edate', sprintf('%04d/%02d/%02d', $year, $month, $day));

$today = mktime(0, 0, 0, date('n'), date('j'), date('Y'));
$SMARTY->assign('today', $today);

$SMARTY->assign('period', $DB->GetRow('SELECT MIN(date) AS fromdate, MAX(date) AS todate FROM events'));
$SMARTY->assign('eventlist',$eventlist);
$SMARTY->assign('listdata',$listdata);
$SMARTY->assign('days',$days);
$SMARTY->assign('day',$day);
$SMARTY->assign('daylist',$daylist);
$SMARTY->assign('month',$month);
$SMARTY->assign('year',$year);
$SMARTY->assign('date',$date);
$SMARTY->assign('userlist',$LMS->GetUserNames());
if (!ConfigHelper::checkConfig('phpui.big_networks'))
	$SMARTY->assign('customerlist',$LMS->GetCustomerNames());
$SMARTY->assign('getHolidays', getHolidays($year));
$SMARTY->display('event/eventlist.html');

?>
