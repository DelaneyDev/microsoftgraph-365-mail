<?php

namespace LLoadout\Microsoftgraph\Traits;

use Microsoft\Graph\Graph;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use App\Models\MicrosoftGraphAccessToken;

trait Connect
{
    /**
     * The underlying Graph client.
     *
     * @var \Microsoft\Graph\Graph|null
     */
    private ?Graph $connection = null;

    /**
     * Retrieve (and refresh if needed) the single stored access token.
     */
    protected function getAccessToken(): string
    {
        // Pull the one token record
        $record = MicrosoftGraphAccessToken::firstOrFail();

        // If expired (or about to), refresh it
        if (Carbon::now()->gte($record->expires_at)) {
            $data = $this->refreshTokenFromApi(Crypt::decrypt($record->refresh_token));

            $record->update([
                'access_token'  => Crypt::encrypt($data['access_token']),
                'refresh_token' => Crypt::encrypt($data['refresh_token'] ?? Crypt::decrypt($record->refresh_token)),
                // subtract 30 seconds as a safety buffer
                'expires_at'    => Carbon::now()->addSeconds($data['expires_in'] - 30),
            ]);
        }

        return Crypt::decrypt($record->access_token);
    }

    /**
     * Exchange a refresh token for a new access token payload.
     */
    private function refreshTokenFromApi(string $refreshToken): array
    {
        $response = Http::asForm()->post(
            'https://login.microsoftonline.com/' . config('services.microsoft.tenant') . '/oauth2/v2.0/token',
            [
                'client_id'     => config('services.microsoft.client_id'),
                'client_secret' => config('services.microsoft.client_secret'),
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
                'scope'         => 'https://graph.microsoft.com/.default offline_access',
            ]
        );

        if (! $response->successful()) {
            throw new \RuntimeException('Microsoft Graph token refresh failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Instantiate (or re-use) a Graph client with a valid token.
     */
    private function connect(): Graph
    {
        if (is_null($this->connection)) {
            $this->connection = (new Graph())
                ->setAccessToken($this->getAccessToken());
        }

        return $this->connection;
    }

    /**
     * Shortcut for GET requests.
     */
    protected function get(string $url, array $headers = [], $returns = null)
    {
        return $this->call('GET', $url, [], $headers, $returns);
    }

    /**
     * Shortcut for POST requests.
     */
    protected function post(string $url, array $data, array $headers = [], $returns = null)
    {
        return $this->call('POST', $url, $data, $headers, $returns);
    }

    /**
     * Shortcut for PATCH requests.
     */
    protected function patch(string $url, array $data, array $headers = [], $returns = null)
    {
        return $this->call('PATCH', $url, $data, $headers, $returns);
    }

    /**
     * Shortcut for DELETE requests.
     */
    protected function delete(string $url, array $headers = [], $returns = null)
    {
        return $this->call('DELETE', $url, [], $headers, $returns);
    }

    /**
     * Make the actual Graph HTTP call.
     */
    private function call(string $method, string $url, array $data = [], array $headers = [], $returns = null)
    {
        $response = $this->connect()
            ->createRequest($method, $url)
            ->addHeaders($headers)
            ->attachBody($data)
            ->setReturnType($returns)
            ->execute();

        // Unwrap simple GET responses
        if (blank($returns) && strtolower($method) === 'get') {
            $body = $response->getBody();
            return $body['value'] ?? $body;
        }

        return $response;
    }

    /**
     * Helper to fetch the /me endpoint.
     */
    public function getMe()
    {
        return $this->get('/me/');
    }
}
