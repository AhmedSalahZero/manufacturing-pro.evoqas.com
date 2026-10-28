<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Opening balance: long-term loans.
// Table: long_term_loan_opening_balances
class LongTermLoanOpeningBalance extends Model
{
    protected $table = 'long_term_loan_opening_balances';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount'       => 'decimal:2',
            'interests'    => 'array',
            'installments' => 'array',
            'statement'    => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
