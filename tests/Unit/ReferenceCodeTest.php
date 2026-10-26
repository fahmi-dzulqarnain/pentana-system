<?php

namespace Tests\Unit;

use App\Support\ReferenceCode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ReferenceCodeTest extends TestCase
{
    public function test_code_is_prefix_padded_id_and_luhn_digit(): void
    {
        $this->assertSame('PTN01420', ReferenceCode::for(142));
        $this->assertSame('PTN00018', ReferenceCode::for(1));
        $this->expectException(InvalidArgumentException::class);
        ReferenceCode::for(10000);
    }

    public function test_every_single_digit_typo_and_adjacent_swap_is_invalid(): void
    {
        foreach ([1, 142, 907, 9999] as $id) {
            $digits = substr(ReferenceCode::for($id), 3);
            for ($i = 0; $i < 5; $i++) {
                foreach (range(0, 9) as $d) {
                    if ((string) $d === $digits[$i]) {
                        continue;
                    }
                    $typo = substr_replace($digits, (string) $d, $i, 1);
                    $this->assertFalse(ReferenceCode::isValid('PTN'.$typo), "PTN$typo");
                }
                // Luhn's one blind spot: swapping adjacent 0 and 9 keeps the sum, so that pair is excluded.
                if ($i < 4 && $digits[$i] !== $digits[$i + 1] && ! in_array($digits[$i].$digits[$i + 1], ['09', '90'], true)) {
                    $swap = $digits;
                    [$swap[$i], $swap[$i + 1]] = [$swap[$i + 1], $swap[$i]];
                    $this->assertFalse(ReferenceCode::isValid('PTN'.$swap), "PTN$swap");
                }
            }
        }
    }

    public function test_extracts_the_user_id_from_free_text(): void
    {
        $this->assertSame(142, ReferenceCode::extractUserId('CLUB FEE ptn 0142 0 sept'));
        $this->assertSame(142, ReferenceCode::extractUserId('PTN01420 2024'));
        $this->assertSame(142, ReferenceCode::extractUserId('PTN01420 again PTN01420'));
        $this->assertNull(ReferenceCode::extractUserId('PTN01421'));
        $this->assertNull(ReferenceCode::extractUserId('PTN01420 PTN00018'));
        $this->assertNull(ReferenceCode::extractUserId('Pentana fee Aug Sep'));
    }
}
