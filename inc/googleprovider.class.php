<?php
class PluginAppointmentmanagerGoogleProvider extends PluginAppointmentmanagerOAuthProvider {

    const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const EVENTS_URL = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    public function getAuthUrl(string $state): string {
        global $CFG_GLPI;

        $settings = self::getSettings('google');
        if (!$settings) {
            throw new RuntimeException('Google provider not configured');
        }

        $redirect_uri = $this->getRedirectUri();
        $params = http_build_query([
            'client_id'     => $settings['client_id'],
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/calendar.events',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);
        return self::AUTH_URL . '?' . $params;
    }

    public function exchangeCode(string $code): array {
        $settings     = self::getSettings('google');
        $redirect_uri = $this->getRedirectUri();

        $result = $this->httpRequest('POST', self::TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'],
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
            throw new RuntimeException('Google token exchange failed: ' . $result['body']);
        }
        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? '',
            'expires_in'    => (int)($data['expires_in'] ?? 3600),
        ];
    }

    public function refreshAccessToken(string $refresh_token): array {
        $settings = self::getSettings('google');

        $result = $this->httpRequest('POST', self::TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'refresh_token' => $refresh_token,
                'client_id'     => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'grant_type'    => 'refresh_token',
            ])
        );
        $data = json_decode($result['body'], true) ?? [];
        if (empty($data['access_token'])) {
            throw new RuntimeException('Google token refresh failed: ' . $result['body']);
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
            throw new RuntimeException('Google createEvent failed: ' . json_encode($result['data']));
        }
        return $result['data']['id'];
    }

    public function updateEvent(string $access_token, string $event_id, array $event_data): void {
        $result = $this->jsonRequest('PATCH', self::EVENTS_URL . '/' . urlencode($event_id),
            ['Authorization: Bearer ' . $access_token],
            $event_data
        );
        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw new RuntimeException('Google updateEvent failed (HTTP ' . $result['status'] . ')');
        }
    }

    public function deleteEvent(string $access_token, string $event_id): void {
        $result = $this->httpRequest('DELETE', self::EVENTS_URL . '/' . urlencode($event_id),
            ['Authorization: Bearer ' . $access_token]
        );
        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw new RuntimeException('Google deleteEvent failed (HTTP ' . $result['status'] . ')');
        }
    }

    public function fetchEvents(string $access_token, string $from, string $to, string $from_raw = '', string $to_raw = ''): array {
        $url = self::EVENTS_URL . '?' . http_build_query([
            'timeMin'      => $from_raw ?: date('c', strtotime($from)),
            'timeMax'      => $to_raw   ?: date('c', strtotime($to)),
            'singleEvents' => 'true',
            'orderBy'      => 'startTime',
            'maxResults'   => 250,
        ]);
        $result = $this->httpRequest('GET', $url, ['Authorization: Bearer ' . $access_token]);
        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw new RuntimeException('Google fetchEvents failed (HTTP ' . $result['status'] . ')');
        }
        $data   = json_decode($result['body'], true) ?? [];

        $slots = [];
        foreach ($data['items'] ?? [] as $item) {
            $start = $item['start']['dateTime'] ?? ($item['start']['date'] ?? null);
            $end   = $item['end']['dateTime']   ?? ($item['end']['date']   ?? null);
            if ($start && $end) {
                $slots[] = ['start' => $start, 'end' => $end];
            }
        }
        return $slots;
    }

    private function getRedirectUri(): string {
        global $CFG_GLPI;
        $base = rtrim($CFG_GLPI['url_base'] ?? '', '/');
        return $base . '/plugins/appointmentmanager/front/oauth_callback.php?provider=google';
    }
}
