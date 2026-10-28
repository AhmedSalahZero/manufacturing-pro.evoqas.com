<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\HasSeasonality;

// Products of a project: sales targets, prices, collection policy, inventory and the calculated statements.
// Table: products
class Product extends Model
{
    use HasSeasonality;

    protected $table = 'products';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'collection_policy_value'                     => 'array',
            'collection_statement'                        => 'array',
            'export_collection_statement'                 => 'array',
            'local_collection_statement'                  => 'array',
            'max_capacity'                                => 'array',
            'target_percentages'                          => 'array',
            'target_quantities'                           => 'array',
            'local_target_quantities'                     => 'array',
            'local_growth_rates'                          => 'array',
            'local_price_per_unit'                        => 'array',
            'sensitivity_price_rate'                      => 'decimal:2',
            'sales_target_values'                         => 'array',
            'fg_beginning_inventory_breakdowns'           => 'array',
            'product_inventory_value_statement'           => 'array',
            'product_overheads_statement'                 => 'array',
            'raw_material_statement'                      => 'array',
            'product_overheads_allocation'                => 'array',
            'product_depreciation_allocation'             => 'array',
            'product_depreciation_statement'              => 'array',
            'product_manpower_statement'                  => 'array',
            'product_manpower_allocation'                 => 'array',
            'product_inventory_qt_statement'              => 'array',
            'fg_inventory_quantity'                       => 'decimal:5',
            'vat_rate'                                    => 'decimal:4',
            'withhold_tax_rate'                           => 'decimal:4',
            'monthly_sales_target_values'                 => 'array',
            'monthly_sales_target_quantities'             => 'array',
            'sensitivity_monthly_sales_target_quantities' => 'array',
            'sensitivity_monthly_sales_target_values'     => 'array',
            'product_raw_material_consumed'               => 'array',
            'local_target_quantity_percentages'           => 'array',
            'export_target_quantity_percentages'          => 'array',
            'export_growth_rates'                         => 'array',
            'export_price_per_unit'                       => 'array',
            'export_target_quantities'                    => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function rawMaterials(): BelongsToMany
    {
        return $this->belongsToMany(RawMaterial::class, 'product_raw_material', 'product_id', 'raw_material_id')
            ->withPivot(['percentages', 'project_id'])
            ->withTimestamps();
    }

    public function expenseAllocations(): HasMany
    {
        return $this->hasMany(ProductExpenseAllocation::class, 'product_id');
    }
}
