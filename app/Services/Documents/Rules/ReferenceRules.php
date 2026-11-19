<?php

namespace App\Services\Documents\Rules;

/**
 * Buffalo Valley "Reference Verification and Check" (REV 6/18/2025):
 * "Only One Family Reference Allowed; One Reference Known for Minimum of Five (5) years".
 */
class ReferenceRules implements FormRules
{
    public function check(array $answers): array
    {
        $errors = [];
        $refs = [1, 2, 3];

        $family = array_filter($refs, fn ($n) => ($answers["ref{$n}_relationship"] ?? null) === 'Family');
        if (count($family) > 1) {
            $errors['ref'.end($family).'_relationship'] = 'Only one family member may be listed as a reference.';
        }

        $longKnown = array_filter($refs, fn ($n) => (int) ($answers["ref{$n}_years"] ?? 0) >= 5);
        if ($longKnown === []) {
            $errors['ref1_years'] = 'At least one reference must have known you for five (5) years or more.';
        }

        return $errors;
    }
}
