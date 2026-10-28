<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// How overheads, depreciation and manpower are split between products.
// Table: product_expense_allocations
class ProductExpenseAllocation extends Model
{
    protected $table = 'product_expense_allocations';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_expense'              => 'boolean',
            'is_depreciation'         => 'boolean',
            'is_opening_depreciation' => 'boolean',
            'payload'                 => 'array',
            'sensitivity_payload'     => 'array',
            'is_indirect_manpower'    => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
