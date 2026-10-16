<?php

namespace Tests\Feature\Filament;

use App\Filament\Concerns\HidesListBreadcrumb;
use App\Models\User;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class ListBreadcrumbsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_pages_drop_the_breadcrumb_but_detail_pages_keep_it(): void
    {
        $this->actingAs(User::factory()->admin()->create(['activated_at' => now()]));
        $member = User::factory()->create();

        $this->get('/account/users')->assertOk()->assertDontSee('fi-breadcrumbs', false);
        $this->get('/account/users/'.$member->id)->assertOk()->assertSee('fi-breadcrumbs', false);
    }

    public function test_every_list_page_uses_the_trait(): void
    {
        $missing = [];
        foreach ((new Finder)->files()->in(app_path('Filament/Resources'))->path('Pages')->name('List*.php') as $file) {
            $class = 'App\\Filament\\Resources\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (is_subclass_of($class, ListRecords::class) && ! in_array(HidesListBreadcrumb::class, class_uses_recursive($class), true)) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing);
    }
}
