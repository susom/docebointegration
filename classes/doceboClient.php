<?php

namespace Stanford\DoceboIntegration;

use ExternalModules\ExternalModules;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

require_once 'GoogleSecretManager.php';

class doceboClient
{
    // ----- Config -----
    public const BASE_URL = 'https://experience.stanford.edu';
    private const TOKEN_REFRESH_BUFFER = 60; // seconds

    // Secret names in Google Secret Manager
    public const DECEBO_CLIENT_ID     = 'DOCEBO_CLIENT_ID';
    public const DECEBO_CLIENT_SECRET = 'DOCEBO_CLIENT_SECRET';
    public const DECEBO_USERNAME      = 'DOCEBO_USERNAME';
    public const DECEBO_PASSWORD      = 'DOCEBO_PASSWORD';

    public const GOOGLE_PROJECT_ID = 'google-project-id';

    public const DOCEBO_ACCESS_TOKEN  = 'docebo-access-token';

    public const DOCEBO_REFRESH_TOKEN = 'docebo-refresh-token';

    public const DOCEBO_TOKEN_EXPIRY = 'docebo-token-expiry';
    /** @var GoogleSecretManager */
    private $secretManager;
    private $googleProjectId;

    // Secrets (loaded on first use)
    private $client_id;
    private $client_secret;
    private $username;
    private $password;

    // Token state (inject from storage; we’ll update them as we refresh)
    private $access_token;
    private $refresh_token;
    private $token_expiry; // absolute epoch seconds

    /** @var Client */
    private $http;

    private $PREFIX;
    public function __construct($PREFIX, $access_token = '', $refresh_token = '', $token_expiry = '')
    {
        $this->PREFIX = $PREFIX;
        $this->googleProjectId = ExternalModules::getSystemSetting($this->PREFIX, self::GOOGLE_PROJECT_ID) ?: '';
        $this->access_token    = ExternalModules::getSystemSetting($this->PREFIX, self::DOCEBO_ACCESS_TOKEN) ?: '';
        $this->refresh_token   = ExternalModules::getSystemSetting($this->PREFIX, self::DOCEBO_REFRESH_TOKEN) ?: '';
        $this->token_expiry    = is_numeric(ExternalModules::getSystemSetting($this->PREFIX, self::DOCEBO_TOKEN_EXPIRY)) ? (int)ExternalModules::getSystemSetting($this->PREFIX, self::DOCEBO_TOKEN_EXPIRY) : 0;

        $this->http = new Client([
            'base_uri' => rtrim(self::BASE_URL, '/') . '/',
            'timeout'  => 30,
        ]);
    }

    private function getSecretManager(): GoogleSecretManager
    {
        if (!$this->secretManager) {
            $this->secretManager = new GoogleSecretManager($this->googleProjectId);
        }
        return $this->secretManager;
    }

    /** Lazily load all secrets once */
    private function ensureSecretsLoaded(): void
    {
        if ($this->client_id && $this->client_secret && $this->username && $this->password) {
            return;
        }
        $sm = $this->getSecretManager();
        $this->client_id     = trim($sm->getSecret(self::DECEBO_CLIENT_ID));
        $this->client_secret = trim($sm->getSecret(self::DECEBO_CLIENT_SECRET));
        $this->username      = trim($sm->getSecret(self::DECEBO_USERNAME));
        $this->password      = trim($sm->getSecret(self::DECEBO_PASSWORD));
    }

    /** Public: returns a valid access token (refreshing or logging in as needed) */
    public function getAccessToken(): string
    {
        $now = time();

        if ($this->access_token && $this->token_expiry > ($now + self::TOKEN_REFRESH_BUFFER)) {
            return $this->access_token;
        }

        if ($this->refresh_token) {
            if ($this->refreshAccessToken()) {
                return $this->access_token;
            }
        }

        $this->authenticateWithPassword();
        return $this->access_token;
    }

    /** True iff current token looks valid for at least buffer seconds */
    public function isTokenValid(): bool
    {
        return $this->access_token && $this->token_expiry > (time() + self::TOKEN_REFRESH_BUFFER);
    }

    /** Expose tokens so caller can persist them */
    public function getTokenState(): array
    {
        return [
            'access_token'  => $this->access_token,
            'refresh_token' => $this->refresh_token,
            'token_expiry'  => $this->token_expiry,
        ];
    }

    /** Refresh using refresh_token; returns true on success */
    private function refreshAccessToken(): bool
    {
        $this->ensureSecretsLoaded();

        try {
            $res = $this->http->post('oauth2/token', [
                'form_params' => [
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $this->refresh_token,
                    'client_id'     => $this->client_id,
                    'client_secret' => $this->client_secret,
                ],
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (RequestException $e) {
            // optional: log $e->getMessage()
            return false;
        }

        $json = $this->decodeJson($res);
        if (!empty($json['access_token'])) {
            $this->access_token  = $json['access_token'];
            $this->refresh_token = $json['refresh_token'] ?? $this->refresh_token;
            $expiresIn           = isset($json['expires_in']) ? (int)$json['expires_in'] : 3600;
            $this->token_expiry  = time() + $expiresIn;
            return true;
        }
        return false;
    }

    /** First-time login with password grant */
    private function authenticateWithPassword(): void
    {
        $this->ensureSecretsLoaded();

        try {
            $res = $this->http->post('oauth2/token', [
                'form_params' => [
                    'grant_type'    => 'password',
                    'client_id'     => $this->client_id,
                    'client_secret' => $this->client_secret,
                    'username'      => $this->username,
                    'password'      => $this->password,
                    'scope'         => 'api',
                ],
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (RequestException $e) {
            $msg = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
            throw new \RuntimeException("Docebo auth error: {$msg}");
        }

        $json = $this->decodeJson($res);
        if (empty($json['access_token'])) {
            throw new \RuntimeException('Docebo auth response missing access_token');
        }
        $this->access_token  = $json['access_token'];
        $this->refresh_token = $json['refresh_token'] ?? '';
        $expiresIn           = isset($json['expires_in']) ? (int)$json['expires_in'] : 3600;
        $this->token_expiry  = time() + $expiresIn;
    }

    /** Decode JSON safely */
    private function decodeJson(ResponseInterface $res): array
    {
        $raw = (string)$res->getBody();
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }

    // ------------------------------------------------------------------
    // HTTP helpers (Guzzle) with auto-bearer + single 401 retry on refresh
    // ------------------------------------------------------------------

    private function authedRequest(string $method, string $path, array $options = [], bool $retryOn401 = true): array
    {
        // Ensure Authorization header
        $options['headers'] = ($options['headers'] ?? []);
        $options['headers']['Authorization'] = 'Bearer ' . $this->getAccessToken();
        $options['headers']['Accept'] = $options['headers']['Accept'] ?? 'application/json';

        try {
            $res  = $this->http->request($method, ltrim($path, '/'), $options);
            $json = $this->decodeJson($res);
            return ['ok' => true, 'status' => $res->getStatusCode(), 'json' => $json];
        } catch (RequestException $e) {
            $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;

            // If unauthorized once, try to refresh and retry exactly once
            if ($status === 401 && $retryOn401) {
                if ($this->refresh_token && $this->refreshAccessToken()) {
                    // Replace header with new token and retry once
                    $options['headers']['Authorization'] = 'Bearer ' . $this->access_token;
                    try {
                        $res2  = $this->http->request($method, ltrim($path, '/'), $options);
                        $json2 = $this->decodeJson($res2);
                        return ['ok' => true, 'status' => $res2->getStatusCode(), 'json' => $json2];
                    } catch (RequestException $e2) {
                        return $this->formatError($e2);
                    }
                } else {
                    // If refresh failed, attempt password auth and retry once
                    $this->authenticateWithPassword();
                    $options['headers']['Authorization'] = 'Bearer ' . $this->access_token;
                    try {
                        $res3  = $this->http->request($method, ltrim($path, '/'), $options);
                        $json3 = $this->decodeJson($res3);
                        return ['ok' => true, 'status' => $res3->getStatusCode(), 'json' => $json3];
                    } catch (RequestException $e3) {
                        return $this->formatError($e3);
                    }
                }
            }

            return $this->formatError($e);
        }
    }

    private function formatError(RequestException $e): array
    {
        $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
        $body   = $e->getResponse() ? (string)$e->getResponse()->getBody() : '';
        $json   = $body ? json_decode($body, true) : null;
        return [
            'ok'     => false,
            'status' => $status,
            'error'  => $json ?: $body ?: $e->getMessage(),
        ];
    }

    // -------- Public API helpers (auto bearer) --------

    /** GET with query params */
    public function get(string $path, array $query = []): array
    {
        $opts = [];
        if (!empty($query)) {
            $opts['query'] = $query;
        }
        return $this->authedRequest('GET', $path, $opts);
    }

    /** POST JSON */
    public function post(string $path, array $json = []): array
    {
        $opts = [];
        if (!empty($json)) {
            $opts['json'] = $json;
        }
        return $this->authedRequest('POST', $path, $opts);
    }

    /** POST form-encoded (if you need it for any endpoints) */
    public function postFormAuthed(string $path, array $form = []): array
    {
        $opts = ['form_params' => $form];
        return $this->authedRequest('POST', $path, $opts);
    }

    // -------- Convenience: explicit token refresh if you want to trigger it --------
    public function forceRefresh(): bool
    {
        if (!$this->refresh_token) return false;
        return $this->refreshAccessToken();
    }
}
