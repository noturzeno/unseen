<?php

namespace App\Enums;

/**
 * Where a project ships. A project lists its platforms; a feature lists the ones it applies to,
 * or none to mean every platform of the project (shared code, backend, API).
 */
enum Platform: string
{
    case Ios = 'ios';
    case Android = 'android';
    case Web = 'web';

    public function label(): string
    {
        return match ($this) {
            self::Ios => 'iOS',
            self::Android => 'Android',
            self::Web => 'Web',
        };
    }

    /** How to get JUnit XML for `unseen verify` from this platform's tests. */
    public function junitHint(): string
    {
        return match ($this) {
            self::Ios => 'XCTest: pipe xcodebuild test through xcbeautify --report junit, then pass build/reports/junit.xml with --junit.',
            self::Android => 'Gradle writes JUnit XML to app/build/test-results/ (e.g. testDebugUnitTest/); pass the files with --junit.',
            self::Web => 'Run Jest, Vitest, PHPUnit or pytest with its JUnit reporter and pass the file with --junit.',
        };
    }

    /**
     * Valid values in enum order, duplicates and unknown values dropped.
     *
     * @return list<self>
     */
    public static function listFrom(?array $values): array
    {
        $values = array_map('strval', $values ?? []);

        return array_values(array_filter(self::cases(), fn (self $p) => in_array($p->value, $values, true)));
    }
}
