<?php

namespace App\Support\Digibee\Testing;

/**
 * §3.4's three test-matrix categories, plus the one the spec folds into the
 * others and shouldn't: an exception TRACK is not a branch of a `choice`.
 *
 * The distinction is not taxonomy for its own sake — it is about who can run
 * the case. A Contract case is derived and runnable unattended; a
 * BranchCoverage or ErrorHandler case usually needs a downstream system to
 * behave a certain way, so it is reported as coverage somebody still owes
 * rather than silently emitted as a payload that exercises the happy path
 * again (see PipelineTestCase::$blocked).
 */
enum TestCaseCategory: string
{
    case HappyPath = 'happy_path';
    case BranchCoverage = 'branch_coverage';
    case ErrorHandler = 'error_handler';
    case Contract = 'contract';

    public function label(): string
    {
        return match ($this) {
            self::HappyPath      => 'Caminho feliz',
            self::BranchCoverage => 'Cobertura de branch',
            self::ErrorHandler   => 'Tratamento de erro',
            self::Contract       => 'Contrato de entrada',
        };
    }
}
