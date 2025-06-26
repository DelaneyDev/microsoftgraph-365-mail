<?php

namespace LLoadout\Microsoftgraph\Traits;

use Microsoft\Graph\Graph;
use Illuminate\Support\Facades\Crypt;
use App\Models\MicrosoftGraphAccessToken;

trait Connect
{
    /**
     * The underlying Graph client.
     *
     * @var \Microsoft\Graph\Graph|null
     */
    private $connection;

    /**
     * Retrieve the single stored access token from the database.
     *
     * @return string
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    protected function getAccessToken(): string
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

    /**
     * Instantiate (or re-use) a Graph client with a valid token.
     *
     * @return Graph
     */
    private function connect(): Graph
    {
        if (blank($this->connection)) {
            $this->connection = (new Graph())
                ->setAccessToken($this->getAccessToken());
        }

        return $this->connection;
    }

    /**
     * Shortcut for GET requests.
     */
    protected function get($url, $headers = [], $returns = null)
    {
        return $this->call('GET', $url, [], $headers, $returns);
    }

    /**
     * Shortcut for POST requests.
     */
    protected function post($url, $data, $headers = [], $returns = null)
    {
        return $this->call('POST', $url, $data, $headers, $returns);
    }

    /**
     * Shortcut for PATCH requests.
     */
    protected function patch($url, $data, $headers = [], $returns = null)
    {
        return $this->call('PATCH', $url, $data, $headers, $returns);
    }

    /**
     * Shortcut for DELETE requests.
     */
    protected function delete($url, $headers = [], $returns = null)
    {
        return $this->call('DELETE', $url, [], $headers, $returns);
    }

    /**
     * Make the actual Graph HTTP call.
     *
     * @param  string  $method    HTTP verb
     * @param  string  $url       Graph endpoint (e.g. '/me/sendMail')
     * @param  array   $data      Body payload
     * @param  array   $headers   HTTP headers
     * @param  mixed   $returns   Optional return type for the Graph SDK
     * @return mixed
     */
    private function call($method, $url, $data = [], $headers = [], $returns = null)
    {
        $response = $this->connect()
            ->createRequest($method, $url)
            ->addHeaders($headers)
            ->attachBody($data)
            ->setReturnType($returns)
            ->execute();

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
