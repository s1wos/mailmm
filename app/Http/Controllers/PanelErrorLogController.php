<?php

namespace App\Http\Controllers;

use App\Http\Responses\PanelResponse;
use App\Services\LogCollectionHealth;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PanelErrorLogController extends Controller
{
    public function __invoke(Request $request): View|JsonResponse
    {
        return PanelResponse::screen($request, 'errors', function () use ($request) {
            $encoded = $request->query('cursor');
            $cursor = Cursor::fromEncoded($encoded);
            if ($encoded !== null && Validator::make($cursor?->toArray() ?? [], [
                'occurred_at' => ['required', 'string', 'date'],
                'id' => ['required', 'integer:strict', 'min:1'],
                '_pointsToNextItems' => ['required', 'boolean:strict'],
            ])->fails()) {
                throw ValidationException::withMessages(['cursor' => 'Некорректная страница журнала.']);
            }

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
