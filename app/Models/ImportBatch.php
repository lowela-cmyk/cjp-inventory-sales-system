<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $batch_uuid
 * @property string $original_filename
 * @property string $stored_path
 * @property string $file_hash
 * @property string $file_type
 * @property string $status
 * @property array<string, mixed>|null $workbook_summary
 * @property array<string, mixed>|null $mapping_summary
 * @property array<string, mixed>|null $validation_summary
 * @property array<string, mixed>|null $result_summary
 * @property int|null $created_by
 * @property int|null $confirmed_by
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property string|null $failure_message
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
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
