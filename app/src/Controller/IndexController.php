<?php

namespace App\Controller;

use Payone\Sdk\Api\Client\ClientExceptionInterface;
use Payone\Sdk\Api\Client\ErrorResponseExceptionInterface;
use Payone\Sdk\Api\Message\Payment\AuthorizationRequest as PayoneAuthorizationRequest;
use Payone\Sdk\Api\Message\Payment\G;
use Payone\Sdk\Api\Message\Response as PayoneResponse;
use Payone\Sdk\Sdk;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;


class IndexController extends AbstractController
{
    private CurlHttpClient $httpClient;

    private Logger $logger;

    private string $massMarkerFile = '/app/public/.well-known/mass.txt';

    public function __construct(
        private Sdk $payoneSdk,
    ) {
        $this->httpClient = new CurlHttpClient();
        $this->logger = new Logger('apl');
    }

    public function getContainer()
    {
        return $this->container;
    }

    #[Route('/index', name: 'index')]
    public function index(): Response
    {
        return $this->render('index/index.html.twig', [
            'controller_name' => 'IndexController',
            'merchant_id' => $this->getParameter('payone.api.merchant_id'),
            'api_endpoint' => $this->getParameter('payone.api.endpoint'),
            'mode' => $this->getParameter('payone.api.mode'),
            'merchant_identifier' => $this->getParameter('payone.apple.merchant_identifier'),
            'initiative_context' => $this->getParameter('payone.apple.initiative_context'),
        ]);
    }

    #[Route('/api/apple-pay/session', name: 'create_apple_pay_session', methods: ['POST'])]
    public function createApplePaySession(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $debugMessage = ['message' => 'Incoming data for creating apple pay session', 'data' => $data];
        $this->logger->debug(print_r($debugMessage, true));
        $this->rawLogging(print_r($debugMessage, true));

        $checkoutVariant =
            (
                isset($data['checkout_variant']) &&
                in_array($data['checkout_variant'],['legacy', 'mass'])
            ) ? $data['checkout_variant'] : 'legacy';

        if ($checkoutVariant === 'legacy') {
            return $this->createLegacyAppleSession($request);
        } else if ($checkoutVariant === 'mass') {
            return $this->createMassAppleSession($request);
        }

        return $this->json("Error: No checkout variant selected", 500);
    }

    #[Route('/api/apple-pay/payment', name: 'create_apple_pay_payment', methods: ['POST'])]
    public function createApplePayPayment(Request $request): JsonResponse
    {
        $data = $request->toArray();

        $authorizationMethod = $data['authorization_method'] ?? 'preauthorization';
        $amount = (int) $data['amount'] ?? 100;
        $currency = 'EUR';
        $tokenData = $data['token'] ?? [];

        $cardTypeMappings = [
            'visa'       => 'V',
            'mastercard' => 'M',
            'girocard'   => 'G',
            'amex'       => 'A',
            'default'    => '?',
        ];

        $payoneRequest = new PayoneAuthorizationRequest([
            // https://docs.payone.com/pages/viewpage.action?spaceKey=PLATFORM&title=Special+Remarks+-+Apple+Pay
            'request'      => $authorizationMethod,
            'clearingtype' => 'wlt',
            'wallettype'   => 'APL',
            'cardtype'     => $cardTypeMappings[strtolower($tokenData['paymentMethod']['network'] ?? 'default')],

            // Other mandatory parameters
            'lastname'     => 'Demo',
            'firstname'    => 'Dieter',
            'country'      => 'DE',

            // Special Apple Pay platform parameters
            'add_paydata[paymentdata_token_version]'             => $tokenData['paymentData']['version'] ?? 'EC_v1',
            'add_paydata[paymentdata_token_data]'                => $tokenData['paymentData']['data'] ?? '',
            'add_paydata[paymentdata_token_signature]'           => $tokenData['paymentData']['signature'] ?? '',
            'add_paydata[paymentdata_token_ephemeral_publickey]' => $tokenData['paymentData']['header']['ephemeralPublicKey'] ?? '',
            'add_paydata[paymentdata_token_publickey_hash]'      => $tokenData['paymentData']['header']['publicKeyHash'] ?? '',
            'add_paydata[paymentdata_token_transaction_id]'      => $tokenData['paymentData']['header']['transactionId'] ?? '',
        ]);

        $reference = bin2hex(random_bytes(8));

        $payoneRequest
            ->setCurrency($currency)
            ->setAmount($amount)
            ->setReference($reference);

        $payoneResponse = new PayoneResponse();

        try {
            $this->payoneSdk->getApiService()->sendRequest($payoneRequest, $payoneResponse);
        } catch (ClientExceptionInterface $e) {
            return $this->json([
                'success' => false,
                'error' => [
                    'type'    => 'sdk_client_error',
                    'message' => $e->getMessage(),
                ],
            ], 502);
        } catch (ErrorResponseExceptionInterface $e) {
            return $this->json([
                'success' => false,
                'error' => [
                    'type'             => 'payone_api_error',
                    'message'          => $e->getMessage(),
                    'customer_message' => $e->getCustomerMessage(),
                    'code'             => $e->getCode(),
                ],
            ], 400);
        }

        return $this->json([
            'success' => true,
            'payone_response' => unserialize($payoneResponse->serialize()),
        ], 201);
    }

    protected function rawLogging($message) {
        $file = fopen('/app/var/log/apple.log', 'a');
        $prefix = "[".date('Ymd-His')."] ";
        fwrite($file, $prefix.$message."\n");
        fclose($file);
    }

    protected function createLegacyAppleSession(Request $request) {
        $this->markSessionTypeAsMassEnablement(false);
        $data = json_decode($request->getContent(), true);

        if (!isset($data['validation_url'])) {
            $debugMessage = ['error' => 'Missing parameter gateway_url in request data.'];
            $this->logger->debug(print_r($debugMessage, true));
            $this->rawLogging(print_r($debugMessage, true));
            return $this->json($debugMessage, 400);
        }

        $paymentSessionEndpoint = "{$data['validation_url']}/paymentservices/paymentSession";
        $debugMessage = ['message' => 'Create payment session endpoint', 'data' => $paymentSessionEndpoint];
        $this->rawLogging(print_r($debugMessage, true));
        $this->logger->debug(print_r($debugMessage, true));

        try {
            $paymentRequestContent = [
                'json' => [
                    'merchantIdentifier' => $this->getParameter('payone.apple.merchant_identifier'),
                    'displayName' => 'PAYONE Apple Pay Prototyp',
                    'initiative' => 'web',
                    'initiativeContext' => $this->getParameter('payone.apple.initiative_context'),
                ],
                'verify_peer' => false,
                'local_cert'  => __DIR__ . '/../../cert/merchant_id.pem',
                'local_pk'    => __DIR__ . '/../../cert/merchant_id.key',
                'passphrase'  => $this->getParameter('payone.apple.passphrase'),
            ];
            $debugMessage = ['message' => 'Requesting endpoint with data', 'data' => $paymentRequestContent];
            $this->logger->debug(print_r($debugMessage, true));
            $this->rawLogging(print_r($debugMessage, true));

            $response = $this->httpClient->request('POST', $paymentSessionEndpoint, $paymentRequestContent);
            $this->rawLogging(print_r($response, true));
        } catch (\Exception $e) {
            $debugMessage = [
                'error' => "Error while establishing connection to Apple Pay service: {$e->getMessage()}",
            ];
            $this->logger->debug(print_r($debugMessage, true));
            $this->rawLogging(print_r($debugMessage, true));
            return $this->json($debugMessage, 500);
        }

        if ($response->getStatusCode() !== 200) {
            $debugMessage = [
                'error' => sprintf(
                    'Apple Pay merchant validation / session creation failed, received status %d from Apple Pay service.',
                    $response->getStatusCode(),
                ),
                'apple_service_response' => json_decode($response->getContent(), true),
            ];

            $this->logger->debug(print_r($debugMessage, true));
            $this->rawLogging(print_r($debugMessage, true));
            return $this->json($debugMessage, 500);
        }

        $merchantSession = json_decode($response->getContent(), true);
        $debugMessage = ['message' => 'Merchant session created', 'data' => $merchantSession];
        $this->logger->debug(print_r($debugMessage, true));
        $this->rawLogging(print_r($debugMessage, true));

        return $this->json($merchantSession, 201);

    }

    protected function createMassAppleSession(Request $request)
    {
        $this->markSessionTypeAsMassEnablement(true);
        $apiKey = md5($this->getParameter('payone.api.key'));

        $genericRequestData = [
            'request'                   => 'genericpayment',
            'mid'                       => $this->getParameter('payone.api.merchant_id'),
            'portalid'                  => $this->getParameter('payone.api.portal_id'),
            'key'                       => $apiKey,
            'aid'                       => $this->getParameter('payone.api.sub_account_id'),
            'mode'                      => $this->getParameter('payone.api.mode'),
            'currency'                  => 'EUR',
            'clearingtype'              => 'wlt',
            'reference'                 => bin2hex(random_bytes(8)),
            'lastname'                  => 'Apologist',
            'country'                   => 'de',
            'wallettype'                => 'APL',
            'add_paydata[action]'       => 'init_applepay_session',
            'add_paydata[display_name]' => 'Payone Applepay Prototyp',
            'add_paydata[domain_name]'  => $this->getParameter('payone.apple.initiative_context'),
        ];

        $this->rawLogging("Generic request data: ".print_r($genericRequestData, true));
        $response = $this->getCurlPhpResponse($genericRequestData);
        $this->rawLogging("Answer: ".print_r($response, true));
        if (is_array($response)) {
            $this->rawLogging("Calling createMerchantSession");
            $merchantSession = $this->createMerchantSession($response);
            $debugMessage = ['message' => 'Mass-Enablement merchant session created', 'data' => $merchantSession];
            $this->logger->debug(print_r($debugMessage, true));
            $this->rawLogging(print_r($debugMessage, true));

            return $this->json($merchantSession, 201);
        }
        $this->logger->debug($response);
        $this->rawLogging($response);

        return $this->json("Error building merchant session from init_applepay_session.", 500);
    }

    /**
     * Using native php curl to perform request
     *
     * @param array $requestParameters
     * @return array|string $response
     */
    protected function getCurlPhpResponse(array $requestParameters): array|string
    {
        $url = $this->getParameter('payone.api.endpoint');

        $requestUrl = '';
        foreach ($requestParameters as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $i => $val1) {
                    $requestUrl .= "&" . $key . "[" . $i . "]=" . urlencode($val1);
                }
            } else {
                $requestUrl .= "&" . $key . "=" . urlencode($value);
            }
        }
        $requestUrl = $url . "?" . substr($requestUrl, 1);
        $parsedUrl = parse_url($requestUrl);
        $curlUrl = $parsedUrl['scheme'] . "://" . $parsedUrl['host'] . $parsedUrl['path'];
        $this->rawLogging('Send request to '.$curlUrl." with parameters:\n".$parsedUrl['query']);

        $curl = curl_init($curlUrl);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $parsedUrl['query']);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);  // no ssl check => demo
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);  // no hostname check => demo
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 45);

        $result = curl_exec($curl);
        $this->rawLogging("Executed curl with result:\n ".$result);
        if (curl_error($curl)) {
            $this->rawLogging("Stopped curl with error:\n ".curl_error($curl));
            $response = "connection-type: 1 - errormessage=" . curl_errno($curl) . ": " . curl_error($curl);
        } else {
            $response = explode("\n", $result);
        }

        curl_close($curl);
        $this->rawLogging("Unformatted response:\n ".print_r($response, true));
        $formattedResponse = [];
        foreach ($response as $responseLine) {
            $responseParts = explode("=", $responseLine);
            if (!isset($responseParts[1])) continue;
            $formattedResponse[$responseParts[0]] = $responseParts[1];
        }

        $this->rawLogging("Returning response:\n ".print_r($formattedResponse, true));
        return $formattedResponse;
    }

    /**
     * Creates merchant session array from payone response
     *
     * @param array $response
     * @return array
     */
    private function createMerchantSession(array $response): array
    {
        $debugMessage = ['message' => 'Payone response for mass enablement call to payone.', 'data' => $response];
        $this->logger->debug(print_r($debugMessage, true));
        $this->rawLogging(print_r($debugMessage, true));

        // fetch session from response and return it
        $merchantSession = base64_decode($response['add_paydata[applepay_payment_session]']);
        return json_decode($merchantSession, true);
    }

    /**
     * Mark type via file existence for later rewriting
     *
     * @param bool $marked
     * @return void
     */
    private function markSessionTypeAsMassEnablement(bool $marked): void
    {
        if ($marked) {
            $file = fopen($this->massMarkerFile, 'w');
            fclose($file);
        } else if (file_exists($this->massMarkerFile)) {
            unlink($this->massMarkerFile);
        }
    }

}
