<?php
/**
 * Web Honeypot Client
 * 
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 * 
 * index.php
 *
 *   This file is the main "honeypot client". It will collect the request header information and
 * pass it to the collection server.
 *
 */

// automatically generated by the SVN repository
$whVersion = '$Rev: 123 $';
$whVersion = '0.1.r' . preg_replace('/^.*(\d+).*$/', '\\1', $whVersion);
// we don't care if the client disconnected - still want to log the request
ignore_user_abort(TRUE);

/* Do not edit the following parameters unless you know what you are doing */

$sBaseDir=realpath(dirname(__FILE__) . '/../');
require_once "$sBaseDir/lib/common_functions.php";

/* to ease install, we do not use any of the fileinfo mime detection.
 instead, we use this little hash to lookup file types */
$aMimeCheat=array();
	
initialize_default_config(array('logfile' => 'honeypot-%Y-%m.log'));
read_configuration();
check_config_parameters();
if (!defined('TESTING')) {
	// we have been called from the web and are not in testing mode
		
	/*
	 * Contains the list of mime types for which nothing will be logged if
	 * (a) the REFERER header is set and (b) the URL it is set to resolves to
	 * the same template id as the current one
	 * Separator taken from http://www.ietf.org/rfc/rfc1341.txt
	 */
	$aFilterRequestsByReferrer = preg_split('/[, ]/', $aConfig['filter_referred_requests']);
	
	$sUrl = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : $_SERVER['QUERY_STRING'];
	logdata("Debug: sUrl - before processing: $sUrl", 9);	
	// if we use the index.php/foo/bar/baz method of invocation, remove ourselves from the list
	// warning! when using mod_alias, SCRIPT_NAME is not correct, so try to do some detection here
	if (isset($_SERVER['SCRIPT_NAME']) 
		&& strstr($_SERVER['SCRIPT_NAME'], basename(__FILE__)) == basename(__FILE__)
		// ok, SCRIPT_NAME looks to be correct
		&& 0 == strncmp($sUrl, $_SERVER['SCRIPT_NAME'], strlen($_SERVER['SCRIPT_NAME'])))
		// and it is present in the string, so strip it 		
			$sUrl = substr($sUrl, strlen($_SERVER['SCRIPT_NAME']));
	logdata("Debug: sUrl - after processing: $sUrl", 9);

	if (0 === strpos($sUrl, '/?') && isset($_GET['sitemap']) 
		&& isset($_GET['smval']) && isset($aConfig['smval']) && $_GET['smval'] == $aConfig['smval']) {
		deliver_sitemap($sUrl, $_GET['sitemap']);									
		$iTemplateId = 3;
	}
	else {
		/* deliver the template to the client */
		$sAppendContent = '';
  	if ($aConfig['fetch_rfi'] || $aConfig['emulate_rfi']) 
  		$sAppendContent = process_rfi();  
  		
		$aResults = deliver_template($sUrl, $sAppendContent);
		$iTemplateId = $aResults[0];
	}
	
	//make sure that from here on we don't output anything to the client...
	flush();	
	ob_start('ob_no_output');
	logdata("Delivered template $iTemplateId", 1);
	// for template 0 we don't log anything
	if (0 == $iTemplateId) exit;
	
	/* collect the request */
	$sRequest=collect_request();
	$aRequest = explode("\n", $sRequest);
	$aRequest[0] = "Collected request: " . $aRequest[0];
	logdata($aRequest, 4);
	logdata("Debug: Done collecting request. Size=".strlen($sRequest)." Bytes", 8);
	
	if (should_request_be_sent($iTemplateId, $aResults[1])) {
		/* posting the request to the collection site */
		$sResult=post_result($sRequest);
		logdata("Debug: Post result = $sResult", 8);
	}
	else {
		logdata("Debug: Request is for an image, referred by the same template, will not be posted");
	}	
}

function find_template($sURI, $sType) {
	global $aTemplateLookup;	
	
	$iLastMatchLen = -1; //the length of the last match so that
		//we can find the most specific expression
	$iTemplate = 0; $iStatusCode = 0;
	// the filename portion of the request - this will determine which
	// file to serve up from the template directory
	$sFilename = '';
	foreach ($aTemplateLookup[$sType] as $candidate) {
		if (!preg_match($candidate['rx'], $sURI, $aMatches, PREG_OFFSET_CAPTURE)) continue;
		$aMatch = array_pop($aMatches);
		logdata("Debug: Match {$candidate['rx']} in {$sURI}",9);
		if (strlen($aMatch[0]) > $iLastMatchLen) {
			logdata("Debug: was more specific than the previous one",9);
			$iLastMatchLen = strlen($aMatch[0]);
			$iStatusCode   = $candidate['status_code'];
			$iTemplate     = $candidate['template'];
			$sFilename     = substr($sURI, $aMatch[1]);
			// remove GET parameters from the filename
			$sFilename = preg_replace('/\?.*/sD', '', $sFilename);
		}
	}

	if (-1 == $iLastMatchLen) return null;
	
	if ('headers.txt' == strtolower($sFilename))
		$sFilename = 'index.html';
	
	return array(
		'status_code' => $iStatusCode,
		'template'    => $iTemplate,
		'filename'    => $sFilename,
	);
}

/**
 * deliver template
 * 
 * @Param $sURI request URI. Used to lookup the right template.
 * @Param $sInsertedString String to insert from the RFI attempt (if any)
 */
function deliver_template($sURI, $sInsertedString) {
  global $aConfig;
  global $aTemplateLookup;
  global $sTemplateDir;
  global $aMimeCheat;
  global $aTokens;
  global $aLinkURLs;
  logdata("Debug: Request URI {$sURI}",9);



  $aTemplate = find_template($sURI, 'local');
  if (is_null($aTemplate))
  	$aTemplate = find_template($sURI, 'global');
  	
  /* if no match was found, we use the default template ('1') */
  if (is_null($aTemplate))
    $aTemplate = array(
			'status_code' => 404,
			'template'    => 1,   
    	'filename'    => '',
    );

  logdata("Debug: Using template # {$aTemplate['template']}",8);
  
  // try to construct the final filename in a safe manner. 
  // the important problem: avoid path traversal
  $sTemplateDirL = $sTemplateDir . '/' . $aTemplate['template'];
  $sTemplatefile = virtual_realpath($sTemplateDirL . '/' . $aTemplate['filename']);
  if (is_above_directory($sTemplatefile, $sTemplateDirL))
  	$sTemplatefile = $sTemplateDirL . '/';
  // for directories - we request the index.html from the given directory
  if ('/' == substr($sTemplatefile, -1)) $sTemplatefile .= 'index.html';    
 
  logdata("Debug: Looking for $sTemplatefile", 8);

  if (!file_exists($sTemplatefile)) {
  	$aProposals = array(
  		// first, try index.html from the given directory
  		dirname($sTemplatefile) . '/index.html',
  		// next, fall back on index.html in the template directory
  		$sTemplateDir . '/' . $aTemplate['template'] . '/index.html',
  	);
  	
  	foreach ($aProposals as $sProposed) {
  		if (file_exists($sProposed)) {
  			$sTemplatefile = $sProposed;
  			logdata("Debug: falling back on $sProposed", 8);
  		}
  	}
  	  	
  	// next, we fall back to template 1 (404)
  	if (!file_exists($sTemplatefile)) {
  		logdata("Debug: can not find index using default",8);
  		$aTemplate['status_code'] = 404;
  		$sTemplatefile = $sTemplateDir . '/1/index.html';
  	}
  }
  
  if (0 != $aConfig['restrict_templates']) {
  	// disable templates based on client/server IP  	
  	if (1 == $aConfig['restrict_templates']) $sIP = $_SERVER['SERVER_ADDR'];
  	else $sIP = $_SERVER['REMOTE_ADDR'];
  	$iFilter = 10 + array_sum(explode('.', $sIP)) % 10;  	

  	if (0 == ($aTemplate['template'] % $iFilter))
  		// we don't want to serve this template to this client...
	    $aTemplate = array(
				'status_code' => 404,
				'template'    => 1,   
	    	'filename'    => '',
	    );  	
  }
  logdata("Debug: using file $sTemplatefile",8); 

  /* Next, we get the extension, and sent a mime type based on the extension. */
  $sContentType = 'text/html';
  if (preg_match("/\.([^\.]+)$/", $sTemplatefile, $aMatch)) {
  	$sExt=$aMatch[1];
  	if (array_key_exists($sExt,$aMimeCheat)) {
  		logdata("Debug: setting content type to {$aMimeCheat[$sExt]}",8);
  		$sContentType = $aMimeCheat[$sExt];
  	}
  	else {
  		logdata("Info: no content type found for $sExt",3);
  	}
  }
  else {
  	logdata("Debug: no extension found",6);
  }
  header("Content-Type: $sContentType");

  /* read the file */
  $sTemplate = @file_get_contents($sTemplatefile, FILE_BINARY);  
  if (!$sTemplate) {
  	//we failed to read the template file, make a last ditch effort to deliver
  	//something to the client - hopefully the logger part will be able to log
  	//the request  	
  	logdata("ERROR: can not open $sTemplatefile",1);
  	header('HTTP/1.0 404 Not Found');
  	return array(-1, 'text/html');
  }
  $iLastModifiedTime = filemtime($sTemplatefile);


  usleep($aConfig['deliverydelay']);
    
  // send the specified status code
  if (404 == $aTemplate['status_code'])
  	header('HTTP/1.0 404 Not Found');   
  	
  /* figure out if we got a header.*/
  $aAdditionalHeaders = array();
  if (file_exists($sTemplateDir . '/' . $aTemplate['template'] . '/headers.txt')) {
  	$sHeaders = file_get_contents($sTemplateDir . '/' . $aTemplate['template'] . '/headers.txt');
  	foreach (preg_split('/[\x0d\x0a]+/', $sHeaders) as $sHeader)
  		$aAdditionalHeaders[] = $sHeader;  		  	    
  } 
  if (preg_match('/^(.*?)[\x0d\x0a]+####HEADEREND[\x0d\x0a]+(.*)$/sD', $sTemplate, $aMatch)) {
  	logdata("INFO: we got a header",9);
  	$sHeaders = $aMatch[1];
  	$sTemplate = $aMatch[2];

  	foreach (preg_split('/[\x0d\x0a]+/', $sHeaders) as $sHeader)
  		$aAdditionalHeaders[] = $sHeader;  		  	  
  }  
	/* now iterrate through headers
	 * we could send the header in one piece. but this may interfere with 
   * some protection schemes like suhosin that do not allow CR-LF in the header
	 * this will also make sure we do actuall send the LF and not just the CR */
  foreach ($aAdditionalHeaders as $sHeader)
  	header($sHeader, true);
  
  // (try to) filter out from the template: javascripts and SSIs
  // this is far from perfect, since javascript can hide in many places
  // issue warnings is such thing is found
  $sSanitizedBody = preg_replace(
  		array(
  			'/<script.*?<\/script>/is',
  			'/<script.*?\/\s*>/is',
  			'/<!--#.*?>/s',  		
  		),
  		array(
  			'',
  			'',
  			'',
  		),
  		$sTemplate  		  		
  	);
  if ($sSanitizedBody != $sTemplate) {
 	  //it would be more efficient to use the count parameter from preg_replace,
 	  //but it is only available in PHP5
  	logdata("WARN: removed risky content from templatefile \"$sTemplatefile\"",2);
  	$sTemplate = $sSanitizedBody;   
  }

  while ( preg_match('/%%([A-Z]+)%%/',$sTemplate,$aMatch) ){
    $sKey=$aMatch[1];
    $sValue='';
    if ( $sKey=='LINKURL' && is_array($aLinkURLs) && count($aLinkURLs)>0 ) {
      $nURL=rand(0,count($aLinkURLs)-1); 
      $sValue=$aLinkURLs[$nURL];
    } else {
      if ( array_key_exists($sKey,$aTokens) ) {
	$sValue=$aTokens[$sKey];
      }
    }
    $sTemplate=preg_replace("/%%$sKey%%/",$sValue,$sTemplate);
  }

  
  logdata("INFO: sending body", 9);
  $bDeliverBody = true;
  if ($iLastModifiedTime) { 
  	header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $iLastModifiedTime) . ' GMT');
  	if (@strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) == $iLastModifiedTime) {
  		logdata("INFO: body not modified", 9);
  		header('HTTP/1.1 304 Not Modified');
  		$bDeliverBody = false; 
  	}  	
  }  
  
  if ($bDeliverBody) {  
  	header('Content-Length: ' . strlen($sTemplate));
  	if (200 == $aTemplate['status_code'] && 'text/html' == $sContentType)
  		print $sInsertedString;  	
  	print $sTemplate;
  }
  
  return array($aTemplate['template'], $sContentType); 
}

/**
 * collect_request
 * 
 *   no parameters. Returns the full request (with body)
 */

function collect_request() {
  // assemble method, url and protocol
  $sRequest=$_SERVER['REMOTE_ADDR']."\n";
  $sRequest.=$_SERVER['REQUEST_METHOD'] . ' '
  	. (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : $_SERVER['QUERY_STRING']) 
  	. ' ' . $_SERVER['SERVER_PROTOCOL']."\n";
  
  	// get all headers
  foreach ( $_SERVER as $sKey=>$sHeader) {
    if ( preg_match("/^HTTP_/",$sKey) ) {
      $sRequest.=preg_replace("/^HTTP_/","",$sKey).": $sHeader\n";
    }
  }
  
  // get body
  $sBody=@file_get_contents('php://input');
  
  // only append body to request if there is actually one.
  if ( strlen($sBody) > 0 ) {
    $sRequest=$sRequest."\n\n".$sBody;
  }
    
  // return the request.
  return $sRequest;
}

/**
 * post_result
 */
function post_result($sRequest) {
	global $aConfig;

	if (!send_post_request($aConfig['url'], 'request=' . urlencode($sRequest))) {
		logdata("ERROR: Failed to send requests to server!");
		return false;
	}
  
	return true;
}

function ob_no_output($sBuffer) {
	return '';
}

function deliver_sitemap($sUrl, $sSitemapType) {
	global $aTemplateLookup, $aConfig;
	logdata("Delivering sitemap", 2);		
	
	// calculate the base url
	$sBaseUrl = (('on' == @$_SERVER['HTTPS']) ? 'https://' : 'http://') 
		. (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME']) 
		. substr($sUrl, 0, strpos($sUrl, '/?')) . '/';
					
	// construct the sitemap - validate the provided URLs
	$aUrls = array();
	foreach ($aConfig['template_basenames'] as $iTid => $sPaths) {
		foreach ($sPaths as $sPath) {
			$sSitemapUrl = $sBaseUrl . $sPath;			
			$aTemplateMatch = find_template($sSitemapUrl, 'local');
			if (null == $aTemplateMatch) $aTemplateMatch = find_template($sSitemapUrl, 'global');
			
			if (null == $aTemplateMatch)
				die_on_error("The generated URL \"$sSitemapUrl\" doesn't match any of the regular expresions!");
			if ($aTemplateMatch['template'] != $iTid)
				die_on_error("The generated URL \"$sSitemapUrl\" matches the wrong "
					. "regular expressions (expected: $iTid, got: {$aTemplateMatch['template']})!");
			
			$aUrls[] = $sSitemapUrl;
		}
	}
	
	if ('xml' == $sSitemapType) {
		header('Content-Type: text/xml');
		print '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
			. 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 '
			. 'http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">';
		foreach ($aUrls as $sUrl) 
			print "<url>\n"
				. "  <loc>" . htmlentitieS($sUrl) . "</loc>\n"
				. "  <priority>1.00</priority>\n"
				. "  <lastmod>" . strftime('%Y-%m-%dT%H:%M:%S+00:00') . "</lastmod>"
				. "  <changefreq>daily</changefreq>\n"
				. "</url>\n";
		print "</urlset>";
	}
	elseif ('ror' == $sSitemapType) {
		header('Content-Type: text/xml');
		print '<?xml version="1.0" encoding="UTF-8"?>';
		print '<rss version="2.0" xmlns:ror="http://rorweb.com/0.1/" ><channel>';
  	print '<title>ROR Sitemap for ' . htmlentities($sBaseUrl) . '</title>';
  	print '<link>' . htmlentities($sBaseUrl) . '</link>';
  	foreach ($aUrls as $sUrl) 
  		print "<item>\n"
  			. "  <link>" . htmlentities($sUrl) . "</link>\n"
     		. "  <title>Link to " . htmlentities($sUrl) . "</title>\n"
     		. "  <ror:updatePeriod>daily</ror:updatePeriod>\n"
     		. "  <ror:sortOrder>0</ror:sortOrder>\n"
     		. "  <ror:resourceOf>sitemap</ror:resourceOf>\n"
     		. "</item>\n";
  	print '</channel></rss>';
	}
	else {
		print "<html><body><h1>It works!</h1><div style='display: none'>";
		foreach ($aUrls as $sUrl) print "<a href='" . htmlentities($sUrl) 
			. "'>" . htmlentities($sUrl) . "</a>";
		print "</div></body></html>";			
	}
}

function process_rfi() {
	global $aConfig;

  foreach(array_merge(array_values($_GET), array_values($_POST)) as $sUrl) {  	
  	if (!preg_match('@^[a-z]+://@i', $sUrl)) continue;  	  
  	logdata("Possible RFI attempt found: $sUrl", 4);

  	$sFile = false;
  	if (function_exists('curl_init')) {
  		$ch = curl_init();
  		curl_setopt($ch, CURLOPT_URL, $sUrl);
  		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  		curl_setopt($ch, CURLOPT_USERAGENT , 'PHP');  		
  		$sFile = curl_exec($ch);
  		curl_close($ch);  
  	} else if (ini_get('allow_url_fopen')) {
  		$sFile = @file_get_contents($sUrl);
  	} else {
  		$sFile = send_get_request($sUrl);
  	}
  	if (!$sFile) continue;
  	
  	logdata("Fetched from '$sUrl': $sFile", 4);
  	
  	if (!$aConfig['emulate_rfi']) continue;
  		
  	$aResult = array();
  	$sLine = strtok($sFile, "\x0d\x0a");
  	while ($sLine !== false) {
  		$sLine = strtok("\x0d\x0a");
  		
  		if (preg_match('/uname|Uname|Kernel/i', $sLine))
  			$aResult[] = array_shift(explode('$', $sLine)) . pick_array_rand(array('Linux debian 2.6.8 Tue Dec 12 12:58:25 UTC 2007 i686 GNU/Linux',
  				'Linux my.leetserver.com 2.6.18-6-k7 #1 SMP Fri Jun 6 22:56:53 UTC 2008 i686 GNU/Linux'));
  		else if (preg_match('/os:|OSTYPE|SySOs/i', $sLine))
  			$aResult[] = array_shift(explode(':', $sLine)) . ': Linux';
  		else if (preg_match('/$up|uptime/i', $sLine))
  			$aResult[] = array_shift(explode(' ', $sLine)) . ' ' . strftime('%H:%M:%S up ') . rand(10, 200) . ' days ' . rand(10, 24) . ':'
  				. rand(10, 59) . ', ' . rand(0, 2) . ' user, load average: 0.' . rand(0, 70) . ', 0.' . rand(10, 50) . ', 0.' . rand(10, 20);  			
  		else if (preg_match('/$id.|id/i', $sLine)) {
  			$iId = rand(30, 70);
  			$sUid = pick_array_rand(array('www-data', 'webserver', 'server', 'user', 'apache', 'www', 'data'));  			
  			$aResult[] = array_shift(explode(':', $sLine)) . " uid=$iId($sUid) gid=$iId($sUid) groups=$iId($sUid)";
  		} else if (preg_match('/$pwd.|pwd/i', $sLine)) 
  			$aResult[] = array_shift(explode(' ', $sLine)) . ' ' . pick_array_rand(array('/var/www/httpdocs', '/var/www/server',
  				'/var/www/', '/var/www/http', '/var/www/webserver'));
  		else if (preg_match('/$usr.|user/i', $sLine))
  			$aResult[] = array_shift(explode(' ', $sLine)) . ' ';
  		else if (preg_match('/$php.|php./i', $sLine))
  			$aResult[] = array_shift(explode(' ', $sLine)) . ' ' . pick_array_rand(array('5.2.6', '5.0.1', '4.2.3'));
  		else if (preg_match('/$sof.|SoftWare|software|sof/i', $line))
  			$aResult[] = array_shift(explode(' ', $sLine)) . ': ' . pick_array_rand(array('Apache/2.0.53 (Unix)', 'Apache/2.2.6 (Unix)'));
  		else if (preg_match('/$name.|ServerName|srvname|server-name|name/i', $line))
  			$aResult[] = array_shift(explode(' ', $sLine)) . ' ';
  		else if (preg_match('/$ip.|ServerAddr|srvip|server-ip/i', $line))
  			$aResult[] = array_shift(explode(' ', $sLine)) . ' ';
  		else if (preg_match('/$free|free|Free/i', $sLine))
  			$aResult[] = array_shift(explode(':', $sLine)) . ': ' . rand(10, 20) . '.' . rand(10,90) . ' Gb';
  		else if (preg_match('/$free|free|Free/i', $sLine))
  			$aResult[] = array_shift(explode(':', $sLine)) . ': ' . rand(10, 20) . '.' . rand(10,90) . ' Gb';
  		else if (preg_match('/$used|used/i', $sLine))
  			$aResult[] = array_shift(explode(' ', $sLine)) . ' ' . rand(50, 60) . '.' . rand(10,90) . ' Gb';
  		else if (preg_match('/$all|total/i', $sLine))
  			$aResult[] = array_shift(explode(' ', $sLine)) . ' ' . rand(70, 100) . '.' . rand(10,90) . ' Gb';
  	}
  	
  	if (count($aResult) > 0)
  		return implode("<br>\n", array_map('htmlentities', $aResult));
  } 	
  
  return '';
} 

function pick_array_rand($aElements) {
	$sKey = array_rand($aElements);
	return $aElements[$sKey];
}

function should_request_be_sent($iDeliveredTemplate, $sDeliveredContentType) {
	global $aFilterRequestsByReferrer;
	if (!isset($_SERVER['HTTP_REFERER'])) return true;
	if (!in_array($sDeliveredContentType, $aFilterRequestsByReferrer)) return true;
	// don't do this for "special" templates
	if ($iDeliveredTemplate <= 4) return true;
	
	$sUrl = parse_url_parts($_SERVER['HTTP_REFERER']);
	if (!$sUrl) return true;
	$sUrl = $sUrl['path'];
	
	$aTemplate = find_template($sUrl, 'local');
  if (is_null($aTemplate))
  	$aTemplate = find_template($sUrl, 'global');
  if (is_null($aTemplate)) return true;
  if ($aTemplate['template'] <= 4) return true;
  
  if ($aTemplate['template'] == $iDeliveredTemplate)
  	return false;
  else
  	return true;		
}