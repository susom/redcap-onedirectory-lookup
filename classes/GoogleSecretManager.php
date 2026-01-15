<?php
namespace Stanford\RedcapOneDirectoryLookup;

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
        error_log('[GoogleSecretManager] Initialized with project: ' . $projectId . ', using ' . ($keyJson ? 'provided credentials' : 'default credentials'));
    }

    private function getClient(): SecretManagerServiceClient {
        if (!$this->client) {
            try {
                error_log('[GoogleSecretManager] Initializing Secret Manager client...');
                if ($this->keyJson) {
                    error_log('[GoogleSecretManager] Using provided JSON credentials');
                    $credentialsArray = json_decode($this->keyJson, true);
                    if (!is_array($credentialsArray)) {
                        error_log('[GoogleSecretManager] ERROR: Failed to decode credentials JSON');
                        throw new \Exception('Invalid credentials JSON format');
                    }
                    $this->client = new SecretManagerServiceClient([
                        'credentialsConfig' => ['keyFile' => $credentialsArray]
                    ]);
                } else {
                    error_log('[GoogleSecretManager] Using default/environment credentials (Application Default Credentials)');
                    $this->client = new SecretManagerServiceClient();
                }
                error_log('[GoogleSecretManager] Secret Manager client initialized successfully');
            } catch (ApiException $e) {
                error_log('[GoogleSecretManager] ApiException during client initialization: ' . $e->getMessage());
                error_log('[GoogleSecretManager] Status code: ' . $e->getCode());
                throw $e;
            } catch (\Exception $e) {
                error_log('[GoogleSecretManager] Exception during client initialization: ' . $e->getMessage());
                throw $e;
            }
        }
        return $this->client;
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
            error_log('[GoogleSecretManager] Fetching secret: ' . $key);

            // Build the full secret resource name
            $name = $this->getClient()->secretVersionName($this->projectId, $key, 'latest');
            error_log('[GoogleSecretManager] Built secret resource name for key: ' . $key);

            // Build and send the access request
            $request = AccessSecretVersionRequest::build($name);
            error_log('[GoogleSecretManager] Sending access request for secret: ' . $key);

            $response = $this->getClient()->accessSecretVersion($request);
            error_log('[GoogleSecretManager] Successfully retrieved secret: ' . $key);

            $secretValue = $response->getPayload()->getData();
            if (empty($secretValue)) {
                error_log('[GoogleSecretManager] WARNING: Retrieved empty value for secret: ' . $key);
            } else {
                error_log('[GoogleSecretManager] Secret retrieved successfully, value length: ' . strlen($secretValue) . ' bytes');
            }

            return $secretValue;
        } catch (ApiException $e) {
            error_log('[GoogleSecretManager] ApiException fetching secret ' . $key . ': ' . $e->getMessage());
            error_log('[GoogleSecretManager] Status code: ' . $e->getCode());
            error_log('[GoogleSecretManager] Details: ' . $e->getDetails());
            throw $e;
        } catch (\Exception $e) {
            error_log('[GoogleSecretManager] Exception fetching secret ' . $key . ': ' . $e->getMessage());
            error_log('[GoogleSecretManager] Exception class: ' . get_class($e));
            throw $e;
        }
    }
}
