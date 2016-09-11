<?php
/**
 * Defines the functionnalities to acces to the OpenClassroooms API.
 *
 * This codes is organized in four layers:
 * - OpenClassrooms user data retrieval @todo(still in dev)
 * - OAuth2 token request method: 
 *   - getAccessToken() encapsultates the OAuth2 protocol
 * - Generic HTTP request and redirection methods: 
 *   - httpRequest() httpRedirect() and getCurrentURL()
 * - Wordpress dependent methods: 
 *   - userData() for remanent user data management, 
 *   - getAuth()  authentification parameters retrieval.
 *
 * @see https://github.com/vthierry/OpenClassroomsAPI for download
 * @see <a href="https://github.com/vthierry/OpenClassroomsAPI/blob/master/README.md">README.md</a> and <a href="https://github.com/vthierry/OpenClassroomsAPI/blob/master/LICENSE">LICENCE</a> for meta information
 * @see http://docs.openclassrooms.apiary.io/# for OpenClassrooms documentation
 *
 * \ingroup class_code
 */
class OpenClassroomsAPI {

  // Tests the interface
  // @see http://httpbin.org/basic-auth/user/passwd to test the Basic Auth 
  public static function test() { 
    $api = new OpenClassroomsAPI();
    //echo '<h1>RESULT</h1><hr><pre>'.print_r($api->getData("courses"), true).'</pre><hr>';
    $api->getAccessToken();
    //echo '<h1>RESULT</h1><hr><pre>'.print_r($api->getProfile(), true).'</pre><hr>';
  }
  
  //
  // OpenClassrooms user data retrieval ! still a mess in development !
  //

  /** Gets the user profile information from the OpenClassroooms API. 
   * -> NOT YET TESTED , NOT SURE OF THE CODE
   */
  public function getProfile() {
    return self::httpRequest(array(
				   'url' => 'https://openclassrooms.com/api/v0/',
				   'header' => array(
						     "Accept: application/json",
						     "Accept-Language: fr",
						     "Authorization: Bearer ".$api->getAccessToken()),
				   'basic-auth' => self::getAuth('BasicAuth')));
  }
  /** Gets a given data structure from the OpenClassroooms API.
   * @param $name The data structure name, e.g. "courses"
   * @return An associative array of the form: <tt>{ "code" : "value", "body" = { … } }</tt>.
   */
  public function getData($name) {
    return self::httpRequest(array(
			     'url' => 'https://openclassrooms.com/api/v0/'.$name,
			     'header' => array(
					       "Accept: application/json",
					       "Accept-Language: fr"),
			     'basic-auth' => self::getAuth('BasicAuth'),
			     'with-range' => true,
			     ));
  }
  
  //
  // OAuth2 token request method
  //

  /** Encapsulates a OAuth2 token request.
   * - This method automatically detects if an access token exists, has to be refreshed, or if an authentification code has to be granted.
   * @see http://docs.openclassrooms.apiary.io/
   * @see http://oauth.net/2/
   */
  public function getAccessToken() {
    // Checks if an access token is defined
    if ($this->userData('access_token')) {
      // Checks if the access token has to be refreshed
      if ($this->userData('access_token_expires_at') < time()) {
	$acces_token_request = self::httpRequest(array(
          'url' => 'http://private-anon-dfbe24371-openclassrooms.apiary-mock.com/api/token', 
          'header' => array("Content-Type: application/json", "Authorization: Basic ".self::getAuth('BasicAuth')),
          'post' => '{
            "grant_type":"refresh_token",
            "client_id":"'.self::$client_id.'",
            "refresh_token": "'.$this->userData('refresh_token').'"
        }'));
	echo "<pre> Refresh token response".print_r($acces_token_request, true)."</pre>";
	if ($acces_token_request['code'] == 200) {
	  $acces_token_data = json_decode($acces_token_request['body'], true);
	  $this->userData('access_token', $acces_token_data['access_token']);
	  $this->userData('access_token_expires_at', time() - 5 +  $acces_token_data['expires_in']);
	  if (isset($acces_token_data['refresh_token']))
	    $this->userData('refresh_token', $acces_token_data['refresh_token']);
	} else {
	  self::display_error("refresh token", print_r($acces_token_request['header'], true));
	}
      }
      return $this->userData('access_token');
    } else {
      // Switchs to the authentification URL
      self::httpRedirect('http://private-anon-dfbe24371-openclassrooms.apiary-mock.com/api/authorize?response_type=code&client_id='.urlencode(self::$client_id).'&redirect_uri='.urlencode(self::getCurrentURL()).'&scope=profile&state='.$this->userData('state'));
    }
  }
  /** Implements the hook to manage redirection URL during OAuth2 token request. 
   * \private
   */
  static function do_token_request($request) {
    // Tests if there is a code and if the state is correct.
    if (isset($request['code']) && isset($request['state']) && $request['state'] == userData('state')) {
      $this->userData('code', urldecode($request['code']));
      // Requires the access token from the code
      $acces_token_request = self::httpRequest(array(
        'url' => 'https://openclassrooms.com/api/v0/token', 
        'header' => array("Content-Type: application/json", "Authorization: Basic ".self::getAuth('BasicAuth')),
        'post' => '{
          "grant_type":"authorization_code",
          "client_id":"'.self::$client_id.'",
          "redirect_uri":"'.self::getCurrentURL().'",
          "code": "'.$this->userData('code').'"
        }'));
      echo "<pre> Access token response".print_r($acces_token_request, true)."</pre>";
      $acces_token_data = json_decode($acces_token_request['body'], true);
      if ($acces_token_request['code'] == 200) {
	$this->userData('access_token', $acces_token_data['access_token']);
	$this->userData('access_token_expires_at', time() - 5 +  $acces_token_data['expires_in']);
	$this->userData('refresh_token', $acces_token_data['refresh_token']);
      } else {
	self::display_error("access token", print_r($acces_token_request['header'], true));
      }
    }
    return $request;
  }

  //
  // Generic HTTP request and redirection methods
  //

  /** Encasulates a HTTP GET or POST request using curl.
   * @param $request An array with the following input fields:
   * - <tt>url</tt> : The required URL to request,
   * - <tt>header</tt> : An optional array of strings with the different header lines,
   * - <tt>basic-auth</tt> : The optional $username.":".$password of the basic auth, if any,
   * - <tt>with-range</tt> : If true performs several request in order to complete a partial range answer (i.e. code 206).
   * - <tt>post</tt> : If set, defines a post request and provides the post body. This sets header the Content-Length field.
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
	if (isset($request['post'])) {
	  $curl_options[CURLOPT_POST] = true;
	  if(!isset($request['header'])) 
	    $request['header'] = array();
	  $request['header'][] = "Content-Length: "+strlen($request['post']);
	  $curl_options[CURLOPT_POSTFIELDS] = $request['post'];
	}
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
	    } else if (trim($header) == "") {
	      unset($header[$index]);
	    }
	  }
	}	
	// Manages a response with partial content
	if ($code == 206) {
	  if (isset($header['Content-Range'])) {
	    $range = json_decode(preg_replace('|\s*items\s*([0-9]+)-([0-9]+)/([0-9]+)|', '{ "start-index":"$1", "stop-index":"$2", "total-length":"$3"}', $header['Content-Range']), true);
	    if ($range) {
	      $header['Content-Range'] = $range;
	      if (isset($request['with-range']) && $request['with-range'])
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
			'body' => $body,
			'error' => $error,
			);
      } else
	$result = array('error' => "Unable to perform a curl request, error: ".curl_error($curl_request), 'code' => $code);
      curl_close($curl_request);
      return $result;
    }
  }
  // Retrieves partial content when content range (i.e., code 206)
  private static function doGetContentRange($request, $range, &$body, &$error) {
    $length = $range['stop-index'] - $range['start-index'] + 1;
    for($next_code = 206, $nn = 2; (!$error) && $next_code == 206 && $nn <= 1 + $range['total-length'] / $length; $nn++) {
      $next_request = 
	array_merge($request, 
		    array(
			  'with-range' => false,
			  'header' => 
			  array_merge(isset($request['header']) ? $request['header'] : array(),
				      array("Range: items=".($range['stop-index']+1)."-".min($range['total-length']-1,$range['stop-index']+$length)))));
      $next_result = self::httpRequest($next_request);
      $next_code = $next_result['code'];
      $range = $next_result['header']['Content-Range'];
      $body[] = $next_result['body'];
      if ($next_code != 206 || $next_result['error'] || !is_array($range))
	$error = "Error during 'Content-Range' request #".$nn.", code=".$next_code.", request-header=".print_r($next_request['header'], true)." result-header=".print_r($next_result['header'], true)." error='".$next_result['error']."'";
    }
  }
  /** Encapsulates a HTTP redirection. 
   * @param $url The redirection URL
   */
  public static function httpRedirect($url) {
    // Since some HTML text may be already output, header("Location : $url") may generate an error
    echo '<script language="javascript">location.replace("'.$url.'");</script>';
    exit(0);
  }
  /** Gets the current HTTP URL. */
  public static function getCurrentURL() {
    return (empty($_SERVER["HTTPS"]) ? "http://" : "https://").$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"];
  }
  
  //
  // Wordpress dependent section
  //
  
  /** Adds a HTTP request filter to capture redirect URI
   *  - This method must be overwitten for a non wordpress implementation.
   * \private
   */
  function __construct() { 
    add_filter('request', array($this, 'do_token_request'), 1, 1);
    self::getAuth();
  }

  /** Gets/Sets the user data from the WP database.
   * - This method must be overwitten for a non wordpress implementation.
   * @param $name The parameter name. Registered names are:
   * - client_id : The OC client ID;
   * - state : An opaque value to mainain state between the request and callback.
   * - access_token, access_token_expires_at, refresh_token : For OAuth2 management.
   * @param $value The parameter value if to be set. 
   * @return The parameter values.
   */
  public function userData($name, $value = false) {
    $user_id = wp_get_current_user()->ID;
    if (!$this->user_data) {
      $this->user_data = get_user_meta($user_id, 'OpenClassroomsAPI/UserData', false);
      $this->user_data['state'] = 'OC_authrequest_'.$user_id.'_'.rand(0, 1000000);
      echo "<pre> Loading the user data".print_r($this->user_data, true)."</pre>";
    }
    if ($value) {
      $this->user_data[$name] = $value;
      update_user_meta($user_id, 'OpenClassroomsAPI/UserData', $this->user_data);
    }
    return isset($this->user_data[$name]) ? $this->user_data[$name] : false;
  }
  // This array contains all OpenClassroomsAPI user data and is managed by userData()
  private $user_data = false;

  /** Retrieves the authentification parameters.
   *  - This method must be overwitten for a non wordpress implementation.
   * - In order to initially register the parameters:
   *   - A <tt>.httpkeys</tt> file must be present in the same directory as this source file
   *   - It must contains the information in an array of the form <tt>&;lt;php $httpkeys = array('BasicAuth/username' => ..., 'BasicAuth/password' => ..., 'OAuth2/client_id' => ..., 'OAuth2/key'  => ..., 'OAuth2/secret' => ...); ?></tt>
   *   - This file is going to be loaded and then deleted.
   */
  static private function getAuth($name = "") {
    if (!self::$httpkeys) {
      $httpkeys_file = plugin_dir_path( __FILE__ ).'.httpkeys';
      if (is_file($httpkeys_file)) {
	include($httpkeys_file);
	update_site_option("OpenClassroomsAPI/httpkeys", serialize($httpkeys));
	unlink($httpkeys_file);
      }
      self::$httpkeys = unserialize(get_site_option("OpenClassroomsAPI/httpkeys"));
      self::$httpkeys['BasicAuth'] = self::$httpkeys['BasicAuth/username'].":".self::$httpkeys['BasicAuth/password'];
    }
    return isset(self::$httpkeys[$name]) ? self::$httpkeys[$name] : false;
  }
  // This array contains all OpenClassroomsAPI authentification parameters
  private static $httpkeys = false;
}
?>
