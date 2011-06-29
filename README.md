TODO: Clean up this readme (currently it's just a copy-paste from comments)

NOTE:

Do not confuse with https://github.com/tedeh/pingback-php which is
a similar library.

# php-pingback 

Light-weight pingback consumer/provider.
Implements this spec:
http://www.hixie.ch/specs/pingback/pingback (see also http://www.xmlrpc.com/spec)
License: 2-clause BSD -- driedfruit

If you already have a working XML-RPC solution, AVOID THIS CODE
LIKE PLAGUE. It uses fsockopen (or curl) for HTTP and regexps for XML!

To send pingbacks:
	Call pingback_ping($sourceURI, $targetURI), for example:
  $links = match_links($article_HTML);
	 foreach ($links as $remote_URL)
	   pingback_ping($article_URL, $remote_URL);

To receive pingbacks:
 You must specifiy a pingback endpoint, i.e. some URI on your end that will
 handle remote requests, for example "http://example.org/pingback"
  a) using header('X-Pingback: ' .   
  b) using &lt; link rel="pingback" href=" ( make sure to close it validly )
in each and every resource that you wish to make pingable.
...
//Then, wait on the endpoint to receive requests:
...
//suddenly your code realizes it received a pingback request, say
if (method == POST && url == MY_PINGBACK_ENDPOINT) 
{
		// Start by initiating a new pingback handler
		$ping = new PingBackHandler;
		// If you wish to see if remoteURI exists and actually links back, call
		$ping->validate();
		// Pingbacks have "error 0", so we use "NULL" to denote "no error"
		if ($ping->error === NULL) { // or ask the ->isValid() method 
			// Now, perform your validations (if any)
			if ($ping->localURI == /SOME_RESOURCE_YOU_WANTED_PINGED/)
			{
				// And actually save the pingback to the database/store/whatever
				echo "Saving ping: " . $ping->remoteURI . "\n";
				echo "To article: " . $ping->localURI . "\n";
				// You can also use author and comment properties, altho they
				// are empty strings if you didn't call ->validate() beforehand
				echo "Author: " . $ping->author . "\n";
				echo "Comment: " . $ping->comment . "\n";
				// Beware: both are not XSS-safe 
			}
			else {
				// If you can't accept a given pingback, set an error yourself
				$ping->error = PINGBACK_ERROR;	// or call $ping->fail();
				// If you can be more specific, for unknown URIs set
				$ping->error = PINGBACK_TARGET_MISSING;	// or call $ping->notFound();
				// for URIs pointing to resources you know, but that are not pingable, set
				$ping->error = PINGBACK_TARGET_INVALID;	// or call $ping->notValid();
				// for resources protected from pingbacks (whatever it means for you app), set
				$ping->error = PINGBACK_ACCESS_DENIED;	// or call $ping->Forbidden();
				// and for Pingbacks you already received and proccessed (duplicates) set
				$ping->error = PINGBACK_DUPLICATE;	// or call $ping->notFirst();
			}
		}
		// Finally, send the response. Manually,
		header("Content-Type: text/xml");
		echo $ping->asXML();
		exit;
		// or by calling the ->respond() method:
		$ping->respond();
		exit;
