<?php

namespace Tests\Feature;

use Tests\TestCase;

class RiskClassificationTest extends TestCase
{
    private function classify(
        int $detSeverity,
        bool $detClean,
        int $complexity,
        int $mlConfidence
    ): array {
        if ($detSeverity >= 80) {
            return ['high', max($detSeverity, $mlConfidence)];
        }

        if ($detClean && $complexity <= 6 && $mlConfidence <= 30) {
            return ['low', 25];
        }

        if ($detClean && ($complexity >= 7 || $mlConfidence >= 40)) {
            return ['medium', max(45, min(65, max($complexity * 5, $mlConfidence)))];
        }

        return ['medium', 50];
    }

    public function test_high_risk_is_always_high()
    {
        [$risk, $prob] = $this->classify(
            detSeverity: 95,
            detClean: false,
            complexity: 3,
            mlConfidence: 20
        );

        $this->assertEquals('high', $risk);
        $this->assertGreaterThanOrEqual(80, $prob);
    }

    public function test_low_risk_clean_simple_code()
    {
        [$risk, $prob] = $this->classify(
            detSeverity: 0,
            detClean: true,
            complexity: 4,
            mlConfidence: 20
        );

        $this->assertEquals('low', $risk);
        $this->assertLessThanOrEqual(30, $prob);
    }

    public function test_medium_risk_clean_but_complex_code()
    {
        [$risk, $prob] = $this->classify(
            detSeverity: 0,
            detClean: true,
            complexity: 12,
            mlConfidence: 35
        );

        $this->assertEquals('medium', $risk);
        $this->assertGreaterThanOrEqual(45, $prob);
        $this->assertLessThanOrEqual(65, $prob);
    }

    public function test_medium_risk_from_ml_uncertainty()
    {
        [$risk, $prob] = $this->classify(
            detSeverity: 0,
            detClean: true,
            complexity: 5,
            mlConfidence: 55
        );

        $this->assertEquals('medium', $risk);
    }

    public function test_medium_is_not_accidentally_low()
    {
        [$risk] = $this->classify(
            detSeverity: 0,
            detClean: true,
            complexity: 9,
            mlConfidence: 25
        );

        $this->assertNotEquals('low', $risk);
    }
}
