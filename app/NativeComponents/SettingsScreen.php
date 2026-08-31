<?php

namespace App\NativeComponents;

use App\Models\AppSetting;
use App\Models\Site;
use App\Services\DeviceIdentity;
use App\Services\SiteCredentials;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Dialog;
use Throwable;

/**
 * The Profile tab: which sites this device is connected to, which one is
 * active, and the device name that check-ins are attributed to on the
 * server (PLAN.md Stage 6 settings screen).
 */
class SettingsScreen extends NativeComponent
{
    private const REMOVE_DIALOG_ID = 'remove-site-confirm';

    /** @var array<int, array{id: int, name: string, host: string, username: string, active: bool, pending: int}> */
    public array $sites = [];

    public string $deviceName = '';

    public string $deviceIdentity = '';

    public string $notice = '';

    /** Same as EventsIndex: large in-content heading beats the folded stub. */
    protected bool $hidesNavBar = true;

    /** Site queued for removal while the confirm dialog is up. */
    public int $removingSiteId = 0;

    public function mount(): void
    {
        $this->deviceName = (string) AppSetting::get(DeviceIdentity::SETTING_KEY, '');
        $this->loadSites();
    }

    public function onResume(): void
    {
        $this->loadSites();
    }

    /** Switch which site the Events and Scan tabs work in. */
    public function switchTo(int $siteId): void
    {
        $site = Site::find($siteId);

        if (! $site) {
            return;
        }

        $site->activate();
        $this->notice = "Now using {$site->name}.";
        $this->loadSites();
    }

    public function addSite(): void
    {
        $this->navigate('/connect');
    }

    public function saveDeviceName(): void
    {
        $name = trim($this->deviceName);

        AppSetting::put(DeviceIdentity::SETTING_KEY, $name === '' ? null : $name);

        $this->deviceIdentity = app(DeviceIdentity::class)->id();
        $this->notice = $name === ''
            ? 'Device name cleared — check-ins are attributed to the device id.'
            : "Check-ins from this device are recorded as “{$name}”.";
    }

    public function confirmRemove(int $siteId): void
    {
        $site = Site::find($siteId);

        if (! $site) {
            return;
        }

        $this->removingSiteId = $siteId;
        $pending = $site->checkinOperations()->pending()->count();

        try {
            Dialog::alert(
                "Disconnect {$site->name}?",
                $pending > 0
                    ? "{$pending} check-in(s) have not synced yet and will be lost. The site's events and attendees will be removed from this device."
                    : "The site's events and attendees will be removed from this device. Check-ins already synced stay on the site.",
                ['Cancel', 'Disconnect'],
            )->id(self::REMOVE_DIALOG_ID)->show();
        } catch (Throwable) {
            // No native dialog (dev bridge absent): fall back to acting
            // directly rather than leaving a dead button.
            $this->remove();
        }
    }

    #[On(ButtonPressed::class)]
    public function onDialogButton(int $index, string $label = '', ?string $id = null): void
    {
        if ($id === self::REMOVE_DIALOG_ID && $index === 1) {
            $this->remove();
        }
    }

    private function remove(): void
    {
        $site = Site::find($this->removingSiteId);
        $this->removingSiteId = 0;

        if (! $site) {
            return;
        }

        $name = $site->name;
        $wasActive = $site->is_active;

        app(SiteCredentials::class)->forget($site);
        $site->delete(); // events/attendees/operations cascade

        if ($wasActive) {
            // Promotes whatever site remains, or returns null when none do.
            Site::current();
        }

        $this->notice = "{$name} disconnected.";
        $this->loadSites();

        if (! Site::query()->exists()) {
            $this->replace('/connect');
        }
    }

    private function loadSites(): void
    {
        $this->deviceIdentity = app(DeviceIdentity::class)->id();

        $this->sites = Site::query()
            ->oldest('id')
            ->get()
            ->map(fn (Site $site) => [
                'id' => $site->id,
                'name' => $site->name,
                'host' => (string) parse_url($site->base_url, PHP_URL_HOST),
                'username' => $site->username,
                'active' => (bool) $site->is_active,
                'pending' => $site->checkinOperations()->pending()->count(),
            ])
            ->all();
    }

    public function navTitle(): string
    {
        return 'Profile';
    }

    public function render(): View
    {
        return view('native.settings-screen');
    }
}
