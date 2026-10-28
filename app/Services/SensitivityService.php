<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Project;
use App\Models\Sensitivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — SensitivityService ("what if …?")
//  Location: app/Services/SensitivityService.php
//
//  Three settings per product (the first five products of the study, in the order they were added):
//    · sales target  ± %     every yearly quantity (local and export) is multiplied by (1 + %)
//    · raw material  ± %     every "% of selling price" of the product's recipe is multiplied by (1 + %)
//    · collection    + days  added to every "collected after … days" of the product's local and export collection terms
//  The settings are saved in `sensitivities` (one row per study). To see their effect the WHOLE calculation is run again
//  with the settings applied, inside a database transaction that is ALWAYS rolled back: the study itself never changes,
//  and the scenario uses exactly the same engines as the real numbers (nothing is copied or approximated).
// ══════════════════════════════════════════════════════════════════

class SensitivityService
{
    public const SLOTS = ['first', 'second', 'third', 'fourth', 'fifth'];

    public function __construct(
        private readonly SalesCalculationService $calculations,
        private readonly DashboardService $dashboards,
    ) {
    }

    /** The first five products of the study, each with the saved settings. @return list<array{id:int,name:string,slot:string,target:float,rm:float,days:int}> */
    public function rows(Project $project): array
    {
        $saved = Sensitivity::where('project_id', $project->id)->first();
        $rows = [];

        foreach (Product::where('project_id', $project->id)->orderBy('id')->limit(count(self::SLOTS))->get() as $i => $product) {
            $slot = self::SLOTS[$i];
            $rows[] = [
                'id'     => $product->id,
                'name'   => $product->name,
                'slot'   => $slot,
                'target' => (float) ($saved?->{"target_product_$slot"} ?? 0),
                'rm'     => (float) ($saved?->{"rm_product_$slot"} ?? 0),
                'days'   => (int) ($saved?->{"collections_product_$slot"} ?? 0),
            ];
        }

        return $rows;
    }

    /** @param list<array{slot:string,target:float|int,rm:float|int,days:int}> $rows */
    public function save(Project $project, User $user, array $rows): void
    {
        $values = ['user_id' => $user->id];
        foreach (self::SLOTS as $slot) {
            $values["target_product_$slot"] = 0;
            $values["rm_product_$slot"] = 0;
            $values["collections_product_$slot"] = 0;
        }
        foreach ($rows as $row) {
            $values["target_product_{$row['slot']}"] = $row['target'];
            $values["rm_product_{$row['slot']}"] = $row['rm'];
            $values["collections_product_{$row['slot']}"] = $row['days'];
        }

        Sensitivity::updateOrCreate(['project_id' => $project->id], $values);
    }

    public function hasChanges(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['target'] != 0 || $row['rm'] != 0 || $row['days'] != 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The dashboard as it is now and as it would be with the settings.
     *
     * @param  list<array{id:int,target:float|int,rm:float|int,days:int}>  $rows
     * @return array{base:array<string,mixed>,scenario:array<string,mixed>}
     */
    public function compare(Project $project, array $rows): array
    {
        $timeline = $this->calculations->timeline($project);
        $base = $this->dashboards->present($this->dashboards->build($project, $timeline), $project, $timeline);

        DB::beginTransaction();

        try {
            $this->apply($project, $rows);
            $fresh = Project::findOrFail($project->id);
            $this->calculations->recalculate($fresh);
            $scenario = $this->dashboards->present($this->dashboards->build($fresh, $timeline), $fresh, $timeline);
        } finally {
            DB::rollBack();
        }

        return ['base' => $base, 'scenario' => $scenario];
    }

    // ── Applying the settings (always inside the transaction of compare()) ──

    /** @param list<array{id:int,target:float|int,rm:float|int,days:int}> $rows */
    private function apply(Project $project, array $rows): void
    {
        foreach ($rows as $row) {
            $product = Product::where('project_id', $project->id)->find($row['id']);
            if ($product === null) {
                continue;
            }

            $changes = [];
            if ($row['target'] != 0) {
                $factor = 1 + $row['target'] / 100;
                $changes['local_target_quantities'] = self::scale((array) $product->local_target_quantities, $factor);
                $changes['export_target_quantities'] = self::scale((array) $product->export_target_quantities, $factor);
            }
            if ($row['days'] != 0) {
                $changes['collection_policy_value'] = self::delay((array) $product->collection_policy_value, (int) $row['days']);
            }
            if ($changes !== []) {
                $product->update($changes);
            }

            if ($row['rm'] != 0) {
                $factor = 1 + $row['rm'] / 100;
                foreach (DB::table('product_raw_material')->where('product_id', $product->id)->get() as $pivot) {
                    $percentages = json_decode((string) $pivot->percentages, true);
                    if (is_array($percentages)) {
                        DB::table('product_raw_material')->where('id', $pivot->id)->update(['percentages' => json_encode(self::scale($percentages, $factor))]);
                    }
                }
            }
        }
    }

    /** Every number in the list (or nested lists) multiplied by the factor. */
    public static function scale(array $values, float $factor): array
    {
        return array_map(function ($value) use ($factor) {
            if (is_array($value)) {
                return self::scale($value, $factor);
            }

            return is_numeric($value) ? (float) $value * $factor : $value;
        }, $values);
    }

    /** Days added to every due date of the local and export terms (never below 0). */
    public static function delay(array $policy, int $days): array
    {
        foreach (['local', 'export'] as $kind) {
            foreach ((array) ($policy[$kind] ?? []) as $year => $terms) {
                foreach ((array) ($terms['due_in_days'] ?? []) as $n => $due) {
                    if ($due !== null && $due !== '') {
                        $policy[$kind][$year]['due_in_days'][$n] = max(0, (int) $due + $days);
                    }
                }
            }
        }

        return $policy;
    }
}
