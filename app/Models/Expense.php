<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// All expense lines (fixed, per unit, % of revenue, one-time) with their calculated monthly amounts, VAT and withholding tax.
// Table: expenses
class Expense extends Model
{
    protected $table = 'expenses';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'products'                    => 'array',
            'payment_amounts'             => 'array',
            'collection_statements'       => 'array',
            'amount'                      => 'decimal:0',
            'withhold_amounts'            => 'array',
            'vat_amounts'                 => 'array',
            'total_vat'                   => 'array',
            'total_after_vat'             => 'array',
            'withhold_payments'           => 'array',
            'net_payments_after_withhold' => 'array',
            'product_revenues'            => 'array',
            'product_allocations'         => 'array',
            'monthly_product_allocations' => 'array',
            'prepaid_expense_statement'   => 'array',
            'monthly_repeating_amounts'   => 'array',
            'expense_as_percentages'      => 'array',
            'payload'                     => 'array',
            'custom_collection_policy'    => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
