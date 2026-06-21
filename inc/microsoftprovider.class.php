<?php
class PluginAppointmentmanagerMicrosoftProvider extends PluginAppointmentmanagerOAuthProvider {

    const EVENTS_URL = 'https://graph.microsoft.com/v1.0/me/events';

    private function authBaseUrl(): string {
        $settings  = self::getSettings('microsoft');
        $tenant_id = $settings['tenant_id'] ?? 'common';
        return 'https://login.microsoftonline.com/' . urlencode($tenant_id) . '/oauth2/v2.0';
    }

    public function getAuthUrl(string $state): string {
        $settings     = self::getSettings('microsoft');
        $redirect_uri = $this->getRedirectUri();

        $params = http_build_query([
            'client_id'     => $settings['client_id'],
            'response_type' => 'code',
            'redirect_uri'  => $redirect_uri,
            'scope'         => 'Calendars.ReadWrite offline_access',
            'state'         => $state,
            'response_mode' => 'query',
        ]);
        return $this->authBaseUrl() . '/authorize?' . $params;
    }

    public function exchangeCode(string $code): array {
        $settings     = self::getSettings('microsoft');
        $redirect_uri = $this->getRedirectUri();

        $result = $this->httpRequest('POST', $this->authBaseUrl() . '/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'code'          => $code,
                'client_id'     => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code',
            ])
        );
        $data = json_decode($result['body'], true) ?? [];
        if (empty($data['access_token'])) {
            throw new RuntimeException('Microsoft token exchange failed: ' . $result['body']);
        }
        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? '',
            'expires_in'    => (int)($data['expires_in'] ?? 3600),
        ];
    }

    public function refreshAccessToken(string $refresh_token): array {
        $settings = self::getSettings('microsoft');

        $result = $this->httpRequest('POST', $this->authBaseUrl() . '/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'refresh_token' => $refresh_token,
                'client_id'     => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'grant_type'    => 'refresh_token',
                'scope'         => 'Calendars.ReadWrite offline_access',
            ])
        );
        $data = json_decode($result['body'], true) ?? [];
        if (empty($data['access_token'])) {
            throw new RuntimeException('Microsoft token refresh failed: ' . $result['body']);
        }
        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refresh_token,
            'expires_in'    => (int)($data['expires_in'] ?? 3600),
        ];
    }

    public function createEvent(string $access_token, array $event_data): string {
        $result = $this->jsonRequest('POST', self::EVENTS_URL,
            ['Authorization: Bearer ' . $access_token],
            $event_data
        );
        if (empty($result['data']['id'])) {
            throw new RuntimeException('Microsoft createEvent failed: ' . json_encode($result['data']));
        }
        return $result['data']['id'];
    }

    public function updateEvent(string $access_token, string $event_id, array $event_data): void {
        $this->jsonRequest('PATCH', self::EVENTS_URL . '/' . urlencode($event_id),
            ['Authorization: Bearer ' . $access_token],
            $event_data
        );
    }

    public function deleteEvent(string $access_token, string $event_id): void {
        $this->httpRequest('DELETE', self::EVENTS_URL . '/' . urlencode($event_id),
            ['Authorization: Bearer ' . $access_token]
        );
    }

    public function fetchEvents(string $access_token, string $from, string $to, string $from_raw = '', string $to_raw = ''): array {
        $url = 'https://graph.microsoft.com/v1.0/me/calendarview?' . http_build_query([
            'startDateTime' => $from_raw ?: date('Y-m-d\TH:i:s', strtotime($from)),
            'endDateTime'   => $to_raw   ?: date('Y-m-d\TH:i:s', strtotime($to)),
            '$top'          => 250,
            '$select'       => 'start,end',
        ]);
        $result = $this->httpRequest('GET', $url, [
            'Authorization: Bearer ' . $access_token,
            'Prefer: outlook.timezone="UTC"',
        ]);
        $data = json_decode($result['body'], true) ?? [];

        $slots = [];
        foreach ($data['value'] ?? [] as $item) {
            $start = $item['start']['dateTime'] ?? null;
            $end   = $item['end']['dateTime']   ?? null;
            if ($start && $end) {
                $slots[] = ['start' => $start . 'Z', 'end' => $end . 'Z'];
            }
        }
        return $slots;
    }

    private function getRedirectUri(): string {
        global $CFG_GLPI;
        $base = rtrim($CFG_GLPI['url_base'] ?? '', '/');
        return $base . '/plugins/appointmentmanager/front/oauth_callback.php?provider=microsoft';
    }
}
