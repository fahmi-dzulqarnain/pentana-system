<?php

namespace Tests\Feature\Nickname;

use App\Models\User;
use App\Support\Nickname;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class NicknameModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_name_falls_back_to_full_name(): void
    {
        $user = User::factory()->create(['name' => 'Fahmi Dzulqarnain', 'nickname' => null]);

        $this->assertSame('Fahmi Dzulqarnain', $user->display_name);

        $user->nickname = 'Fahmi';

        $this->assertSame('Fahmi', $user->display_name);
        $this->assertSame('Fahmi', $user->getFilamentName());
    }

    public function test_display_name_keeps_a_zero_nickname(): void
    {
        $this->assertSame('0', (new User(['name' => 'Fahmi Dzulqarnain', 'nickname' => '0']))->display_name);
    }

    public function test_normalise_trims_and_nulls_blank(): void
    {
        $this->assertSame('Fahmi', Nickname::normalise('  Fahmi '));
        $this->assertNull(Nickname::normalise('   '));
        $this->assertNull(Nickname::normalise(null));
    }

    public function test_rules_reject_case_insensitive_duplicate(): void
    {
        User::factory()->create(['nickname' => 'Fahmi']);
        $other = User::factory()->create();

        $validator = Validator::make(['nickname' => 'fahmi'], ['nickname' => Nickname::rules($other)]);

        $this->assertTrue($validator->fails());
        $this->assertSame('That nickname is already taken.', $validator->errors()->first('nickname'));
    }

    public function test_rules_reject_duplicate_with_surrounding_whitespace(): void
    {
        User::factory()->create(['nickname' => 'Fahmi']);

        $this->assertTrue(Validator::make(['nickname' => '  fahmi '], ['nickname' => Nickname::rules()])->fails());
    }

    public function test_rules_allow_own_nickname_in_new_case(): void
    {
        $user = User::factory()->create(['nickname' => 'fahmi']);

        $this->assertTrue(Validator::make(['nickname' => 'Fahmi'], ['nickname' => Nickname::rules($user)])->passes());
    }

    public function test_rules_validate_the_trimmed_length(): void
    {
        $rules = ['nickname' => Nickname::rules()];

        $this->assertTrue(Validator::make(['nickname' => str_repeat('a', 31)], $rules)->fails());
        $this->assertTrue(Validator::make(['nickname' => str_repeat('a', 30)], $rules)->passes());
        $this->assertTrue(Validator::make(['nickname' => '  '.str_repeat('a', 30).'  '], $rules)->passes());
        $this->assertTrue(Validator::make(['nickname' => '   '], $rules)->passes());
        $this->assertTrue(Validator::make(['nickname' => null], $rules)->passes());
    }

    public function test_database_index_rejects_case_insensitive_duplicate(): void
    {
        User::factory()->create(['nickname' => 'Fahmi']);
        $other = User::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        $other->forceFill(['nickname' => 'FAHMI'])->save();
    }

    public function test_length_message_uses_the_translated_attribute_name(): void
    {
        app()->setLocale('ms');
        $validator = Validator::make(['nickname' => str_repeat('a', 31)], ['nickname' => Nickname::rules()]);

        $this->assertStringContainsString('nama panggilan', $validator->errors()->first('nickname'));
    }

    public function test_validation_and_index_agree_on_non_ascii_case_pairs(): void
    {
        foreach ([['Élan', 'élan'], ['élan2', 'ÉLAN2'], ['ÄNNA', 'änna'], ['Straße', 'STRASSE']] as $i => [$first, $second]) {
            User::factory()->create(['nickname' => $first]);
            $other = User::factory()->create();

            $accepted = Validator::make(['nickname' => $second], ['nickname' => Nickname::rules($other)])->passes();

            try {
                $other->forceFill(['nickname' => $second])->save();
                $saved = true;
            } catch (UniqueConstraintViolationException) {
                $saved = false;
            }

            // Whatever the DB's lower() does with non-ASCII, validation must predict it:
            // no pair validation accepts may blow up at the index (a 500 for the member).
            $this->assertSame($saved, $accepted, "validation and index disagree for pair #{$i}");
        }
    }

    public function test_many_users_may_have_no_nickname(): void
    {
        User::factory()->count(2)->create(['nickname' => null]);

        $this->assertSame(2, User::whereNull('nickname')->count());
    }
}
