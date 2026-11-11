<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// The FX forecast of one foreign currency of a study: how many units of the study currency one foreign unit is worth, per study year.
// Table: fx_rates   (rates = year index => rate; 0 = the calendar year the study starts in)
class FxRate extends Model
{
    protected $table = 'fx_rates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rates' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
