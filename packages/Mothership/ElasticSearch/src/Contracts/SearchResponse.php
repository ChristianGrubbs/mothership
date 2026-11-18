<?php

namespace Mothership\ElasticSearch\Contracts;

interface SearchResponse
{
    /**
     * @return mixed
     */
    public function getResponseData();
}
