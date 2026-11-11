<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// An export market of a study: a country and the foreign currency it pays in.
// Table: export_markets
class ExportMarket extends Model
{
    protected $table = 'export_markets';

    protected $guarded = ['id'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(ProductSegment::class, 'export_market_id');
    }
}
