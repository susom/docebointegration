<?php

namespace Stanford\DoceboIntegration;

use ExternalModules\ExternalModules;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

require_once 'GoogleSecretManager.php';

/**
 * Class doceboClient
 *
 * Lightweight Docebo API client with token management backed by REDCap External Modules system settings
 * and secrets loaded from Google Secret Manager.
 *
 * Responsibilities:
 * - Load Docebo credentials from Google Secret Manager.
 * - Acquire and refresh OAuth2 access tokens (password and refresh token grants).
 * - Persist token state into ExternalModules system settings.
 * - Provide authenticated GET/POST helpers that automatically add Bearer token and retry once on 401.
 *
 * Note: The class intentionally uses the REDCap ExternalModules system settings for persistence.
 *
 * @package Stanford\DoceboIntegration
 */
class doceboClient
{
    // ----- Config -----
    /** @var string Base URL for Docebo API (no trailing slash) */
    public const BASE_URL = 'https://experience.stanford.edu';
    /** @var int Seconds before expiry to attempt refresh */
    private const TOKEN_REFRESH_BUFFER = 60; // seconds

    // Secret names in Google Secret Manager
    public const DECEBO_CLIENT_ID     = 'DOCEBO_CLIENT_ID';
    public const DECEBO_CLIENT_SECRET = 'DOCEBO_CLIENT_SECRET';
    public const DECEBO_USERNAME      = 'DOCEBO_USERNAME';
    public const DECEBO_PASSWORD      = 'DOCEBO_PASSWORD';

    public const GOOGLE_PROJECT_ID = 'google-project-id';

    public const DOCEBO_ACCESS_TOKEN  = 'docebo-access-token';
    public const DOCEBO_REFRESH_TOKEN = 'docebo-refresh-token';
    public const DOCEBO_TOKEN_EXPIRY  = 'docebo-token-expiry';

    /** @var GoogleSecretManager|null Secret manager instance (lazy) */
    private $secretManager;
    /** @var string Google project id (from ExternalModules system setting) */
    private $googleProjectId;

    // Secrets (loaded on first use)
    /** @var string|null Docebo OAuth client id */
    private $client_id;
    /** @var string|null Docebo OAuth client secret */
    private $client_secret;
    /** @var string|null Docebo username for password grant */
    private $username;
    /** @var string|null Docebo password for password grant */
    private $password;

    // Token state (inject from storage; we’ll update them as we refresh)
    /** @var string|null Current access token */
    private $access_token;
    /** @var string|null Current refresh token */
    private $refresh_token;
    /** @var int Token expiry time as epoch seconds */
    private $token_expiry; // absolute epoch seconds

    /** @var Client Guzzle HTTP client used for requests */
    private $http;

    /** @var string External Modules prefix used for system settings */
    private $PREFIX;

    /**
     * doceboClient constructor.
     *
     * @param string $PREFIX External Modules prefix to use for system settings.
     * @param string $access_token Optional initial access token to seed client.
     * @param string $refresh_token Optional initial refresh token to seed client.
     * @param string|int $token_expiry Optional token expiry (epoch seconds) to seed client.
     */
    public function __construct($PREFIX)
    {
        $this->PREFIX = $PREFIX;
        $this->googleProjectId = ExternalModules::getSystemSetting($this->PREFIX, self::GOOGLE_PROJECT_ID) ?: '';
        $this->access_token    = ExternalModules::getSystemSetting($this->PREFIX, self::DOCEBO_ACCESS_TOKEN) ?: '';
        $this->refresh_token   = ExternalModules::getSystemSetting($this->PREFIX, self::DOCEBO_REFRESH_TOKEN) ?: '';
        $token_expiry = ExternalModules::getSystemSetting($this->PREFIX, self::DOCEBO_TOKEN_EXPIRY);
        $this->token_expiry    = is_numeric($token_expiry) ? (int)$token_expiry :  0;

        $this->http = new Client([
            'base_uri' => rtrim(self::BASE_URL, '/') . '/',
            'timeout'  => 30,
        ]);
    }

    /**
     * Get or create GoogleSecretManager instance.
     *
     * @return GoogleSecretManager
     */
    private function getSecretManager(): GoogleSecretManager
    {
        if (!$this->secretManager) {
            $this->secretManager = new GoogleSecretManager($this->googleProjectId);
        }
        return $this->secretManager;
    }

    /**
     * Lazily load all required secrets from Google Secret Manager.
     *
     * After execution the following properties are populated:
     *  - client_id
     *  - client_secret
     *  - username
     *  - password
     *
     * @return void
     */
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

    /**
     * Public: returns a valid access token (refreshing or logging in as needed).
     *
     * @return string Valid access token.
     * @throws \RuntimeException If authentication fails during password grant.
     */
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

    /**
     * Check whether the currently held token is likely valid for at least the buffer period.
     *
     * @return bool True when token exists and expiry is sufficiently in the future.
     */
    public function isTokenValid(): bool
    {
        return $this->access_token && $this->token_expiry > (time() + self::TOKEN_REFRESH_BUFFER);
    }

    /**
     * Expose the current token state for persistence or inspection.
     *
     * @return array{access_token:string|null,refresh_token:string|null,token_expiry:int}
     */
    public function getTokenState(): array
    {
        return [
            'access_token'  => $this->access_token,
            'refresh_token' => $this->refresh_token,
            'token_expiry'  => $this->token_expiry,
        ];
    }

    /**
     * Refresh access token using the stored refresh token.
     *
     * @return bool True on success, false on failure.
     */
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
            $this->saveTokens();
            return true;
        }
        return false;
    }

    /**
     * Persist current tokens into ExternalModules system settings.
     *
     * @return void
     */
    private function saveTokens()
    {
        ExternalModules::setSystemSetting($this->PREFIX, self::DOCEBO_ACCESS_TOKEN, $this->access_token);
        ExternalModules::setSystemSetting($this->PREFIX, self::DOCEBO_REFRESH_TOKEN, $this->refresh_token);
        ExternalModules::setSystemSetting($this->PREFIX, self::DOCEBO_TOKEN_EXPIRY, (string)$this->token_expiry);
    }

    /**
     * Authenticate to Docebo using password grant and populate token fields.
     *
     * @return void
     * @throws \RuntimeException On authentication error or malformed response.
     */
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
        $this->saveTokens();
    }

    /**
     * Decode JSON response body into associative array.
     *
     * @param ResponseInterface $res Guzzle response
     * @return array Decoded JSON or empty array when invalid.
     */
    private function decodeJson(ResponseInterface $res): array
    {
        $raw = (string)$res->getBody();
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }

    // ------------------------------------------------------------------
    // HTTP helpers (Guzzle) with auto-bearer + single 401 retry on refresh
    // ------------------------------------------------------------------

    /**
     * Perform an authenticated HTTP request, automatically adding Bearer Authorization
     * and attempting a single retry on 401 by refreshing tokens or re-authenticating.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path Relative path (leading slash optional)
     * @param array $options Guzzle request options
     * @param bool $retryOn401 Whether to attempt retry on 401 (default true)
     * @return array Response structured as ['ok' => bool, 'status' => int, 'json'| 'error' => mixed]
     */
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

    /**
     * Format a RequestException into a structured error array.
     *
     * @param RequestException $e Exception from Guzzle
     * @return array Structured error: ['ok'=>false,'status'=>int,'error'=>mixed]
     */
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

    /**
     * Perform a GET request with optional query params.
     *
     * @param string $path API path (relative)
     * @param array $query Query parameters to attach
     * @return array Response structure from authedRequest
     */
    public function get(string $path, array $query = []): array
    {
        $opts = [];
        if (!empty($query)) {
            $opts['query'] = $query;
        }
        return $this->authedRequest('GET', $path, $opts);
    }

    /**
     * Perform a POST request with JSON body.
     *
     * @param string $path API path (relative)
     * @param array $json JSON-serializable payload
     * @return array Response structure from authedRequest
     */
    public function post(string $path, array $json = []): array
    {
        $opts = [];
        if (!empty($json)) {
            $opts['json'] = $json;
        }
        return $this->authedRequest('POST', $path, $opts);
    }

    /**
     * Perform a POST request with form-encoded body.
     *
     * @param string $path API path (relative)
     * @param array $form Form parameters to send as application/x-www-form-urlencoded
     * @return array Response structure from authedRequest
     */
    public function postFormAuthed(string $path, array $form = []): array
    {
        $opts = ['form_params' => $form];
        return $this->authedRequest('POST', $path, $opts);
    }

    /**
     * Force a token refresh using the stored refresh token.
     *
     * @return bool True on success, false when no refresh token or refresh failed.
     */
    public function forceRefresh(): bool
    {
        if (!$this->refresh_token) return false;
        return $this->refreshAccessToken();
    }
}
