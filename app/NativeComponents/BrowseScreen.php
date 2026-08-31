<?php

namespace App\NativeComponents;

use Illuminate\Support\Facades\Cache;
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
        // On-device the payload can arrive empty (vendor drops it), so
        // senders also stash it in cache — pull that as the fallback.
        $cached = (array) Cache::pull('ticketscanner.browse', []);

        $this->url = (string) $this->data('url', $cached['url'] ?? '');
        $this->title = (string) $this->data('title', $cached['title'] ?? 'Browser');

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
