<?php

namespace LLoadout\Microsoftgraph\Traits;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use LLoadout\Microsoftgraph\EventListeners\MicrosoftGraphCallbackReceived;
use LLoadout\Microsoftgraph\EventListeners\MicrosoftGraphErrorReceived;

trait Authenticate
{
    public function callback(): void
    {
        $response = Http::asForm()->post(
            'https://login.microsoftonline.com/' . config('services.microsoft.tenant') . '/oauth2/v2.0/token',
            $this->getTokenFields(request('code'))
        );

        if (!$response->successful()) {
            throw new \Exception('OAuth callback failed: ' . $response->body());
        }

        $tokenData = $response->object();

        if (!isset($tokenData->access_token)) {
            throw new \Exception('Access token missing in response: ' . json_encode($tokenData));
        }

        $this->dispatchCallbackReceived($tokenData);
    }

    private function dispatchCallbackReceived($tokenData): void
    {
        $user = Http::withToken($tokenData->access_token)
            ->get('https://graph.microsoft.com/v1.0/me')
            ->object();

        MicrosoftGraphCallbackReceived::dispatch(encrypt((object)[
            'user' => $user,
            'expires_on' => $tokenData->expires_on ?? Carbon::now()->addSeconds($tokenData->expires_in)->timestamp,
            'access_token' => $tokenData->access_token,
            'refresh_token' => $tokenData->refresh_token ?? null,
        ]));
    }

    private function getAccessToken()
    {
        return $this->isSingleUserMode()
            ? $this->getSingleUserAccessToken()
            : $this->getSessionAccessToken();
    }

    private function isSingleUserMode(): bool
    {
        return config('microsoftgraph.single_user', false);
    }

    private function getSingleUserAccessToken(): string
    {
        $token = \App\Models\MicrosoftGraphAccessToken::latest()->firstOrFail();

        if (now()->greaterThan($token->expires_at)) {
            $data = $this->refreshTokenFromApi(Crypt::decrypt($token->refresh_token));
            $token->update([
                'access_token' => Crypt::encrypt($data['access_token']),
                'refresh_token' => Crypt::encrypt($data['refresh_token'] ?? Crypt::decrypt($token->refresh_token)),
                'expires_at' => now()->addSeconds($data['expires_in']),
            ]);
        }

        return Crypt::decrypt($token->access_token);
    }

    private function getSessionAccessToken(): string
    {
        if (!session()->has('microsoftgraph-access-data')) {
            throw new \Exception('Please create a session variable named microsoftgraph-access-data with your access data as value');
        }

        $accessData = decrypt(session('microsoftgraph-access-data'));

        if (!isset($accessData->access_token)) {
            throw new \Exception('Your access data is invalid, please reconnect');
        }

        if (Carbon::createFromTimestamp($accessData->expires_on)->lte(Carbon::now())) {
            $this->refreshAccessToken($accessData->refresh_token);
            $accessData = decrypt(session('microsoftgraph-access-data'));
        }

        return $accessData->access_token;
    }

    private function refreshAccessToken($refreshToken): void
    {
        $response = Http::asForm()->post(
            'https://login.microsoftonline.com/' . config('services.microsoft.tenant') . '/oauth2/v2.0/token',
            $this->getRefreshFields($refreshToken)
        );

        if (!$response->successful()) {
            throw new \Exception('Token refresh failed (v2.0): ' . $response->body());
        }

        $tokenData = $response->object();

        if (!isset($tokenData->access_token)) {
            throw new \Exception('Access token missing in refresh response: ' . json_encode($tokenData));
        }

        $this->dispatchCallbackReceived($tokenData);
    }

    private function refreshTokenFromApi(string $refreshToken): array
    {
        $response = Http::asForm()->post(
            'https://login.microsoftonline.com/' . config('services.microsoft.tenant') . '/oauth2/v2.0/token',
            [
                'client_id' => config('services.microsoft.client_id'),
                'client_secret' => config('services.microsoft.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'scope' => 'https://graph.microsoft.com/.default offline_access',
            ]
        );

        throw_if(!$response->successful(), new \Exception('Token refresh failed: ' . $response->body()));

        return $response->json();
    }

    private function getBaseFields()
    {
        return [
            'client_id' => config('services.microsoft.client_id'),
            'client_secret' => config('services.microsoft.client_secret'),
            'redirect_uri' => config('services.microsoft.redirect'),
        ];
    }

    protected function getRefreshFields($refresh)
    {
        return array_merge($this->getBaseFields(), [
            'grant_type' => 'refresh_token',
            'scope' => 'openid profile offline_access',
            'refresh_token' => $refresh,
        ]);
    }

    protected function getTokenFields($code = null)
    {
        return array_merge($this->getBaseFields(), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'scope' => 'openid profile offline_access https://graph.microsoft.com/User.Read',
        ]);
    }
}
