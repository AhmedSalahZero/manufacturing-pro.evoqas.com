<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

// The study / business plan: name, dates, duration, tax and return rates, manpower settings. Everything else hangs on a project.
// Table: projects
class Project extends Model
{
    use SoftDeletes;

    protected $table = 'projects';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_completed'                       => 'boolean',
            'operation_dates'                    => 'array',
            'extended_operation_dates'           => 'array',
            'study_dates'                        => 'array',
            'extended_study_dates'               => 'array',
            'new_company'                        => 'boolean',
            'start_date'                         => 'date',
            // A position in the study months (0 = the study's first month), like every product's selling start.
            'operation_start_date'               => 'integer',
            'end_date'                           => 'date',
            'extended_end_date'                  => 'date',
            'selling_start_date'                 => 'date',
            'perpetual_growth_rate'              => 'decimal:2',
            'return_rate'                        => 'decimal:2',
            'tax_rate'                           => 'decimal:2',
            'product_first_selling_date'         => 'date',
            'product_second_selling_date'        => 'date',
            'product_third_selling_date'         => 'date',
            'product_fourth_selling_date'        => 'date',
            'product_fifth_selling_date'         => 'date',
            'manpower_allocations'               => 'array',
            'monthly_manpower_allocations'       => 'array',
            'manpower_is_as_revenue_percentages' => 'array',
            'salaries_annual_increase'           => 'decimal:2',
            'salaries_tax_rate'                  => 'decimal:2',
            'social_insurance_rate'              => 'decimal:2',
            'vat_statements'                     => 'array',
            'corporate_taxes_statement'          => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(ProjectShare::class);
    }

    /**
     * What this person may do with this study: 'owner', 'admin' (company admin), 'edit', 'view' or null (nothing).
     * Only people of the study's own company can have any access.
     */
    public function accessFor(User $user): ?string
    {
        if ($user->isSuperAdmin() || $user->company_id === null || (int) $user->company_id !== (int) $this->company_id) {
            return null;
        }

        if ((int) $user->id === (int) $this->user_id) {
            return 'owner';
        }

        if ($user->isCompanyAdmin()) {
            return 'admin';
        }

        $share = $this->shares->firstWhere('user_id', $user->id);

        return $share ? ($share->can_edit ? 'edit' : 'view') : null;
    }

    public function businessSector(): BelongsTo
    {
        return $this->belongsTo(BusinessSector::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'project_id');
    }

    public function rawMaterials(): HasMany
    {
        return $this->hasMany(RawMaterial::class, 'project_id');
    }

    public function manpowers(): HasMany
    {
        return $this->hasMany(Manpower::class, 'project_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'project_id');
    }

    public function fixedAssets(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'project_id');
    }

    public function fixedAssetOpeningBalances(): HasMany
    {
        return $this->hasMany(FixedAssetOpeningBalance::class, 'project_id');
    }

    public function cashAndBankOpeningBalances(): HasMany
    {
        return $this->hasMany(CashAndBankOpeningBalance::class, 'project_id');
    }

    public function otherDebtorsOpeningBalances(): HasMany
    {
        return $this->hasMany(OtherDebtorsOpeningBalance::class, 'project_id');
    }

    public function supplierPayableOpeningBalances(): HasMany
    {
        return $this->hasMany(SupplierPayableOpeningBalance::class, 'project_id');
    }

    public function otherCreditsOpeningBalances(): HasMany
    {
        return $this->hasMany(OtherCreditsOpeningBalance::class, 'project_id');
    }

    public function otherLongTermLiabilitiesOpeningBalances(): HasMany
    {
        return $this->hasMany(OtherLongTermLiabilitiesOpeningBalance::class, 'project_id');
    }

    public function equityOpeningBalances(): HasMany
    {
        return $this->hasMany(EquityOpeningBalance::class, 'project_id');
    }

    public function vatAndCreditWithholdTaxOpeningBalances(): HasMany
    {
        return $this->hasMany(VatAndCreditWithholdTaxOpeningBalance::class, 'project_id');
    }

    public function longTermLoanOpeningBalances(): HasMany
    {
        return $this->hasMany(LongTermLoanOpeningBalance::class, 'project_id');
    }

    public function productExpenseAllocations(): HasMany
    {
        return $this->hasMany(ProductExpenseAllocation::class, 'project_id');
    }

    public function seasonalities(): HasMany
    {
        return $this->hasMany(Seasonality::class, 'project_id');
    }

    public function loanSchedulePayments(): HasMany
    {
        return $this->hasMany(LoanSchedulePayment::class, 'project_id');
    }

    public function sensitivityLoanSchedulePayments(): HasMany
    {
        return $this->hasMany(SensitivityLoanSchedulePayment::class, 'project_id');
    }

    public function sharingLinks(): HasMany
    {
        return $this->hasMany(SharingLink::class, 'project_id');
    }

    public function sensitivity(): HasOne
    {
        return $this->hasOne(Sensitivity::class, 'project_id');
    }

    public function incomeStatement(): HasOne
    {
        return $this->hasOne(IncomeStatement::class, 'project_id');
    }

    public function balanceSheet(): HasOne
    {
        return $this->hasOne(BalanceSheet::class, 'project_id');
    }

    public function cashInOutStatement(): HasOne
    {
        return $this->hasOne(CashInOutStatement::class, 'project_id');
    }

    /** The first operation month as "Y-m-d", worked out from its position in the study months (null until the study is saved). */
    public function operationStartDate(): ?string
    {
        $index = $this->operation_start_date;
        $date = ($this->extended_study_dates ?? [])[$index] ?? null;

        return $index === null ? null : $date;
    }
}
