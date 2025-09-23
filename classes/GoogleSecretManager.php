<?php
namespace Stanford\DoceboIntegration;

use Google\ApiCore\ApiException;
use Google\Cloud\SecretManager\V1\Client\SecretManagerServiceClient;
use Google\Cloud\SecretManager\V1\AccessSecretVersionRequest;

/**
 * Class GoogleSecretManager
 *
 * Lightweight wrapper for Google Secret Manager operations used by the Docebo integration.
 *
 * Responsibilities:
 * - Lazily create a SecretManagerServiceClient.
 * - Retrieve the payload for the latest version of a named secret.
 *
 * @package Stanford\DoceboIntegration
 */
class GoogleSecretManager {
    /**
     * Secret Manager client instance (lazy).
     *
     * @var SecretManagerServiceClient|null
     */
    private $client;

    /**
     * Google Cloud project id containing the secrets.
     *
     * @var string
     */
    private $projectId;

    /**
     * Optional JSON key contents (not used by current implementation but kept for future explicit auth).
     *
     * @var string|null
     */
    private $keyJson;

    /**
     * GoogleSecretManager constructor.
     *
     * @param string $projectId Google Cloud project id that holds the secrets.
     * @param string|null $keyJson Optional service account JSON key contents for explicit authentication.
     */
    public function __construct(string $projectId, ?string $keyJson = null) {
        $this->projectId = $projectId;
        $this->keyJson = $keyJson;
    }

    /**
     * Get or create the SecretManagerServiceClient.
     *
     * @return SecretManagerServiceClient
     */
    private function getClient(): SecretManagerServiceClient {
        if (!$this->client) {
            $this->client = new SecretManagerServiceClient();
        }
        return $this->client;
    }

    /**
     * Retrieve the secret payload for the latest version of the provided secret key.
     *
     * This returns the raw secret data as a string. Caller should handle interpretation
     * (for example, trimming or JSON-decoding) as needed.
     *
     * @param string $key Secret resource id (the secret name, not a full resource path).
     * @return string Secret payload data (raw bytes as string).
     * @throws ApiException If the Secret Manager request fails (permissions, not found, etc.).
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
