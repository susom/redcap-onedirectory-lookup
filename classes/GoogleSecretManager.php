<?php
namespace Stanford\RedcapOneDirectoryLookup;

use Google\ApiCore\ApiException;
use Google\Cloud\SecretManager\V1\Client\SecretManagerServiceClient;
use Google\Cloud\SecretManager\V1\AccessSecretVersionRequest;

/**
 * Lightweight wrapper around Google Cloud Secret Manager for retrieving secret values.
 *
 * This class supports both Application Default Credentials (ADC) and explicit
 * Service Account JSON passed at runtime. It can use either gRPC (default)
 * or REST transports and will automatically retry with REST if a common
 * protobuf parsing error occurs when using gRPC.
 *
 * Typical usage:
 * ```php
 * $gsm = new GoogleSecretManager($projectId);                  // Uses ADC
 * // or: $gsm = new GoogleSecretManager($projectId, $jsonSa);  // Explicit SA JSON
 * $value = $gsm->getSecret('my_secret_key');                   // Reads latest version
 * ```
 *
 * Environment variables:
 * - GOOGLE_APPLICATION_CREDENTIALS: path to JSON for ADC (optional)
 * - GSM_TRANSPORT: 'grpc' or 'rest' to force transport (optional)
 *
 * @package Stanford\RedcapOneDirectoryLookup
 */
class GoogleSecretManager {
    /**
     * Lazily-initialized Secret Manager client.
     *
     * @var SecretManagerServiceClient|null
     */
    private $client;

    /**
     * Google Cloud Project ID containing the secrets.
     *
     * @var string
     */
    private $projectId;

    /**
     * Optional raw Service Account JSON string. If null/empty, ADC is used.
     *
     * @var string|null
     */
    private $keyJson;

    /**
     * Preferred transport for the client: 'grpc' (default) or 'rest'.
     * If null, client library default is used.
     *
     * @var string|null
     */
    private $transport; // 'grpc' (default) or 'rest'

    /**
     * @param string      $projectId Google Cloud Project ID where the secrets reside.
     * @param string|null $keyJson   Optional Service Account JSON string. If omitted or empty, ADC is used.
     */
    public function __construct(string $projectId, ?string $keyJson = null) {
        $this->projectId = $projectId;
        $this->keyJson = $keyJson;
        $envTransport = getenv('GSM_TRANSPORT');
        $this->transport = ($envTransport && in_array(strtolower($envTransport), ['rest','grpc'])) ? strtolower($envTransport) : null;
        $this->envStatus();
    }

    /**
     * Emit a namespaced debug line to error_log.
     *
     * @param string $msg
     * @return void
     */
    private function debug(string $msg): void {
        error_log('[GSM] ' . $msg);
    }

    /**
     * Log a brief snapshot of environment/transport status for diagnostics.
     *
     * @return void
     */
    private function envStatus(): void {
        $ga = getenv('GOOGLE_APPLICATION_CREDENTIALS');
        $grpcLoaded = extension_loaded('grpc') ? 'yes' : 'no';
        $proj = $this->projectId ?: '(null)';
        $tr = $this->transport ?: '(default)';
        $this->debug("Env: GOOGLE_APPLICATION_CREDENTIALS=" . ($ga ?: '(unset)') . ", grpc ext loaded=" . $grpcLoaded . ", projectId=" . $proj . ", transport=" . $tr);
    }

    /**
     * Decode and validate the provided Service Account JSON (if any).
     *
     * @return array|null Associative array of credentials or null to indicate ADC should be used.
     * @throws \RuntimeException If JSON is invalid or missing required keys.
     */
    private function decodeKeyJson(): ?array {
        if ($this->keyJson === null || $this->keyJson === '') {
            return null; // use ADC
        }
        $decoded = json_decode($this->keyJson, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->debug('Invalid service account JSON: ' . json_last_error_msg());
            throw new \RuntimeException('Invalid service account JSON in keyJson: ' . json_last_error_msg());
        }
        if (!isset($decoded['client_email']) || !isset($decoded['private_key'])) {
            $this->debug('Missing client_email/private_key in provided keyJson');
            throw new \RuntimeException('Provided keyJson is missing required fields (client_email/private_key).');
        }
        return $decoded;
    }

    /**
     * Lazily initialize and return the Secret Manager client using the configured
     * transport and credentials strategy (explicit SA JSON or ADC).
     *
     * @return SecretManagerServiceClient
     * @throws \Throwable If client initialization fails.
     */
    private function getClient(): SecretManagerServiceClient {
        if (!$this->client) {
            try {
                $opts = [];
                if ($this->transport) { $opts['transport'] = $this->transport; }

                $creds = $this->decodeKeyJson();
                if ($creds) {
                    $opts['credentials'] = $creds; // explicit SA JSON
                    $this->client = new SecretManagerServiceClient($opts);
                    $this->debug('Initialized SecretManagerServiceClient with explicit credentials (array); transport=' . ($opts['transport'] ?? 'default'));
                } else {
                    $this->client = new SecretManagerServiceClient($opts); // ADC
                    $this->debug('Initialized SecretManagerServiceClient with ADC; transport=' . ($opts['transport'] ?? 'default'));
                }
            } catch (\Throwable $e) {
                $this->debug('Failed to initialize SecretManagerServiceClient: ' . $e->getMessage());
                throw $e;
            }
        }
        return $this->client;
    }

    /**
     * Retrieve the latest version of a secret's payload as a string.
     *
     * On gRPC parsing failures (commonly due to protobuf metadata issues), this method
     * retries once with the REST transport to improve resilience.
     *
     * @param string $key Secret ID (resource name without version).
     * @return string Secret payload (may be an empty string if the payload is empty).
     * @throws ApiException On API errors returned by the service.
     * @throws \Throwable   On unexpected errors if fallback to REST also fails.
     */
    public function getSecret(string $key): string {
        try {
            $name = $this->getClient()->secretVersionName($this->projectId, $key, 'latest');
            $this->debug('Accessing secret: ' . $name . ' (transport=' . ($this->transport ?: 'default') . ')');

            // Build the request explicitly (avoid static build to reduce metadata parse surprises)
            $request = new AccessSecretVersionRequest();
            $request->setName($name);

            // Access the secret version
            $response = $this->getClient()->accessSecretVersion($request);
            $payload = $response->getPayload()->getData();
            if ($payload === null || $payload === '') {
                $this->debug('Empty payload returned for secret: ' . $key);
            }
            return $payload;
        } catch (ApiException $e) {
            $this->debug('ApiException while accessing secret "' . $key . '": ' . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $this->debug('Unexpected error while accessing secret "' . $key . '": ' . $msg);
            // Common protobuf parsing failure path: retry once with REST for clearer error / to bypass gRPC
            if ($this->transport !== 'rest') {
                $this->debug('Retrying with transport=rest to bypass gRPC parsing...');
                $this->transport = 'rest';
                $this->client = null; // force re-init with REST
                $client = $this->getClient();
                $name = $client->secretVersionName($this->projectId, $key, 'latest');
                $request = new AccessSecretVersionRequest();
                $request->setName($name);
                $response = $client->accessSecretVersion($request);
                $payload = $response->getPayload()->getData();
                return $payload;
            }
            throw $e;
        }
    }
}
