<?php

namespace App\Http\Controllers;

use App\Events\MailboxAccessRevoked;
use App\Exceptions\NoIssuableDomains;
use App\Http\Responses\PanelResponse;
use App\Models\Email;
use App\Models\Message;
use App\Models\User;
use App\Services\AttachmentDownload;
use App\Services\EmailGeneratorService;
use App\Services\MailboxDomainCatalog;
use App\Services\MailboxIssuance;
use App\Services\MailboxNotifications;
use App\Services\MailDashboard;
use App\Services\SitePresence;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PanelController extends Controller
{
    public function __construct(
        private readonly EmailGeneratorService $generator,
        private readonly MailboxDomainCatalog $domains,
    ) {}

    public function index(Request $request, MailDashboard $dashboard): View|JsonResponse
    {
        $user = $request->user();
        if (! $user->is_admin || $request->has('recipient')) {
            return $this->archive($request);
        }
        if ($request->has('issued')) {
            return $this->mailboxes($request);
        }

        return PanelResponse::screen($request, 'dashboard', fn () => $dashboard->overview());
    }

    public function activeUsers(): JsonResponse
    {
        return response()->json(['activeUsers' => SitePresence::activeUsers()]);
    }

    public function archive(Request $request): View|JsonResponse
    {
        return PanelResponse::screen($request, 'archive', fn () => $this->inboxData($request));
    }

    private function inboxData(Request $request): array
    {
        $user = $request->user();
        $recipient = $request->validate(['recipient' => ['nullable', 'string', 'max:254']])['recipient'] ?? '';
        $query = $this->messages($user);
        $listQuery = [];
        if ($user->is_admin && $recipient !== '') {
            $query->where('recipient', Str::lower($recipient));
            $listQuery['recipient'] = $recipient;
        }
        $summary = (clone $query)->selectRaw('count(*) as total, min(received_at) as received_at')->first();
        $messages = $query->latest('received_at')->latest('id')
            ->paginate(25, ['id', 'recipient', 'from', 'subject', 'received_at'], total: (int) $summary->total)
            ->through(fn (Message $letter) => $letter->panelSummary())
            ->withPath(route($user->is_admin ? 'panel.archive' : 'panel.index', absolute: false))
            ->appends($listQuery);

        if ($messages->currentPage() > 1) {
            $listQuery['page'] = $messages->currentPage();
        }

        return [
            'serverTime' => now()->toISOString(),
            'nextExpiryAt' => $summary->received_at ? Message::expiresAt($summary->received_at)->toISOString() : null,
            'messages' => $this->page($messages),
            'recipient' => $recipient,
            'listQuery' => (object) $listQuery,
        ];
    }

    public function mailboxes(Request $request): View|JsonResponse
    {
        return PanelResponse::screen($request, 'mailboxes', fn () => $this->mailboxesData($request));
    }

    public function temporaryMailboxes(Request $request): View|JsonResponse
    {
        return PanelResponse::screen($request, 'temporary-mailboxes', function () {
            $query = Email::active()->whereNull('owner_user_id')->whereNotNull('expires_at');
            $summary = (clone $query)->selectRaw('COUNT(*) AS total, MIN(expires_at) AS expires_at')->first();
            $mailboxes = $query->select(['id', 'email', 'created_at', 'expires_at'])
                ->withCount(['messages' => fn (Builder $query) => $query->retained()])
                ->latest('id')->paginate(12, total: (int) $summary->total)
                ->withPath(route('panel.temporary-mailboxes', absolute: false));

            return [
                'serverTime' => now()->toISOString(),
                'nextExpiryAt' => $summary->expires_at?->toISOString(),
                'mailboxes' => $this->page($mailboxes),
            ];
        });
    }

    private function mailboxesData(Request $request): array
    {
        $issued = $request->validate(['issued' => ['nullable', 'integer', 'min:1']])['issued'] ?? null;
        $mailboxes = Email::whereNotNull('owner_user_id')->active()->latest('id')
            ->paginate(12, ['id', 'email', 'created_at'])
            ->withPath(route('panel.mailboxes', absolute: false));

        return [
            'mailboxes' => $this->page($mailboxes),
            'domains' => $this->domainOptions(),
            'request_id' => (string) Str::uuid(),
            'credentials' => $issued && ! $request->isMethod('HEAD') ? $this->readCredentials($request, (int) $issued) : null,
        ];
    }

    private function domainOptions(): array
    {
        return array_map(fn (array $option) => $option + [
            'limit' => $this->generator->localPartLimit($option['domain']),
        ], $this->domains->options(includeReceiveOnly: true));
    }

    private function page(LengthAwarePaginator $page): array
    {
        return [
            'data' => $page->items(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'prev_page_url' => $page->previousPageUrl(),
            'next_page_url' => $page->nextPageUrl(),
            'total' => $page->total(),
        ];
    }

    public function createMailbox(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'string', 'uuid'],
            'domain' => ['sometimes', 'required', 'string', 'max:252'],
            'format' => ['sometimes', 'required', 'string', 'in:random,words'],
            'local_part' => ['nullable', 'string', 'max:64', 'regex:/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/'],
        ], [
            'request_id.*' => 'Обновите страницу, чтобы начать новую выдачу.',
            'local_part.max' => 'Максимальная длина имени — 64 символа.',
            'local_part.regex' => 'Используйте строчные латинские буквы и цифры. Внутри имени допустимы точка, дефис и подчёркивание.',
            'domain.*' => 'Выберите доступный почтовый домен.',
            'format.*' => 'Выберите один из доступных форматов имени.',
        ]);
        $ticket = strtolower($data['request_id']);
        try {
            $id = DB::transaction(fn () => MailboxIssuance::issue(
                $request, $data, fn (string $password) => $this->newMailbox($data, $password),
            ));
        } catch (NoIssuableDomains|ValidationException $exception) {
            $error = $exception instanceof NoIssuableDomains
                ? ValidationException::withMessages(['domain' => 'Нет подключённых почтовых доменов.']) : $exception;
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $error->getMessage(), 'errors' => $error->errors(), 'domains' => $this->domainOptions(),
                ], 422);
            }
            throw $error;
        }

        return PanelResponse::redirect($request, route('panel.mailboxes', ['issued' => $id, 'ticket' => $ticket], false));
    }

    private function newMailbox(array $data, string $password): Email
    {
        $localPart = $data['local_part'] ?? null;
        $format = $localPart === null ? ($data['format'] ?? 'random') : 'random';
        $domain = $this->domains->forIssuance(
            $data['domain'] ?? null, $format, $localPart === null ? 1 : strlen($localPart), includeReceiveOnly: true,
        );
        $addresses = $localPart === null ? $this->generator->candidates($domain->name, $format) : [$localPart.'@'.$domain->name];
        foreach ($addresses as $address) {
            try {
                return DB::transaction(function () use ($address, $password) {
                    $owner = User::create(['name' => $address, 'email' => $address, 'password' => $password]);

                    return $owner->mailbox()->create(['email' => $address]);
                });
            } catch (UniqueConstraintViolationException) {
                if ($localPart !== null) {
                    throw ValidationException::withMessages(['local_part' => 'Этот адрес уже занят.']);
                }
            }
        }

        throw ValidationException::withMessages(['local_part' => 'Не удалось подобрать свободный адрес. Укажите имя вручную или повторите попытку.']);
    }

    public function resetMailboxPassword(Request $request, int $mailbox): JsonResponse|RedirectResponse
    {
        $email = Email::query()->active()->whereNotNull('owner_user_id')->whereNull('expires_at')->findOrFail($mailbox);
        $password = Str::random(32);
        DB::transaction(function () use ($email, $password) {
            $owner = User::where('is_admin', false)->lockForUpdate()->findOrFail($email->owner_user_id);
            $owner->password = $password;
            $owner->setRememberToken(Str::random(60));
            $owner->save();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table'))->where('user_id', $owner->id)->delete();
            }
            MailboxNotifications::publish(new MailboxAccessRevoked($email->id));
        });

        return $this->issued($request, $email, $password);
    }

    private function issued(Request $request, Email $mailbox, string $password): JsonResponse|RedirectResponse
    {
        $ticket = Str::random(24);
        $request->session()->cache()->put(
            'issued-'.$request->user()->id.'-'.$mailbox->id,
            Crypt::encrypt(['email' => $mailbox->email, 'password' => $password, 'ticket' => $ticket]),
            now()->addMinutes(10),
        );

        return PanelResponse::redirect($request, route('panel.mailboxes', ['issued' => $mailbox->id, 'ticket' => $ticket], false));
    }

    private function readCredentials(Request $request, int $mailbox): ?array
    {
        $ticket = $request->query('ticket');
        if (is_string($ticket) && Str::isUuid($ticket)) {
            return MailboxIssuance::read($request, $mailbox, $ticket);
        }

        $cache = $request->session()->cache();
        $key = 'issued-'.$request->user()->id.'-'.$mailbox;
        $encrypted = $cache->get($key);
        if (! $encrypted) {
            return null;
        }
        $receipt = Crypt::decrypt($encrypted);
        if (($receipt['ticket'] ?? null) !== $request->query('ticket')) {
            return null;
        }
        $cache->forget($key);
        $hash = User::whereEmail($receipt['email'])->value('password');
        if (! $hash || ! Hash::check($receipt['password'], $hash)) {
            return null;
        }

        return ['email' => $receipt['email'], 'password' => $receipt['password']];
    }

    public function show(Request $request, int $message): View|JsonResponse
    {
        $user = $request->user();
        $letter = $this->messages($user)->findOrFail($message, $request->wantsJson() ? ['*'] : ['id']);

        return PanelResponse::screen($request, 'message', fn () => $this->messageData($request, $letter));
    }

    private function messageData(Request $request, Message $letter): array
    {
        $user = $request->user();
        $overview = $user->is_admin && $request->query('from') === 'overview';
        $activeSection = $user->is_admin && ! $overview ? 'panel.archive' : 'panel.index';
        $parameters = [];
        $recipient = $request->query('recipient');
        if ($user->is_admin && ! $overview && is_string($recipient) && $recipient !== '' && strlen($recipient) <= 254) {
            $parameters['recipient'] = $recipient;
        }
        if (! $overview && $request->integer('page') > 1) {
            $parameters['page'] = $request->integer('page');
        }
        $attachments = [];
        foreach ($letter->attachments ?? [] as $index => $file) {
            $attachments[] = ['filename' => $file['filename'], 'url' => route('panel.attachment', [$letter->id, $index], false)];
        }

        return [
            'serverTime' => now()->toISOString(),
            'letter' => $letter->panelSummary() + [
                'body_text' => $letter->readableBody(),
                'attachments' => $attachments,
            ],
            'backUrl' => route($activeSection, $parameters, false),
        ];
    }

    public function attachment(Request $request, int $message, int $index, AttachmentDownload $download): Response
    {
        return $download->forMessage($request, $this->messages($request->user())->findOrFail($message), $index);
    }

    private function messages(User $user): Builder
    {
        $query = Message::retained();
        if (! $user->is_admin) {
            $query->whereIn('email_id', $user->mailbox()->active()->select('id'));
        }

        return $query;
    }
}
