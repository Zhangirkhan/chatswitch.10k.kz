<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\VectorCosine;
use PHPUnit\Framework\TestCase;

final class VectorCosineTest extends TestCase
{
    public function test_identical_vectors_have_similarity_one(): void
    {
        $vector = [1.0, 2.0, 3.0];

        $this->assertEqualsWithDelta(1.0, VectorCosine::similarity($vector, $vector), 0.0001);
    }

    public function test_orthogonal_vectors_have_zero_similarity(): void
    {
        $this->assertEqualsWithDelta(0.0, VectorCosine::similarity([1.0, 0.0], [0.0, 1.0]), 0.0001);
    }

    public function test_mismatched_lengths_return_zero(): void
    {
        $this->assertSame(0.0, VectorCosine::similarity([1.0], [1.0, 0.0]));
    }
}
