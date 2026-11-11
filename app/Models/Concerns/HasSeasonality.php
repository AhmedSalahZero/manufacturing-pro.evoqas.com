<?php

namespace App\Models\Concerns;

use App\Models\Seasonality;
use Illuminate\Database\Eloquent\Relations\HasOne;

// Anything that can have monthly sales patterns (today: a product).
// A pattern is one row in `seasonality`, found by the owner's id and class name (e.g. "Product") and its scope:
// `local` (the pattern of every sales channel) or `export` (the pattern of every export market).
trait HasSeasonality
{
    /** The local pattern (rows saved before the scope existed are local). */
    public function seasonality(): HasOne
    {
        return $this->hasOne(Seasonality::class, 'model_id', 'id')->where('model_name', class_basename($this))->where('scope', Seasonality::LOCAL);
    }

    public function exportSeasonality(): HasOne
    {
        return $this->hasOne(Seasonality::class, 'model_id', 'id')->where('model_name', class_basename($this))->where('scope', Seasonality::EXPORT);
    }
}
