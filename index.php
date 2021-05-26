<?php
// Kickstart the framework
$f3=require('lib/base.php');

$f3->config('config.ini');

$f3->set('DB', new DB\SQL('sqlite:./db/websitechangetracker.sqlite3'));


$f3->route('GET /',
	function($f3) {
		//$f3->set('content','start.htm');
		//echo View::instance()->render('layout.htm');
		$f3->reroute('/websites');		
	}
);



/*******************************************************************************
 * Listing all websites
 ******************************************************************************/
$f3->route('GET /websites',
	function($f3) {
		$db = $f3->get('DB');

		$f3->set('result',$db->exec("SELECT w.*,
			case WHEN length(website_url) > 80 THEN substr(website_url,0,40) || '...' || substr(website_url,length(website_url)-40,40)
			            ELSE website_url
			        end as website_shorturl,
			CAST((strftime('%s', datetime()) - strftime('%s', website_last_check)) / (60 * 60 * 24) AS TEXT) || 'd ' ||
	    CAST(((strftime('%s', datetime()) - strftime('%s', website_last_check)) % (60 * 60 * 24)) / (60 * 60) AS TEXT) || 'h ' ||
	    CAST((((strftime('%s', datetime()) - strftime('%s', website_last_check)) % (60 * 60 * 24)) % (60 * 60)) / 60 AS TEXT) || 'm' as lastcheck,
			max(l.log_datetime) as lastdiff
																 FROM websites w LEFT JOIN logs l on w.website_id = l.log_website_id GROUP BY w.website_id"));
		echo Template::instance()->render('websites.htm');
	}
);

/*******************************************************************************
 * Listing all websites
 ******************************************************************************/
$f3->route('GET /websites/checkall',
	function($f3) {
		$db = $f3->get('DB');

		$sites = $db->exec("SELECT * FROM websites WHERE website_active = 1");

		foreach ($sites as $site) {
			echo "<li>" . $site['website_url'];
			checkwebsite($site['website_id']);

		}
		$f3->reroute('/websites');
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

    $f3->reroute('/websites');
	}
);

/*******************************************************************************
 * viewing a website
 ******************************************************************************/
$f3->route('GET /websites/view/@id',
	function($f3) {
		$db = $f3->get('DB');
		$websites=new DB\SQL\Mapper($db,'websites');
		$websites->load(array('website_id=:id',array(':id' => $f3->get('PARAMS.id'))));

		$f3->set('websites',$websites);

		echo Template::instance()->render('website.htm');
	}
);

/*******************************************************************************
 * viewing a website changes
 ******************************************************************************/
$f3->route('GET /websites/showchanges/@id',
	function($f3) {
		$db = $f3->get('DB');

		$logs=new DB\SQL\Mapper($db,'logs');
		$logs->load(array('log_website_id=:id ORDER BY log_id DESC LIMIT 0,1',array(':id' => $f3->get('PARAMS.id'))));


		$f3->set('logs',$logs);
		$f3->set('html',"<b>test</b>");

		echo Template::instance()->render('changes.htm');
	}
);


/*******************************************************************************
 * check one website
 ******************************************************************************/
$f3->route('GET /websites/check/@id',
 	function($f3) {
			$id = $f3->get('PARAMS.id');
      checkwebsite($id);
			$f3->reroute('/websites');
			}
		);



//** helper function to check website **//
function checkwebsite($id) {
		global $f3;
		$db = $f3->get('DB');
		$now = date("Y-m-d h:i:s");

		$website=new DB\SQL\Mapper($db,'websites');
		$oldlog=new DB\SQL\Mapper($db,'logs');
		$newlog=new DB\SQL\Mapper($db,'logs');


		$website->load(array('website_id=:id',array(':id' => $id)));
		$oldlog->load(array('log_website_id=:id ORDER BY log_id DESC LIMIT 0,1',array(':id' => $id)));


		// getting website content
		$content = file_get_contents($website->website_url);

		$delta = detectChange($oldlog->log_content, $content);
		if ($delta !== false) {
			// If there is a website change then ...
				$smtp = new SMTP ( $f3->get('host'), $f3->get('port'), $f3->get('scheme'), $f3->get('user'), $f3->get('pw') );

				$diff = $log->log_content;

				// writing new log
				$newlog->log_website_id = $id;
				$newlog->log_diff = $diff;
				$newlog->log_content=$content;
				$newlog->log_datetime=$now;
				$newlog->log_diff = html_entity_decode($delta);
				$newlog->save();


				// Email notification if activated
				if ($website->website_sendmail) {
					$smtp->set('Errors-to', '<notifier@tillwitt.de>');
					$smtp->set('From', '"Notifier" <notifier@tillwitt.de>');
					$smtp->set('To', '"Till Witt" <mail@tillwitt.de>');
					$smtp->set('Subject', '[WCT] change detected at ' . $website->website_url);

					$content = "A change at " . $website->website_url . " was detected. You can see details at http://" . $_SERVER['HTTP_HOST'] . "/websites/view/" . $website->website_id;
					$smtp->send($content . $change);
				}
			}

		$website->website_last_check = $now;
		$website->save();

	}
//);

$f3->run();


/******************************************************************************/
function detectChange($old,$new,$bodyOnly=true, $stripTags=false, $removeJavaScript=true, $removeTagAttributes=true) {
	// full version of compare
	if ($bodyOnly) {
		$old = tidy_parse_string($old)->Body()->value;
		$new = tidy_parse_string($new)->Body()->value;
	}

	if ($removeJavaScript) {
		$old = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $old);
		$new = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $new);
	}

	if ($removeTagAttributes) {
		$old = preg_replace("/<([a-z][a-z0-9]*)[^>]*?(\/?)>/si",'<$1$2>', $old);
		$new = preg_replace("/<([a-z][a-z0-9]*)[^>]*?(\/?)>/si",'<$1$2>', $new);
	}


	if ($stripTags) {
		$old = stripUnwantedTagsAndAttrs($old);
		$new = stripUnwantedTagsAndAttrs($new);
	}

	// now check if things differ - if not stop
	if ($old == $new) return false;

	// diffing content
	require_once('finediff.php');
	$opcodes = FineDiff::getDiffOpcodes($old, $new);
	$change = FineDiff::renderDiffToHTMLFromOpcodes($new, $opcodes);
  return $change;
}


// src: https://www.php.net/manual/de/function.strip-tags.php
function stripUnwantedTagsAndAttrs($html_str){
  $xml = new DOMDocument();
//Suppress warnings: proper error handling is beyond scope of example
  libxml_use_internal_errors(true);
//List the tags you want to allow here, NOTE you MUST allow html and body otherwise entire string will be cleared
  $allowed_tags = array("html", "body", "b", "br", "em", "hr", "i", "li", "ol", "p", "s", "span", "table", "tr", "td", "u", "ul");
//List the attributes you want to allow here
  $allowed_attrs = array ("class", "id", "style");
  if (!strlen($html_str)){return false;}
  if ($xml->loadHTML($html_str, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)){
    foreach ($xml->getElementsByTagName("*") as $tag){
      if (!in_array($tag->tagName, $allowed_tags)){
        $tag->parentNode->removeChild($tag);
      }else{
        foreach ($tag->attributes as $attr){
          if (!in_array($attr->nodeName, $allowed_attrs)){
            $tag->removeAttribute($attr->nodeName);
          }
        }
      }
    }
  }
  return $xml->saveHTML();
}
