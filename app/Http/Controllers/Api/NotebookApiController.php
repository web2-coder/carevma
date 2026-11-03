<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotebookDocument;
use App\Models\User;
use App\Services\OperationalNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class NotebookApiController extends Controller
{
    public function index(Request $request)
    {
        $this->assertNotebookManager($request);

        $query = NotebookDocument::query()
            ->with([
                'user:id,name,email,role',
                'recipient:id,name,email,role',
            ]);

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhere('original_name', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                    ->orWhereHas('recipient', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            });
        }

        return response()->json(
            $query->latest('id')->paginate(min(max((int) $request->query('per_page', 10), 1), 100))
        );
    }

    public function sharedIndex(Request $request)
    {
        $user = $request->user();
        abort_unless($user && in_array($user->role, ['employee', 'client'], true), 403);

        $query = NotebookDocument::query()
            ->with('user:id,name,email,role')
            ->where('recipient_user_id', $user->id);

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhere('original_name', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
            });
        }

        return response()->json(
            $query->latest('id')->paginate(min(max((int) $request->query('per_page', 10), 1), 100))
        );
    }

    public function store(Request $request, OperationalNotifier $notifier)
    {
        $this->assertNotebookManager($request);

        $data = $request->validate([
            'recipient_user_id' => ['required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'document' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png'],
        ]);

        $recipient = User::query()->findOrFail($data['recipient_user_id']);
        if (!in_array($recipient->role, ['employee', 'client'], true)) {
            throw ValidationException::withMessages([
                'recipient_user_id' => ['Documents can only be shared with a VA / Employee or Doctor / Client account.'],
            ]);
        }

        $file = $request->file('document');
        // New shared documents are private. They are never exposed through /storage.
        $path = $file->store('notebook/shared/'.$recipient->id, 'local');

        $document = NotebookDocument::create([
            'user_id' => $request->user()->id,
            'recipient_user_id' => $recipient->id,
            'title' => $data['title'],
            'notes' => $data['notes'] ?? null,
            'file_path' => $path,
            'storage_disk' => 'local',
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize() ?: 0,
            'shared_at' => now(),
        ]);

        $recipientPath = $recipient->role === 'client' ? '/doctor/documents' : '/employee/documents';
        $notifier->send(
            $recipient,
            'Document shared with you',
            ($request->user()->name ?: 'CareVMA').' shared “'.$document->title.'” with you.',
            'SharedDocument',
            $recipientPath,
            ['document_id' => $document->id]
        );

        return response()->json([
            'message' => 'Document shared with '.$recipient->name.'.',
            'document' => $document->load(['user:id,name,email,role', 'recipient:id,name,email,role']),
        ], 201);
    }

    public function download(Request $request, NotebookDocument $document)
    {
        $user = $request->user();
        abort_unless($user, 401);

        $allowed = in_array($user->role, ['admin', 'superadmin', 'hr'], true)
            || (int) $document->recipient_user_id === (int) $user->id
            || (int) $document->user_id === (int) $user->id;
        abort_unless($allowed, 403, 'This document was not shared with your account.');

        $disk = $document->storage_disk ?: 'public';
        abort_unless(Storage::disk($disk)->exists($document->file_path), 404, 'Document file not found.');

        return Storage::disk($disk)->download(
            $document->file_path,
            $document->original_name ?: basename($document->file_path),
            array_filter(['Content-Type' => $document->mime_type ?: null])
        );
    }

    public function destroy(Request $request, NotebookDocument $document)
    {
        $this->assertNotebookManager($request);

        $disk = $document->storage_disk ?: 'public';
        if ($document->file_path) {
            Storage::disk($disk)->delete($document->file_path);
        }
        $document->delete();

        return response()->json(['message' => 'Notebook document deleted.']);
    }

    private function assertNotebookManager(Request $request): void
    {
        abort_unless(
            $request->user() && in_array($request->user()->role, ['admin', 'superadmin', 'hr'], true),
            403,
            'Notebook is available only to Admin and HR.'
        );
    }
}
