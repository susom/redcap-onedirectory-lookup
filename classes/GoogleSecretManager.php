<?php
namespace Stanford\RedcapOneDirectoryLookup;

use Google\ApiCore\ApiException;
use Google\Cloud\SecretManager\V1\Client\SecretManagerServiceClient;
use Google\Cloud\SecretManager\V1\AccessSecretVersionRequest;
use Google\Protobuf\Internal\GPBDecodeException;

class GoogleSecretManager {
    private $grpcClient;
    private $restClient;
    private $projectId;
    private $keyJson;

    public function __construct(string $projectId, ?string $keyJson = null) {
        $this->projectId = $projectId;
        $this->keyJson = $keyJson;
        error_log('[GoogleSecretManager] Initialized with project: ' . $projectId . ', using ' . ($keyJson ? 'provided credentials' : 'default credentials'));
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
            error_log('[GoogleSecretManager] Initializing Secret Manager client (transport=' . $transport . ')...');

            $opts = [
                // IMPORTANT: forcing REST can avoid GPBDecodeException in some environments.
                'transport' => $transport,
            ];

            if ($this->keyJson) {
                error_log('[GoogleSecretManager] Using provided JSON credentials');
                $credentialsArray = json_decode($this->keyJson, true);
                if (!is_array($credentialsArray)) {
                    error_log('[GoogleSecretManager] ERROR: Failed to decode credentials JSON');
                    throw new \Exception('Invalid credentials JSON format');
                }
                $opts['credentialsConfig'] = ['keyFile' => $credentialsArray];
            } else {
                error_log('[GoogleSecretManager] Using default/environment credentials (Application Default Credentials)');
            }

            $this->{$prop} = new SecretManagerServiceClient($opts);
            error_log('[GoogleSecretManager] Secret Manager client initialized successfully (transport=' . $transport . ')');
            return $this->{$prop};
        } catch (ApiException $e) {
            error_log('[GoogleSecretManager] ApiException during client initialization: ' . $e->getMessage());
            error_log('[GoogleSecretManager] Status code: ' . $e->getCode());
            throw $e;
        } catch (\Exception $e) {
            error_log('[GoogleSecretManager] Exception during client initialization: ' . $e->getMessage());
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
        } catch (GPBDecodeException $e) {
            // Common when a proxy/load balancer corrupts or truncates gRPC frames.
            // Retry using REST transport.
            error_log('[GoogleSecretManager] GPBDecodeException (gRPC parse) for secret ' . $key . ': ' . $e->getMessage());
            error_log('[GoogleSecretManager] Retrying Secret Manager call using REST transport...');

            $name = $this->getClient('rest')->secretVersionName($this->projectId, $key, 'latest');
            $request = AccessSecretVersionRequest::build($name);
            $response = $this->getClient('rest')->accessSecretVersion($request);

            error_log('[GoogleSecretManager] Successfully retrieved secret via REST: ' . $key);
            $secretValue = $response->getPayload()->getData();
            if (empty($secretValue)) {
                error_log('[GoogleSecretManager] WARNING: Retrieved empty value for secret (REST): ' . $key);
            } else {
                error_log('[GoogleSecretManager] Secret retrieved successfully via REST, value length: ' . strlen($secretValue) . ' bytes');
            }

            return $secretValue;
        } catch (\RuntimeException $e) {
            // The Google PHP client will throw this when transport=grpc is selected but the PHP gRPC
            // extension isn't installed:
            //   "gRPC support has been requested but required dependencies have not been found."
            // Retry using REST.
            $msg = $e->getMessage();
            if (stripos($msg, 'gRPC support has been requested') !== false) {
                error_log('[GoogleSecretManager] gRPC extension missing: ' . $msg);
                error_log('[GoogleSecretManager] Retrying Secret Manager call using REST transport...');

                $name = $this->getClient('rest')->secretVersionName($this->projectId, $key, 'latest');
                $request = AccessSecretVersionRequest::build($name);
                $response = $this->getClient('rest')->accessSecretVersion($request);

                error_log('[GoogleSecretManager] Successfully retrieved secret via REST: ' . $key);
                $secretValue = $response->getPayload()->getData();
                if (empty($secretValue)) {
                    error_log('[GoogleSecretManager] WARNING: Retrieved empty value for secret (REST): ' . $key);
                } else {
                    error_log('[GoogleSecretManager] Secret retrieved successfully via REST, value length: ' . strlen($secretValue) . ' bytes');
                }

                return $secretValue;
            }

            throw $e;
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
