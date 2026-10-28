<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Opening balance: VAT and withholding-tax credit.
// Table: vat_and_credit_withhold_tax_opening_balances
class VatAndCreditWithholdTaxOpeningBalance extends Model
{
    protected $table = 'vat_and_credit_withhold_tax_opening_balances';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'vat_amount'              => 'decimal:2',
            'credit_withhold_taxes'   => 'decimal:2',
            'corporate_taxes_payable' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
