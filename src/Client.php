<?php

namespace GraphQL;

use GraphQL\Exception\QueryError;
use GraphQL\Exception\MethodNotSupportedException;
use GraphQL\QueryBuilder\QueryBuilderInterface;
use GraphQL\Util\GuzzleAdapter;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientInterface;
use TypeError;

/**
 * Class Client
 *
 * @package GraphQL
 */
class Client
{
    /**
     * @var string
     */
    protected $endpointUrl;

    /**
     * @var ClientInterface
     */
    protected $httpClient;

    /**
     * @var array
     */
    protected $httpHeaders;

    /**
     * @var string
     */
    protected $requestMethod;

    /**
     * Client constructor.
     *
     * @param string $endpointUrl
     * @param array $authorizationHeaders
     * @param array $httpOptions
     * @param ClientInterface $httpClient
     * @param string $requestMethod
     */
    public function __construct(
        string $endpointUrl,
        array $authorizationHeaders = [],
        array $httpOptions = [],
        ClientInterface $httpClient = null,
        string $requestMethod = 'POST'
    ) {
        $headers = array_merge(
            $authorizationHeaders,
            $httpOptions['headers'] ?? [],
            ['Content-Type' => 'application/json']
        );

        /**
         * All headers will be set on the request objects explicitly,
         * Guzzle doesn't have to care about them at this point, so to avoid any conflicts
         * we are removing the headers from the options
         */
        unset($httpOptions['headers']);

        $this->endpointUrl          = $endpointUrl;
        $this->httpClient           = $httpClient ?? new GuzzleAdapter(new \GuzzleHttp\Client($httpOptions));
        $this->httpHeaders          = $headers;
        if ($requestMethod !== 'POST') {
            throw new MethodNotSupportedException($requestMethod);
        }
        $this->requestMethod        = $requestMethod;
    }

    /**
     * @param Query|QueryBuilderInterface $query
     * @param bool                        $resultsAsArray
     * @param array                       $variables
     *
     * @return Results
     * @throws QueryError
     */
    public function runQuery($query, bool $resultsAsArray = false, array $variables = []): Results
    {
        if ($query instanceof QueryBuilderInterface) {
            $query = $query->getQuery();
        }

        if (!$query instanceof Query) {
            throw new TypeError('Client::runQuery accepts the first argument of type Query or QueryBuilderInterface');
        }

        return $this->runRawQuery((string) $query, $resultsAsArray, $variables, $query->getFiles());
    }

    /**
     * @param string $queryString
     * @param bool   $resultsAsArray
     * @param array  $variables
     * @param File[] $files
     *
     * @return Results
     * @throws QueryError
     */
    public function runRawQuery(string $queryString, $resultsAsArray = false, array $variables = [], array $files = []): Results
    {
        $request = new Request($this->requestMethod, $this->endpointUrl);

        foreach($this->httpHeaders as $header => $value) {
            $request = $request->withHeader($header, $value);
        }

        // Convert empty variables array to empty json object
        if (empty($variables)) $variables = (object) null;

        $bodyArray = ['query' => (string) $queryString, 'variables' => $variables];
        $body = json_encode($bodyArray);

        if (empty($files)) {
            // Set query in the request body
            $request = $request->withBody(Psr7\stream_for($body));
        }else {
            $formatted_files = [];
            $map = [];

            foreach ($files as $key => $file) {
                $map[$key] = ["variables.${key}"];

                $formatted_files[] = [
                    'name' => $key,
                    'contents' => $file->contents,
                    'filename' => $file->filename,
                ];
            }

            $map = json_encode((object)$map);

            // Remove the application/json content-type
            $request = $request->withoutHeader('Content-Type');
            $request = $request->withBody(new Psr7\MultipartStream(array_merge([
                [
                    'name'     => 'operations',
                    'contents' => $body,
                ],
                [
                    'name'     => 'map',
                    'contents' => $map,
                ],
            ], $formatted_files)));
        }

        // Send api request and get response
        try {
            $response = $this->httpClient->sendRequest($request);
        }
        catch (ClientException $exception) {
            $response = $exception->getResponse();

            // If exception thrown by client is "400 Bad Request ", then it can be treated as a successful API request
            // with a syntax error in the query, otherwise the exceptions will be propagated
            if ($response->getStatusCode() !== 400) {
                throw $exception;
            }
        }

        // Parse response to extract results
        return new Results($response, $resultsAsArray);
    }
}
