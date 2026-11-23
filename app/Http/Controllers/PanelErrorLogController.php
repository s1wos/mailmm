<?php

namespace App\Http\Controllers;

use App\Http\Requests\PanelErrorLogRequest;
use App\Http\Responses\PanelResponse;
use App\Services\LogCollectionHealth;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PanelErrorLogController extends Controller
{
    public function __invoke(PanelErrorLogRequest $request): View|JsonResponse
    {
        return PanelResponse::screen($request, 'errors', function () use ($request) {
            $cursor = Cursor::fromEncoded($request->validated('cursor'));
            $entries = DB::table('admin_error_logs')->orderByDesc('occurred_at')->orderByDesc('id')
                ->cursorPaginate(50, ['id', 'occurred_at', 'service', 'level', 'message'], cursor: $cursor)
                ->withPath(route('panel.errors', absolute: false));

            return ['collection' => LogCollectionHealth::snapshot(), 'entries' => [
                'data' => $entries->getCollection()->map(fn ($entry) => [
                    ...(array) $entry,
                    'occurred_at' => Carbon::parse($entry->occurred_at)->toISOString(),
                ]),
                'next_page_url' => $entries->nextPageUrl(),
                'prev_page_url' => $entries->previousPageUrl(),
            ]];
        });
    }
}
