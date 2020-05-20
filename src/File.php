<?php

namespace GraphQL;

/**
 * Represents a file to be uploaded to GraphQL via the GraphQL multipart upload spec
 *
 * @package GraphQL
 */
class File
{
    public $gql_type;
    public $contents;
    public $filename;

    public function __construct(string $gql_type, string $contents, string $filename)
    {
        $this->gql_type = $gql_type;
        $this->contents = $contents;
        $this->filename = $filename;
    }
}
