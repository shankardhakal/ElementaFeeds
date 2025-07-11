<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConnectionCleanupRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'connection_id',
        'type',
        'status',
        'products_found',
        'products_processed',
        'products_failed',
        'dry_run',
        'started_at',
        'completed_at',
        'error_summary',
    ];

    protected $casts = [
        'products_found' => 'integer',
        'products_processed' => 'integer',
        'products_failed' => 'integer',
        'dry_run' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function connection()
    {
        return $this->belongsTo(FeedWebsite::class, 'connection_id');
    }

    /**
     * Get the duration of the cleanup run in minutes
     */
    public function getDurationAttribute(): ?float
    {
        if (!$this->started_at || !$this->completed_at) {
            return null;
        }

        return $this->started_at->diffInMinutes($this->completed_at);
    }

    /**
     * Get the success rate of the cleanup run
     */
    public function getSuccessRateAttribute(): ?float
    {
        if ($this->products_found === 0) {
            return null;
        }

        return ($this->products_processed / $this->products_found) * 100;
    }

    /**
     * Check if the cleanup run is currently running
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Check if the cleanup run can be cancelled
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, ['pending', 'running']);
    }

    /**
     * Check if the cleanup run has completed (successfully or failed)
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled']);
    }
}
