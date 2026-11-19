<?php

namespace App\Services\Documents\Rules;

/**
 * Extra rules for one form that span several fields (configured per template
 * code in config/portal.php "form_rules"). Return error messages keyed by
 * field; an empty array means the answers are acceptable.
 */
interface FormRules
{
    /**
     * @param  array<string, mixed>  $answers  validated, normalized answers
     * @return array<string, string>
     */
    public function check(array $answers): array;
}
