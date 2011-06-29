# php-pingback

github: https://github.com/driedfruit/php-pingback

NOTE:

Not to be confused with [pingback-php library](https://github.com/tedeh/pingback-php),
which actually provides *proper* implementation on top of DOM, cURL and XML-RPC.

NOTE:

If you already have a working XML-RPC solution, *AVOID THIS CODE
LIKE PLAGUE*. It uses fsockopen for HTTP and regexps for XML! It _might_
use cURL and xmlrpc_decode if it finds them, but you get the idea.

# php-pingback 

Light-weight pingback client/server, implementing the [Pingback 
1.0 specification](http://www.hixie.ch/specs/pingback/pingback).

License: 2-clause BSD -- driedfruit

## Sending Pingbacks

pingback_ping($sourceURI, $targetURI);

#### Example:

	$links = match_links($article_HTML);
	foreach ($links as $remote_URL)
		pingback_ping($article_URL, $remote_URL);

## Receiving Pingbacks

### Pingable resources

All "pingable" resources must be served with an "X-Pingback" header,
declaring an endpoint that will handle all pingback requests.
It must be an absolute URI. 

	header("X-Pingback: " . MY_PINGBACK_ENDPOINT);

Alternatively, HTML (and XHTML) documents might provide the same URI
in a link tag,
	
	<link rel="pingback" href="MY_PINGBACK_ENDPOINT"...

### Pingback endpoint

	// Suddenly...
	if (method == POST && url == /MY_PINGBACK_ENDPOINT/) 
	{
		$ping = new PingBackHandler;
		if ($ping->validate()) { 
			// Now, perform YOUR validations (if any)
			if ($ping->localURI == /SOME_RESOURCE_YOU_WANTED_PINGED/)
			{
				// Do something useful
				echo "Pingback from: "	. $ping->remoteURI	. "\n";
				echo "To article: " 	. $ping->localURI	. "\n";
				echo "From Author: "	. $ping->author 	. "\n";
				echo "With Comment: "	. $ping->comment	. "\n";
			}
			// You didn't like what you saw
			else {
				// An error occurred! Call one of those:
				$ping->notFound(); // resource not found
				$ping->notValid(); // resource not pingable
				$ping->Forbidden(); // pingbacks to resource are not allowed 
				$ping->notFirst(); // pingback already registered
				$ping->fail(); // for any other generic error
				// or you could assign your own faultCode
				// An error occurred! Set faultCode: 
				$ping->error = PINGBACK_DUPLICATE; // see below for full list
				$ping->error = 20309; // it's OK to use custom codes too
			}
		}
		// Finally, send the response. Manually,
		header("Content-Type: text/xml");
		echo $ping->asXML();
		exit;
		// or by calling the ->respond() method:
		$ping->respond();
		exit;
	}

### Validation

A call to ->validate() tests for 2 things

 1. if sourceURI exists
 2. if sourceURI contains a link to targetURI

Although those tests are SUGGESTED by the spec (and make total sense), they
are not required. You're free to call it at any point in your code, or not
at all.

### Author and Excerpt

Although pingback provides no information beside the link, it is possible to 
extract some additional data from the sourceURI. As that behavior is
out of scope of the formal specification, you must explicitly request it by
 
 - Calling ->validate() method (you might be doing it anyway).
 - Reading ->author or ->excerpt properties. 

Right now, ->author is extracted from source page &lt;title&gt; and ->excerpt is
assembled from text surrounding the targetURI. Both methods are prone to errors,
and what's worse, open doors to XSS. Beware!


### Error codes

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
