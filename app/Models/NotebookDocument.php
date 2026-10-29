<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotebookDocument extends Model
{
    protected $fillable = [
        'user_id',
        'recipient_user_id',
        'title',
        'notes',
        'file_path',
        'storage_disk',
        'original_name',
        'mime_type',
        'file_size',
        'shared_at',
    ];

    protected $casts = [
        'shared_at' => 'datetime',
    ];

    protected $appends = ['file_url'];

    /** User who uploaded/shared the document. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Exact VA or Doctor user who may open the document. */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function getFileUrlAttribute(): string
    {
        // Never expose the storage path directly. The controller performs an
        // authenticated per-user authorization check before returning bytes.
        return '/api/shared-documents/'.$this->getKey().'/download';
    }
}
