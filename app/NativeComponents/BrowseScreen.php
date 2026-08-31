<?php

namespace App\NativeComponents;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Minimal in-app browser. This shell has no `Browser.Open` bridge function
 * (it ships with the premium plugins), so external pages — walk-up
 * registration, mainly — render in a webview screen instead.
 */
class BrowseScreen extends NativeComponent
{
    public string $url = '';

    public string $title = '';

    public function mount(): void
    {
        // navigate() payload arrives as data(); params are route segments.
        $this->url = (string) $this->data('url', '');
        $this->title = (string) $this->data('title', 'Browser');

        if (! str_starts_with($this->url, 'http')) {
            $this->back();
        }
    }

    public function navTitle(): string
    {
        return $this->title;
    }

    public function render(): View
    {
        return view('native.browse-screen');
    }
}
