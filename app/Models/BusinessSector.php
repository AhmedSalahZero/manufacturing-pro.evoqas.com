<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// List of industry sectors (Arabic / English names).
// Table: business_sectors
class BusinessSector extends Model
{
    protected $table = 'business_sectors';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'business_sector_id');
    }
}
