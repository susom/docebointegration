<?php
namespace Stanford\DoceboIntegration;

use Google\ApiCore\ApiException;
use Google\Cloud\SecretManager\V1\Client\SecretManagerServiceClient;
use Google\Cloud\SecretManager\V1\AccessSecretVersionRequest;
class GoogleSecretManager {
    private $client;
    private $projectId;
    private $keyJson;

    public function __construct(string $projectId, ?string $keyJson = null) {
        $this->projectId = $projectId;
        $this->keyJson = $keyJson;
    }

    private function getClient(): SecretManagerServiceClient {
        if (!$this->client) {
            $this->client = new SecretManagerServiceClient();
        }
        return $this->client;
    }

    /**
     * @throws ApiException
     */
    public function getSecret(string $key): string {

        $name = $this->getClient()->secretVersionName($this->projectId, $key, 'latest');
        // Build the request.
        $request = AccessSecretVersionRequest::build($name);
        // Access the secret version.
        $response = $this->getClient()->accessSecretVersion($request);

        return $response->getPayload()->getData();
    }
}
