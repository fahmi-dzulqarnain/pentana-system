<?php

namespace Tests\Feature\Localisation;

use App\Support\Locales;
use Tests\TestCase;

class LocalesTest extends TestCase
{
    public function test_defaults_to_english_only(): void
    {
        config(['app.locales' => 'en']);

        $this->assertSame(['en'], Locales::enabled());
        $this->assertFalse(Locales::multipleEnabled());
    }

    public function test_parses_list_keeps_english_first_and_drops_unknown_codes(): void
    {
        config(['app.locales' => ' id, xx ,ms']);

        $this->assertSame(['en', 'id', 'ms'], Locales::enabled());
        $this->assertTrue(Locales::multipleEnabled());
    }

    public function test_is_enabled_checks_membership_and_handles_null(): void
    {
        config(['app.locales' => 'en,ms']);

        $this->assertTrue(Locales::isEnabled('ms'));
        $this->assertFalse(Locales::isEnabled('id'));
        $this->assertFalse(Locales::isEnabled('my'));
        $this->assertFalse(Locales::isEnabled(null));
        $this->assertFalse(Locales::isEnabled(''));
    }

    public function test_names_and_labels(): void
    {
        $this->assertSame('Bahasa Melayu', Locales::nativeName('ms'));
        $this->assertSame('Bahasa Indonesia', Locales::nativeName('id'));
        $this->assertSame('English', Locales::nativeName('en'));
        $this->assertSame('BM', Locales::shortLabel('ms'));
        $this->assertSame('ID', Locales::shortLabel('id'));
        $this->assertSame('EN', Locales::shortLabel('en'));
    }
}
