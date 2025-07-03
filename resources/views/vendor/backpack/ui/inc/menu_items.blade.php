{{-- This file is used for menu items by any Backpack v6 theme --}}
<li class="nav-item"><a class="nav-link" href="{{ backpack_url('dashboard') }}"><i class="la la-home nav-icon"></i> {{ trans('backpack::base.dashboard') }}</a></li>
<x-backpack::menu-item title="Networks" icon="la la-network-wired" :link="backpack_url('network')" />
<x-backpack::menu-item title="Feeds" icon="la la-rss" :link="backpack_url('feed')" />
<x-backpack::menu-item title="Websites" icon="la la-desktop" :link="backpack_url('website')" />
<x-backpack::menu-item title="Connections" icon="la la-link" :link="backpack_url('connection')" />
<x-backpack::menu-item title="Settings" icon="la la-cogs" :link="backpack_url('setting')" />

{{-- Add other menu items or separators as needed --}}