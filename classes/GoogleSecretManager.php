<?php
namespace Stanford\RedcapOneDirectoryLookup;

use Google\ApiCore\ApiException;
use Google\Cloud\SecretManager\V1\Client\SecretManagerServiceClient;
use Google\Cloud\SecretManager\V1\AccessSecretVersionRequest;

class GoogleSecretManager {
    private $client;
    private $projectId;
    private $keyJson;
    private $transport; // 'grpc' (default) or 'rest'

    public function __construct(string $projectId, ?string $keyJson = null) {
        $this->projectId = $projectId;
        $this->keyJson = $keyJson;
        $envTransport = getenv('GSM_TRANSPORT');
        $this->transport = ($envTransport && in_array(strtolower($envTransport), ['rest','grpc'])) ? strtolower($envTransport) : null;
        $this->envStatus();
    }

    private function debug(string $msg): void {
        error_log('[GSM] ' . $msg);
    }

    private function envStatus(): void {
        $ga = getenv('GOOGLE_APPLICATION_CREDENTIALS');
        $grpcLoaded = extension_loaded('grpc') ? 'yes' : 'no';
        $proj = $this->projectId ?: '(null)';
        $tr = $this->transport ?: '(default)';
        $this->debug("Env: GOOGLE_APPLICATION_CREDENTIALS=" . ($ga ?: '(unset)') . ", grpc ext loaded=" . $grpcLoaded . ", projectId=" . $proj . ", transport=" . $tr);
    }

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
     * @throws ApiException
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
