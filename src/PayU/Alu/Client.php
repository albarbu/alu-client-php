<?php

namespace PayU\Alu;
use PayU\Alu\Exceptions\ClientException;
use PayU\Alu\Exceptions\ConnectionException;

/**
 * Class Client
 * @package PayU\Alu
 */
class Client
{
    /**
     * @var MerchantConfig
     */
    private $merchantConfig;

    /**
     * @var array
     */
    private $aluUrlHostname = array(
        'ro' => 'https://secure.payu.ro',
        'ru' => 'https://secure.payu.ru',
        'ua' => 'https://secure.payu.ua',
        'hu' => 'https://secure.payu.hu',
        'tr' => 'https://secure.payu.com.tr',
    );

    /**
     * @var string
     */
    private $aluUrlPath = '/order/alu/v3';

    /**
     * @var string
     */
    private $customUrl = null;

    /**
     * @param MerchantConfig $merchantConfig
     */
    public function __construct(MerchantConfig $merchantConfig)
    {
        $this->merchantConfig = $merchantConfig;
    }

    /**
     * @return string
     * @throws ClientException
     */
    private function getAluUrl()
    {
        if (!empty($this->customUrl)) {
            return $this->customUrl;
        }

        if (!isset($this->aluUrlHostname[$this->merchantConfig->getPlatform()])) {
            throw new ClientException('Invalid platform');
        }
        return $this->aluUrlHostname[$this->merchantConfig->getPlatform()] . $this->aluUrlPath;
    }

    /**
     * @param $fullUrl
     * @codeCoverageIgnore
     */
    public function setCustomUrl($fullUrl)
    {
        $this->customUrl = $fullUrl;
    }

    /**
     * @param \SimpleXMLElement $xmlObject
     * @return Response
     */
    private function getResponse($xmlObject)
    {
        $response = new Response();
        $response->setRefno((string) $xmlObject->REFNO);
        $response->setAlias((string) $xmlObject->ALIAS);
        $response->setStatus((string) $xmlObject->STATUS);
        $response->setReturnCode((string) $xmlObject->RETURN_CODE);
        $response->setReturnMessage((string) $xmlObject->RETURN_MESSAGE);
        $response->setDate((string) $xmlObject->DATE);

        if (property_exists($xmlObject, 'ORDER_REF')) {
            $response->setOrderRef((string) $xmlObject->ORDER_REF);
        }

        if (property_exists($xmlObject, 'URL_3DS')) {
            $response->setThreeDsUrl((string)$xmlObject->URL_3DS);
        }

        if (property_exists($xmlObject, 'AUTH_CODE')) {
            $response->setAuthCode((string)$xmlObject->AUTH_CODE);
        }

        if (property_exists($xmlObject, 'RRN')) {
            $response->setRrn((string)$xmlObject->RRN);
        }

        if (property_exists($xmlObject, 'HASH')) {
            $response->setHash((string)$xmlObject->HASH);
        }

        return $response;
    }

    /**
     * @param Request $request
     * @param HTTPClient $httpClient
     * @param HashService $hashService
     * @throws ClientException
     * @throws ConnectionException
     * @return Response
     */
    public function pay(Request $request, HTTPClient $httpClient = null, HashService $hashService = null)
    {
        if (null === $hashService) {
            $hashService = new HashService($this->merchantConfig->getSecretKey());
        }

        if (null === $httpClient) {
            $httpClient = new HTTPClient();
        }

        $requestHash = $hashService->makeRequestHash($request);
        $request->setOrderHash($requestHash);

        $requestParams = $request->getRequestParams();
        $responseXML = $httpClient->post($this->getAluUrl(), $requestParams);
        try {
            $xmlObject = new \SimpleXMLElement($responseXML);
        } catch (\Exception $e) {
            throw new ClientException($e->getMessage(), $e->getCode());
        }
        $response = $this->getResponse($xmlObject);
        if ('' != $response->getHash()) {
            $hashService->validateResponseHash($response);
        }
        return $response;
    }

    /**
     * @param array $returnData
     * @return Response
     * @throws ClientException
     */
    public function handleThreeDSReturnResponse(array $returnData = array())
    {
        if (!empty($returnData['HASH'])) {
            $hashService = new HashService($this->merchantConfig->getSecretKey());
            $threeDSReturnResponse = $this->getThreeDSReturnResponse($returnData);
            $hashService->validateResponseHash($threeDSReturnResponse);
        } else {
            throw new ClientException('Missing HASH');
        }
        return $threeDSReturnResponse;
    }

    /**
     * @param array $returnData
     * @return Response
     */
    private function getThreeDSReturnResponse(array $returnData = array())
    {
        $response = new Response();
        $response->setRefno($returnData['REFNO']);
        $response->setAlias($returnData['ALIAS']);
        $response->setStatus($returnData['STATUS']);
        $response->setReturnCode($returnData['RETURN_CODE']);
        $response->setReturnMessage($returnData['RETURN_MESSAGE']);
        $response->setDate($returnData['DATE']);
        $response->setOrderRef($returnData['ORDER_REF']);
        $response->setAuthCode($returnData['AUTH_CODE']);
        $response->setRrn($returnData['RRN']);
        $response->setHash($returnData['HASH']);

        return $response;
    }
}
