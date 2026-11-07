<div
    @if($asOverlay)
        data-testid="{{ $mobileOnly ? 'mobile-post-detail-overlay' : 'post-detail-overlay-host' }}"
        @class([
            'pointer-events-none fixed inset-x-0 top-[60px] bottom-0 z-50',
            'lg:hidden' => $mobileOnly,
        ])
    @endif
    aria-hidden="true"
></div>
