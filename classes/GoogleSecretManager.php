<?php
namespace Stanford\DoceboIntegration;

use Google\ApiCore\ApiException;
use Google\Cloud\SecretManager\V1\Client\SecretManagerServiceClient;
use Google\Cloud\SecretManager\V1\AccessSecretVersionRequest;
use Google\Protobuf\Internal\GPBDecodeException;
use Google\Cloud\SecretManager\V1\AccessSecretVersionResponse;

class GoogleSecretManager {
    private $grpcClient;
    private $restClient;
    private $projectId;
    private $keyJson;
    private $module;

    // ---- Pure HTTP fallback (avoids protobuf parsing issues entirely) ----
    private const METADATA_TOKEN_URL = 'http://169.254.169.254/computeMetadata/v1/instance/service-accounts/default/token';
    private const SECRET_MANAGER_ACCESS_URL = 'https://secretmanager.googleapis.com/v1/projects/%s/secrets/%s/versions/%s:access';

    private ?string $cachedHttpAccessToken = null;
    private int $cachedHttpAccessTokenExpiresAt = 0;

    public function __construct(string $projectId, ?string $keyJson = null, $module = null) {
        $this->projectId = $projectId;
        $this->keyJson = $keyJson;
        $this->module = $module;
        $this->module->emDebug('[GoogleSecretManager] Initialized with project: ' . $projectId . ', using ' . ($keyJson ? 'provided credentials' : 'default credentials'));
    }


    private function getClient(?string $transport = null): SecretManagerServiceClient {
        // We keep two clients so we can fail over from gRPC -> REST.
        // Some environments/proxies can corrupt gRPC responses and trigger:
        //   Google\Protobuf\Internal\GPBDecodeException: Fail to push limit.
        // REST transport avoids gRPC framing issues.

        $transport = $transport ? strtolower($transport) : null;
        if ($transport !== null && $transport !== 'grpc' && $transport !== 'rest') {
            $transport = null;
        }

        // Default transport: allow env override, otherwise prefer grpc.
        if ($transport === null) {
            $env = getenv('GSM_TRANSPORT');
            // Default to REST so this works in environments without the PHP gRPC extension.
            // You can still force gRPC by setting GSM_TRANSPORT=grpc.
            $transport = $env ? strtolower($env) : 'rest';
            if ($transport !== 'grpc' && $transport !== 'rest') {
                $transport = 'rest';
            }
        }

        $prop = ($transport === 'rest') ? 'restClient' : 'grpcClient';
        if ($this->{$prop}) {
            return $this->{$prop};
        }

        try {
            $this->module->emDebug('[GoogleSecretManager] Initializing Secret Manager client (transport=' . $transport . ')...');

            $opts = [
                // IMPORTANT: forcing REST can avoid GPBDecodeException in some environments.
                'transport' => $transport,
            ];

            if ($this->keyJson) {
                $this->module->emDebug('[GoogleSecretManager] Using provided JSON credentials');
                $credentialsArray = json_decode($this->keyJson, true);
                if (!is_array($credentialsArray)) {
                    $this->module->emDebug('[GoogleSecretManager] ERROR: Failed to decode credentials JSON');
                    throw new \Exception('Invalid credentials JSON format');
                }
                $opts['credentialsConfig'] = ['keyFile' => $credentialsArray];
            } else {
                $this->module->emDebug('[GoogleSecretManager] Using default/environment credentials (Application Default Credentials)');
            }

            $this->{$prop} = new SecretManagerServiceClient($opts);
            $this->module->emDebug('[GoogleSecretManager] Secret Manager client initialized successfully (transport=' . $transport . ')');
            return $this->{$prop};
        } catch (ApiException $e) {
            $this->module->emDebug('[GoogleSecretManager] ApiException during client initialization: ' . $e->getMessage());
            $this->module->emDebug('[GoogleSecretManager] Status code: ' . $e->getCode());
            throw $e;
        } catch (\Exception $e) {
            $this->module->emDebug('[GoogleSecretManager] Exception during client initialization: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Fetch a secret by key name
     * @param string $key The secret key name (e.g., 'MS_GRAPH_CLIENT_ID')
     * @return string The secret value
     * @throws ApiException
     * @throws \Exception
     */
    public function getSecret(string $key): string {
        try {
            $this->module->emDebug('[GoogleSecretManager] Fetching secret: ' . $key);

            // Build the full secret resource name
            $name = $this->getClient()->secretVersionName($this->projectId, $key, 'latest');
            $this->module->emDebug('[GoogleSecretManager] Built secret resource name for key: ' . $key);

            // Build and send the access request
            $request = AccessSecretVersionRequest::build($name);
            $this->module->emDebug('[GoogleSecretManager] Sending access request for secret: ' . $key);

            $response = $this->getClient()->accessSecretVersion($request);
            $this->module->emDebug('[GoogleSecretManager] Successfully retrieved secret: ' . $key);

            $secretValue = $response->getPayload()->getData();
            if (empty($secretValue)) {
                $this->module->emDebug('[GoogleSecretManager] WARNING: Retrieved empty value for secret: ' . $key);
            } else {
                $this->module->emDebug('[GoogleSecretManager] Secret retrieved successfully, value length: ' . strlen($secretValue) . ' bytes');
            }

            return $secretValue;
        } catch (GPBDecodeException $e) {
            // We can hit protobuf decoding errors even when using the library's REST transport,
            // because the client still converts JSON -> protobuf internally.
            // In some prod networks/proxies, the response can be truncated or altered.
            // The most reliable fix is to bypass the library and call the REST API directly.
            $this->module->emDebug('[GoogleSecretManager] GPBDecodeException for secret ' . $key . ': ' . $e->getMessage());
            $this->module->emDebug('[GoogleSecretManager] Falling back to pure HTTP Secret Manager REST call...');

            $secretValue = $this->getSecretViaHttp($key, 'latest');
            $this->module->emDebug('[GoogleSecretManager] Successfully retrieved secret via pure HTTP: ' . $key);
            if (empty($secretValue)) {
                $this->module->emDebug('[GoogleSecretManager] WARNING: Retrieved empty value for secret (pure HTTP): ' . $key);
            } else {
                $this->module->emDebug('[GoogleSecretManager] Secret retrieved successfully via pure HTTP, value length: ' . strlen($secretValue) . ' bytes');
            }

            return $secretValue;
        } catch (\RuntimeException $e) {
            // The Google PHP client will throw this when transport=grpc is selected but the PHP gRPC
            // extension isn't installed:
            //   "gRPC support has been requested but required dependencies have not been found."
            // Retry using REST.
            $msg = $e->getMessage();
            if (stripos($msg, 'gRPC support has been requested') !== false) {
                $this->module->emDebug('[GoogleSecretManager] gRPC extension missing: ' . $msg);
                $this->module->emDebug('[GoogleSecretManager] Falling back to pure HTTP Secret Manager REST call...');

                $secretValue = $this->getSecretViaHttp($key, 'latest');
                $this->module->emDebug('[GoogleSecretManager] Successfully retrieved secret via pure HTTP: ' . $key);
                if (empty($secretValue)) {
                    $this->module->emDebug('[GoogleSecretManager] WARNING: Retrieved empty value for secret (pure HTTP): ' . $key);
                } else {
                    $this->module->emDebug('[GoogleSecretManager] Secret retrieved successfully via pure HTTP, value length: ' . strlen($secretValue) . ' bytes');
                }

                return $secretValue;
            }

            throw $e;
        } catch (ApiException $e) {
            $this->module->emDebug('[GoogleSecretManager] ApiException fetching secret ' . $key . ': ' . $e->getMessage());
            $this->module->emDebug('[GoogleSecretManager] Status code: ' . $e->getCode());
            $this->module->emDebug('[GoogleSecretManager] Details: ' . $e->getDetails());
            throw $e;
        } catch (\Exception $e) {
            $this->module->emDebug('[GoogleSecretManager] Exception fetching secret ' . $key . ': ' . $e->getMessage());
            $this->module->emDebug('[GoogleSecretManager] Exception class: ' . get_class($e));
            throw $e;
        }
    }
    /**
     * Pure HTTP: get access token from GKE/GCE metadata server.
     * This is the most reliable option inside GKE when gRPC/protobuf parsing is flaky.
     */
    private function getHttpAccessTokenFromMetadata(): string
    {
        $now = time();
        if ($this->cachedHttpAccessToken && ($this->cachedHttpAccessTokenExpiresAt - 60) > $now) {
            return $this->cachedHttpAccessToken;
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException('Failed to init cURL for metadata token');
        }

        curl_setopt($ch, CURLOPT_URL, self::METADATA_TOKEN_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Metadata-Flavor: Google',
            'Accept: application/json',
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Metadata token cURL error ' . $errno . ': ' . $err);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            throw new \RuntimeException('Metadata token HTTP ' . $status . ' body=' . substr((string)$body, 0, 300));
        }

        $data = json_decode((string)$body, true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException('Metadata token response missing access_token. body=' . substr((string)$body, 0, 300));
        }

        $token = (string)$data['access_token'];
        $expiresIn = (int)($data['expires_in'] ?? 3600);

        $this->cachedHttpAccessToken = $token;
        $this->cachedHttpAccessTokenExpiresAt = time() + max(60, $expiresIn);

        return $token;
    }

    /**
     * Pure HTTP: read secret via Secret Manager REST API and base64 decode the payload.
     * Returns the plaintext secret value.
     */
    private function getSecretViaHttp(string $key, string $version = 'latest'): string
    {
        // This fallback is intended for GKE/GCE where metadata server is available.
        $token = $this->getHttpAccessTokenFromMetadata();

        $url = sprintf(self::SECRET_MANAGER_ACCESS_URL, $this->projectId, $key, $version);

        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException('Failed to init cURL for secret access');
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Secret HTTP cURL error ' . $errno . ': ' . $err);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            throw new \RuntimeException('Secret Manager REST HTTP ' . $status . ' body=' . substr((string)$body, 0, 500));
        }

        $data = json_decode((string)$body, true);
        if (!is_array($data) || empty($data['payload']['data'])) {
            throw new \RuntimeException('Secret Manager REST response missing payload.data. body=' . substr((string)$body, 0, 500));
        }

        $decoded = base64_decode((string)$data['payload']['data'], true);
        if ($decoded === false) {
            throw new \RuntimeException('Failed to base64 decode secret payload for ' . $key);
        }

        return $decoded;
    }
}
