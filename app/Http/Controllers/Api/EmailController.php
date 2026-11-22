<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\NoIssuableDomains;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmailResource;
use App\Services\MailboxDomainCatalog;
use App\Services\MailboxSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class EmailController extends Controller
{
    public function __construct(private readonly MailboxSession $mailboxes) {}

    public function show(Request $request): JsonResponse
    {
        return (new EmailResource($this->mailboxes->getOrCreate($request)))->response()->setStatusCode(200);
    }

    public function store(Request $request, MailboxDomainCatalog $catalog): EmailResource|JsonResponse
    {
        try {
            return new EmailResource($this->mailboxes->rotate($request));
        } catch (ValidationException $error) {
            return response()->json([
                'message' => $error->getMessage(), 'errors' => $error->errors(), 'options' => $catalog->options(),
            ], 422);
        } catch (NoIssuableDomains $error) {
            return response()->json([
                'code' => 'no_issuable_domains', 'message' => $error->getMessage(), 'options' => $catalog->options(),
            ], 503);
        }
    }

    public function options(MailboxDomainCatalog $catalog): JsonResponse
    {
        return response()->json(['data' => $catalog->options()]);
    }

    public function destroy(Request $request): Response
    {
        $this->mailboxes->close($request);

        return response()->noContent();
    }
}
