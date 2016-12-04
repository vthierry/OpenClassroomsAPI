<?php
/**
 * Defines the functionnalities to acces to the OpenClassroooms API.
 *
 * <i>Usage</i>: <pre>
 *  global $OpenClassroomsAPI;
 *  $data = $OpenClassroomsAPI->getUserLearningActivity();</pre>
 * - The only routine to call is getUserLearningActivity() lower layer's call are transparent to the application layer
 *
 * <i>Code implementation</i>: The code is organized in four layers:
 * - OpenClassrooms user data retrieval 
 *   - getUserLearningActivity()
 * - OAuth2 token request method: 
 *   - getAccessToken() encapsultates the OAuth2 protocol
 * - Generic HTTP request and redirection methods: 
 *   - httpRequest() httpRedirect() and getCurrentURL()
 * - Wordpress dependent methods and error management
 *   - _construct() to hook the do_token_request() method
 *   - userData() for remanent user data management, 
 *   - getAuth() secure local authentification parameters retrieval.
 *   - errorPage() user level error page if the syndication fails.
 *   - reportLog() to report an error in the log system.
 *
 * <i>OAuth2 parameters registration</i>:
 * - A <tt>.httpkeys</tt> PHP file must be present in the same directory as this source file (and never added to the git or svn !!!).
 * - It must contains the information in an array of the form <tt>&lt;php $httpkeys = array(</tt>
 *   - 'OAuth2/client_id'    => ..., // The OAuth2 client ID
 *   - 'OAuth2/basic_auth'   => ..., // The OAuth2 login:passwd basic auth
 *   - 'OAuth2/redirect_uri' => ..., // The OAuth2 rediect URI
 *   - 'OAuth2/help_url'     => ..., // The user help url if the syndication fails
 *   - 'OAuth2/log_email'    => ..., // The mail to send error logs, in any (optional)
 * <tt>); ?></tt>
 *
 * @see https://github.com/vthierry/OpenClassroomsAPI for download
 * @see <a href="https://github.com/vthierry/OpenClassroomsAPI/blob/master/README.md">README.md</a> and <a href="https://github.com/vthierry/OpenClassroomsAPI/blob/master/LICENSE">LICENCE</a> for meta information
 * @see http://docs.openclassrooms.apiary.io/# for OpenClassrooms documentation
 *
 * \ingroup class_code
 */
class OpenClassroomsAPI {

  // Tests the interface
  public static function test() { 
    global $OpenClassroomsAPI;
    $OpenClassroomsAPI->do_token_request($_REQUEST);
    echo "<pre>running ...</pre>"; echo "<pre>".print_r($OpenClassroomsAPI->getUserLearningActivity(true, true), true)."</pre>";
  }
  public static function reset() { 
    global $OpenClassroomsAPI;
    echo "<pre>cleaning ...</pre>"; $OpenClassroomsAPI->clearAccessToken();
  }
  
  ////////////////////////////////////////////////////////////////////////////////
  
  //
  // OpenClassrooms user data retrieval
  //

  /** Gets the OpenClassroooms user learning activity for the registered courses.
   * @param $update If true, request on https://api.openclassrooms.com the user_public_profile, user_followed_courses and related table-of-content to bluid the user-learning-activity. If false, simply returns the last stored value.
   * @param $as_string If true, returns the data as JSON string, else as a PHP array.
   * @return the userData user_learning_activity field or false if the operation fails. See usedData() for details.
   */
  public function getUserLearningActivity($update = true, $as_string = false) {
    if ($update) {
      // Gets user_public_profile
      {
	$user_public_profile = self::httpRequest(array(
          'url' => 'https://api.openclassrooms.com/me',
	  'header' => array(
			    "Accept: application/json",
			    "Content-Type:application/json",
			    "Authorization: Bearer ".$this->getAccessToken())));
	if ($user_public_profile['code'] == 200) {
	  $this->userData('user_public_profile', $user_public_profile['body']);
	  $this->userData('user_id', $user_public_profile['body']['id']);
	} else {
	  self::reportLog("OpenClassroomsAPI::getUserLearningActivity(): user_public_profile error ".print_r($user_public_profile, true));
	  return false;
	}
      }
      // Gets user_followed_courses
      {
	$user_followed_course = self::httpRequest(array(
          'url' => 'https://api.openclassrooms.com/users/'.$this->userData('user_id').'/followed-courses/',
	  'header' => array(
			    "Accept: application/json",
			    "Content-Type:application/json",
			    "Authorization: Bearer ".$this->getAccessToken())));
	if ($user_followed_course['code'] == 200) {
	  // Cleans useless data and Gets courses table of contents for each course
	  foreach($user_followed_course['body'] as &$course) {
	    // Here useless data is cleaned
	    foreach(array('creatorPartners', 'authors', 'description') as $item)
	      unset($course[$item]);
	    $course_content = self::httpRequest(array(
	      'url' => 'https://api.openclassrooms.com/users/'.$this->userData('user_id').'/courses/'.$course['id'].'/table-of-content',
	      'header' => array(
				"Accept: application/json",
				"Content-Type:application/json",
				"Authorization: Bearer ".$this->getAccessToken())));
	    if ($course_content['code'] == 200) {
	      $course['table-of-content'] = $course_content['body'];
	    } else {
	      $course['table-of-content'] = "unable to retrieve table-of-content";
	      self::reportLog("OpenClassroomsAPI::getUserLearningActivity(): course_content error ".print_r($course_content, true));
	    }
	  }
	  $this->userData('user_learning_activity', $user_followed_course['body']);
	} else {
	  self::reportLog("OpenClassroomsAPI::getUserLearningActivity(): user_public_profile error".print_r($user_public_profile, true));
	  return false;
	}
      }
    }
    if ($as_string) {
      return json_encode($this->userData('user_learning_activity'), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_NUMERIC_CHECK);
    } else {
      return $this->userData('user_learning_activity');
    }
  }
  
  ////////////////////////////////////////////////////////////////////////////////
  
  //
  // OAuth2 token request method
  //

  /** Encapsulates a OAuth2 token request.
   * - This method automatically detects if an access token exists, has to be refreshed, or if an authentification code has to be granted.
   * @return The "Authorization: Bearer " access token on succes; otherwise redirects to a user error page if it fails.
   */
  public function getAccessToken() {
    // Checks if an access token is defined
    if ($this->userData('access_token')) {
      // Checks if the access token has to be refreshed
      if ($this->userData('access_token_expires_at') < time()) {
	$acces_token_request = self::httpRequest(array(
	  'url' => 'https://api.openclassrooms.com/oauth2/token',
          'header' => array("Accept: application/json", "Content-Type: application/json"),
	  'basic-auth' => self::getAuth('OAuth2/basic_auth'),
	  'post' => '{
            "grant_type":"refresh_token",
            "client_id":"'.self::getAuth('OAuth2/client_id').'",
            "redirect_uri":"'.self::getAuth('OAuth2/redirect_uri').'",
            "refresh_token": "'.$this->userData('refresh_token').'"
        }'));
	if ($acces_token_request['code'] == 200) {
	  $this->userData('access_token', $acces_token_request['body']['access_token']);
	  $this->userData('access_token_expires_at', time() - 5 +  $acces_token_request['body']['expires_in']);
	  if (isset($acces_token_request['body']['refresh_token']))
	    $this->userData('refresh_token', $acces_token_request['body']['refresh_token']);
	} else {
	  // Restarts authentification
	  self::reportLog("OpenClassroomsAPI::getAccessToken(): refresh token error".print_r($acces_token_request, true));
	  $this->do_authentification();
	}
      }
      return $this->userData('access_token');
    } else {
      // Starts authentification
      $this->do_authentification();
    }
  }
  // Requests authentification
  private function do_authentification() {
    // Prevents from repetitive authentification failures
    if ($this->userData('last_authentification') && (time() - $this->userData('last_authentification') < 60)) {
      self::errorPage("Il y a un problème d'authentification avec OpenClassrooms (échec il y a ".(time() - $this->userData('last_authentification'))." secondes, moins d'une minute)");
    } else {
      $this->userData('last_authentification', time());
    }
    // Switchs to the authentification URL
    self::httpRedirect('https://openclassrooms.com/oauth2/authorize?response_type=code&scope=user_email%20user_public_profile%20user_learning_activity&client_id='.urlencode(self::getAuth('OAuth2/client_id')).'&redirect_uri='.urlencode(self::getAuth('OAuth2/redirect_uri')).'&state='.$this->userData('state'));
  }
  
  /** Clears the OAuth2 data in order to restart an authentification. */
  public function clearAccessToken() {
    $this->userData('access_token', false, true);
  }

  /** Implements the hook to manage redirection URL during OAuth2 token request. 
   * @param $request The global $_REQUEST parameter.
   */
  public function do_token_request($request) { 
    // Tests if there is a code and if the state is correct.
    if (isset($request['code']) && isset($request['state']) && $request['state'] == $this->userData('state')) {
      $this->userData('code', /*urldecode-done*/($request['code']));
      // Requires the access token from the code
      $acces_token_request = self::httpRequest(array(
        'url' => 'https://api.openclassrooms.com/oauth2/token',
        'header' => array("Accept: application/json", "Content-Type: application/json"),
	'basic-auth' => self::getAuth('OAuth2/basic_auth'),
        'post' => '{
          "grant_type":"authorization_code",
          "client_id":"'.self::getAuth('OAuth2/client_id').'",
          "redirect_uri":"'.self::getAuth('OAuth2/redirect_uri').'",
          "code": "'.$this->userData('code').'"
        }'));
      if ($acces_token_request['code'] == 200) {
	$this->userData('access_token', $acces_token_request['body']['access_token']);
	$this->userData('access_token_expires_at', time() - 5 +  $acces_token_request['body']['expires_in']);
	$this->userData('refresh_token', $acces_token_request['body']['refresh_token']);
      } else {
	self::reportLog("OpenClassroomsAPI::do_token_request: access token error".print_r($acces_token_request, true));
	self::errorPage("Il y a un problème de synchronisation avec OpenClassrooms");
      }
    }
    return $request;
  }

  /** Calls the hook to manage redirection URL during OAuth2 token request. 
   * \private
   */
  function __construct() {
    add_filter('request', array($this, 'do_token_request'), 1, 1);
  }

  ////////////////////////////////////////////////////////////////////////////////
  
  //
  // Generic HTTP request and redirection methods
  //

  /** Encasulates a HTTP GET or POST request using curl.
   * - The method manages responses with partial content.
   * @param $request An array with the following input fields:
   * - <tt>url</tt> : The required URL to request,
   * - <tt>header</tt> : An optional array of strings with the different header lines,
   * - <tt>basic-auth</tt> : The optional $username.":".$password of the basic auth, if any,
   * - <tt>post</tt> : If set, defines a post request and provides the post body. This sets header the Content-Length field.
   * - <tt>with-range</tt> : If true performs several request in order to complete a partial range answer (i.e. code 206).
   *   - In that case the returned header is the 1st request header and the body is an array with all collected bodies.
   * @return An array with the following output fields:
   * - <tt>code</tt> : The HTTP response status code (e.g., code <tt>200</tt> if ok),
   * - <tt>request</tt> : The request, for tracing/debugging purpose,
   * - <tt>header</tt> : An associative array with the response header as <tt>key => value</tt>,
   *   - With a code <tt>206</tt> the <tt>Content-range</tt> field is parsed as an array with the <tt>start-index</tt>, <tt>stop-index</tt>, and <tt>total-length</tt>.
   * - <tt>body</tt> : The response body, if any. It is parsed as a JSON structure, if possible.
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
	  $request['header'][] = "Content-Length: ".strlen($request['post']);
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
	    } else if (trim($value) == "") {
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
			'request' => $request,
			'header' => $header,
			'body' => $body,
			'error' => $error,
			);
      } else
	$result = array('error' => "Unable to perform a curl request, error: ".curl_error($curl_request), 'code' => $code, 'request' => $request);
      curl_close($curl_request);
      return $result;
    }
  }
  // Retrieves partial content when content range (i.e., code 206) as specified by the HTTP protocol
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

  //////////////////

  /** Encapsulates a HTTP redirection. 
   * - The methods output javascript to replace the location and not header("Location : $url") because some HTML may have previously output.
   * @param $url The redirection URL.
   */
  public static function httpRedirect($url) {
    echo '<script language="javascript">location.replace("'.$url.'");</script>';
    exit(0);
  }

  //////////////////

  /** Gets the current HTTP URL. */
  public static function getCurrentURL() {
    return (empty($_SERVER["HTTPS"]) ? "http://" : "https://").$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"];
  }
  
  //////////////////////////////////////////////////////////////////////////////// 
  
  //
  // Wordpress dependent section
  //
 
  /** Gets/Sets the OpenClassroomsAPI user data from the WP database.
   * - This method must be overwitten for a non wordpress implementation.
   * - This method returns the previously registered value, the getUserLearningActivity() method updates the data.
   * @param $name The parameter name. Registered names are:
   * - Registered by the getUserLearningActivity() method
   *   - user_id : The OC client ID;
   *   - user_public_profile : The OC user public profile.
   *     - @see http://docs.openclassrooms.apiary.io/#reference/users/current-user/me
   *   - user_learning_activity : The OC user learning activity. 
   *     - It is defined by the get-followed-courses-with-user-information,
   *     - With each course table-of-content with user information
   *     - @see http://docs.openclassrooms.apiary.io/#reference/learning-activity/courses-followed-by-a-user/get-followed-courses-with-user-information 
   *     - @see http://docs.openclassrooms.apiary.io/#reference/learning-activity/table-of-content-with-user-information/get-table-of-content-with-user-information
   * - Registered by the getAccessToken() method
   *   - code, state, access_token, access_token_expires_at, refresh_token, last_authentification : For OAuth2 management.
   * @param $value The parameter value if to be set. 
   * @param $clear If the $value is false and $clear is true, remove the value.
   * @return The parameter values.
   */
  public function userData($name, $value = false, $clear = false) {
    $user_id = wp_get_current_user()->ID;
    if (!$this->user_data) {
      $this->user_data = get_user_meta($user_id, 'OpenClassroomsAPI/UserData', true);
      if (!isset($this->user_data['state'])) {
	$this->user_data['state'] = 'OC_authrequest_'.$user_id.'_'.rand(0, 1000000);
	update_user_meta($user_id, 'OpenClassroomsAPI/UserData', $this->user_data);
      }
    }
    if ($value) {
      $this->user_data[$name] = $value;
      update_user_meta($user_id, 'OpenClassroomsAPI/UserData', $this->user_data);
    } else if ($clear) {
      $this->user_data[$name] = false;
      update_user_meta($user_id, 'OpenClassroomsAPI/UserData', $this->user_data);
    }
    return isset($this->user_data[$name]) ? $this->user_data[$name] : false;
  }
  // This array contains all OpenClassroomsAPI user data and is managed by userData()
  private $user_data = false;

  //////////////////

  // Retrieves the authentification parameters.
  // - This method uses the <tt>plugin_dir_path( __FILE__ )</tt> wordpress dependent routine.
  static private function getAuth($name = "") {
    if (!self::$httpkeys) {
      $httpkeys_file = plugin_dir_path( __FILE__ ).'.httpkeys';
      if (is_file($httpkeys_file)) {
	include($httpkeys_file);
      } else {
	self::reportLog("OpenClassroomsAPI::getAuth: Unable to read the '.httpkeys' file");
	exit(0);
      }
      self::$httpkeys = $httpkeys;
    }
    return isset(self::$httpkeys[$name]) ? self::$httpkeys[$name] : false;
  }
  // This array contains all OpenClassroomsAPI authentification parameters
  private static $httpkeys = false;

  //////////////////

  // Echoes the error page with message, and then redirects to the basic URI
  private static function errorPage($message) {
    echo '
<html>
 <head>
    <meta charset="utf-8"/>    
    <title>Erreur de syndication avec OpenClassrooms</title>
  </head>
  <body style="padding:0px;margin-left:auto;margin-right:auto;background-color:white"><table><tr>
    <td style="margin:40px;width:40%">
      <img style="margin:40px;" src="https://openclassrooms.com/bundles/common/images/zozor_404.png"/>
    </td>
    <td style="margin:40px;width:40%">
      <h2>Upps !!! '.$message.'</h2>
      <h3>Renouveler la demande dans quelques instants.<h3>
      <h4 style="padding-left:20px;">(vous allez être <a href="'.self::getAuth('OAuth2/redirect_uri').'">redirigé</a> dans 5 secondes).</h4>
      <h3>Sinon <a href="'.self::getAuth('OAuth2/help_url').'">rapporter</a> cette erreur si elle se reproduit.</h3>
    </td>
    </tr></table>
    <script language="javascript">setTimeout(function() { window.location = "'.self::getAuth('OAuth2/redirect_uri').'"; }, 7 * 1000);</script>
  </body>
</html>';
    exit;
  }

  //////////////////

  // Outputs and Mails logs in wordpress/wp-content/logs/OpenClassroomsAPI.log and on 'OAuth2/log_email'
  private static function reportLog($message, $append = true) {
    $echo_file = get_home_path()."/wp-content/logs/OpenClassroomsAPI.log";
    $log_message = "\n[".date('c').", ".wp_get_current_user()->user_nicename."]".preg_replace("/\s+/", " ", $message)."\n";
    file_put_contents($echo_file, $log_message, $append ? FILE_APPEND : 0);
    if (self::getAuth('OAuth2/log_email'))
      mail(self::getAuth('OAuth2/log_email'),  "OpenClassroomsAPI log alert", $log_message, "Content-type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n");
  }

  //////////////////
}
$OpenClassroomsAPI = new OpenClassroomsAPI();
?>
