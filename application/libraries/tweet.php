<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/**
 * CodeIgniter Twitter
 *
 * A CodeIgniter library to interact with Api Twitter.
 * Original author: elliothaughin
 *
 * @package        	CodeIgniter
 * @category    	Libraries
 * @author        	Marcos Sanz
 * @link 			https://github.com/israelhope/codeigniter-twitter
 * @license         http://www.opensource.org/licenses/mit-license.html
 */

class tweet {

	private $_oauth = NULL;

	function __construct() {
		$this -> _oauth = new tweetOauth();
	}

	function __call($method, $args) {
		if (method_exists($this, $method)) {
			return call_user_func_array(array($this, $method), $args);
		}

		return call_user_func_array(array($this -> _oauth, $method), $args);
	}

	function logged_in() {
		return $this -> _oauth -> loggedIn();
	}

	function get_header() {
		return $this -> _oauth -> getHeader();
	}

	function set_callback($url) {
		$this -> _oauth -> setCallback($url);
	}

	function login($sign_in_twitter = false) {
		return $this -> _oauth -> login($sign_in_twitter);
	}

	function logout() {
		return $this -> _oauth -> logout();
	}

	function get_tokens() {
		$tokens = array(
			'oauth_token' => $this -> _oauth -> getAccessKey(), 
			'oauth_token_secret' => $this -> _oauth -> getAccessSecret()
		);

		return $tokens;
	}

	function set_tokens($tokens) {
		return $this -> _oauth -> setAccessTokens($tokens);
	}

}

class tweetException extends Exception {

	function __construct($string) {
		parent::__construct($string);
	}

	public function __toString() {
		return "exception '" . __CLASS__ . "' with message '" . $this -> getMessage() . "' in " . $this -> getFile() . ":" . $this -> getLine() . "\nStack trace:\n" . $this -> getTraceAsString();
	}

}

class tweetConnection {

	// Allow multi-threading.

	private $_mch = NULL;
	private $_allowMulticall = false;
	private $_properties = array();

	public $header = NULL;

	function __construct($allow_multicall = false) {
		$this -> _mch = curl_multi_init();
		$this -> _allowMulticall = $allow_multicall;
		$this -> _properties = array(
			'code' => CURLINFO_HTTP_CODE, 
			'time' => CURLINFO_TOTAL_TIME, 
			'length' => CURLINFO_CONTENT_LENGTH_DOWNLOAD, 
			'type' => CURLINFO_CONTENT_TYPE
		);
	}

	function __destruct(){
		curl_multi_close($this -> _mch);
	}

	private function _initConnection($url) {
		$this -> _ch = curl_init($url);
		curl_setopt($this -> _ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($this -> _ch, CURLOPT_VERBOSE, TRUE);
		curl_setopt($this -> _ch, CURLOPT_HEADER, TRUE);
	}

	public function get($url, $params) {
		if (count($params['request']) > 0) {
			$url .= '?';

			foreach ($params['request'] as $k => $v) {
				$k = str_ireplace(array('+', '%7E'), array(' ', '~'), rawurlencode($k));
				$v = str_ireplace(array('+', '%7E'), array(' ', '~'), rawurlencode($v));
				$url .= "{$k}={$v}&";
			}

			$url = substr($url, 0, -1);
		}

		$this -> _initConnection($url);
		$response = $this -> _addCurl($url, $params);

		return $response;
	}

	public function post($url, $params) {
		$post = '';

		foreach ($params['request'] as $k => $v) {
			$k = str_ireplace(array('+', '%7E'), array(' ', '~'), rawurlencode($k));
			$v = str_ireplace(array('+', '%7E'), array(' ', '~'), rawurlencode($v));
			$post .= "{$k}={$v}&";
		}

		$post = substr($post, 0, -1);

		$this -> _initConnection($url, $params);
		curl_setopt($this -> _ch, CURLOPT_POST, 1);
		curl_setopt($this -> _ch, CURLOPT_POSTFIELDS, $post);

		$response = $this -> _addCurl($url, $params);

		return $response;
	}

	private function _addOauthHeaders(&$ch, $url, $oauthHeaders) {
		$_h = array(
			'Expect:', 
			'Connection: Keep-Alive', 
			'Cache-Control: no-cache'
		);

		if (isset($_SERVER['HTTP_USER_AGENT'])) {
			array_push($_h, 'User-Agent: ' . $_SERVER['HTTP_USER_AGENT']);
		}

		$urlParts = parse_url($url);
		$oauth = 'Authorization: OAuth realm="' . $urlParts['path'] . '",';

		foreach ($oauthHeaders as $name => $value) {
			$oauth .= "{$name}=\"{$value}\",";
		}

		// Additional headers
		$_h[] = substr($oauth, 0, -1);

		curl_setopt($ch, CURLOPT_HTTPHEADER, $_h);
	}

	private function _addCurl($url, $params = array()) {
		if (!empty($params['oauth'])) {
			$this -> _addOauthHeaders($this -> _ch, $url, $params['oauth']);
		}

		$ch = $this -> _ch;

		$key = (string)$ch;
		$this -> _requests[$key] = $ch;

		$response = curl_multi_add_handle($this -> _mch, $ch);

		if ( !$this -> _allowMulticall && ($response === CURLM_OK || $response === CURLM_CALL_MULTI_PERFORM) ) {
			do {
				$mch = curl_multi_exec($this -> _mch, $active);
			} while ( $mch === CURLM_CALL_MULTI_PERFORM );

			return $this -> _getResponse($key);
		} else {
			return $response;
		}
	}

	public function getMultiResponses(){
		if(empty($this -> _requests)){
			return FALSE;
		}
		
		//Ejecuto los handles
		$active = null; 
		do{
			$mrc = curl_multi_exec($this -> _mch, $active);
		} while ($mrc == CURLM_CALL_MULTI_PERFORM);

		while ($active && $mrc == CURLM_OK) {
			if(curl_multi_select($this -> _mch) != -1){
				do{
					$mrc = curl_multi_exec($this -> _mch, $active);
				} while ($mrc == CURLM_CALL_MULTI_PERFORM);
			}
		}

		$this -> _setResponse();

		$responseArray = array();
		foreach ($this-> _responses as $key => $value){
			$responseOauth = new tweetResponseOauth((object)$this -> _responses[$key]);
			$responseArray[] = (isset($responseOauth -> __resp))?($responseOauth -> __resp): FALSE;
		}
		
		return $responseArray;
	}

	private function _getResponse($key = NULL) {
		if ($key == NULL)
			return FALSE;

		if (isset($this -> _responses[$key])) {
			return $this -> _responses[$key];
		}

		$running = NULL;

		do {
			$response = curl_multi_exec($this -> _mch, $running_curl);

			if ($running !== NULL && $running_curl != $running) {
				$this -> _setResponse();

				if (isset($this -> _responses[$key])) {
					$response = new tweetResponseOauth((object)$this -> _responses[$key]);

					if (isset($response -> __resp -> header -> status)) {
						$response -> __resp -> header -> status = $response -> __resp -> code;
						$this -> header = $response -> __resp -> header;
					}

					if ($response -> __resp -> code !== 200) {
						log_message('error','ERROR TWITTER API:'.print_r($response,true));
						throw new tweetException($response -> __resp -> code . ' | Request Failed: ' . $response -> __resp -> data -> errors[0] -> message);
					}

					return $response;
				}
			}

			$running = $running_curl;

		} while ( $running_curl > 0);

	}

	private function _setResponse() {
		while ($done = curl_multi_info_read($this -> _mch)) {
			$key = (string)$done['handle'];

			$header_size = curl_getinfo($done['handle'], CURLINFO_HEADER_SIZE);
			$header = substr(curl_multi_getcontent($done['handle']), 0, $header_size);
			$body = substr(curl_multi_getcontent($done['handle']), $header_size);

			$this -> _responses[$key]['header'] = $header;
			$this -> _responses[$key]['data'] = $body;

			foreach ($this->_properties as $curl_key => $value) {
				$this -> _responses[$key][$curl_key] = curl_getinfo($done['handle'], $value);
			}
			curl_multi_remove_handle($this -> _mch, $done['handle']);
		}
	}

}

class tweetResponseOauth {

	private $__construct;

	public function __construct($resp) {
		$this -> __resp = $resp;

		if (strpos($this -> __resp -> type, 'json') !== FALSE) {
			$this -> __resp -> data = json_decode($this -> __resp -> data);

			$this -> __resp -> header = (object)$this -> _formatHeader($this -> __resp -> header);
		}
	}

	public function __get($name) {
		if ($this -> __resp -> code < 200 || $this -> __resp -> code > 299)
			return FALSE;

		if (is_string($this -> __resp -> data)) {
			parse_str($this -> __resp -> data, $result);
		} else {
			$result = $this -> __resp -> data;
		}

		foreach ($result as $k => $v) {
			$this -> $k = $v;
		}

		if ($name === '_result') {
			return $result;
		}

		return $result[$name];
	}

	private function _formatHeader($header) {
		$result = explode("\n", $header);

		$result_header = array();
		foreach ($result as $k => $v) {
			if ($k == 0 || trim($v) == '')
				continue;

			list($key, $value) = array_pad(explode(':', $v), 2, null);
			$result_header[strtolower(trim($key))] = trim($value);
		}

		return $result_header;
	}

}

class tweetOauth extends tweetConnection {

	private $_obj;
	private $_tokens = array();
	private $_authorizationUrl = 'http://api.twitter.com/oauth/authorize'; // Login each time
	private $_authenticationUrl = 'http://api.twitter.com/oauth/authenticate'; // Keep logged in
	private $_requestTokenUrl = 'http://api.twitter.com/oauth/request_token';
	private $_accessTokenUrl = 'http://api.twitter.com/oauth/access_token';
	private $_signatureMethod = 'HMAC-SHA1';
	private $_version = '1.0a';
	private $_apiVersion = '1.1';
	private $_apiUrl = 'http://api.twitter.com';
	private $_searchUrl = 'http://search.twitter.com';
	private $_responseType = 'json';
	private $_callback = NULL;
	private $_verifier = NULL;
	private $_errors = array();
	private $_enable_debug = FALSE;
	private $_allowMulticall = false;

	function __construct() {
		parent::__construct();

		$this -> _obj = &get_instance();
		$this -> _obj -> load -> config('tweet');
		$this -> _obj -> load -> library('session');
		$this -> _obj -> load -> library('unit_test');
		$this -> _obj -> load -> helper('url');

		$this -> _tokens = array(
			'consumer_key' => $this -> _obj -> config -> item('tweet_consumer_key'), 
			'consumer_secret' => $this -> _obj -> config -> item('tweet_consumer_secret'), 
			'access_key' => $this -> _getAccessKey(), 
			'access_secret' => $this -> _getAccessSecret()
		);

		$this -> _checkLogin();
	}

	function __destruct() {
		if (!$this -> _enable_debug)
			return;

		if (!empty($this -> _errors)) {
			foreach ($this->_errors as $key => $e) {
				echo '<pre>' . $e . '</pre>';
			}
		}
	}
	
	public function init_multicall(){
		$this->_allowMulticall = TRUE;
		$this -> _connection = new tweetConnection($this->_allowMulticall);
	}

	public function exec_multicall(){
		$this -> _allowMulticall = FALSE;	
		if (!isset($this -> _connection)){
			return FALSE;
		}
		return $this -> _connection -> getMultiResponses();
	}

	public function enable_debug($debug) {
		$debug = (bool)$debug;
		$this -> _enable_debug = $debug;
	}

	public function getHeader() {
		return $this -> header;
	}

	public function call($method, $path, $args = NULL) {
		$response = $this -> _httpRequest(strtoupper($method), $this -> _apiUrl . '/' . $this -> _apiVersion . '/' . $path . '.' . $this -> _responseType, $args);

		// var_dump($response);
		// die();
		if(is_numeric($response)){
			return $response;
		}
		return ($response === NULL) ? FALSE : $response -> _result;
	}

	public function search($args = NULL) {
		$response = $this -> _httpRequest('GET', $this -> _searchUrl . '/search.' . $this -> _responseType, $args);

		return ($response === NULL) ? FALSE : $response -> _result;
	}

	public function loggedIn() {
		$access_key = $this -> _getAccessKey();
		$access_secret = $this -> _getAccessSecret();

		$loggedIn = FALSE;

		if ($this -> _getAccessKey() !== NULL && $this -> _getAccessSecret() !== NULL) {
			$loggedIn = TRUE;
		}

		//$this -> _obj -> unit -> run($loggedIn, TRUE, 'Logged In');
		return $loggedIn;
	}

	private function _checkLogin() {
		if(isset($_GET['oauth_verifier'])){
			$this -> _setVerifier($_GET['oauth_verifier']);
		}
		if (isset($_GET['oauth_token'])) {
			$this -> _setAccessKey($_GET['oauth_token']);
			$token = $this -> _getAccessToken();

			$token = $token -> _result;

			$token = ( is_bool($token)) ? $token : (object)$token;

			if (!empty($token -> oauth_token) && !empty($token -> oauth_token_secret)) {
				$this -> _setAccessKey($token -> oauth_token);
				$this -> _setAccessSecret($token -> oauth_token_secret);
			}

			redirect(current_url());
			return NULL;
		}
	}

	public function login($sign_in_twitter = false) {
		if (($this -> _getAccessKey() === NULL || $this -> _getAccessSecret() === NULL)) {
			header('Location: ' . $this -> _getAuthorizationUrl($sign_in_twitter));
			return;
		}

		return $this -> _checkLogin();
	}

	public function logout() {
		$this -> _obj -> session -> unset_userdata('twitter_oauth_tokens');
	}

	public function getTokens() {
		return $this -> _tokens;
	}

	private function _getConsumerKey() {
		return $this -> _tokens['consumer_key'];
	}

	private function _getConsumerSecret() {
		return $this -> _tokens['consumer_secret'];
	}

	public function getAccessKey() {
		return $this -> _getAccessKey();
	}

	private function _getAccessKey() {
		$tokens = $this -> _obj -> session -> userdata('twitter_oauth_tokens');
		return ($tokens === FALSE || !isset($tokens['access_key']) || empty($tokens['access_key'])) ? NULL : $tokens['access_key'];
	}

	private function _setAccessKey($access_key) {
		$tokens = $this -> _obj -> session -> userdata('twitter_oauth_tokens');

		if ($tokens === FALSE || !is_array($tokens)) {
			$tokens = array('access_key' => $access_key);
		} else {
			$tokens['access_key'] = $access_key;
		}

		$this -> _obj -> session -> set_userdata('twitter_oauth_tokens', $tokens);
	}

	public function getAccessSecret() {
		return $this -> _getAccessSecret();
	}

	private function _getAccessSecret() {
		$tokens = $this -> _obj -> session -> userdata('twitter_oauth_tokens');
		return ($tokens === FALSE || !isset($tokens['access_secret']) || empty($tokens['access_secret'])) ? NULL : $tokens['access_secret'];
	}

	private function _setAccessSecret($access_secret) {
		$tokens = $this -> _obj -> session -> userdata('twitter_oauth_tokens');

		if ($tokens === FALSE || !is_array($tokens)) {
			$tokens = array('access_secret' => $access_secret);
		} else {
			$tokens['access_secret'] = $access_secret;
		}

		$this -> _obj -> session -> set_userdata('twitter_oauth_tokens', $tokens);
	}

	private function _setAccessTokens($tokens) {
		$this -> _setAccessKey($tokens['oauth_token']);
		$this -> _setAccessSecret($tokens['oauth_token_secret']);
	}

	public function setAccessTokens($tokens) {
		return $this -> _setAccessTokens($tokens);
	}

	private function _getAuthorizationUrl($sign_in_twitter = false) {
		$token = $this -> _getRequestToken();
		if ($sign_in_twitter) {
			return $this -> _authenticationUrl . '?oauth_token=' . $token -> oauth_token;
		}
		return $this -> _authorizationUrl . '?oauth_token=' . $token -> oauth_token;
	}

	private function _getRequestToken() {
		return $this -> _httpRequest('GET', $this -> _requestTokenUrl);
	}

	private function _getAccessToken() {
		return $this -> _httpRequest('GET', $this -> _accessTokenUrl);
	}

	protected function _httpRequest($method = null, $url = null, $params = null) {
		if (empty($method) || empty($url))
			return NULL;
		if (empty($params['oauth_signature']))
			$params = $this -> _prepareParameters($method, $url, $params);

		if (!$this->_allowMulticall) {
			$this -> _connection = new tweetConnection();	
		}

		try {
			switch ( $method ) {
				case 'GET' :
					$response = $this -> _connection -> get($url, $params);
					$this -> header = $this -> _connection -> header;
					return $response;
					break;

				case 'POST' :
					$response = $this -> _connection -> post($url, $params);
					$this -> header = $this -> _connection -> header;
					return $response;
					break;

				case 'PUT' :
					return NULL;
					break;

				case 'DELETE' :
					return NULL;
					break;
			}
		} catch (tweetException $e) {
			$this -> _errors[] = $e;
		}
	}

	private function _getCallback() {
		return $this -> _callback;
	}

	public function setCallback($url) {
		$this -> _callback = $url;
	}

	private function _getVerifier(){
		return $this -> _verifier;
	}

	private function _setVerifier($verifier){
		$this -> _verifier = $verifier;
	}

	private function _prepareParameters($method = NULL, $url = NULL, $params = NULL) {
		if (empty($method) || empty($url))
			return FALSE;

		$callback = $this -> _getCallback();
		if (!empty($callback)) {
			$oauth['oauth_callback'] = $callback;
		}
		$this -> setCallback(NULL);

		$verifier = $this -> _getVerifier();
		if (!empty($verifier)) {
			$oauth['oauth_verifier'] = $verifier;
		}

		$oauth['oauth_consumer_key'] = $this -> _getConsumerKey();
		$oauth['oauth_token'] = $this -> _getAccessKey();
		$oauth['oauth_nonce'] = $this -> _generateNonce();
		$oauth['oauth_timestamp'] = time();
		$oauth['oauth_signature_method'] = $this -> _signatureMethod;
		$oauth['oauth_version'] = $this -> _version;

		array_walk($oauth, array($this, '_encode_rfc3986'));

		if (is_array($params)) {
			array_walk($params, array($this, '_encode_rfc3986'));
		}

		$encodedParams = array_merge($oauth, (array)$params);

		ksort($encodedParams);

		$oauth['oauth_signature'] = $this -> _encode_rfc3986($this -> _generateSignature($method, $url, $encodedParams));
		return array('request' => $params, 'oauth' => $oauth);
	}

	private function _generateNonce() {
		return md5(uniqid(rand(), TRUE));
	}

	private function _encode_rfc3986($string) {
		return str_ireplace(array('+', '%7E'), array(' ', '~'), rawurlencode($string));
	}

	private function _generateSignature($method = null, $url = null, $params = null) {
		if (empty($method) || empty($url))
			return FALSE;

		// concatenating
		$concatenatedParams = '';

		foreach ($params as $k => $v) {
			$k = $this -> _encode_rfc3986($k);
			$v = $this -> _encode_rfc3986($v);
			$concatenatedParams .= "{$k}={$v}&";
		}

		$concatenatedParams = $this -> _encode_rfc3986(substr($concatenatedParams, 0, -1));

		// normalize url
		$normalizedUrl = $this -> _encode_rfc3986($this -> _normalizeUrl($url));
		$method = $this -> _encode_rfc3986($method);
		// don't need this but why not?

		$signatureBaseString = "{$method}&{$normalizedUrl}&{$concatenatedParams}";
		return $this -> _signString($signatureBaseString);
	}

	private function _normalizeUrl($url = NULL) {
		$urlParts = parse_url($url);

		if (!isset($urlParts['port']))
			$urlParts['port'] = 80;

		$scheme = strtolower($urlParts['scheme']);
		$host = strtolower($urlParts['host']);
		$port = intval($urlParts['port']);

		$retval = "{$scheme}://{$host}";

		if ($port > 0 && ($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) {
			$retval .= ":{$port}";
		}

		$retval .= $urlParts['path'];

		if (!empty($urlParts['query'])) {
			$retval .= "?{$urlParts['query']}";
		}

		return $retval;
	}

	private function _signString($string) {
		$retval = FALSE;
		switch ( $this->_signatureMethod ) {
			case 'HMAC-SHA1' :
				$key = $this -> _encode_rfc3986($this -> _getConsumerSecret()) . '&' . $this -> _encode_rfc3986($this -> _getAccessSecret());
				$retval = base64_encode(hash_hmac('sha1', $string, $key, true));
				break;
		}

		return $retval;
	}

}

/* End of file tweet.php */
/* Location: ./application/libraries/tweet.php */