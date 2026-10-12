{{-- Search the cards on the Continuous or Discrete page by name or code, once there are enough to need it --}}
@if($cards->count() > 6)
<div class="relative mb-4 max-w-sm">
    <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
    <input type="text" x-model="find" placeholder="Search material or code..." autocomplete="off" aria-label="Search materials"
        class="w-full text-sm rounded-lg border border-slate-300 bg-white pl-9 pr-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
</div>
<p x-show="noneShown()" style="display:none;" class="text-sm text-slate-500 mb-4">No material matches "<span x-text="find"></span>".</p>
@endif
