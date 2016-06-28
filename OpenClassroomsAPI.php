<?php
/**
 * Defines the functionnalities to acces to the OpenClassroooms API.
 * - The present implementation manages basic authentification.
 *
 * @see https://openclassrooms.com/api for documentation
 *
 * \ingroup class_code
 */
class OpenClassroomsAPI {

  // Tests the interface
    // @see http://httpbin.org/basic-auth/user/passwd to test the Basic Auth 
  public static function test() { 
    echo '<h1>RESULT</h1><hr><pre>'.print_r(OpenClassroomsAPI::getData("courses"), true).'</pre><hr>';
  }
  /** Gets a given data structure from the OpenClassroooms API.
   * @name The data structure name, e.g. "courses"
   * @return An associative array of the form: <tt>{ "code" : "value", "body" = { … } }</tt>.
   */
  public static function getData($name) {
    return self::httpRequest(array(
			     'url' => 'https://openclassrooms.com/api/v0/'.$name,
			     'header' => array(
					       "Accept: application/json",
					       "Accept-Language: fr"),
			     'basic-auth' => self::getBasicAuth(),
			     'with-range' => true,
			     ));
  }

  /** Returns the result of a http request.
   * @param $request An array with the following input fields:
   * - <tt>url</tt> : The required URL to request,
   * - <tt>header</tt> : An optional array of strings with the different header lines,
   * - <tt>basic-auth</tt> : The optional $username.":".$password of the basic auth, if any,
   * - <tt>with-range</tt> : If true performs several request in order to complete a partial range answer (i.e. code 206).
   *   - In that case the returned header is the 1st request header and the body is an array with all collected bodies.
   * @return An array with the following output fields:
   * - <tt>code</tt> : The HTTP response status code (e.g., code <tt>200</tt> if ok),
   * - <tt>header</tt> : An associative array with header as <tt>key => value</tt>,
   *   - With a code <tt>206</tt> the <tt>Content-range</tt> field is parsed as an array with the <tt>start-index</tt>, <tt>stop-index</tt>, and <tt>total-length</tt>.
   * - <tt>body</tt> : The response body, parsed as a JSON structure, if possible.
   * - <tt>error</tt> : A string with an error message, or false if no error.
   *  @see http://php.net/manual/fr/ref.curl.php
   */
  public static function httpRequest($request) {
    // Performs the related curl request 
    {
      $curl_request = curl_init();
      // Sets the curl request options
      {
	$curl_options = array(
			      CURLOPT_URL => $request['url'],
			      CURLOPT_HEADER => true,
			      CURLOPT_RETURNTRANSFER => true);
	if (isset($request['header'])) 
	  $curl_options[CURLOPT_HTTPHEADER] = $request['header'];
	if (isset($request['basic-auth'])) {
	  $curl_options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
	  $curl_options[CURLOPT_USERPWD] = $request['basic-auth'];
	}
	curl_setopt_array($curl_request, $curl_options);
      }
      $response = curl_exec($curl_request);
      $code = curl_getinfo($curl_request, CURLINFO_HTTP_CODE);
      if ($response) {
	// Splits header and body in the response
	$header_size = curl_getinfo($curl_request, CURLINFO_HEADER_SIZE);
	$header = explode("\n", substr($response, 0, $header_size));
	$body = array(substr($response, $header_size));
	$error = false;
	// Parses the header as an associative array
	{
	  foreach($header as $index => $value) {
	    if (strpos($value, ":") !== false) {
	      $pair = explode(":", $value, 2);
	      $header[$pair[0]] = $pair[1];
	      unset($header[$index]);
	    } else if ($value.trim() == "") {
	      unset($header[$index]);
	    }
	  }
	}	
	// Manages a response with partial content
	if ($code == 206) {
	  if (isset($header['Content-Range'])) {
	    // Parses the 'Content-Range' header field
	    $range = json_decode(preg_replace('|\s*items\s*([0-9]+)-([0-9]+)/([0-9]+)|', '{ "start-index":"$1", "stop-index":"$2", "total-length":"$3"}', $header['Content-Range']), true);
	    if ($range) {
	      $header['Content-Range'] = $range;
	      // Iterates though the content ranges
	      self::doGetContentRange($request, $range, $body, $error);
	    } else
	      $error = "Undefined 'Content-Range' format, Content-Range => '".$header['Content-Range']."'";
	  } else
	    $error = "Undefined 'Content-Range' with a code 206";
	}
	// Parses the body and reports the result
	$body = array_map(function($body_i) { 
	    $jbody_i = json_decode($body_i, true); return $jbody_i ? $jbody_i : $body_i; 
	  }, $body);
	if (count($body) == 1)
	  $body = $body[0];
	$result = array('code' =>  $code,
			'header' => $header,
			'body' => $jresult ? $jresult : $body,
			'error' => $error,
			);
      } else {
	$result = array('error' => "Unable to perform a curl request, error: ".curl_error($curl_request), 'code' => $code);
      }
      curl_close($curl_request);
      return $result;
    }
  }
  // Retrieves partial content when content range (i.e., code 206)
  private static function doGetContentRange($request, $range, &$body, &$error) {
    if (isset($request['with-range']) && $request['with-range']) {
      $length = $range['stop-index'] - $range['start-index'] + 1;
      for($next_code = 206, $nn = 2; (!$error) && $next_code == 206 && $nn <= 1 + $range['total-length'] / $length; $nn++) {
	$next_request = 
	  array_merge($request, 
		      array(
			    'with-range' => false,
			    'header' => 
			    array_merge(isset($request['header']) ? $request['header'] : array(),
					array("Range: items=".($range['stop-index']+1)."-".min($range['total-length'],$range['stop-index']+$length)))));
	$next_result = self::httpRequest($next_request);
	$next_code = $next_result['code'];
	$range = $next_result['header']['Content-Range'];
	$body[] = $next_result['body'];
	if ($next_code != 206 || $next_result['error'] || !is_array($range))
	  $error = "Error during 'Content-Range' request #".$nn.", code=".$next_code.", header=".print_r($next_result['header'], true)." error='".$next_result['error']."'";
      }
    }
  }
  /** Registers the username:password in the WP database. 
   * - The present implementation stores the data as a base64 site option.
   * - This and the getBasicAuth() methods must be overwitten for a non wordpress implementation.
   * - In order to register this basic-auth:
   *   - the PHP line <tt>OpenClassroomsAPI::registerBasicAuth("username", "password");</tt> must be inserted in the <tt>wordpress/wp-config.php</tt> server file with proper values,
   *   - it will be called during the next acces to the site,
   *   - it then must be deleted in order in order to preserve the password confidentiality.
   */
  static function registerBasicAuth($username, $password) { 
    update_site_option("OpenClassroomsAPI/BasicAuth", base64_encode($username.":".$password));
  }
  // Retrieves the basic auth data
  static private function getBasicAuth() {
    return base64_decode(get_site_option("OpenClassroomsAPI/BasicAuth"));
  }
}
?>
