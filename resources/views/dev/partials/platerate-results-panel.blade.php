<section data-ui="results-panel" class="grid gap-6 rounded-rgCard border border-rg-border bg-rg-card p-5 sm:grid-cols-2">
    <div>
        <h3 class="text-base font-bold text-rg-text">Results</h3>
        <p class="mt-1 text-xs text-rg-muted">You voted: Homemade</p>

        <div class="mt-4 space-y-3">
            <div>
                <div class="mb-1 flex justify-between text-xs font-semibold text-rg-text2">
                    <span>Homemade</span><span>62%</span>
                </div>
                <div class="h-2 rounded-rgPill bg-rg-card2"><div class="h-2 w-[62%] rounded-rgPill bg-rg-good"></div></div>
            </div>
            <div>
                <div class="mb-1 flex justify-between text-xs font-semibold text-rg-text2">
                    <span>Restaurant</span><span>38%</span>
                </div>
                <div class="h-2 rounded-rgPill bg-rg-card2"><div class="h-2 w-[38%] rounded-rgPill bg-rg-accent"></div></div>
            </div>
        </div>

        <p class="mt-4 text-[22px] font-bold text-rg-text">200 votes</p>
    </div>

    <div>
        <h3 class="text-base font-bold text-rg-text">Cuisine guess distribution</h3>
        <div class="mt-4 space-y-2.5">
            @foreach (['IT' => 41, 'AS' => 7, 'US' => 18, 'MX' => 28, 'OT' => 6] as $label => $value)
                <div>
                    <div class="mb-1 flex justify-between text-xs font-semibold text-rg-text2">
                        <span>{{ $label }}</span><span>{{ $value }}%</span>
                    </div>
                    <div class="h-2 rounded-rgPill bg-rg-card2"><div class="h-2 rounded-rgPill bg-rg-accent" style="width: {{ $value }}%"></div></div>
                </div>
            @endforeach
        </div>
    </div>
</section>
