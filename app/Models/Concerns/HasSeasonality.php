<?php

namespace App\Models\Concerns;

use App\Models\Seasonality;
use Illuminate\Database\Eloquent\Relations\HasOne;

// Anything that can have a monthly sales pattern (today: a product).
// The pattern is one row in `seasonality`, found by the owner's id and class name (e.g. "Product").
trait HasSeasonality
{
    public function seasonality(): HasOne
    {
        return $this->hasOne(Seasonality::class, 'model_id', 'id')->where('model_name', class_basename($this));
    }
}
