<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class NoIssuableDomains extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('Нет доменов с включённой выдачей адресов.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['code' => 'no_issuable_domains', 'message' => $this->getMessage()], 503);
    }
}
