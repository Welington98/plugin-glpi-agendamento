<?php

namespace GlpiPlugin\Agendamento;

use Config as GlpiConfig;
use Session;
use Toolbox;

class GoogleCalendarAuth
{
    private const TOKEN_TABLE = 'glpi_plugin_agendamento_google_tokens';
    private const OAUTH_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const OAUTH_REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    public static function getAuthorizationUrl(): string
    {
        global $CFG_GLPI;

        $config = Config::getConfig();
        $clientId = trim($config['google_client_id'] ?? '');
        if ($clientId === '') {
            throw new \RuntimeException('Google Client ID não configurado.');
        }

        $rootDoc = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
        $redirectUri = self::getRedirectUri($rootDoc);

        $state = self::generateState();

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ];

        return self::OAUTH_AUTH_URL . '?' . http_build_query($params);
    }

    public static function handleCallback(string $code, string $state): void
    {
        global $CFG_GLPI;

        self::validateState($state);

        $config = Config::getConfig();
        $clientId = trim($config['google_client_id'] ?? '');
        $clientSecret = self::decryptSecret($config['google_client_secret'] ?? '');
        $rootDoc = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
        $redirectUri = self::getRedirectUri($rootDoc);

        $response = self::httpPost(self::OAUTH_TOKEN_URL, [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (!isset($response['access_token'])) {
            $error = $response['error_description'] ?? $response['error'] ?? 'Resposta inválida do Google';
            throw new \RuntimeException('Falha ao obter token: ' . $error);
        }

        $userId = (int) Session::getLoginUserID();
        $expiresIn = (int) ($response['expires_in'] ?? 3600);
        $expiry = date('Y-m-d H:i:s', time() + $expiresIn);

        self::storeTokens($userId, [
            'access_token' => self::encryptToken($response['access_token']),
            'refresh_token' => isset($response['refresh_token']) ? self::encryptToken($response['refresh_token']) : null,
            'token_expiry' => $expiry,
            'is_active' => 1,
        ]);

        Toolbox::logInFile('agendamento', "Google Calendar conectado para o usuário #{$userId}");
    }

    public static function refreshAccessToken(int $userId): ?string
    {
        global $DB;

        $tokenData = self::getTokenData($userId);
        if ($tokenData === null || empty($tokenData['refresh_token'])) {
            return null;
        }

        $config = Config::getConfig();
        $clientId = trim($config['google_client_id'] ?? '');
        $clientSecret = self::decryptSecret($config['google_client_secret'] ?? '');
        $refreshToken = self::decryptToken($tokenData['refresh_token']);

        $response = self::httpPost(self::OAUTH_TOKEN_URL, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (!isset($response['access_token'])) {
            $DB->update(self::TOKEN_TABLE, ['is_active' => 0], ['users_id' => $userId]);
            Toolbox::logInFile('agendamento', "Falha ao renovar token para usuário #{$userId}: " . ($response['error'] ?? 'desconhecido'));
            return null;
        }

        $expiresIn = (int) ($response['expires_in'] ?? 3600);
        $expiry = date('Y-m-d H:i:s', time() + $expiresIn);

        $updateData = [
            'access_token' => self::encryptToken($response['access_token']),
            'token_expiry' => $expiry,
            'is_active' => 1,
        ];

        if (isset($response['refresh_token'])) {
            $updateData['refresh_token'] = self::encryptToken($response['refresh_token']);
        }

        $DB->update(self::TOKEN_TABLE, $updateData, ['users_id' => $userId]);

        return $response['access_token'];
    }

    public static function revokeAccess(int $userId): void
    {
        global $DB;

        $tokenData = self::getTokenData($userId);
        if ($tokenData !== null && !empty($tokenData['access_token'])) {
            $token = self::decryptToken($tokenData['access_token']);
            self::httpPost(self::OAUTH_REVOKE_URL, ['token' => $token]);
        }

        $DB->delete(self::TOKEN_TABLE, ['users_id' => $userId]);
        Toolbox::logInFile('agendamento', "Google Calendar desconectado para o usuário #{$userId}");
    }

    public static function getValidToken(int $userId): ?string
    {
        $tokenData = self::getTokenData($userId);
        if ($tokenData === null || !(int) $tokenData['is_active']) {
            return null;
        }

        $accessToken = self::decryptToken($tokenData['access_token']);
        $expiry = strtotime($tokenData['token_expiry']);

        if ($expiry !== false && $expiry > (time() + 60)) {
            return $accessToken;
        }

        return self::refreshAccessToken($userId);
    }

    public static function isConnected(int $userId): bool
    {
        $tokenData = self::getTokenData($userId);
        return $tokenData !== null && (int) ($tokenData['is_active'] ?? 0) === 1;
    }

    public static function getTokenData(int $userId): ?array
    {
        global $DB;

        if (!$DB->tableExists(self::TOKEN_TABLE)) {
            return null;
        }

        $iterator = $DB->request([
            'FROM' => self::TOKEN_TABLE,
            'WHERE' => ['users_id' => $userId],
            'LIMIT' => 1,
        ]);

        return count($iterator) > 0 ? $iterator->current() : null;
    }

    private static function storeTokens(int $userId, array $data): void
    {
        global $DB;

        $existing = self::getTokenData($userId);
        if ($existing !== null) {
            $updateData = array_filter([
                'access_token' => $data['access_token'],
                'token_expiry' => $data['token_expiry'],
                'is_active' => $data['is_active'],
            ], fn($v) => $v !== null);

            if (isset($data['refresh_token']) && $data['refresh_token'] !== null) {
                $updateData['refresh_token'] = $data['refresh_token'];
            }

            $DB->update(self::TOKEN_TABLE, $updateData, ['users_id' => $userId]);
        } else {
            $DB->insert(self::TOKEN_TABLE, array_merge($data, [
                'users_id' => $userId,
                'calendar_id' => 'primary',
            ]));
        }
    }

    private static function getRedirectUri(string $rootDoc): string
    {
        global $CFG_GLPI;
        $baseUrl = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
        return $baseUrl . '/plugins/agendamento/front/google_callback.php';
    }

    private static function generateState(): string
    {
        $userId = (int) Session::getLoginUserID();
        $timestamp = time();
        $payload = $userId . '|' . $timestamp;
        $key = self::getEncryptionKey();
        $hmac = hash_hmac('sha256', $payload, $key);
        return rtrim(strtr(base64_encode($payload . '|' . $hmac), '+/', '-_'), '=');
    }

    private static function validateState(string $state): void
    {
        $decoded = base64_decode(strtr($state, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \RuntimeException('Estado OAuth inválido.');
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Estado OAuth inválido.');
        }

        [$userId, $timestamp, $hmac] = $parts;
        $payload = $userId . '|' . $timestamp;
        $key = self::getEncryptionKey();
        $expectedHmac = hash_hmac('sha256', $payload, $key);

        if (!hash_equals($expectedHmac, $hmac)) {
            throw new \RuntimeException('Estado OAuth inválido. Possível ataque CSRF.');
        }

        if (time() - (int) $timestamp > 600) {
            throw new \RuntimeException('Estado OAuth expirado. Tente novamente.');
        }

        if ((int) $userId !== (int) Session::getLoginUserID()) {
            throw new \RuntimeException('Estado OAuth inválido. Usuário diferente.');
        }
    }

    private static function getEncryptionKey(): string
    {
        $glpiKey = new \GLPIKey();
        $key = $glpiKey->get();
        if (empty($key)) {
            $key = defined('GLPI_CONFIG_DIR') ? GLPI_CONFIG_DIR : 'glpi-agendamento-fallback';
        }
        return hash('sha256', $key, true);
    }

    public static function encryptToken(string $plaintext): string
    {
        $key = self::getEncryptionKey();
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            throw new \RuntimeException('Falha na criptografia do token.');
        }
        return base64_encode($iv . $encrypted);
    }

    public static function decryptToken(string $ciphertext): string
    {
        $key = self::getEncryptionKey();
        $data = base64_decode($ciphertext, true);
        if ($data === false || strlen($data) < 17) {
            return '';
        }
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return $decrypted !== false ? $decrypted : '';
    }

    public static function decryptSecret(string $value): string
    {
        if ($value === '' || str_starts_with($value, 'ENC:') === false) {
            return $value;
        }
        return self::decryptToken(substr($value, 4));
    }

    public static function encryptSecret(string $value): string
    {
        if ($value === '') {
            return '';
        }
        return 'ENC:' . self::encryptToken($value);
    }

    public static function httpPost(string $url, array $postFields): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postFields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Toolbox::logInFile('agendamento', "cURL error: {$curlError}");
            return ['error' => 'curl_error', 'error_description' => $curlError];
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : ['error' => 'invalid_response', 'http_code' => $httpCode];
    }

    public static function httpRequest(string $method, string $url, ?array $body = null, string $accessToken = ''): array
    {
        $ch = curl_init();
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ];

        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];

        switch (strtoupper($method)) {
            case 'POST':
                $opts[CURLOPT_POST] = true;
                if ($body !== null) {
                    $opts[CURLOPT_POSTFIELDS] = json_encode($body);
                }
                break;
            case 'PUT':
                $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
                if ($body !== null) {
                    $opts[CURLOPT_POSTFIELDS] = json_encode($body);
                }
                break;
            case 'DELETE':
                $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
                break;
            case 'GET':
            default:
                break;
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Toolbox::logInFile('agendamento', "Google API cURL error: {$curlError}");
            return ['error' => 'curl_error', 'error_description' => $curlError, 'http_code' => 0];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        $decoded['http_code'] = $httpCode;

        return $decoded;
    }
}
