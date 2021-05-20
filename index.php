<?php
// Kickstart the framework
$f3=require('lib/base.php');

$f3->config('config.ini');

$f3->set('DB', new DB\SQL('sqlite:./db/websitechangetracker.sqlite3'));


$f3->route('GET /',
	function($f3) {
		$f3->set('content','start.htm');
		echo View::instance()->render('layout.htm');
	}
);



/*******************************************************************************
 * Listing all websites
 ******************************************************************************/
$f3->route('GET /websites',
	function($f3) {
		$db = $f3->get('DB');

		$f3->set('result',$db->exec("SELECT w.*,
			CAST((strftime('%s', datetime()) - strftime('%s', website_last_check)) / (60 * 60 * 24) AS TEXT) || 'd ' ||
	    CAST(((strftime('%s', datetime()) - strftime('%s', website_last_check)) % (60 * 60 * 24)) / (60 * 60) AS TEXT) || 'h ' ||
	    CAST((((strftime('%s', datetime()) - strftime('%s', website_last_check)) % (60 * 60 * 24)) % (60 * 60)) / 60 AS TEXT) || 'm' as lastcheck,
			max(l.log_datetime) as lastdiff
																 FROM websites w LEFT JOIN logs l on w.website_id = l.log_website_id GROUP BY w.website_id"));
		echo Template::instance()->render('websites.htm');
	}
);

/*******************************************************************************
 * Activating / deactivating website check
 ******************************************************************************/
$f3->route('GET /websites/switch/@id',
	function($f3) {
		$db = $f3->get('DB');

		$f3->set('result',$db->exec('UPDATE websites SET website_active = NOT website_active WHERE website_id = :id', array(':id'=>$f3->get('PARAMS.id'))));
    $f3->reroute('/websites');
	}
);

/*******************************************************************************
 * deleting a website
 ******************************************************************************/
$f3->route('GET /websites/delete/@id',
	function($f3) {
		$db = $f3->get('DB');

		$db->exec('DELETE FROM websites WHERE website_id = :id', array(':id'=>$f3->get('PARAMS.id')));
		$db->exec('DELETE FROM logs WHERE log_website_id = :id', array(':id'=>$f3->get('PARAMS.id')));

    $f3->reroute('/websites');
	}
);

/*******************************************************************************
 * adding a website
 ******************************************************************************/
$f3->route('POST /websites/add',
	function($f3) {
		$db = $f3->get('DB');

		$website=new DB\SQL\Mapper($db,'websites');
		$website->copyFrom('POST');
		$website->save();
		var_dump($website);

    $f3->reroute('/websites');
	}
);


/*******************************************************************************
 * check one website
 ******************************************************************************/
$f3->route('GET /websites/check/@id',
	function($f3) {
		$db = $f3->get('DB');
		$id = $f3->get('PARAMS.id');
		$now = date("Y-m-d h:i:s");

		$website=new DB\SQL\Mapper($db,'websites');
		$oldlog=new DB\SQL\Mapper($db,'logs');
		$newlog=new DB\SQL\Mapper($db,'logs');


		$website->load(array('website_id=:id',array(':id' => $id)));
		$oldlog->load(array('log_website_id=:id ORDER BY log_id DESC LIMIT 0,1',array(':id' => $id)));


		// getting website content
		$content = file_get_contents($website->website_url);

		// If there is a website change then ...
		if ($content != $oldlog->log_content) {
			$smtp = new SMTP ( $f3->get('host'), $f3->get('port'), $f3->get('scheme'), $f3->get('user'), $f3->get('pw') );

			$diff = $log->log_content;

			// writing new log
			$newlog->log_website_id = $id;
			$newlog->log_diff = $diff;
			$newlog->log_content=$content;
			$newlog->log_datetime=$now;
			$newlog->diff = $diff;
			$newlog->save();

			echo $smtp->set('Errors-to', '<notifier@tillwitt.de>');
			echo $smtp->set('From', '"Notifier" <notifier@tillwitt.de>');
			echo $smtp->set('To', '"Till Witt" <mail@tillwitt.de>');
			echo $smtp->set('Subject', '[WCT] change detected at ' . $website->website_url);

			$content = "A change at " . $website->website_url . " was detected. You can see details at http://" . $_SERVER['HTTP_HOST'] . "/websites/";

			echo $smtp->send($content);

		}
		$website->website_last_check = $now;
		$website->save();

    $f3->reroute('/websites');
	}
);

$f3->run();
