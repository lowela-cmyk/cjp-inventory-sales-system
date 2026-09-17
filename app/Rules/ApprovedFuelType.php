<?php

namespace App\Rules;

use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class ApprovedFuelType
{
    public static function rule(): Exists
    {
        return Rule::exists('fuel_types', 'id')->where(function (Builder $query): Builder {
            $approvedCodes = array_keys(config('fuels.approved', [
                'F1' => true,
                'UNL' => true,
                'DSL' => true,
                'PREM' => true,
            ]));

            return $query->where('status', 'active')
                ->whereIn('code', $approvedCodes);
        });
    }
}
