<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Opening balance: fixed assets already owned.
// Table: fixed_asset_opening_balances
class FixedAssetOpeningBalance extends Model
{
    protected $table = 'fixed_asset_opening_balances';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'gross_amount'                          => 'decimal:2',
            'accumulated_depreciation'              => 'decimal:2',
            'admin_depreciation_percentage'         => 'decimal:2',
            'manufacturing_depreciation_percentage' => 'decimal:2',
            'product_allocations'                   => 'array',
            'monthly_product_allocations'           => 'array',
            'monthly_depreciation'                  => 'decimal:2',
            'admin_depreciations'                   => 'array',
            'statement'                             => 'array',
            'manufacturing_depreciations'           => 'array',
            'monthly_accumulated_depreciations'     => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
