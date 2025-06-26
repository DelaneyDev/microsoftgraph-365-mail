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
        // Because you're in single-user mode, there's exactly one record.
        $token = MicrosoftGraphAccessToken::firstOrFail();

        return Crypt::decrypt($token->access_token);
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
