<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class AttachmentDownload
{
    public function forMessage(Request $request, Message $message, int $index): Response
    {
        $file = $message->attachments[$index] ?? null;
        $disk = Storage::disk('local');
        abort_unless($file && $disk->exists($file['path']), 404);

        $response = $disk->download($file['path'], AttachmentFilename::normalize($file['filename']), [
            'Content-Type' => 'application/octet-stream',
            'Accept-Ranges' => 'bytes',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
        if ($request->headers->has('Range')) {
            $range = (new BinaryFileResponse($disk->path($file['path']), 200, $response->headers->all(), false))->prepare($request);
            if ($range->getStatusCode() !== 200) {
                return $range;
            }
        }
        if ($request->isMethod('HEAD')) {
            return $response;
        }
        return MailboxActionLimit::run($request, 'attachment', fn () => $response, $message->id.'/'.$index);
    }
}
