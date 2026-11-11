<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// A local sales channel / customer segment of a study (Retail, Wholesale, Distributors ...).
// Table: sales_channels
class SalesChannel extends Model
{
    protected $table = 'sales_channels';

    protected $guarded = ['id'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(ProductSegment::class, 'sales_channel_id');
    }
}
