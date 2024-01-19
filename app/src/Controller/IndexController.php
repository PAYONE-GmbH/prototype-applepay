<?php

namespace App\Controller;

use Payone\Sdk\Api\Client\ClientExceptionInterface;
use Payone\Sdk\Api\Client\ErrorResponseExceptionInterface;
use Payone\Sdk\Api\Message\Payment\AuthorizationRequest as PayoneAuthorizationRequest;
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
        ]);
    }

    #[Route('/api/apple-pay/session', name: 'create_apple_pay_session', methods: ['POST'])]
    public function createApplePaySession(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $debugMessage = ['message' => 'Incoming data for creating apple pay session', 'data' => $data];
        $this->logger->debug(print_r($debugMessage, true));
        $this->rawLogging(print_r($debugMessage, true));
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
                    'merchantIdentifier' => 'merchant.Payone.ApplePayontheweb',
                    'displayName' => 'PAYONE Apple Pay Prototyp',
                    'initiative' => 'web',
                    'initiativeContext' => 'apldemo-mass.patsy.dev',
                ],
                'verify_peer' => false,
                'local_cert'  => __DIR__ . '/../../cert/merchant_id.pem',
                'local_pk'    => __DIR__ . '/../../cert/merchant_id.key',
                'passphrase'  => 'Payone1!',
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
}
