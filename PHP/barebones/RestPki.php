<?php

/*
 * REST PKI client lib for PHP
 *
 * This file contains classes that encapsulate the calls to the REST PKI API.
 *
 * This file depends on the GuzzleHttp package, which in turn requires PHP 5.5 or greater.
 */

namespace Lacuna;

require_once 'vendor/autoload.php';
use GuzzleHttp\Client;

class RestPkiClient {

	private $endpointUrl;
	private $accessToken;

	public function __construct($endpointUrl, $accessToken) {
		$this->endpointUrl = $endpointUrl;
		$this->accessToken = $accessToken;
	}

	public function getRestClient() {
		$client = new Client([
			'base_uri' => $this->endpointUrl,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->accessToken,
				'Accept' => 'application/json'
			]
		]);
		return $client;
	}

	public function getAuthentication() {
		return new Authentication($this);
	}
}

class Authentication {

	/** @var RestPkiClient */
	private $restPkiClient;

	private $certificate;
	private $done;

	public function __construct($restPkiClient) {
		$this->restPkiClient = $restPkiClient;
		$this->done = false;
	}

	public function startWithWebPki($securityContextId) {
		$client = $this->restPkiClient->getRestClient();
		$httpResponse = $client->post('Api/Authentications', [
			'json' => [
				'securityContextId' => $securityContextId
			]
		]);
		$response = json_decode($httpResponse->getBody());
		return $response->token;
	}

	public function completeWithWebPki($token) {
		$client = $this->restPkiClient->getRestClient();
		$httpResponse = $client->post("Api/Authentications/$token/Finalize");
		$response = json_decode($httpResponse->getBody());
		$this->certificate = $response->certificate;
		$this->done = true;
		return new ValidationResults($response->validationResults);
	}

	public function getCertificate() {
		if (!$this->done) {
			throw new \Exception('The method getCertificate() can only be called after calling the completeWithWebPki method');
		}
		return $this->certificate;
	}

}

class PadesSignatureStarter {

	/** @var RestPkiClient */
	private $restPkiClient;
	private $pdfContent;
	private $securityContextId;
	private $signaturePolicyId;
	private $visualRepresentation;

	public function __construct($restPkiClient) {
		$this->restPkiClient = $restPkiClient;
	}

	public function setPdfToSignPath($pdfPath) {
		$this->pdfContent = file_get_contents($pdfPath);
	}

	public function setPdfToSignContent($content) {
		$this->pdfContent = $content;
	}

	public function setSecurityContext($securityContextId) {
		$this->securityContextId = $securityContextId;
	}

	public function setSignaturePolicy($signaturePolicyId) {
		$this->signaturePolicyId = $signaturePolicyId;
	}

	public function setVisualRepresentation($visualRepresentation) {
		$this->visualRepresentation = $visualRepresentation;
	}

	public function startWithWebPki() {

		if (empty($this->pdfContent)) {
			throw new \Exception("The PDF to sign was not set");
		}
		if (empty($this->signaturePolicyId)) {
			throw new \Exception("The signature policy was not set");
		}

		$client = $this->restPkiClient->getRestClient();
		$httpResponse = $client->post('Api/PadesSignatures', [
			'json' => [
				'pdfToSign' => base64_encode($this->pdfContent),
				'signaturePolicyId' => $this->signaturePolicyId,
				'securityContextId' => $this->securityContextId,
				'visualRepresentation' => $this->visualRepresentation
			]
		]);
		$response = json_decode($httpResponse->getBody());
		return $response->token;
	}

}

class PadesSignatureFinisher {

	/** @var RestPkiClient */
	private $restPkiClient;
	private $token;

	private $done;
	private $signedPdf;
	private $certificate;

	public function __construct($restPkiClient) {
		$this->restPkiClient = $restPkiClient;
	}

	public function setToken($token) {
		$this->token = $token;
	}

	public function finish() {

		if (empty($this->token)) {
			throw new \Exception("The token was not set");
		}

		$client = $this->restPkiClient->getRestClient();
		$httpResponse = $client->post("Api/PadesSignatures/{$this->token}/Finalize");
		$response = json_decode($httpResponse->getBody());

		$this->signedPdf = base64_decode($response->signedPdf);
		$this->certificate = $response->certificate;
		$this->done = true;

		return $this->signedPdf;
	}

	public function getCertificate() {
		if (!$this->done) {
			throw new \Exception('The method getCertificate() can only be called after calling the finish() method');
		}
		return $this->certificate;
	}

	public function writeSignedPdfToPath($pdfPath) {
		if (!$this->done) {
			throw new \Exception('The method getCertificate() can only be called after calling the finish() method');
		}
		file_put_contents($pdfPath, $this->signedPdf);
	}
}

class StandardSecurityContexts {
	const PKI_BRAZIL = '201856ce-273c-4058-a872-8937bd547d36';
	const PKI_ITALY = 'c438b17e-4862-446b-86ad-6f85734f0bfe';
	const WINDOWS_SERVER = '3881384c-a54d-45c5-bbe9-976b674f5ec7';
}

class StandardSignaturePolicies {
	const PADES_BASIC = '78d20b33-014d-440e-ad07-929f05d00cdf';
}

class PadesVisualPositioningPresets {

	private static $cachedPresets = [];

	public static function getFootnote(RestPkiClient $restPkiClient, $pageNumber = null, $rows = null) {
		$urlSegment = 'Footnote';
		if (!empty($pageNumber)) {
			$urlSegment .= "?pageNumber=" . $pageNumber;
		}
		if (!empty($rows)) {
			$urlSegment .= "?rows=" . $rows;
		}
		return self::getPreset($restPkiClient, $urlSegment);
	}

	public static function getNewPage(RestPkiClient $restPkiClient) {
		return self::getPreset($restPkiClient, 'NewPage');
	}

	private static function getPreset(RestPkiClient $restPkiClient, $urlSegment) {
		if (array_key_exists($urlSegment, self::$cachedPresets)) {
			return self::$cachedPresets[$urlSegment];
		}
		$httpResponse = $restPkiClient->getRestClient()->get("Api/PadesVisualPositioningPresets/$urlSegment");
		$preset = json_decode($httpResponse->getBody());
		self::$cachedPresets[$urlSegment] = $preset;
		return $preset;
	}
}

class ValidationResults {

	private $errors;
	private $warnings;
	private $passedChecks;

	public function __construct($model) {
		$this->errors = self::convertItems($model->errors);
		$this->warnings = self::convertItems($model->warnings);
		$this->passedChecks = self::convertItems($model->passedChecks);
	}

	public function isValid() {
		return empty($this->errors);
	}

	public function getChecksPerformed() {
		return count($this->errors) + count($this->warnings) + count($this->passedChecks);
	}

	public function hasErrors() {
		return !empty($this->errors);
	}

	public function hasWarnings() {
		return !empty($this->warnings);
	}

	public function __toString() {
		return $this->toString(0);
	}

	public function toString($indentationLevel) {
		$tab = str_repeat("\t", $indentationLevel);
		$text = '';
		$text .= $this->getSummary($indentationLevel);
		if ($this->hasErrors()) {
			$text .= "\n{$tab}Errors:\n";
			$text .= self::joinItems($this->errors, $indentationLevel);
		}
		if ($this->hasWarnings()) {
			$text .= "\n{$tab}Warnings:\n";
			$text .= self::joinItems($this->warnings, $indentationLevel);
		}
		if (!empty($this->passedChecks)) {
			$text .= "\n{$tab}Passed checks:\n";
			$text .= self::joinItems($this->passedChecks, $indentationLevel);
		}
		return $text;
	}

	public function getSummary($indentationLevel = 0) {
		$tab = str_repeat("\t", $indentationLevel);
		$text = "{$tab}Validation results: ";
		if ($this->getChecksPerformed() === 0) {
			$text .= 'no checks performed';
		} else {
			$text .= "{$this->getChecksPerformed()} checks performed";
			if ($this->hasErrors()) {
				$text .= ', ' . count($this->errors) . ' errors';
			}
			if ($this->hasWarnings()) {
				$text .= ', ' . count($this->warnings) . ' warnings';
			}
			if (!empty($this->passedChecks)) {
				if (!$this->hasErrors() && !$this->hasWarnings()) {
					$text .= ", all passed";
				} else {
					$text .= ', ' . count($this->passedChecks) . ' passed';
				}
			}
		}
		return $text;
	}

	private static function convertItems($items) {
		$converted = [];
		foreach ($items as $item) {
			$converted[] = new ValidationItem($item);
		}
		return $converted;
	}

	private static function joinItems($items, $indentationLevel) {
		$text = '';
		$isFirst = true;
		$tab = str_repeat("\t", $indentationLevel);
		foreach ($items as $item) {
			/** @var ValidationItem $item */
			if ($isFirst) {
				$isFirst = false;
			} else {
				$text .= "\n";
			}
			$text .= "{$tab}- ";
			$text .= $item->toString($indentationLevel);
		}
		return $text;
	}

}

class ValidationItem {

	private $type;
	private $message;
	private $detail;
	/** @var ValidationResults */
	private $innerValidationResults;

	public function __construct($model) {
		$this->type = $model->type;
		$this->message = $model->message;
		$this->detail = $model->detail;
		if ($model->innerValidationResults !== null) {
			$this->innerValidationResults = new ValidationResults($model->innerValidationResults);
		}
	}

	public function getType() {
		return $this->type;
	}

	public function getMessage() {
		return $this->message;
	}

	public function getDetail() {
		return $this->detail;
	}

	public function __toString() {
		return $this->toString(0);
	}

	public function toString($indentationLevel) {
		$text = '';
		$text .= $this->message;
		if (!empty($this->detail)) {
			$text .= " ({$this->detail})";
		}
		if ($this->innerValidationResults !== null) {
			$text .= "\n";
			$text .= $this->innerValidationResults->toString($indentationLevel + 1);
		}
		return $text;
	}

}
