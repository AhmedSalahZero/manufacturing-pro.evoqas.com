<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// The business tables exist with exactly the agreed columns. Run:  php artisan test --filter=DatabaseStructureTest
class DatabaseStructureTest extends TestCase
{
    use RefreshDatabase;

    /** Every business table and its columns, checked against the structure of the previous ZAVERO database. */
    private function expected(): array
    {
        return [
        'business_sectors' => 'id,name_ar,name_en,created_at,updated_at',
        'projects' => 'id,is_completed,operation_dates,extended_operation_dates,study_dates,extended_study_dates,user_id,name,slug,new_company,start_date,operation_start_date,duration,end_date,extended_end_date,selling_start_date,business_sector_id,perpetual_growth_rate,return_rate,tax_rate,product_first,product_first_selling_date,product_second,product_second_selling_date,product_third,product_third_selling_date,product_fourth,product_fourth_selling_date,product_fifth,product_fifth_selling_date,created_at,updated_at,deleted_at,manpower_allocations,monthly_manpower_allocations,manpower_is_as_revenue_percentages,salaries_annual_increase,salaries_tax_rate,social_insurance_rate,comment_for_raw_materials,vat_statements,corporate_taxes_statement,intro_paragraph,dashboard_comment_1,dashboard_comment_2,main_functional_currency,company_id',
        'sensitivities' => 'id,user_id,project_id,target_product_first,target_product_second,target_product_third,target_product_fourth,target_product_fifth,rm_product_first,rm_product_second,rm_product_third,rm_product_fourth,rm_product_fifth,collections_product_first,collections_product_second,collections_product_third,collections_product_fourth,collections_product_fifth,created_at,updated_at',
        'products' => 'id,collection_policy_type,collection_policy_value,collection_statement,export_collection_statement,local_collection_statement,collection_policy_interval,name,selling_start_date,max_capacity,target_percentages,target_quantities,local_target_quantities,local_growth_rates,local_price_per_unit,sensitivity_price_rate,sales_target_values,fg_inventory_value,fg_beginning_inventory_breakdowns,product_inventory_value_statement,product_overheads_statement,raw_material_statement,product_overheads_allocation,product_depreciation_allocation,product_depreciation_statement,product_manpower_statement,product_manpower_allocation,product_inventory_qt_statement,fg_inventory_quantity,fg_inventory_coverage_days,comment_for_sales,project_id,created_at,updated_at,measurement_unit,vat_rate,withhold_tax_rate,monthly_sales_target_values,monthly_sales_target_quantities,sensitivity_monthly_sales_target_quantities,sensitivity_monthly_sales_target_values,product_raw_material_consumed,local_target_quantity_percentages,export_target_quantity_percentages,export_growth_rates,export_price_per_unit,export_target_quantities',
        'raw_materials' => 'id,collection_policy_value,collection_statement,credit_withhold_statement,collection_policy_type,collection_policy_interval,beginning_inventory_value,name,rm_inventory_coverage_days,measurement_unit,project_id,created_at,updated_at,vat_rate,withhold_tax_rate,inventory_value_statement',
        'product_raw_material' => 'id,product_id,raw_material_id,percentages,project_id,created_at,updated_at',
        'manpowers' => 'id,type,position,avg_salary,existing_count,hirings,salary_expenses,tax_and_social_insurance_statement,salary_payments,accumulated_manpower_counts,project_id,created_at,updated_at',
        'balance_sheets' => 'id,supplier_payables,other_debtors,raw_material_inventory,total_fgs,customer_receivables,cash_and_banks,total_current_liabilities,total_current_assets,change_in_customer_receivables,change_in_fg_inventory,change_in_raw_material_inventory,change_in_other_debtors,change_in_supplier_payables,change_in_other_creditors,net_change_in_working_capital,debit_funding_percentages,equity_funding_percentages,project_id,created_at,updated_at',
        'cash_and_bank_opening_balances' => 'id,cash_and_bank_amount,customer_receivable_amount,inventory_amount,payload,project_id,created_at,updated_at,statement',
        'cash_in_out_statements' => 'id,cash_end_balance,working_capital_injection,equity_injection,loan_withdrawal,customer_collection,supplier_payments,taxes,expenses,fixed_asset_payments,loan_installments,total_cash_out,project_id,created_at,updated_at',
        'equity_opening_balances' => 'id,paid_up_capital_amount,legal_reserve_extended,paid_up_capital_extended,legal_reserve,retained_earnings,project_id,created_at,updated_at,statement',
        'expenses' => 'id,amortization_months,name,category_id,start_date,end_date,monthly_cost_of_unit,percentage_of,monthly_percentage,products,payment_terms,payment_amounts,collection_statements,vat_rate,is_deductible,withhold_tax_rate,increase_rate,increase_interval,amount,monthly_repeating_amounts,withhold_amounts,vat_amounts,expense_as_percentages,total_vat,total_after_vat,withhold_payments,net_payments_after_withhold,sensitivity_expense_as_percentages,payload,expense_type,relation_name,custom_collection_policy,created_at,updated_at,project_id,product_revenues,product_allocations,monthly_product_allocations,is_as_revenue_percentages,prepaid_expense_statement',
        'fixed_asset_opening_balances' => 'id,name,gross_amount,accumulated_depreciation,monthly_counts,admin_depreciation_percentage,manufacturing_depreciation_percentage,product_allocations,monthly_product_allocations,is_as_revenue_percentages,project_id,created_at,updated_at,monthly_depreciation,admin_depreciations,statement,manufacturing_depreciations,monthly_accumulated_depreciations',
        'fixed_assets' => 'id,name,counts,amount,depreciation_duration,start_date,end_date,payment_terms,custom_collection_policy,from_total_or_executions,admin_depreciation_percentage,manufacturing_depreciation_percentage,equity_funding_rate,interest_rate,tenor,installment_interval,project_id,product_allocations,monthly_product_allocations,is_as_revenue_percentages,created_at,updated_at,replacement_cost_rate,replacement_cost_interval,grace_period,depreciation_statement,capitalization_statement,admin_depreciations,ffe_equity_payment,ffe_loan_withdrawal,income_statement_loan_capitalized_interests,loan_capitalized_interests,ffe_loan_withdrawal_end_balance,ffe_payment,statement,ffe_execution_and_payment,ffe_payable,reservation_rate,contractual_rate,after_months,installment_grace_period,installment_count,payment_installment_interval',
        'income_statements' => 'id,total_sales_revenues,annually_sales_revenues_growth_rates,gross_profit,annually_gross_profit_revenue_percentages,ebitda,annually_ebitda_revenue_percentages,ebit,annually_ebit_revenue_percentages,ebt,monthly_ebt,annually_ebt_revenue_percentages,net_profit,annually_net_profit,annually_net_profit_revenue_percentages,accumulated_retained_earnings,total_depreciation,total_cogs,total_percentages_cogs,sganda,sganda_revenues_percentages,project_id,created_at,updated_at',
        'loan_schedule_payments' => 'id,fixed_asset_id,month_as_index,loan_type,beginning,interestAmount,schedulePayment,InterestPayment,principleAmount,endBalance,created_at,updated_at,project_id,accured_interest',
        'long_term_loan_opening_balances' => 'id,amount,interests,installments,project_id,created_at,updated_at,statement',
        'other_credits_opening_balances' => 'id,name,amount,payload,project_id,created_at,updated_at,statement',
        'other_debtors_opening_balances' => 'id,name,amount,payload,project_id,created_at,updated_at,statement',
        'other_long_term_liabilities_opening_balances' => 'id,amount,payload,project_id,created_at,updated_at,statement',
        'product_expense_allocations' => 'id,product_id,expense_type,expense_category,expense_name,is_expense,is_depreciation,is_opening_depreciation,payload,sensitivity_payload,project_id,created_at,updated_at,is_indirect_manpower',
        'seasonality' => 'id,model_id,model_name,type,percentages,distributed_percentages,created_at,updated_at,project_id',
        'sensitivity_loan_schedule_payments' => 'id,fixed_asset_id,month_as_index,loan_type,beginning,interestAmount,schedulePayment,InterestPayment,principleAmount,endBalance,created_at,updated_at,project_id,accured_interest',
        'sharing_links' => 'id,user_id,project_id,name,link_code,closed,open,created_at,updated_at',
        'sharing_links_visitors' => 'id,ip,link_code,created_at,updated_at',
        'supplier_payable_opening_balances' => 'id,amount,payload,project_id,created_at,updated_at,statement',
        'vat_and_credit_withhold_tax_opening_balances' => 'id,vat_amount,credit_withhold_taxes,project_id,created_at,updated_at,corporate_taxes_payable',
        ];
    }

    public function test_all_business_tables_exist(): void
    {
        $this->assertCount(27, $this->expected());

        foreach (array_keys($this->expected()) as $table) {
            $this->assertTrue(Schema::hasTable($table), "table '{$table}' is missing");
        }
    }

    public function test_every_table_has_exactly_the_agreed_columns(): void
    {
        foreach ($this->expected() as $table => $columns) {
            $want = explode(',', $columns);
            $have = Schema::getColumnListing($table);
            sort($want);
            sort($have);

            $this->assertSame($want, $have, "table '{$table}' does not have the agreed columns");
        }
    }

    public function test_the_company_tables_have_the_agreed_columns(): void
    {
        $agreed = [
            'companies'      => 'id,name_en,name_ar,status,users_limit,subscription_starts_at,subscription_ends_at,contact_phone,contact_email,notes,created_by,created_at,updated_at',
            'project_shares' => 'id,project_id,user_id,can_edit,created_at,updated_at',
            'users'          => 'id,company_id,name,email,email_verified_at,password,role,job_title,language,theme,is_active,created_by,last_login_at,remember_token,created_at,updated_at',
        ];

        foreach ($agreed as $table => $columns) {
            $want = explode(',', $columns);
            $have = Schema::getColumnListing($table);
            sort($want);
            sort($have);

            $this->assertSame($want, $have, "table '{$table}' does not have the agreed columns");
        }
    }

    public function test_a_study_can_be_shared_with_a_person_only_once(): void
    {
        $user = User::factory()->create();
        $project = DB::table('projects')->insertGetId(['user_id' => $user->id, 'company_id' => $user->company_id, 'name' => 'Plant A']);
        $other = User::factory()->create();

        DB::table('project_shares')->insert(['project_id' => $project, 'user_id' => $other->id, 'can_edit' => false]);

        $this->expectException(QueryException::class);

        DB::table('project_shares')->insert(['project_id' => $project, 'user_id' => $other->id, 'can_edit' => true]);
    }

    public function test_the_accounts_and_system_tables_are_still_there(): void
    {
        foreach (['users', 'password_reset_tokens', 'sessions', 'cache', 'jobs', 'failed_jobs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "table '{$table}' is missing");
        }
    }

    public function test_deleting_a_project_removes_what_belongs_to_it(): void
    {
        $user = User::factory()->create();
        $project = DB::table('projects')->insertGetId(['user_id' => $user->id, 'name' => 'Plant A']);
        DB::table('sharing_links')->insert(['user_id' => $user->id, 'project_id' => $project, 'name' => 'link', 'link_code' => 'abc']);

        $this->assertSame(1, DB::table('sharing_links')->count());

        DB::table('projects')->where('id', $project)->delete();

        $this->assertSame(0, DB::table('sharing_links')->count());
    }

    public function test_a_person_who_owns_projects_cannot_be_deleted_by_accident(): void
    {
        $user = User::factory()->create();
        DB::table('projects')->insert(['user_id' => $user->id, 'name' => 'Plant A']);

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $user->id)->delete();
    }

    public function test_a_project_must_belong_to_a_real_person(): void
    {
        $this->expectException(QueryException::class);

        DB::table('projects')->insert(['user_id' => 999999, 'name' => 'Orphan']);
    }
}
