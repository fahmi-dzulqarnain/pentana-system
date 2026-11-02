<?php

namespace Tests\Unit;

use App\Support\ReferenceCode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ReferenceCodeTest extends TestCase
{
    public function test_code_is_prefix_padded_id_and_damm_digit(): void
    {
        $this->assertSame('PTN01428', ReferenceCode::for(142));
        $this->assertSame('PTN00013', ReferenceCode::for(1));
        $this->expectException(InvalidArgumentException::class);
        ReferenceCode::for(10000);
    }

    public function test_a_trailing_newline_is_not_a_valid_code(): void
    {
        $this->assertTrue(ReferenceCode::isValid('PTN01428'));
        $this->assertFalse(ReferenceCode::isValid("PTN01428\n"));
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
                if ($i < 4 && $digits[$i] !== $digits[$i + 1]) {
                    $swap = $digits;
                    [$swap[$i], $swap[$i + 1]] = [$swap[$i + 1], $swap[$i]];
                    $this->assertFalse(ReferenceCode::isValid('PTN'.$swap), "PTN$swap");
                }
            }
        }
    }

    public function test_extracts_the_user_id_from_free_text(): void
    {
        $this->assertSame(142, ReferenceCode::extractUserId('CLUB FEE ptn 0142 8 sept'));
        $this->assertSame(142, ReferenceCode::extractUserId('PTN01428 2024'));
        $this->assertSame(142, ReferenceCode::extractUserId('PTN01428 again PTN01428'));
        $this->assertNull(ReferenceCode::extractUserId('PTN01421'));
        $this->assertNull(ReferenceCode::extractUserId('PTN01428 PTN00013'));
        $this->assertNull(ReferenceCode::extractUserId('Pentana fee Aug Sep'));
    }
}
