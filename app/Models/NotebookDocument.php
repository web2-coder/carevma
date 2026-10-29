<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class NotebookDocument extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'notes',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
    ];

    protected $appends = ['file_url'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getFileUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->file_path);
    }
}
