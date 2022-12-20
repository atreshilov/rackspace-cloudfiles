<?php

/**
 * Simple class that supposed to work with Rackspace Cloud Files API 1.0 without dependencies. PHP8 ready.
 *
 * @link https://github.com/atreshilov/rackspace-cloudfiles
 * @version 1.0
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

class cloudfiles {

	private string $endpoint;
	private string $endpoint_cdn;
	private string $token;

	private $curl_response_headers;
	private $curl_response_body;

	/**
	 * @throws Exception
	 * @return void
	 */
	function authorize(string $username, string $api_key, string $region) {
		try {
			$post_json = json_encode([
				"auth"	=>
					[
						"RAX-KSKEY:apiKeyCredentials" =>
							[
								"username"	=>	$username,
								"apiKey"	=> 	$api_key,
							]
					]
			]);
			$this->curl(
				"https://identity.api.rackspacecloud.com/v2.0/tokens",
				"POST",
				[
					CURLOPT_HTTPHEADER => [
						"Content-Type: application/json",
						"Content-Length: ".strlen($post_json)

					],
					CURLOPT_POSTFIELDS => $post_json,
				]
			);

			$json = json_decode($this->curl_response_body, true);
			if (!is_array($json)) {
				throw new Exception("response is not a JSON array");
			}

			$token=$json["access"]["token"]["id"] ?? "";
			if ($token=="") {
				throw new Exception("access->token->id is empty/not found");
			}

			$service_catalog=$json["access"]["serviceCatalog"] ?? [];
			if (count($service_catalog)==0) {
				throw new Exception("access->serviceCatalog is empty/not found");
			}

			$endpoints=[];
			foreach (["cloudFiles", "cloudFilesCDN"] as $service_name) {
				$cloudfiles_endpoints=[];
				foreach ($service_catalog as $service) {
					if (!isset($service["name"]) || !isset($service["endpoints"])) {
						throw new Exception("wrong serviceCatalog entry");
					}
					if ($service["name"]==$service_name) {
						$cloudfiles_endpoints=$service["endpoints"];
					}
				}
				if (count($cloudfiles_endpoints)==0) {
					throw new Exception("unable to find cloudfiles endpoints");
				}

				$endpoint="";
				foreach ($cloudfiles_endpoints as $test_endpoint) {
					if (!isset($test_endpoint["region"]) || !isset($test_endpoint["publicURL"])) {
						throw new Exception("wrong cloudfiles endpoint entry");
					}
					if ($test_endpoint["region"]==$region) {
						$endpoint=$test_endpoint["publicURL"];
						break;
					}
				}
				if ($endpoint=="") {
					throw new Exception("no endpoint found for region '{$region}'");
				}
				$endpoints[$service_name]=$endpoint;
			}

			$this->token=$token;
			$this->endpoint=$endpoints["cloudFiles"];
			$this->endpoint_cdn=$endpoints["cloudFilesCDN"];

		} catch (Exception $e) {
			throw new Exception(
				"Unable to authorize -- {$e->getMessage()}" .
				(($this->curl_response_body=="") ? "" : ", response:\n\n$this->curl_response_body")
			);
		}
	}
	/**
	 * @return void
	 * @throws Exception
	 */
	function container_create($container_name){
		try {
			$this->curl(
				"{$this->endpoint}/{$container_name}",
				"PUT",
				[
					CURLOPT_HTTPHEADER => [
						"X-Auth-Token: {$this->token}",
					],
				]
			);
		} catch (Exception $e) {
			throw new Exception("Unable to create container '$container_name' -- {$e->getMessage()}");
		}
	}
	/**
	 * @return void
	 * @throws Exception
	 */
	function container_delete($container_name){
		try {
			$this->curl(
				"{$this->endpoint}/{$container_name}",
				"DELETE",
				[
					CURLOPT_HTTPHEADER => [
						"X-Auth-Token: {$this->token}",
					],
				]
			);
		} catch (Exception $e) {
			throw new Exception("Unable to delete container '$container_name' -- {$e->getMessage()}");
		}
	}
	/**
	 * @return void
	 * @throws Exception
	 */
	function container_cdn_enable($container_name, int $ttl=2589000) {
		try {
			$this->curl(
				"{$this->endpoint_cdn}/{$container_name}",
				"PUT",
				[
					CURLOPT_HTTPHEADER => [
						"X-Auth-Token: {$this->token}",
						"X-CDN-Enabled: True",
						"X-TTL: {$ttl}",
					],
				]
			);
		} catch (Exception $e) {
			throw new Exception("Unable to enable CDN in container '$container_name' -- {$e->getMessage()}");
		}
	}
	/**
	 * @return void
	 * @throws Exception
	 */
	function container_cdn_disable($container_name) {
		try {
			$this->curl(
				"{$this->endpoint_cdn}/{$container_name}",
				"PUT",
				[
					CURLOPT_HTTPHEADER => [
						"X-Auth-Token: {$this->token}",
						"X-CDN-Enabled: False",
					],
				]
			);
		} catch (Exception $e) {
			throw new Exception("Unable to disable CDN for container '$container_name' -- {$e->getMessage()}");
		}
	}
	/**
	 * @param $container_name
	 * @return string
	 * @throws Exception
	 */
	function container_get_cdn_ssl_uri($container_name): string{
		try {
			$this->curl(
				"{$this->endpoint_cdn}/{$container_name}",
				"HEAD",
				[
					CURLOPT_HTTPHEADER => [
						"X-Auth-Token: {$this->token}",
					],
				]
			);
			if ( ($this->curl_response_headers["X-Cdn-Enabled"] ?? "False") != "True") {
				throw new Exception("X-Cdn-Enabled header is not 'True'");
			}
			$ssl_uri=$this->curl_response_headers["X-Cdn-Ssl-Uri"] ?? "";
			if ($ssl_uri=="") {
				throw new Exception("there is no X-Cdn-Ssl-Uri header in response");
			}
			return $ssl_uri;
		} catch (Exception $e) {
			throw new Exception("Unable to get CDN URI for container '$container_name' -- {$e->getMessage()}");
		}
	}
	/**
	 * @param string $container_name
	 * @param string $file_name
	 * @param string $path
	 * @return void
	 * @throws Exception
	 */
	function file_upload(string $container_name, string $file_name, string $path){
		try {
			if (!is_file($path)) {
				throw new Exception("'$path' is not a file");
			}
			$file_size=filesize($path);
			if (!is_int($file_size)) {
				throw new Exception("failed to get size of '$path'");
			}
			$this->curl(
				"{$this->endpoint}/{$container_name}/{$file_name}",
				"PUT",
				[
					CURLOPT_PUT => 1,
					CURLOPT_HTTPHEADER => [
						"X-Auth-Token: {$this->token}",
						"Content-length: {$file_size}",
					],
					CURLOPT_INFILE => fopen($path, "r"),
					CURLOPT_INFILESIZE => $file_size,
				]
			);
		} catch (Exception $e) {
			throw new Exception("Unable to upload file '$file_name' to container '$container_name' -- {$e->getMessage()}");
		}
	}
	/**
	 * @param string $container_name
	 * @param string $file_name
	 * @param string $path
	 * @return void
	 * @throws Exception
	 */
	function file_download(string $container_name, string $file_name, string $path) {
		try {
			$this->curl(
				"{$this->endpoint}/{$container_name}/{$file_name}",
				"GET",
				[
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_HTTPHEADER => [
						"X-Auth-Token: {$this->token}",
					],
				]
			);
			if (false===file_put_contents($path, $this->curl_response_body, LOCK_EX)) {
				throw new Exception("IO error during writing to '$path'");
			}
		} catch (Exception $e) {
			throw new Exception("Unable to download file '$file_name' from container '$container_name' -- {$e->getMessage()}");
		}
	}
	/**
	 * @param string $container_name
	 * @param string $file_name
	 * @return void
	 * @throws Exception
	 */
	function file_delete(string $container_name, string $file_name) {
		try {
			$this->curl(
				"{$this->endpoint}/{$container_name}/{$file_name}",
				"DELETE",
				[
					CURLOPT_HTTPHEADER => [
						"X-Auth-Token: {$this->token}",
					],
				]
			);
		} catch (Exception $e) {
			throw new Exception("Unable to delete file '$file_name' from container '$container_name' -- {$e->getMessage()}");
		}
	}
	// -------------------------------------------------------------------------------------------
	// -------------------------------------------------------------------------------------------
	// -------------------------------------------------------------------------------------------
	/**
	 * @param $foo
	 * @param $header
	 * @return int
	 */
	private function curl_header_callback($foo, $header){
		$len = strlen($header);
		$header = explode(':', $header, 2);
		if (count($header)==2) {
			$this->curl_response_headers[trim($header[0])] = trim($header[1]);
		}
		return $len;
	}
	/**
	 * @param string $url
	 * @param string $request_type
	 * @param array $curl_opts
	 * @throws Exception
	 */
	private function curl(string $url, string $request_type="GET", array $curl_opts=[]) {
		$this->curl_response_headers=[];
		$this->curl_response_body="";
		try {
			$ch = curl_init($url);
			if ($request_type!="GET") curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request_type);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HEADERFUNCTION, [$this, "curl_header_callback"]);
			foreach ($curl_opts as $option => $value) {
				curl_setopt($ch, $option, $value);
			}
			$raw_response = curl_exec($ch);
			if ($raw_response===false) {
				throw new Exception("connection error: (".curl_errno($ch).") ".curl_error($ch));
			}
			$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			if (!in_array($http_code, [200, 201, 202, 203, 204])) {
				throw new Exception("unexpected HTTP code: ($http_code) $raw_response");
			}
			if (!isset($this->curl_response_headers["X-Trans-Id"])) {
				throw new Exception("there is no X-Trans-Id header in response");
			}
			$this->curl_response_body=$raw_response;
		} finally {
			curl_close($ch);
		}
	}
	/**
	 * @return string[]
	 */
	function __sleep(){
		return [
			"endpoint",
			"endpoint_cdn",
			"token",
		];
	}
}
