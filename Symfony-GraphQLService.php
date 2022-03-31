<?php

declare(strict_types=1);

namespace App\Core\Integration;

use GraphQL\Client;
use GuzzleHttp\RequestOptions;
use Psr\Http\Client\ClientInterface;

abstract class GraphQLService
{
    protected int $timeout;
    protected int $connectTimeout;

    protected string $url;
    protected string $username;
    protected string $password;

    protected ?ClientInterface $httpClient;

    public function __construct(
        string $url,
        string $user,
        string $password,
        int $timeout,
        int $connectTimeout,
        ?ClientInterface $httpClient = null
    ) {
        $this->url = $url;
        $this->username = $user;
        $this->password = $password;

        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;

        $this->httpClient = $httpClient;
    }

    protected function getData($query, $vars = [])
    {
        return $this->makeGraphQlRequest(
            $query,
            '',
            ['Authorization' => 'Basic '.base64_encode("{$this->username}:{$this->password}")],
            [],
            $vars
        );
    }

    final protected function makeGraphQlRequest(
        $query,
        string $requestUri,
        array $headers = [],
        array $options = [],
        array $vars = []
    ): array {
        $client = $this->createClient($this->url.$requestUri, $headers, $options);

        $response = $client->runQuery($query, true, $vars);

        return $response->getResults();
    }

    protected function createClient(string $requestUrl, array $headers = [], array $options = [])
    {
        return new Client(
            $requestUrl,
            $headers,
            array_merge([
                RequestOptions::TIMEOUT => $this->timeout,
                RequestOptions::CONNECT_TIMEOUT => $this->connectTimeout,
            ], $options),
            $this->httpClient
        );
    }
}
