<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// Raw materials of a project: prices, payment terms, inventory and calculated statements.
// Table: raw_materials
class RawMaterial extends Model
{
    protected $table = 'raw_materials';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'collection_policy_value'   => 'array',
            'collection_statement'      => 'array',
            'credit_withhold_statement' => 'array',
            'beginning_inventory_value' => 'decimal:2',
            'vat_rate'                  => 'decimal:4',
            'withhold_tax_rate'         => 'decimal:4',
            'inventory_value_statement' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_raw_material', 'raw_material_id', 'product_id')
            ->withPivot(['percentages', 'project_id'])
            ->withTimestamps();
    }
}
