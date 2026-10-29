<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotebookDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class NotebookApiController extends Controller
{
    public function index(Request $request)
    {
        $query = NotebookDocument::query()->with('user:id,name,email,role');
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'superadmin', 'hr'], true)) {
            $query->where('user_id', $user->id);
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhere('original_name', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            });
        }

        return response()->json(
            $query->latest('id')->paginate(min(max((int) $request->query('per_page', 10), 1), 100))
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'document' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png'],
        ]);

        $file = $request->file('document');
        $path = $file->store('notebook/'.$request->user()->id, 'public');

        $document = NotebookDocument::create([
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'notes' => $data['notes'] ?? null,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize() ?: 0,
        ]);

        return response()->json([
            'message' => 'Notebook document uploaded successfully.',
            'document' => $document->load('user:id,name,email,role'),
        ], 201);
    }

    public function destroy(Request $request, NotebookDocument $document)
    {
        $user = $request->user();
        abort_unless(
            $document->user_id === $user->id || in_array($user->role, ['admin', 'superadmin'], true),
            403
        );

        if ($document->file_path) {
            Storage::disk('public')->delete($document->file_path);
        }
        $document->delete();

        return response()->json(['message' => 'Notebook document deleted.']);
    }
}
