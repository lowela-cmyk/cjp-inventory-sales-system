<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportBatch extends Model
{
    protected $fillable = [
        'batch_uuid',
        'original_filename',
        'stored_path',
        'file_hash',
        'file_type',
        'status',
        'workbook_summary',
        'mapping_summary',
        'validation_summary',
        'result_summary',
        'created_by',
        'confirmed_by',
        'confirmed_at',
        'completed_at',
        'failure_message',
    ];

    protected function casts(): array
    {
        return [
            'workbook_summary' => 'array',
            'mapping_summary' => 'array',
            'validation_summary' => 'array',
            'result_summary' => 'array',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
