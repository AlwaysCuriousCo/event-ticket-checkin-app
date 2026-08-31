<?php

namespace App\NativeLayouts;

use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;

/**
 * The app's persistent bottom navigation: Events / Scan / Profile.
 *
 * Scan sits in the middle as the door-staff default — it opens the
 * any-event scanner, so nobody has to pick an event first.
 *
 * Screens supply their own `<native:top-bar>`, which wins over any nav bar
 * this layout could declare, so only the tab bar is defined here. Pushed
 * detail screens hide the bar with `$hidesTabBar` / `tabBarOptions()`;
 * `TabBar::highlight()` keeps the owning tab lit via longest-prefix match
 * (`/events/12` highlights Events).
 */
class AppLayout extends NativeLayout
{
    public function usesNativeChrome(): bool
    {
        return true;
    }

    public function tabBar(NativeComponent $screen): ?TabBar
    {
        return TabBar::make()
            ->add(Tab::link('Events', '/events', icon: 'calendar'))
            ->add(Tab::link('Scan', '/scan', icon: 'qrcode'))
            ->add(Tab::link('Profile', '/settings', icon: 'profile'));
    }
}
