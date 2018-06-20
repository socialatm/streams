<?php

namespace Zotlabs\Web;

use Zotlabs\Lib\ActivityStreams;
use Zotlabs\Lib\Webfinger;

/**
 * @brief Implements HTTP Signatures per draft-cavage-http-signatures-10.
 *
 * @see https://tools.ietf.org/html/draft-cavage-http-signatures-10
 */

class HTTPSig {

	/**
	 * @brief RFC5843
	 *
	 * @see https://tools.ietf.org/html/rfc5843
	 *
	 * @param string $body The value to create the digest for
	 * @param string $alg hash algorithm (one of 'sha256','sha512')
	 * @return string The generated digest header string for $body
	 */

	static function generate_digest_header($body,$alg = 'sha256') {

		$digest = base64_encode(hash($alg, $body, true));
		switch($alg) {
			case 'sha512':
				return 'SHA-512=' . $digest;
			case 'sha256':
			default:
				return 'SHA-256=' . $digest;
				break;
		}
	}

	static function find_headers($data,&$body) {

		// decide if $data arrived via controller submission or curl

		if(is_array($data) && $data['header']) {
			if(! $data['success'])
				return [];

			$h = new HTTPHeaders($data['header']);
			$headers = $h->fetcharr();
			$body = $data['body'];
		}

		else {
			$headers = [];
			$headers['(request-target)'] = strtolower($_SERVER['REQUEST_METHOD']) . ' ' . $_SERVER['REQUEST_URI'];
			$headers['content-type'] = $_SERVER['CONTENT_TYPE'];

			foreach($_SERVER as $k => $v) {
				if(strpos($k,'HTTP_') === 0) {
					$field = str_replace('_','-',strtolower(substr($k,5)));
					$headers[$field] = $v;
				}
			}
		}

		//logger('SERVER: ' . print_r($_SERVER,true), LOGGER_ALL);

		//logger('headers: ' . print_r($headers,true), LOGGER_ALL);

		return $headers;
	}


	// See draft-cavage-http-signatures-10

	static function verify($data,$key = '') {

		$body      = $data;
		$headers   = null;
		$spoofable = false;

		$result = [
			'signer'         => '',
			'header_signed'  => false,
			'header_valid'   => false,
			'content_signed' => false,
			'content_valid'  => false
		];


		$headers = self::find_headers($data,$body);

		if(! $headers)
			return $result;

		$sig_block = null;

		if(array_key_exists('signature',$headers)) {
			$sig_block = self::parse_sigheader($headers['signature']);
		}
		elseif(array_key_exists('authorization',$headers)) {
			$sig_block = self::parse_sigheader($headers['authorization']);
		}

		if(! $sig_block) {
			logger('no signature provided.', LOGGER_DEBUG);
			return $result;
		}

		// Warning: This log statement includes binary data
		// logger('sig_block: ' . print_r($sig_block,true), LOGGER_DATA);

		$result['header_signed'] = true;

		$signed_headers = $sig_block['headers'];
		if(! $signed_headers)
			$signed_headers = [ 'date' ];

		$signed_data = '';
		foreach($signed_headers as $h) {
			if(array_key_exists($h,$headers)) {
				$signed_data .= $h . ': ' . $headers[$h] . "\n";
			}
			if(strpos($h,'.')) {
				$spoofable = true;
			}
		}
		$signed_data = rtrim($signed_data,"\n");

		$algorithm = null;
		if($sig_block['algorithm'] === 'rsa-sha256') {
			$algorithm = 'sha256';
		}
		if($sig_block['algorithm'] === 'rsa-sha512') {
			$algorithm = 'sha512';
		}

		if(! array_key_exists('keyId',$sig_block))
			return $result;

		$result['signer'] = $sig_block['keyId'];

		$key = self::get_key($key,$result['signer']);

		if(! $key)
			return $result;

		$x = rsa_verify($signed_data,$sig_block['signature'],$key,$algorithm);

		logger('verified: ' . $x, LOGGER_DEBUG);

		if(! $x)
			return $result;

		if(! $spoofable)
			$result['header_valid'] = true;

		if(in_array('digest',$signed_headers)) {
			$result['content_signed'] = true;
			$digest = explode('=', $headers['digest'], 2);
			if($digest[0] === 'SHA-256')
				$hashalg = 'sha256';
			if($digest[0] === 'SHA-512')
				$hashalg = 'sha512';

			if(base64_encode(hash($hashalg,$body,true)) === $digest[1]) {
				$result['content_valid'] = true;
			}
		}

		logger('Content_Valid: ' . (($result['content_valid']) ? 'true' : 'false'));

		return $result;
	}

	static function get_key($key,$id) {

		if($key && function_exists($key)) {
			$key = $key($id);
		}

		if(! $key) {
			$key = self::get_webfinger_key($id);
		}

		if(! $key) {
			$key = self::get_activitystreams_key($id);
		}

		return $key;

	}


	function convertKey($key) {

		if(strstr($key,'RSA ')) { 
			return rsatopem($key);
		}
		elseif(substr($key,0,5) === 'data:') {
			return convert_salmon_key($key);
		}
		else {
			return $key;
		}

	}


	/**
	 * @brief
	 *
	 * @param string $id
	 * @return boolean|string
	 *   false if no pub key found, otherwise return the pub key
	 */

	function get_activitystreams_key($id) {

		$x = q("select xchan_pubkey from xchan left join hubloc on xchan_hash = hubloc_hash where hubloc_addr = '%s' or hubloc_id_url = '%s' limit 1",
			dbesc(str_replace('acct:','',$id)),
			dbesc($id)
		);

		if($x && $x[0]['xchan_pubkey']) {
			return ($x[0]['xchan_pubkey']);
		}

		$r = ActivityStreams::fetch_property($id);

		if($r) {
			$j = json_decode($r,true);

			if(array_key_exists('publicKey',$j) && array_key_exists('publicKeyPem',$j['publicKey'])) {
				if((array_key_exists('id',$j['publicKey']) && $j['publicKey']['id'] !== $id) && $j['id'] !== $id)
					return false;

				return self::convertKey($j['publicKey']['publicKeyPem']);
			}
		}

		return false;
	}


	function get_webfinger_key($id) {

		$x = q("select xchan_pubkey from xchan left join hubloc on xchan_hash = hubloc_hash where hubloc_addr = '%s' or hubloc_id_url = '%s' limit 1",
			dbesc(str_replace('acct:','',$id)),
			dbesc($id)
		);
		if($x && $x[0]['xchan_pubkey']) {
			return $x[0]['xchan_pubkey'];
		}

		$wf = Webfinger::exec($id);

		if($wf) {
		 	if(array_key_exists('properties',$wf) && array_key_exists('https://w3id.org/security/v1#publicKeyPem',$wf['properties'])) {
				return self::convertKey($wf['properties']['https://w3id.org/security/v1#publicKeyPem']);
			}
			else {
				if(array_key_exists('links', $wf) && is_array($wf['links'])) {
					foreach($wf['links'] as $l) {
						if(is_array($l) && array_key_exists('rel',$l) && $l['rel'] === 'magic-public-key' && array_key_exists('href',$l)) {
							return ((self::convertKey($l['href'])) ?: false);
						}
					}
				}
			}
		}

		return false;
	}


	/**
	 * @brief
	 *
	 * @param string $request
	 * @param array $head
	 * @param string $prvkey
	 * @param string $keyid (optional, default 'Key')
	 * @param boolean $send_headers (optional, default false)
	 *   If set send a HTTP header
	 * @param boolean $auth (optional, default false)
	 * @param string $alg (optional, default 'sha256')
	 * @param string $crypt_key (optional, default null)
	 * @param array $encryption [ 'key', 'algorithm' ] or false
	 * @return array
	 */
	static function create_sig($head, $prvkey, $keyid = EMPTY_STR, $auth = false, $alg = 'sha256', $encryption = false ) {

		$return_headers = [];

		if($alg === 'sha256') {
			$algorithm = 'rsa-sha256';
		}
		if($alg === 'sha512') {
			$algorithm = 'rsa-sha512';
		}

		$x = self::sign($head,$prvkey,$alg);

		$headerval = 'keyId="' . $keyid . '",algorithm="' . $algorithm . '",headers="' . $x['headers'] . '",signature="' . $x['signature'] . '"';

		if($encryption) {
			$x = crypto_encapsulate($headerval,$encryption['key'],$encryption['algorithm']);
			$headerval = 'iv="' . $x['iv'] . '",key="' . $x['key'] . '",alg="' . $x['alg'] . '",data="' . $x['data'] . '"';
		}

		if($auth) {
			$sighead = 'Authorization: Signature ' . $headerval;
		}
		else {
			$sighead = 'Signature: ' . $headerval;
		}

		if($head) {
			foreach($head as $k => $v) {
				// strip the request-target virtual header from the output headers
				if($k === '(request-target)') {
					continue;
				}
				$return_headers[] = $k . ': ' . $v;
			}
		}
		$return_headers[] = $sighead;

		return $return_headers;
	}

	static function set_headers($headers) {
		if($headers && is_array($headers)) {
			foreach($headers as $h) {
				header($h);
			}
		} 
	}


	/**
	 * @brief
	 *
	 * @param array  $head
	 * @param string $prvkey
	 * @param string $alg (optional) default 'sha256'
	 * @return array
	 */
	static function sign($head, $prvkey, $alg = 'sha256') {

		$ret = [];

		$headers = '';
		$fields  = '';

		if($head) {
			foreach($head as $k => $v) {
				$headers .= strtolower($k) . ': ' . trim($v) . "\n";
				if($fields)
					$fields .= ' ';

				$fields .= strtolower($k);
			}
			// strip the trailing linefeed
			$headers = rtrim($headers,"\n");
		}

		$sig = base64_encode(rsa_sign($headers,$prvkey,$alg));

		$ret['headers']   = $fields;
		$ret['signature'] = $sig;

		return $ret;
	}

	/**
	 * @brief
	 *
	 * @param string $header
	 * @return array associate array with
	 *   - \e string \b keyID
	 *   - \e string \b algorithm
	 *   - \e array  \b headers
	 *   - \e string \b signature
	 */
	static function parse_sigheader($header) {

		$ret = [];
		$matches = [];

		// if the header is encrypted, decrypt with (default) site private key and continue

		if(preg_match('/iv="(.*?)"/ism',$header,$matches))
			$header = self::decrypt_sigheader($header);

		if(preg_match('/keyId="(.*?)"/ism',$header,$matches))
			$ret['keyId'] = $matches[1];
		if(preg_match('/algorithm="(.*?)"/ism',$header,$matches))
			$ret['algorithm'] = $matches[1];
		if(preg_match('/headers="(.*?)"/ism',$header,$matches))
			$ret['headers'] = explode(' ', $matches[1]);
		if(preg_match('/signature="(.*?)"/ism',$header,$matches))
			$ret['signature'] = base64_decode(preg_replace('/\s+/','',$matches[1]));

		if(($ret['signature']) && ($ret['algorithm']) && (! $ret['headers']))
			$ret['headers'] = [ 'date' ];

 		return $ret;
	}


	/**
	 * @brief
	 *
	 * @param string $header
	 * @param string $prvkey (optional), if not set use site private key
	 * @return array|string associative array, empty string if failue
	 *   - \e string \b iv
	 *   - \e string \b key
	 *   - \e string \b alg
	 *   - \e string \b data
	 */
	static function decrypt_sigheader($header, $prvkey = null) {

		$iv = $key = $alg = $data = null;

		if(! $prvkey) {
			$prvkey = get_config('system', 'prvkey');
		}

		$matches = [];

		if(preg_match('/iv="(.*?)"/ism',$header,$matches))
			$iv = $matches[1];
		if(preg_match('/key="(.*?)"/ism',$header,$matches))
			$key = $matches[1];
		if(preg_match('/alg="(.*?)"/ism',$header,$matches))
			$alg = $matches[1];
		if(preg_match('/data="(.*?)"/ism',$header,$matches))
			$data = $matches[1];

		if($iv && $key && $alg && $data) {
			return crypto_unencapsulate([ 'iv' => $iv, 'key' => $key, 'alg' => $alg, 'data' => $data ] , $prvkey);
		}

		return '';
	}

}
