<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Fixed assets: cost, depreciation, funding by equity and loan, and calculated statements.
// Table: fixed_assets
class FixedAsset extends Model
{
    protected $table = 'fixed_assets';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount'                                      => 'decimal:2',
            'custom_collection_policy'                    => 'array',
            'from_total_or_executions'                    => 'array',
            'admin_depreciation_percentage'               => 'decimal:2',
            'manufacturing_depreciation_percentage'       => 'decimal:2',
            'equity_funding_rate'                         => 'decimal:2',
            'interest_rate'                               => 'decimal:2',
            'product_allocations'                         => 'array',
            'monthly_product_allocations'                 => 'array',
            'replacement_cost_rate'                       => 'decimal:2',
            'depreciation_statement'                      => 'array',
            'capitalization_statement'                    => 'array',
            'admin_depreciations'                         => 'array',
            'ffe_equity_payment'                          => 'array',
            'ffe_loan_withdrawal'                         => 'array',
            'income_statement_loan_capitalized_interests' => 'array',
            'loan_capitalized_interests'                  => 'array',
            'ffe_loan_withdrawal_end_balance'             => 'array',
            'ffe_payment'                                 => 'array',
            'statement'                                   => 'array',
            'ffe_execution_and_payment'                   => 'array',
            'ffe_payable'                                 => 'array',
            'reservation_rate'                            => 'decimal:2',
            'contractual_rate'                            => 'decimal:2',
            'installment_grace_period'                    => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function loanSchedulePayments(): HasMany
    {
        return $this->hasMany(LoanSchedulePayment::class, 'fixed_asset_id');
    }

    public function sensitivityLoanSchedulePayments(): HasMany
    {
        return $this->hasMany(SensitivityLoanSchedulePayment::class, 'fixed_asset_id');
    }
}
