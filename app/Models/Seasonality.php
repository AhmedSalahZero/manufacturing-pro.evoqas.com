<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Monthly sales pattern (flat, quarterly or monthly). The monthly split is calculated in PHP, not in the database.
// Table: seasonality
class Seasonality extends Model
{
    protected $table = 'seasonality';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'percentages'             => 'array',
            'distributed_percentages' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
