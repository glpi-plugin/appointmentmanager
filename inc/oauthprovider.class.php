<?php
abstract class PluginAppointmentmanagerOAuthProvider {

    // ── To implement per provider ─────────────────────────────────────────────

    abstract public function getAuthUrl(string $state): string;
    abstract public function exchangeCode(string $code): array;   // ['access_token','refresh_token','expires_in']
    abstract public function refreshAccessToken(string $refresh_token): array;
    abstract public function createEvent(string $access_token, array $event_data): string;  // returns event ID
    abstract public function updateEvent(string $access_token, string $event_id, array $event_data): void;
    abstract public function deleteEvent(string $access_token, string $event_id): void;
    abstract public function fetchEvents(string $access_token, string $from, string $to, string $from_raw = '', string $to_raw = ''): array; // [['start'=>...,'end'=>...],...]

    // ── Shared static helpers ─────────────────────────────────────────────────

    static function getProviderInstance(string $provider): ?self {
        if ($provider === 'google') {
            return new PluginAppointmentmanagerGoogleProvider();
        }
        if ($provider === 'microsoft') {
            return new PluginAppointmentmanagerMicrosoftProvider();
        }
        return null;
    }

    static function getSettings(string $provider): ?array {
        global $DB;

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_oauth_settings',
            'WHERE' => ['provider' => $provider, 'is_enabled' => 1],
            'LIMIT' => 1,
        ]);
        return $iter->count() > 0 ? $iter->current() : null;
    }

    static function getAllSettings(): array {
        global $DB;

        if (!$DB->tableExists('glpi_plugin_appointmentmanager_oauth_settings')) {
            return [];
        }

        $iter = $DB->request(['FROM' => 'glpi_plugin_appointmentmanager_oauth_settings']);
        $rows = [];
        foreach ($iter as $row) {
            $rows[$row['provider']] = $row;
        }
        return $rows;
    }

    static function saveSettings(string $provider, array $data): void {
        global $DB;

        $existing = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_oauth_settings',
            'WHERE' => ['provider' => $provider],
            'LIMIT' => 1,
        ]);

        $new_secret = trim($data['client_secret'] ?? '');
        // Preserve the stored secret when the field is submitted empty
        if ($new_secret === '' && $existing->count() > 0) {
            $new_secret = $existing->current()['client_secret'] ?? '';
        }

        $payload = [
            'client_id'     => trim($data['client_id']     ?? ''),
            'client_secret' => $new_secret,
            'tenant_id'     => trim($data['tenant_id']     ?? 'common') ?: 'common',
            'is_enabled'    => empty($data['client_id']) ? 0 : 1,
            'date_mod'      => date('Y-m-d H:i:s'),
        ];

        if ($existing->count() > 0) {
            $DB->update('glpi_plugin_appointmentmanager_oauth_settings', $payload, ['provider' => $provider]);
        } else {
            $payload['provider'] = $provider;
            $DB->insert('glpi_plugin_appointmentmanager_oauth_settings', $payload);
        }
    }

    static function getTokenRow(int $users_id, string $provider): ?array {
        global $DB;

        if (!$DB->tableExists('glpi_plugin_appointmentmanager_oauth_tokens')) {
            return null;
        }

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_oauth_tokens',
            'WHERE' => ['users_id' => $users_id, 'provider' => $provider],
            'LIMIT' => 1,
        ]);
        return $iter->count() > 0 ? $iter->current() : null;
    }

    static function saveToken(int $users_id, string $provider, string $access_token, string $refresh_token, int $expires_in): void {
        global $DB;

        $expires_at = date('Y-m-d H:i:s', time() + $expires_in - 60);
        $now        = date('Y-m-d H:i:s');

        $existing = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_oauth_tokens',
            'WHERE' => ['users_id' => $users_id, 'provider' => $provider],
            'LIMIT' => 1,
        ]);

        if ($existing->count() > 0) {
            $DB->update('glpi_plugin_appointmentmanager_oauth_tokens', [
                'access_token'  => $access_token,
                'refresh_token' => $refresh_token ?: $existing->current()['refresh_token'],
                'expires_at'    => $expires_at,
                'date_mod'      => $now,
            ], ['users_id' => $users_id, 'provider' => $provider]);
        } else {
            $DB->insert('glpi_plugin_appointmentmanager_oauth_tokens', [
                'users_id'      => $users_id,
                'provider'      => $provider,
                'access_token'  => $access_token,
                'refresh_token' => $refresh_token,
                'expires_at'    => $expires_at,
                'date_mod'      => $now,
            ]);
        }
    }

    static function deleteToken(int $users_id, string $provider): void {
        global $DB;
        $DB->delete('glpi_plugin_appointmentmanager_oauth_tokens', [
            'users_id' => $users_id,
            'provider' => $provider,
        ]);
    }

    // Returns a fresh access token for this user, refreshing if expired. Returns null if no token or refresh fails.
    static function getValidToken(int $users_id, string $provider): ?string {
        $row = self::getTokenRow($users_id, $provider);
        if (!$row) {
            return null;
        }

        // Still valid
        if (strtotime($row['expires_at']) > time()) {
            return $row['access_token'];
        }

        // Need refresh
        if (empty($row['refresh_token'])) {
            return null;
        }

        $instance = self::getProviderInstance($provider);
        if (!$instance) {
            return null;
        }

        try {
            $refreshed = $instance->refreshAccessToken($row['refresh_token']);
            if (empty($refreshed['access_token'])) {
                return null;
            }
            self::saveToken(
                $users_id,
                $provider,
                $refreshed['access_token'],
                $refreshed['refresh_token'] ?? $row['refresh_token'],
                (int)($refreshed['expires_in'] ?? 3600)
            );
            return $refreshed['access_token'];
        } catch (Throwable $e) {
            error_log('[appointmentmanager] Token refresh failed for user ' . $users_id . ' / ' . $provider . ': ' . $e->getMessage());
            return null;
        }
    }

    // ── HTTP helper ───────────────────────────────────────────────────────────

    protected function httpRequest(string $method, string $url, array $headers = [], string $body = ''): array {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('cURL request failed for ' . $url);
        }
        return ['status' => $status, 'body' => $response];
    }

    protected function jsonRequest(string $method, string $url, array $headers = [], ?array $payload = null): array {
        $body = $payload !== null ? json_encode($payload) : '';
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $result = $this->httpRequest($method, $url, $headers, $body);
        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response from ' . $url . ' (HTTP ' . $result['status'] . '): ' . $result['body']);
        }
        return ['status' => $result['status'], 'data' => $decoded];
    }
}
