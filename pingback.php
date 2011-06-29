<?php
/**
 * php-pingback is a light-weight pingback consumer/provider.
 * Implements this spec:
 * http://www.hixie.ch/specs/pingback/pingback (see also http://www.xmlrpc.com/spec)
 * License: 2-clause BSD -- driedfruit
 *
 * If you already have a working XML-RPC solution, AVOID THIS CODE
 * LIKE PLAGUE. It uses fsockopen (or curl) for HTTP and regexps for XML!
 *
 * To send pingbacks:
 *	Call pingback_ping($sourceURI, $targetURI), for example:
 *   $links = match_links($article_HTML);
 *	 foreach ($links as $remote_URL)
 *	   pingback_ping($article_URL, $remote_URL);
 *
 * To receive pingbacks:
 *  You must specifiy a pingback endpoint, i.e. some URI on your end that will
 *  handle remote requests, for example "http://example.org/pingback"
 *   a) using header('X-Pingback: ' .   
 *   b) using <link rel="pingback" href=" ( make sure to close it validly )
 * in each and every resource that you wish to make pingable.
 * ...
 * //Then, wait on the endpoint to receive requests:
 * ...
 * //suddenly your code realizes it received a pingback request, say
 * if (method == POST && url == MY_PINGBACK_ENDPOINT) 
 * {
 *		// Start by initiating a new pingback handler
 *		$ping = new PingBackHandler;
 *		// If you wish to see if remoteURI exists and actually links back, call
 *		$ping->validate();
 *		// Pingbacks have "error 0", so we use "NULL" to denote "no error"
 *		if ($ping->error === NULL) { // or ask the ->isValid() method 
 *			// Now, perform your validations (if any)
 *			if ($ping->localURI == /SOME_RESOURCE_YOU_WANTED_PINGED/)
 *			{
 *				// And actually save the pingback to the database/store/whatever
 *				echo "Saving ping: " . $ping->remoteURI . "\n";
 *				echo "To article: " . $ping->localURI . "\n";
 *				// You can also use author and comment properties, altho they
 *				// are empty strings if you didn't call ->validate() beforehand
 *				echo "Author: " . $ping->author . "\n";
 *				echo "Comment: " . $ping->comment . "\n";
 *				// Beware: both are not XSS-safe 
 *			}
 *			else {
 *				// If you can't accept a given pingback, set an error yourself
 *				$ping->error = PINGBACK_ERROR;	// or call $ping->fail();
 *				// If you can be more specific, for unknown URIs set
 *				$ping->error = PINGBACK_TARGET_MISSING;	// or call $ping->notFound();
 *				// for URIs pointing to resources you know, but that are not pingable, set
 *				$ping->error = PINGBACK_TARGET_INVALID;	// or call $ping->notValid();
 *				// for resources protected from pingbacks (whatever it means for you app), set
 *				$ping->error = PINGBACK_ACCESS_DENIED;	// or call $ping->Forbidden();
 *				// and for Pingbacks you already received and proccessed (duplicates) set
 *				$ping->error = PINGBACK_DUPLICATE;	// or call $ping->notFirst();
 *			}
 *		}
 *		// Finally, send the response. Manually,
 *		header("Content-Type: text/xml");
 *		echo $ping->asXML();
 *		exit;
 *		// or by calling the ->respond() method:
 *		$ping->respond();
 *		exit;
 */

define ('PINGBACK_ERROR',         	0x0000); /* Pingback FaultCodes */
define ('PINGBACK_SOURCE_MISSING',	0x0010);
define ('PINGBACK_SOURCE_INVALID', 	0x0011);
define ('PINGBACK_TARGET_MISSING',	0x0020); 
define ('PINGBACK_TARGET_INVALID', 	0x0021); 
define ('PINGBACK_DUPLICATE',     	0x0030);
define ('PINGBACK_ACCESS_DENIED', 	0x0031);
define ('PINGBACK_UPSTREAM_ERROR',	0x0032);
define ('PINGBACK_PARSE_ERROR', 	-32700); /* Common XML-RPC FaultCodes */
define ('PINGBACK_WRONG_METHOD', 	-32601);
define ('PINGBACK_WRONG_PARAMS', 	-32500);

function pingback_failure($code=0, $error='') {
static $errors = array(
	PINGBACK_ERROR      	=> 'Error.',
	PINGBACK_SOURCE_MISSING	=> 'The source URI does not exist.',
	PINGBACK_SOURCE_BROKEN	=> 'The source URI does not contain a link to the target URI.',
	PINGBACK_TARGET_MISSING	=> 'The specified target URI does not exist.',
	PINGBACK_TARGET_BROKEN	=> 'The specified target URI cannot be used as a target.',
	PINGBACK_DUPLICATE  	=> 'The pingback has already been registered.',
	PINGBACK_ACCESS_DENIED	=> 'Access denied.',
	PINGBACK_UPSTREAM_ERROR	=> 'Upstream error.',
	PINGBACK_PARSE_ERROR	=> 'parse error. not well formed',
	PINGBACK_WRONG_METHOD	=> 'server error. requested method not found',
	PINGBACK_WRONG_PARAMS	=> 'server error. invalid method parameters',
); if (!$error && isset($errors[$code])) $error = $errors[$code];
return <<<XML
<?xml version="1.0"\x3F>
<methodResponse>
	<fault>
		<value>
		<struct>
			<member>
				<name>faultCode</name>
				<value><int>$code</int></value>
			</member>
			<member>
				<name>faultString</name>
				<value><string>$error</string></value>
			</member>
		</struct>
		</value>
	</fault>
</methodResponse>
XML;
}

function pingback_request($sourceURI, $targetURI) {
return <<<XML
<?xml version="1.0"\x3F>
<methodCall>
	<methodName>pingback.ping</methodName>
	<params>
		<param>
			<value><string>$sourceURI</string></value>
		</param>
		<param>
			<value><string>$targetURI</string></value>
		</param>
	</params>
</methodCall>
XML;
}

function pingback_success($message) {
return <<<XML
<?xml version="1.0"\x3F>
<methodResponse>
	<params>
		<param>
			<value><string>$message</string></value>
		</param>
	</params>
</methodResponse>
XML;
}

/*
 * Pingback response handler
 * They ping you, you invoke this.
 */
class PingBackHandler {

	/* Read-write properties */
	public $error = NULL, $message = NULL;

	/* Read-only properties */
	private $sourceURI, $targetURI, 
			$author = NULL, $comment = NULL;

	/* Truly private */
	private $html = NULL, $p; /* Bleech */

	/* Accept arbitary XML data in constructor */
	public function __construct($data = NULL) {
		if ($data == NULL) $data = file_get_contents('php://input');

		$method = '';/* TODO: maintain compatibility: */
		if (function_exists('111111xmlrpc_decode_request'))
		$params = xmlrpc_decode_request($data, $method);
		else {
			preg_match_all('#<methodName>(.+)</methodName>#', $data, $methods);
			preg_match_all('#<value>(?:<string>)(.+?)(?:</string>)</value>#', $data, $moreparams);
			$method = $methods[1][0];
			$params = $moreparams[1];
		}

		if (!$params || !$method) $this->error = PINGBACK_PARSE_ERROR;
		else if ($method != 'pingback.ping') $this->error = PINGBACK_WRONG_METHOD;
		else if (sizeof($params) < 2 || !isset($params[0]) || !isset($params[1]))
			$this->error = PINGBACK_WRONG_PARAMS;
		else {
			$this->sourceURI = $params[0];
			$this->targetURI = $params[1];
			if (!is_string($this->sourceURI) || !is_string($this->targetURI))
				$this->error = PINGBACK_WRONG_PARAMS;
		}
	}

	/* Some people are lazy like that */
	public function isValid() {
		return ($this->error === NULL ? TRUE : FALSE );
	}

	/* Validate */
	public function validate() {
		if ($this->error !== NULL) return FALSE;

		if (!isset($this->html)) $this->html = @file_get_contents($this->sourceURI);

		if (!$this->html) $this->error = PINGBACK_SOURCE_MISSING;
		else
		if (($p = strpos($this->html, $this->targetURI)) === FALSE) $this->error = PINGBACK_SOURCE_BROKEN;
		$this->p = $p;

		return ($this->error === NULL ? TRUE : FALSE );
	}

	/* Invalidate! */
	public function fail($code, $msg) { $this->error = $code; $this->message = $msg; }
	public function notFound() { $this->error = PINGBACK_TARGET_MISSING; }
	public function notValid() { $this->error = PINGBACK_TARGET_INVALID; }
	public function notFirst() { $this->error = PINGBACK_DUPLICATE; }
	public function notAllowed() { $this->error = PINGBACK_ACCESS_DENIED; }

	/* Get attributes */
	public function __get($name) {
		switch ($name) {
			case 'sourceURI':
			case 'remoteURI':
				return $this->sourceURI;
			break;
			case 'targetURI':
			case 'localURI':
				return $this->targetURI;
			break;
			case 'author':
				if (!isset($this->author)) $this->extract_author();
				return $this->author;
			break;
			case 'comment':
			case 'excerpt':
				if (!isset($this->comment)) $this->extract_comment();
				return $this->comment;
			default:
				throw new Exception("Undefined property ".$name);
			break;
		}
	}

	/* Echo response */
	public function respond() {
		$data = $this->asXML();
		header("Content-Type: text/xml");
		header("Content-Length: ".strlen($data));
		header("Connection: close");
		echo $data;
	}
	/* I just can't resist it: */
	public function pong() { return $this->respond(); }

	/* Return response */
	public function asXML() {
		if ($this->error === NULL) {
			return pingback_success(isset($this->message) ? $this->message : 'Pingback Accepted');
		} else {
		 	return pingback_failure($this->error, $this->message);
		}			
	}

	/* Attempt to extract author */
	private function extract_author() {
		if (isset($this->author)) return;
		$this->author = '';
		if ($this->html === NULL || $this->error !== NULL) return;
		/* Easy, just get page title */
		if (preg_match("#<title>(.+)</title>#", $this->html, $mc)) 
			$this->author = $mc[1];
	}
	/* Attempt to extract comment */
	private function extract_comment() {
		if (isset($this->comment)) return;
		$this->comment = '';
		if ($this->html === NULL || $this->error !== NULL) return;

		/* $p points to an offset where our link was found */
		$html = $this->html;
		$p = $this->p;

		/* Fetch 512 chars to the left and to the right of it */ 
		$left = substr($html, 0, $p);
		$right = substr($html, $p + $this->targetURI);
		$gl = strrpos($left, '>', -512) + 1;/* attempt to land */
		$gr = strpos($right, '<', 512);  /* on tag boundaries */
		$nleft = substr($left, $gl);
		$nright = substr($right, 0, $gr);

		/* Glue them and strip_tags (and remove excessive whitepsace) */
		$nstr = $nleft.$nright;
		$nstr = strip_tags($nstr);
		$nstr = str_replace(array("\n","\t")," ", $nstr);

		/* Take 120 chars from the CENTER of our current string */
		$fat = strlen($nstr) - 120;
		if ($fat > 0) {
			$lfat = $fat / 2;
			$rfat = $fat - $lfat;
			$nstr = substr($nstr, $lfat);
			$nstr = substr($nstr, 0, -$rfat);
		}

		/* Trim a little more and add [...] on the sides */
		$nstr = trim($nstr);
		if ($nstr) $nstr = preg_replace('#^.+?(\s)|(\s)\S+?$#', '\\2[...]\\1', $nstr);

		$this->comment = $nstr;
	}
}

/* HTTP POST and GET functionality. Use curl, fallback to fsockopen */
if (function_exists('curl_setopt_array')) {
function post_remote_xml($url, $xml) {
	$ch = curl_init($url);
	$co = array(
		CURLOPT_HEADER      	=>	0,
		CURLOPT_POST        	=>	1,
		CURLOPT_RETURNTRANSFER	=>	1,
		CURLOPT_HTTPHEADER  	=>	array(
			"Content-Type: text/xml",
			"Content-Length: ".strlen($xml) ),
		CURLOPT_POSTFIELDS => $xml);
	curl_setopt_array($ch, $co); 
	$res = curl_exec($ch);
	curl_close($ch);
	return $res;
}
function get_remote_head($url) {
	$ch = curl_init($url);
	$co = array(
		CURLOPT_HEADER      	=>	1,
		CURLOPT_RETURNTRANSFER	=>	1,
		CURLOPT_HTTPHEADER  	=>	array("Content-Range: bytes 0-4096/*"),
	);
	curl_setopt_array ($ch, $co);
	$res = curl_exec($ch);
	curl_close($ch);
	return $res;
} } else {
function post_remote_xml($url, $xml) {
	$resp = '';
	$purl = parse_url($url);
	if (!isset($purl['port'])) $purl['port'] = 80;
	$fp = fsockopen($purl['host'], $purl['port'], $errno, $errstr, 30);
	if ($fp) {
    	$out = "POST ".$purl['path']." HTTP/1.1\r\n";
    	$out .= "Host: ".$purl['host']."\r\n";
    	$out .= "Content-Length: ".strlen($xml)."\r\n";
    	$out .= "Content-Type: text/xml\r\n";
    	$out .= "Connection: Close\r\n\r\n";
    	$out .= $xml;
    	fwrite($fp, $out);
    	while (!feof($fp)) 
    		$resp .= fread($fp, 1024);
    	fclose($fp);
	}
	return $resp;
}
function get_remote_head($url) {
	$resp = '';
	$purl = parse_url($url);
	if (isset($purl['query'])) $purl['path'] .= '?'.$purl['query'];
	if (!isset($purl['port'])) $purl['port'] = 80;
	$fp = fsockopen($purl['host'], $purl['port'], $errno, $errstr, 30);
	if ($fp) {
    	$out = "GET ".$purl['path']." HTTP/1.1\r\n";
    	$out .= "Host: ".$purl['host']."\r\n";
    	$out .= "Content-Range: bytes 0-4096/*\r\n";
    	$out .= "Connection: Close\r\n\r\n";
    	fwrite($fp, $out);
    	$resp = fread($fp, 4096);
    	fclose($fp);
	}
	return $resp;
} }

/* Yup, 'match' all links in a chunk of html. Always returns an array. */
function match_links($html) {
	if (preg_match_all('#<a.+?href=[\'"](.+?)[\'"]#i', $html, $mc)) 
	return array_unique($mc[1]);
	else return array();
}

/* Decode an XML-RPC response. Returns TRUE on success, ERROR CODE on failure. */
function pingback_decode_response($xml, &$message) {
	/* Failure */
	if (preg_match('#<fault>(.+)</fault>#s', $xml, $fault)) {
		$code = PINGBACK_ERROR;
		if (preg_match_all('#<name>(\w+)</name>.*?<value>(.+?)</value>#s', $fault[1], $values)) {
			$code = $values[2][0];
			$message = $values[2][1];
		}
		return $code;
	/* Success */
	} else {
		if (preg_match('#<value>\s*?(?:<string>)(.+?)(?:</string>)\s*?</value>#s', $xml, $values))
			$message = $values[1];
		return TRUE;
	}
}

/* Auto-discover pingback endpoint. Returns "URI" string on success, FALSE on failure. */
function pingback_discover($targetURI) {
	$top = get_remote_head($targetURI);

	$endpoint = FALSE;

    if (preg_match("#X-Pingback: (.+?)\r\n.*\r\n\r\n#s", $top, $m)) {
    	$endpoint = $m[1];
    } else if (preg_match('#<link rel="pingback" href="([^"]+)" ?/?>#', $top, $m)) {
    	$endpoint = $m[1];
    }

    return $endpoint;
}

/* Do a pingback-ping! Returns "MESSAGE" string on success, ERROR CODE on failure. */
function pingback_ping($sourceURI, $targetURI) {
	$endpointURI = pingback_discover($targetURI);
	if (!$endpointURI) return PINGBACK_ERROR;

	$xml = pingback_request($sourceURI, $targetURI);

	$resp = post_remote_xml($endpointURI, $xml);

	if (!$resp) return PINGBACK_ERROR;

	$message = '';
	$code = pingback_decode_response($resp, $message);

	if ($code !== TRUE) return $code;

	return $message;
}

?>