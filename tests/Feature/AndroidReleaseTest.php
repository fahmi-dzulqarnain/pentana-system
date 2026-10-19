<?php

namespace Tests\Feature;

use App\Filament\Resources\AndroidReleases\Pages\CreateAndroidRelease;
use App\Filament\Resources\AndroidReleases\Pages\ListAndroidReleases;
use App\Models\AndroidRelease;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AndroidReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(AndroidRelease::DISK);
    }

    private function publish(string $versionName, int $versionCode, string $contents = 'apk-bytes'): AndroidRelease
    {
        $path = "android/$versionName.apk";
        Storage::disk(AndroidRelease::DISK)->put($path, $contents);

        return AndroidRelease::create([
            'version_name' => $versionName,
            'version_code' => $versionCode,
            'apk_path' => $path,
            'apk_size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ]);
    }

    public function test_download_is_not_found_before_any_release(): void
    {
        $this->get(route('android.download'))->assertNotFound();
        $this->getJson('/api/v1/android/latest')->assertNotFound();
    }

    public function test_download_serves_the_highest_version_code_as_an_apk(): void
    {
        $this->publish('1.0.2', 3, 'newest');
        $this->publish('1.0.1', 2, 'older');

        $response = $this->get(route('android.download'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.android.package-archive');
        $response->assertDownload('pentana-1.0.2.apk');
        $this->assertSame('newest', $response->streamedContent());
    }

    public function test_latest_endpoint_describes_the_current_release_without_signing_in(): void
    {
        $release = $this->publish('1.1.0', 5, 'apk');

        $this->getJson('/api/v1/android/latest')
            ->assertOk()
            ->assertJson([
                'version_name' => '1.1.0',
                'version_code' => 5,
                'size' => 3,
                'sha256' => $release->sha256,
                'download_url' => route('android.download'),
            ]);
    }

    public function test_landing_page_offers_the_apk_once_a_release_exists(): void
    {
        $this->get('/')->assertSee('Coming soon to Android')->assertDontSee(route('android.download'), false);

        $this->publish('1.0.0', 1);

        $this->get('/')
            ->assertSee('href="'.route('android.download').'"', false)
            ->assertSee('Android 1.0.0')
            ->assertDontSee('Coming soon to Android');
    }

    public function test_install_guide_walks_through_every_step(): void
    {
        $this->get(route('android.install'))
            ->assertOk()
            ->assertSeeInOrder(['Download the app', 'Open the file', 'Allow installs from your browser', 'Install', 'Open and sign in'])
            ->assertSee('The Android app is not available to download yet.')
            ->assertDontSee(route('android.download'), false);
    }

    public function test_install_guide_offers_the_current_release_and_its_checksum(): void
    {
        $release = $this->publish('1.0.1', 2, 'apk');

        $this->get(route('android.install'))
            ->assertSee('href="'.route('android.download').'"', false)
            ->assertSee('Version 1.0.1')
            ->assertSee($release->sha256);
    }

    public function test_landing_page_links_to_the_install_guide_once_a_release_exists(): void
    {
        $this->get('/')->assertDontSee(route('android.install'), false);

        $this->publish('1.0.0', 1);

        $this->get('/')->assertSee('href="'.route('android.install').'"', false);
    }

    public function test_install_guide_is_translated(): void
    {
        config(['app.locales' => 'en,ms,id']);

        $this->withUnencryptedCookie('locale', 'ms')->get(route('android.install'))
            ->assertSee('Pasang PENTANA di Android');
    }

    public function test_admin_upload_records_size_and_checksum(): void
    {
        $this->actingAs(User::factory()->admin()->create(['activated_at' => now()]));
        Filament::setCurrentPanel(Filament::getPanel('account'));
        $apk = UploadedFile::fake()->createWithContent('pentana-release.apk', 'signed-apk-contents');

        Livewire::test(CreateAndroidRelease::class)
            ->fillForm([
                'apk_path' => $apk,
                'version_name' => '1.0.0',
                'version_code' => 1,
                'notes' => 'First Android build',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $release = AndroidRelease::sole();
        $this->assertSame(strlen('signed-apk-contents'), $release->apk_size);
        $this->assertSame(hash('sha256', 'signed-apk-contents'), $release->sha256);
        Storage::disk(AndroidRelease::DISK)->assertExists($release->apk_path);
    }

    public function test_upload_rejects_other_files_and_versions_that_do_not_go_up(): void
    {
        $this->actingAs(User::factory()->admin()->create(['activated_at' => now()]));
        Filament::setCurrentPanel(Filament::getPanel('account'));
        $this->publish('1.0.1', 2);

        Livewire::test(CreateAndroidRelease::class)
            ->fillForm([
                'apk_path' => UploadedFile::fake()->createWithContent('photo.png', 'not-an-apk'),
                'version_name' => '1.0.0',
                'version_code' => 2,
            ])
            ->call('create')
            ->assertHasFormErrors(['apk_path', 'version_code']);
    }

    public function test_deleting_a_release_removes_its_file(): void
    {
        $release = $this->publish('1.0.0', 1);

        $release->delete();

        Storage::disk(AndroidRelease::DISK)->assertMissing($release->apk_path);
    }

    public function test_only_admins_can_manage_releases(): void
    {
        $this->actingAs(User::factory()->create(['activated_at' => now()]));
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(ListAndroidReleases::class)->assertForbidden();
    }
}
