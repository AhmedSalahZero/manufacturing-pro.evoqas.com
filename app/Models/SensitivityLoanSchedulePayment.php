<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Loan repayment schedule lines for the what-if (sensitivity) case.
// Table: sensitivity_loan_schedule_payments
class SensitivityLoanSchedulePayment extends Model
{
    protected $table = 'sensitivity_loan_schedule_payments';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'accured_interest' => 'array',
        ];
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
